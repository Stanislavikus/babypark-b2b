Base is `origin/develop` at `06bf85e9c1d6335a3f69bf0c7cee3499cf3094af`. Locked runtime used for the traces: Laravel `v13.24.0`, Filament Actions `v5.7.6`, OpenSpout `v4.32.0`. No code, migration, or PR.

| Decision | PASS / AMEND / FAIL | Reason |
|---|---|---|
| Outer row transaction | **AMEND** | One catch-free InnoDB transaction per input row is the atomic boundary. Nested writer deadlock retries do not run, and a catch inside that callback commits a partial row. |
| Claim-first exactly-once | **AMEND** | `UNIQUE(run_id, row_number)` inside the same mutation transaction is the right lock. A late failure write, or continuing after duplicate-key, still double-applies or overwrites a committed outcome. |
| Identity resolver A/B/C | **C (AMEND)** | Union of Master Product ID and Exact SKU is the only deterministic fail-closed choice. A exposes an internal split. B rejects catalogues that ordinary editing already creates. |
| Continuation jobs | **AMEND** | Bounded continuation jobs are materially safer than one job for the whole run. Each slice needs both stop conditions and a continuation epoch. |

## Verified Filament chunk behaviour

`Filament\Actions\Imports\Jobs\ImportCsv::handle()` in Actions `v5.7.6` opens one `DB::transaction` for the whole chunk and catches `RowImportFailedException`, `ValidationException`, and `Throwable` inside that callback. BabyPark does not call this class. The trace is what the per-row boundary has to avoid.

1. Chunk `BEGIN`. Row 1 calls `MasterProductDraftCreator`, which takes a savepoint and `SELECT … FROM workspaces … FOR UPDATE`, then inserts Product and Variant. The nested commit only releases the savepoint. The workspace lock stays held.
2. The same row then calls `GovernedDynamicFieldValueWriter`. A validation exception rolls that writer back to its own savepoint. `ImportCsv` catches it and records a failed row.
3. The chunk commits. The Product and Variant from step 1 remain. The row is recorded as failed.

That breaks **RowAtomicity**: one input row must commit every Product, Variant, dynamic-field, and domain write, or none of them.

A deadlock is worse. `ManagesTransactions::handleTransactionException()` in Laravel `v13.24.0` states that MySQL rolls back the entire transaction, and when `transactions > 1` it decrements the counter and throws `DeadlockException` without retrying. `ImportCsv` swallows that exception and keeps looping while `$transactions` is still 1 and InnoDB has no transaction. Later rows issue `SAVEPOINT`, and the outer `PDO::commit()` can commit only the post-deadlock work. Earlier rows are gone, while the in-memory success count can still be written.

## Question 1 — outer row transaction

**AMEND.** Do not rewrite the writers.

`MasterProductDraftCreator`, `BrandManager`, `GovernedProductVariantColumnMutationService`, and `GovernedDynamicFieldValueWriter` all call `DB::transaction()` on the default connection. The column and dynamic writers pass `DEADLOCK_RETRY_ATTEMPTS = 5`. The creator and `BrandManager` use the default of one attempt. None of these four register `afterCommit` work. Nested `afterCommit` callbacks in this Laravel version run only when the level returns to 0.

On this Laravel version the inner retry does **not** fire after InnoDB has aborted the outer transaction. The nested branch throws before `continue`. The dangerous “inner retry commits after the outer transaction is gone” path is closed unless the import catches that exception inside the outer callback.

Concrete trace, two MySQL sessions:

1. Import session: outer `BEGIN`. Claim insert. `MasterProductDraftCreator` savepoint. `SELECT workspaces FOR UPDATE` on workspace W. Product insert is still uncommitted.
2. UI session: `BrandManager::assign` does `SELECT workspaces FOR UPDATE` and waits. It already holds `SELECT products FOR UPDATE` on product P from an overlapping edit.
3. Import session: a later writer in the same row does `SELECT products FOR UPDATE` on P.
4. InnoDB picks the import transaction as deadlock victim and rolls back the whole transaction, including the claim and the product insert. Savepoints are gone.
5. The column writer’s five attempts do not run, because `transactions` was 2. It throws `DeadlockException`. The message still contains `Deadlock found when trying to get lock`, so the outer handler still recognises it.
6. If the outer callback does not catch, the outer handler sets the counter to 0. With `attempts = 1` it rethrows and nothing is committed. With `attempts = 5` it issues a new `BEGIN` and retries the whole row. That retry is safe.
7. If the callback catches and then calls the next writer, that writer sees `$transactions >= 1` and emits `SAVEPOINT` on a connection whose InnoDB transaction is already gone. The later `PDO::commit()` can persist only that later writer.

Lock-wait timeout (`Lock wait timeout exceeded`) is classified as a concurrency error too, but InnoDB’s default is to roll back only the statement. The nested handler still treats it as a full abort. That is safe only when the exception reaches the outer handler, whose `rollBack()` sees `inTransaction() === true` and issues a real `ROLLBACK`.

Workspace `FOR UPDATE` from the creator or `BrandManager` is held until the outer row commits, not until the inner savepoint releases. That matches “locks for one row”. It does extend the creator’s lock across later field writes in that same row.

Minimum boundary:

- The import calls `DB::transaction($row, 5)` once per input row, on the default connection.
- The callback does not catch. Business exceptions, `DeadlockException`, and lock-wait errors propagate to that handler.
- Deadlock and lock-wait are infrastructure failures. They must not write a terminal `failed` outcome.
- Business exceptions abort the catalogue transaction. The failed outcome is the separate transaction in Question 2.

Mandatory MySQL test, same shape as `tests/Integration/MySql/GovernedProductVariantColumnMutationServiceConcurrencyMySqlTest.php` (skip unless `driver === mysql`):

- Force the lock inversion above: outer row transaction calls the creator, then a column or dynamic writer, while a second connection holds the product lock and waits on the workspace lock.
- Assert the victim leaves no Product, no Variant, and no `product_import_run_rows` claim.
- Assert a second outer attempt with `attempts >= 2` commits exactly one Product and one terminal row.
- Assert a callback that catches `DeadlockException` and calls a second writer is rejected by the test fixture: that second writer’s commit must not survive. This can be a focused regression around the boundary, not a rewrite of the writers.
- Assert a validation throw from the second writer rolls back the Product inserted by the creator in the same outer transaction.

## Question 2 — claim-first exactly-once

**AMEND.** `UNIQUE(run_id, row_number)` plus the claim insert as the first statement of the mutation transaction is enough to be the only correctness lock. `WithoutOverlapping` is optional defence in depth. The cache-lock note in `docs/07-TECH_STACK.md` still applies: `expireAfter(0)` on the database cache store falls back to about 24 hours, so a crashed overlap lock must not be the resume mechanism.

`processing` is never visible to other transactions. It is committed only together with the terminal outcome. Other workers either block on the unique index or see a finished row.

Strongest counterexample, duplicate delivery:

1. Worker A’s row transaction inserts the claim, then a writer throws a business exception. InnoDB rolls back the claim and the catalogue writes.
2. Worker B, a duplicate of the same job, inserts the same `(run_id, row_number)`, runs the writers, and commits `created`. The catalogue row exists.
3. Worker A’s follow-up “write failed” transaction does `UPDATE product_import_run_rows SET outcome = 'failed'`. The ledger now says failed, and the catalogue change from B remains.

A second hole is inside one transaction. Under MySQL `REPEATABLE READ`, the first ordinary `SELECT` fixes the snapshot. If identity resolution, or any other read, runs before the claim insert, the loser’s later `SELECT` can miss the winner’s committed row after error 1062. If the loser then continues into the writers and commits, those catalogue writes persist without owning the claim. `INSERT` itself does not establish that snapshot. The claim insert has to be the first statement.

Cancellation hole: the in-flight transaction will not see `runs.status = cancelled` on a snapshot taken before the cancel commits. It can commit the row after the operator cancels.

Minimum fix:

- First statement: `INSERT` the claim. No earlier read on that transaction.
- On 1062: `ROLLBACK` immediately, then a new transaction `SELECT … FOR UPDATE` the existing row. Terminal outcome means skip. No row means retry the claim. Do not call writers in the transaction that received 1062.
- Business failure: after the catalogue rollback, `INSERT` `failed` only when the row is absent. On 1062, leave the existing terminal outcome in place.
- Deadlock, lock-wait, and disconnect: write no terminal outcome.
- A unique SKU violation from `MasterProductDraftCreator` is a business failure. Retrying it as infrastructure loops.
- Immediately before commit, locking-read the run status. If cancelled, roll the catalogue transaction back and `INSERT` `skipped` only if absent.

Mandatory MySQL tests:

- Two connections, same `(run_id, row_number)`. One commits `created`. The other waits, gets 1062, and leaves catalogue row counts unchanged.
- The same race with an ordinary `SELECT` before the claim. The test fails if the loser commits a second product.
- Validation rollback, then a concurrent winner commits `created`, then the loser’s failure write runs. Outcome stays `created`.
- Kill the connection after the writers and before commit. Retry commits one catalogue change and one terminal row.
- Commit, then deliver the job again. The second attempt does not change the catalogue.
- Cancel after the claim is inserted and before commit. No catalogue change, and the ledger is `skipped` or absent, never `created`.
- A second attempt after a deadlock finds no claim and can still commit once.

## Question 3 — identity

Rank: **C, then A, then B.** v1 can show the merchant only **Master Product ID | Exact SKU**. Variant-level columns do not require a third selector.

Evidence on `06bf85e9`:

- `products` and `product_variants` each have their own `UNIQUE(workspace_id, sku)`. The same non-null string can sit on Product P1 and on a Variant of Product P2. Two non-null variant SKUs in one workspace cannot.
- `MasterProductDraftCreator` copies one SKU onto the Product and its single default Variant. That mirror is compatibility-only in the Master Product contract.
- `EditProduct::handleRecordUpdate()` saves the form, including `sku`, through the normal Product update. It does not change `product_variants.sku`. Ordinary editing diverges them.
- `ProductVariantStructureService::promoteSimple()` keeps the existing variant SKU and creates siblings with `sku = null`. `addVariant()` stores whatever SKU was typed, including null. Multiple NULL SKUs are allowed.
- The dictionary’s canonical `sku` binding is `product_variants.sku`. The domain model says not to use physical `products.sku` as canonical parent identity.
- The column-writer allowlist is only `name` and `description`. SKU is not a v1 field write.

**B fails** on a product created as `CAB-001`, then edited so `products.sku = CAB-002` while the only variant remains `CAB-001`. Strict equality rejects both strings, so Update-by-SKU can no longer address the product. It also rejects a configurable sibling whose variant SKU is `RED-1` while `products.sku` is still `BASE-SKU`.

**A fails** the same cross-table collision. The merchant’s file has one column, `Артикул`. Choosing `product_sku` updates P1. Choosing `variant_sku` updates P2. Both are exact matches, and A does not report ambiguity. It also forces the merchant to know a split the resolved contract says must not become the identity model.

**C** queries `products.sku` and `product_variants.sku` inside the run’s workspace, then reduces matches to distinct Master Product IDs. Zero is `not_found`. One is resolved. More than one is `ambiguous_identity`. P1’s product SKU and P1’s own mirrored variant SKU collapse to one product. P1’s product SKU plus P2’s variant SKU is `ambiguous_identity`. Blank SKU is not an identity. NULL variant siblings are not matches.

Variant-level dynamic fields are legal only when that resolution also identifies exactly one variant:

- The SKU hit one `product_variants` row.
- Or the product has exactly one variant.

Otherwise the row fails `ambiguous_variant_target` or `variant_not_addressable`. A configurable product addressed only by Master Product ID, or only by a diverged `products.sku`, with a variant-level column, fails closed. The same Exact SKU control addresses a variant once that variant has its own SKU. No Variant ID mode in v1.

Source-owned rows still resolve. `BrandManager` already refuses assignment when `onec_guid` is set on the product or a variant. The product form also locks name, SKU, and EAN. The column writer does not check `onec_guid` and will change `name`. The import boundary must fail those source-owned fields closed before calling the writer. Description and other merchant-editable dynamic fields can still update.

Create does not look up. `MasterProductDraftCreator` writes the required non-empty SKU to both tables in one transaction. A collision with either unique index rolls that transaction back and is a terminal business failure.

## Question 4 — continuation jobs

**AMEND toward B.** One job for the whole run is the weaker choice on this deployment.

`config/queue.php` gives the default database queue `retry_after` 90. `DEPLOY.md` shows `babypark-queue` on that lane with `--tries=3`, and `babypark-connector-queue` on `database_connectors` with `--timeout=900`, `--tries=3`, and `retry_after` 1200. Laravel’s worker timeout default is 60 seconds when `--timeout` is omitted. A 10,000-row job that exceeds the lane timeout is killed, retried, and after three attempts moved to `failed_jobs` with the tail unprocessed. The claim ledger makes each committed row exactly-once, but it does not give the single job more than three lives.

Continuation jobs stay inside the lease: each `ApplyProductImportRun(run_id, epoch)` resumes from the ledger, then dispatches the next epoch. OpenSpout `v4.32.0` streams sheet rows, but opening an XLSX still extracts shared strings. The reader keeps them in memory only while the unique-string count fits; otherwise it uses files of 10,000 strings. Ending the slice releases that cache. XLSX is not row-seekable, so each slice rereads from the start and skips committed row numbers. At 10,000 rows that reread is acceptable.

Duplicate dispatch happens when a slice dispatches the next job and then crashes before ACK. The queue retries the old payload and dispatches again. Two workers then scan the same run. The claim ledger stops double application. An epoch stops the extra scan: the payload carries `run_id` and `epoch` only. Starting a slice compare-and-sets `owner_epoch`. The dispatch transaction increments the epoch. The retried old payload no longer matches and must exit without dispatching and without writing.

Stop on **both** a row cap and a wall-clock budget, whichever comes first. The clock is what keeps the slice under `worker timeout < retry_after`. The row cap bounds a fast slice and the XLSX reread. Starting pair for the default database lane: **250 rows or 30 seconds**. Do not put catalogue import on the connector lane. One long import would occupy the connector worker that `DEPLOY.md` reserves for discovery.

Cancellation is checked at the start of a slice. A cancelled run dispatches nothing further. The in-flight row uses the Question 2 pre-commit check. Progress is the run row plus the ledger, updated as each row commits, so the operator can see a moving count without waiting for the whole file.

## Secondary checks

Category path creation does not block v1. The resolved category architecture says a Master file import is allowed to create a merchant-supplied path. The dictionary says it may do so according to the import contract. v1 can resolve an existing Category ID or an existing exact path and fail the row when it is missing. `MasterProductDraftCreator` already accepts only an existing, effectively active category. There is no sanctioned writer that changes `category_id` on an existing product. Update rows that carry a category value fail `category_update_not_supported` rather than writing the column directly.

Identity aliases do require an explicit confirmation. The dictionary auto-maps `Артикул`, `Код`, `SKU`, `Code`, and `Item Code` to `sku`. That suggestion can be shown. It must not become the Update identity key, and it must not become a SKU field write. SKU is not an allowlisted column mutation.

OpenSpout represents a formula as `FormulaCell`. `getValue()` is the formula text, and `getComputedValue()` is the cached result. Fail-closed has to reject `FormulaCell` itself. Reading the computed value would accept the formula.

## Findings

### BLOCKER

**1. A catch inside the row transaction commits a partial catalogue row.**

- Trace: creator savepoint inserts Product and Variant; dynamic writer throws; catch records failure; outer commit keeps the product.
- Invariant: **RowAtomicity**.
- Fix: one catch-free `DB::transaction($row, 5)`. Business outcome is written only after that transaction has rolled back.
- Test: the MySQL test in Question 1. Also assert the nested deadlock retry does not commit after a victim rollback.

**2. A failure write or a duplicate-key fall-through breaks exactly-once.**

- Trace: A rolls back, B commits `created`, A’s later update stores `failed`. Separately, a `SELECT` before the claim plus a 1062 handler that continues into writers commits a second catalogue change the ledger does not own.
- Invariant: **ExactlyOnceCommittedEffect**.
- Fix: claim insert first; 1062 rolls back and skips in a new locking read; failure insert is insert-if-absent; deadlock and lock-wait write nothing; cancel is rechecked with a locking read before commit.
- Test: the MySQL tests in Question 2.

**3. Strict synchronized SKU, or a namespaced SKU choice, addresses the wrong product or addresses none.**

- Trace: after a normal product save, `products.sku` is `CAB-002` and the variant is `CAB-001`. B rejects both. The same string on P1’s product and P2’s variant is silently assigned by A.
- Invariant: **DeterministicFailClosedIdentity**.
- Fix: adopt C, workspace-scoped, with the single-variant rule for variant-level columns.
- Test: MySQL fixtures for mirror, diverged product SKU, configurable sibling with its own SKU, SKU only on a variant, cross-table collision, NULL siblings, and a source-owned name update that must not call the column writer.

### SHOULD FIX

- Source-owned name is writable by `GovernedProductVariantColumnMutationService`. The import must refuse name, SKU, EAN, brand, and variant-structure changes when `onec_guid` is present on the product or any of its variants.
- Update has no sanctioned category writer. Category is Create-only, through `MasterProductDraftCreator`, and only for an existing active category.
- Identity confirmation stays separate from alias auto-match.
- Place slices on the default database queue with budget under the worker timeout. Keep them off `database_connectors`.
- `BrandManager` needs the run’s authorized actor. `AuthorizationException` is terminal for that row, not a retry.

### NON-BLOCKING

- v1 defers merchant category-path creation. That does not contradict the resolved category document.
- `processing` need not be shown in the UI. The committed ledger and the run counters are the visible state.
- Workspace `FOR UPDATE` for one row is the current writer behaviour. It serializes creates in that workspace with UI edits. It does not by itself justify a writer rewrite.
- `WithoutOverlapping(run_id)` may be added only with a bounded TTL. It is not the exactly-once mechanism.
- Formula cells must be rejected by type. OpenSpout still exposes the cached computed value.

## Recommended frozen contract

- v1 Apply is CSV and XLSX only. One immutable stored file, reread at Apply, SHA-256 checked before any writer.
- Preview never calls a writer.
- Queue payloads carry `run_id` and a continuation epoch only.
- ZIP, MIME, and size limits run before OpenSpout opens the file. A `FormulaCell` fails the row.
- Create and Update are separate. There is no upsert.
- Create requires a non-empty SKU and calls `MasterProductDraftCreator` only. A unique SKU collision is a terminal business failure.
- Update identity is Master Product ID or Exact SKU, chosen explicitly by the operator. Alias matches may suggest. They do not arm identity and they do not write SKU.
- Exact SKU is a workspace-scoped union of `products.sku` and `product_variants.sku`, reduced to distinct Master Product IDs: 0 `not_found`, 1 resolved, many `ambiguous_identity`.
- Variant-level values are written only when that resolution identifies exactly one variant. Otherwise the row fails closed.
- Source-owned name, SKU, EAN, brand, and variant structure fail closed. Category on Update fails closed. Category on Create must already exist and be effectively active.
- Catalogue writes go through the existing sanctioned writers only: creator, column writer, dynamic writer, and `BrandManager::assign`.
- Each input row is one catch-free `DB::transaction(..., 5)` on the default connection. The claim insert is the first statement. The terminal outcome is updated in that same transaction.
- Business failure rolls that transaction back, then inserts `failed` only if no ledger row exists.
- Deadlock, lock-wait, and disconnect leave no terminal outcome. The slice may retry.
- Cancel rechecks the run with a locking read before commit. A cancelled in-flight row rolls back and inserts `skipped` only if absent.
- Apply runs as continuation jobs on the default database queue: stop at 250 rows or 30 seconds, then dispatch the next epoch after commit. `slice budget < worker timeout < retry_after`.
- A slice whose epoch does not match `owner_epoch` exits without writers and without dispatching.
- Price, stock, media, ODS, PDF, and configurable-family creation stay outside v1.
- Exactly-once is the ledger unique key. An overlap lock is optional and must expire.