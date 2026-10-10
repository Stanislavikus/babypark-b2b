# Product File Import v1 — Narrow Adversarial Closure Study

> **STATUS: Research / Proposal — NOT Resolved.** Product Owner approval required for §6 contract.
> Author: Claude (Opus 5.5). **Independence caveat:** this is the same chat session that produced one of
> the two "prior independent studies" cited in the brief (`PRODUCT_FILE_IMPORT_V1_ADVERSARIAL_ARCHITECTURE_STUDY_2026_10_10.md`).
> Conclusions about that study's claims were re-derived from source, but for true independence the
> chunk-transaction claim should also be checked by a different session/vendor.

## 0. Verified base

| Item | Value |
|---|---|
| `origin/develop` | `06bf85e9c1d6335a3f69bf0c7cee3499cf3094af` (unchanged since the previous study) |
| Laravel | v13.24.0 — `Illuminate/Database/Concerns/ManagesTransactions.php`, `ConcurrencyErrorDetector.php` read at tag |
| Filament | v5.7.6 — `ImportCsv.php:82–127` re-verified: one `DB::transaction` per chunk, `catch (Throwable)` per row, chunk commit |
| Workers | `docker-compose.yml:82` default worker `--tries=3 --max-time=3600` (timeout default 60 s); `:100` connectors worker `database_connectors --timeout=900 --max-time=3600`; `config/queue.php:41` `database` retry_after 90, `:51` `database_connectors` retry_after 1200 |
| Precedent | `AdobeProductReceiveApplyService.php:435–541` already nests `GovernedProductVariantColumnMutationService` + `GovernedDynamicFieldValueWriter` inside one outer transaction (run row `FOR UPDATE` → config → mappings → writers → item insert → run update) |

Prior claim re-verified: native Filament chunk transaction breaks row atomicity (statement-level
rollback + chunk commit) and keeps every writer's workspace `FOR UPDATE` lock for the whole chunk
(InnoDB releases row locks only at top-level commit/rollback, never at `RELEASE SAVEPOINT`). **Confirmed.**

---

## 1. Decision table

| Decision | Verdict | Reason |
|---|---|---|
| Outer row transaction | **AMEND** | Atomic and safe *only if* (a) concurrency/connection errors are never caught inside the row — Laravel skips savepoint rollback for them; (b) lock order is fixed: run → workspace → everything else; (c) all PHP-side state lives inside the retried closure. |
| Claim-first exactly-once | **AMEND** | `UNIQUE(run_id,row_number)` + claim inside the mutation tx is sufficient for committed effects. Two defects: the "separate short transaction" for failures opens a window, and unknown exceptions classified as "infrastructure" create poison rows that stall the run. |
| Identity resolver A/B/C | **C, amended** (C > A > B) | C with `(product, variant?, match_kind)` output and two row-level rules is deterministic and fail-closed. Merchant sees only **Master Product ID \| Exact SKU** — no extra UI concept. B is broken by current editing code; A is either unsafe or degenerates into C. |
| Continuation jobs | **PASS for B** | Materially safer at the 10 000 × 200 bound (no 900 s timeout kills, deploys wait ≤ budget, constant memory). Stop rule: **both** 500 rows **or** 60 s, checked between rows. Dedupe via a run sequence number, not locks. |

---

## 2. Question 1 — RowTransactionDeadlock

### 2.1 What Laravel actually does (v13.24.0)

`transaction($cb, $attempts)` at nesting level > 1 creates `SAVEPOINT transN`. On exception
(`ManagesTransactions.php:88–115`):

- **concurrency error** (`ConcurrencyErrorDetector`: SQLSTATE 40001, "Deadlock found…",
  **"Lock wait timeout exceeded…"**) **and** level > 1 → `transactions--` and throw
  `DeadlockException` **without `ROLLBACK TO SAVEPOINT`** and **without retry** (`:93–101`);
- any other exception → `rollBack()` to the savepoint (`:107`), then rethrow;
- at level 1 → full `ROLLBACK`; retries only if `causedByConcurrencyError` and attempts remain.

Consequences:
- **No inner retry can run after the outer transaction is gone.** Inner `attempts=5`
  (`GovernedProductVariantColumnMutationService` `DEADLOCK_RETRY_ATTEMPTS`, `GovernedDynamicFieldValueWriter:77`)
  is inert when nested. The manual retry loop in `GovernedDynamicFieldValueWriter:574–650` catches only
  `UniqueConstraintViolationException` (statement-level, savepoint already rolled back, tx alive) → safe;
  `DeadlockException` passes through it.
- No v1 writer has non-transactional side effects (no `dispatch`/`event`/`Storage`/`Cache`/`Http` in
  `MasterProductDraftCreator`, column writer, dynamic writer, `BrandManager`, `CategoryTreeMutationService`);
  `Product::booted()` only calls `BasicProductStructureReconciler::ensureWorkspace()`, which writes in the
  same tx and memoizes nothing. No in-process caches found in `Services/Catalog|Fields|ProductStructure`.

### BLOCKER Q1-a — Lock-wait timeout leaves partial writes if the row catches the exception (invariant **RowAtomicity**)

Trace (MySQL default `innodb_rollback_on_timeout=OFF` → 1205 rolls back the *statement only*):

```
L1  BEGIN                                   -- row tx
L1  INSERT product_import_run_rows (claim)
L2  SAVEPOINT trans2                        -- import's "writers" block
L3  SAVEPOINT trans3                        -- MasterProductDraftCreator
      INSERT products ...  ok
      INSERT product_variants ... ok
    RELEASE trans3
L3  SAVEPOINT trans3                        -- GovernedDynamicFieldValueWriter
      SELECT field_bindings ... FOR UPDATE  -> 1205 lock wait timeout (statement only)
    Laravel L3: concurrency → transactions=2, throw DeadlockException (NO rollback to trans3)
    Laravel L2: concurrency → transactions=1, throw DeadlockException (NO rollback to trans2)
L1  import code: catch (Throwable) → UPDATE claim outcome='failed'; COMMIT
=> Product + Variant committed, row reported failed.
```

The same happens for 1213 deadlock only in the opposite way (InnoDB has already rolled back everything,
including the claim; a subsequent outcome write then runs **in autocommit** and commits a "failed" row for
an attempt that left no effects — harmless but misleading; and if the import then continues to the next
row inside what it believes is still the row tx, those writes autocommit one by one).

**Minimum safe fix (import boundary only, no writer rewrite):**
1. Row closure catches only *domain/business* exceptions, and only around **its own** nested
   `DB::transaction` (the "writers savepoint"). Anything where
   `DB::connection()->causedByConcurrencyError($e)` / `DeadlockException` / lost-connection is true is
   **rethrown** so the level-1 handler issues a full `ROLLBACK`.
2. Row tx uses `DB::transaction($closure, attempts: 3)`; the closure is pure with respect to PHP state
   (counters, "created IDs" maps, resolved models are produced inside and applied only after it returns).

**Mandatory MySQL test:** connection B holds `SELECT … FROM field_bindings WHERE id=? FOR UPDATE`;
`SET SESSION innodb_lock_wait_timeout=1` on A; run one Create row with a dynamic field on A →
assert zero new `products`/`product_variants` rows, no committed outcome for that attempt; release B;
retry succeeds → exactly one product, one outcome row.

### BLOCKER Q1-b — Lock-order inversion created by the outer transaction (invariant **WorkspaceWriteLiveness**)

Lock orders in actual code:
- `MasterProductDraftCreator`: **workspace** → brand → insert (`:93–132`);
- `BrandManager::assign`: **workspace** (`:182–187`) → product → brand;
- column writer: binding → definition → **product** (`:39–43, :213–216`) — no workspace lock;
- dynamic writer: binding → definition → slot — no workspace lock.

Counterexample (Update row with `name` + `brand`):
```
T_import (row tx): column writer → product X FOR UPDATE   (holds X)
T_ui (card save): BrandManager::assign(X) → workspace FOR UPDATE (holds W) → wants X  (waits)
T_import:          BrandManager::assign(X) → wants W                              (waits)
=> deadlock. InnoDB may pick T_ui as victim → merchant's card save fails (top-level, attempts=1).
```
Without the outer transaction this cycle cannot form (the column writer commits X before brand assign).

**Minimum safe fix:** the row tx acquires locks in a fixed prefix order before any writer:
`product_import_runs` row `FOR UPDATE` → `workspaces` row `FOR UPDATE` → resolved target
Product/Variant rows `FOR UPDATE` (ascending id) → writers in canonical order (column fields, then
dynamic fields by `field_binding_id` asc). Workspace-first writers then just re-enter locks already held.
Residual deadlocks with multi-writer transactions elsewhere (e.g. Adobe Receive Apply, which writes in
entry order) are possible but handled: import retries; they are not correctness failures.

**Mandatory MySQL test:** query-log assertion that the first two locking statements of every row tx are
the run row and the workspace row; plus a two-connection test reproducing the trace above, asserting the
UI-side `BrandManager::assign` completes (waits, not deadlocks) and the import row commits once.

### SHOULD FIX Q1
- Workspace row is held for the full row; with 200 mapped dynamic fields that is ~1 s per row during
  which every workspace-first writer waits. Acceptable for v1; the 60 s segment budget (§5) bounds total
  impact; document it.
- `field_bindings` rows of **global** (workspace_id NULL) bindings are locked `FOR UPDATE` by every value
  write in every workspace (`lockBindingForMutation`, column `:137`, dynamic `:724`). Pre-existing,
  cross-tenant serialization point; record as GAP-004-adjacent backlog, not an import blocker.

---

## 3. Question 2 — RowClaimExactlyOnce

### 3.1 Amended algorithm

```
for row r (in file order) in this segment:
  DB::transaction(attempts: 3) {                              -- level 1
    run := SELECT run FOR UPDATE                              -- serializes workers of this run
    if run.status != applying or run.cancel_requested → stop segment
    SELECT workspace FOR UPDATE
    if EXISTS run_row(run, r) → skip (already terminal)       -- committed earlier
    INSERT run_row(run, r, outcome='processing')              -- claim; UNIQUE(run_id,row_number)
    try {
      DB::transaction {                                       -- level 2: our savepoint
        resolve identity (locking reads) ; validate ; call writers
      }
      UPDATE run_row SET outcome=created|updated|unchanged|skipped
    } catch (DomainFailure $e) {                              -- savepoint already rolled back by Laravel
      UPDATE run_row SET outcome='failed', error_code=…
    }                                                         -- concurrency / connection / unknown: see below
    UPDATE run counters
  }
```

Failure classification:
- **Concurrency or lost connection** → rethrow → full rollback (claim included) → `attempts` retry;
  after attempts exhausted → job fails → resumed later by the ledger.
- **Known domain/validation exceptions** (writer exception hierarchy, `ValidationException`,
  `InvalidArgumentException` from creator, `UniqueConstraintViolationException` on `products.sku` /
  `product_variants.sku` → `already_exists`) → terminal `failed` **in the same transaction**.
- **Any other `Throwable`** (TypeError, FK violation, LogicException) → `report()` + terminal
  `failed: internal_error` in the same transaction, **after** the level-2 savepoint has been rolled back.

### 3.2 Break attempts

| Scenario | Result with amended algorithm |
|---|---|
| Two workers on the same run | Serialized on the run row lock; the second sees `EXISTS` and skips. Correct. |
| Crash before claim commit | Nothing committed; redelivery reprocesses. Correct. |
| Crash after writers, before commit | InnoDB rolls back writers + claim together. Correct. |
| Crash after commit, before queue ACK | Redelivery after `retry_after` → `EXISTS` → skip. Correct. |
| Duplicate job delivery | Same as two workers. Correct. |
| Validation failure | Savepoint rollback + terminal `failed` in the same tx. No window. |
| Deadlock / lock-wait timeout | Full rollback (Q1-a fix), retry whole row. Correct. |
| Cancellation mid-row | Cancel `UPDATE runs` waits for the row's run lock → takes effect at the next row boundary. Rows already committed stay committed (must be stated in UI: cancel ≠ undo). |

### BLOCKER Q2-a — Separate failure transaction lets a failure overwrite a success (invariant **LedgerTruth**)

Candidate trace (separate short tx for failures):
```
W1: row 5 tx: claim, writer throws InvalidSelectOption (option deleted moments ago) → ROLLBACK
W2 (duplicate delivery): row 5 tx: claim OK (W1 rolled back), option re-added by admin,
    writers succeed, outcome=created, COMMIT
W1: separate tx: write outcome 'failed' for row 5
    - if implemented as updateOrCreate/upsert → overwrites 'created' with 'failed'
      → error report lists a row whose product exists → operator re-submits as Create → duplicate attempt
    - if INSERT-only → 1062, must be swallowed (fragile)
```
**Fix:** terminal failure recorded in the **same** row transaction after rolling back to the level-2
savepoint (§3.1). No separate transaction exists.
**Test (MySQL):** force W2 to commit row 5 between W1's writer failure and W1's next step (two
connections, barrier via a test-only hook) → final ledger `created`, exactly one product.

### BLOCKER Q2-b — "Infrastructure = retry" makes deterministic bugs poison the run (invariant **RunProgress**)

Trace: row 812 hits a deterministic `TypeError` in a writer → classified infrastructure → no terminal
row → job retried 3× → job fails → resume → row 812 again → run never passes row 812; rows 813–10 000
never processed.
**Fix:** only concurrency/lost-connection is retryable; every other `Throwable` is terminal
`internal_error` for that row (with `report()`), and the run continues.
**Test:** writer stub throwing `TypeError` on row 2 of 3 → run `completed_with_errors`, rows 1 and 3
applied, row 2 `internal_error`, zero writes for row 2.

### SHOULD FIX Q2
- Within-file duplicate targets must be detected in a **pre-pass** (dup SKU / dup resolved
  (product, variant)) and stored on the run before Apply, not incrementally — incremental detection
  depends on which worker processed which row first.
- `WithoutOverlapping(run_id)` / `ShouldBeUnique` only as defence-in-depth (§5).

---

## 4. Question 3 — ImportIdentityResolver

### 4.1 Schema facts that decide it
- `unique(workspace_id, sku)` on `products` and separately on `product_variants`
  (`2026_07_08_100002…:22,30`); both nullable (`2026_09_30_160000…:14,19`). No cross-table invariant.
  Two variants in one workspace can never share a non-null SKU.
- Create writes the **same** SKU to Product and its default Variant (`MasterProductDraftCreator.php:111–132`).
- **Ordinary editing diverges them:** `EditProduct::handleRecordUpdate()` saves `products.sku` through
  `parent::handleRecordUpdate()` (`EditProduct.php:111`) and never touches the default Variant's `sku`.
- Configurable Products have Variant SKUs that differ from Product SKU by design.
- 1C ownership: `BrandManager::isSourceOwned` = Product **or any Variant** has `onec_guid`
  (`BrandManager.php:221–229`); the edit form uses Product only (`ProductResource.php:2000–2003`).
  **Column and dynamic writers do not check source ownership at all.**

### 4.2 Ranking: C > A > B

**B (strict synchronized SKU) — FAIL.**
Counterexample: create P (product.sku=A, variant.sku=A) → edit SKU to B in the card →
product.sku=B, variant.sku=A. B rejects both `A` and `B` → a valid simple product is unaddressable by
SKU; every configurable Product is unaddressable by definition.

**A (namespaced) — rejected for v1.**
Without a cross-namespace check: P1.sku=`ABC`, P2.variant.sku=`ABC`; operator picks `product_sku`,
the row carries variant-level fields meant for P2's variant → P1 is updated silently. With the check,
A = C plus a three-way choice merchants cannot reliably make (they do not know which table a SKU lives in).

**C (union) — chosen, with amendments.**
Resolver returns `(master_product_id, variant_id | null, match_kind)`:
- Exact SKU: union of `products.sku` and `product_variants.sku` matches → distinct Product IDs:
  0 → `not_found`, >1 → `ambiguous_identity`, 1 → resolved.
  `variant_id` = the matched Variant if a Variant matched; else the only Variant if the Product has
  exactly one; else null.
- Master Product ID: `variant_id` = only Variant if exactly one, else null.
- **Rule V:** a row carrying any Variant-level field with `variant_id = null` → `variant_not_determined`.
- **Rule P:** a row resolved via a Variant SKU of a **multi-Variant** Product that carries any
  Product-level field → `product_field_on_variant_row` (prevents N variant rows writing conflicting
  Product values, last-wins).
- **Rule D:** two rows resolving to the same `(product, variant)` → both `duplicate_target_in_file`.
- Blank key → `identity_missing`. Never "create instead".

Checklist:
| Case | C result |
|---|---|
| Simple + hidden default Variant | Both match same Product → resolved, variant determined |
| Product/Variant SKU diverged after editing | Either SKU resolves the same Product (stale Variant SKU still identifies it — same physical product, deterministic) |
| Configurable, several Variants | Product SKU → product-level fields only; Variant SKU → variant-level fields (+ Rule P) |
| SKU only on Variant | Resolved via Variant |
| P1 product SKU = P2 variant SKU | `ambiguous_identity`, zero writes |
| Product-level fields | Allowed unless Rule P |
| Variant-level dynamic fields | Allowed only with determined Variant (Rule V) |
| Multiple Variants sharing a SKU | Impossible by schema (non-null unique) |
| Source-owned / 1C | See BLOCKER Q3-a |

**Answer:** v1 can safely expose only **Master Product ID | Exact SKU**. No extra UI concept is needed;
Variant-level fields are handled by Rules V/P as row errors with clear messages ("use the Variant SKU").

**Create preflight:** SKU must be absent from **both** `products.sku` and `product_variants.sku`
(the creator writes both; the divergence case otherwise fails with a raw unique violation on the
Variant insert) → `already_exists`.

### BLOCKER Q3-a — File import bypasses 1C read-only (invariant **SourceAuthority**)

Trace: Product with `onec_guid` (UI shows name/SKU/EAN disabled, brand read-only) → Update row
`name=…` → `GovernedProductVariantColumnMutationService::set()` has no source check → name overwritten;
next 1C sync overwrites it back or conflicts. Two inconsistent `isSourceOwned` definitions exist.
**Fix:** v1 resolver marks a target source-owned using the broader `BrandManager` definition (Product or
any Variant `onec_guid`) → entire row `skipped: source_owned`, zero writes. (Field-level 1C authority is
a later decision.)
**Test:** Product with Variant-level `onec_guid` only → Update row with name → `skipped: source_owned`,
`products.name` unchanged.

---

## 5. Question 4 — ImportExecutionSegmentation

Cost model at the bound: 10 000 rows × up to 200 dynamic fields; each dynamic write ≈ 4–6 statements
(binding/definition/slot locks + write) → up to ~10 M statements. That cannot finish inside the 900 s
connectors-worker timeout as one job.

| Concern | A: one job | B: bounded continuation |
|---|---|---|
| 900 s timeout (`failOnTimeout`) | Killed mid-run at the bound; recovery via ledger only | Never approached |
| Deploy / `queue:restart` / `--max-time=3600` | Waits up to 15 min or is killed | Waits ≤ budget |
| SIGKILL / crash | Redelivery after `retry_after` 1200 s | Same 1200 s for the current segment only |
| Memory | OpenSpout streaming is flat; PHP/Eloquent growth over 10 000 rows | Fresh process state per segment |
| XLSX resume cost | — | Re-parse from row 1 each segment: 20 segments × ≤10 000 rows ≈ 100 k rows parsed — seconds |
| Head-of-line blocking of connectors queue | Up to 15 min | ≤ budget |
| Operator visibility | Same (ledger + counters) | Same, plus `last_segment_at` heartbeat |

**Verdict: B.** Stop rule: **both** — 500 rows **or** 60 s elapsed, checked between rows.
The time budget is the real guard (wide rows); the row cap makes behaviour predictable and testable.

Simplest safe orchestration:
- Job payload `{run_id, segment_seq}`; on `database_connectors`, queue `imports`
  (connectors worker becomes `--queue=connectors,imports`); `$timeout = 300`, `failOnTimeout = true`.
- Start of segment: CAS `UPDATE runs SET segment_seq = segment_seq + 1 WHERE id=? AND segment_seq=? AND status='applying'`;
  0 rows affected → stale/duplicate continuation → exit. This gives exact dedupe without locks.
- End of segment: if rows remain → dispatch next `{run_id, segment_seq+1}` (`afterCommit`).
- Scheduled sweeper (every 5 min): run `applying` with `last_segment_at` older than 25 min → dispatch
  continuation with current `segment_seq` (covers lost dispatch, failed job, crash after commit).
- Fresh authorization (`manage_products`, workspace membership) at each segment start; revoked → run
  `failed: actor_unauthorized`.
- Default `database` connection (retry_after 90, worker timeout 60) must not be used.

**Mandatory tests:** (1) 1 200-row file with cap 500 → exactly 3 segments, 1 200 outcome rows;
(2) duplicate dispatch of the same `segment_seq` → second exits, no extra outcomes; (3) kill after
row 700 commit (simulated exception after commit) → sweeper re-dispatch → completes with 1 200 outcomes,
no duplicates; (4) cancel during segment 2 → run `cancelled`, committed rows unchanged, no further segments.

---

## 6. Secondary consistency checks

1. **Category path creation — may be deferred.** `02-ATTRIBUTE_DICTIONARY.md:101` and Category
   Classification [Resolved] *permit* ("may create… according to the import contract"; "does not prohibit")
   — they do not require it. Additional evidence for deferral: `categories` has **no sibling-name unique
   constraint**, so path find-or-create idempotency would rest on lock discipline alone. **v1: existing
   Category by ID or exact full path (per-level exact name, active path); ambiguous path → row error;
   path creation v1.1.** No contradiction. Note: assigning a category on **Update** still needs a small
   new writer (no existing service writes `products.category_id` for an existing Product).
2. **Identity targets always need explicit confirmation — yes, once per run.** Even exact `SKU`/`sku`
   can be a supplier code; `Код`/`Code` are global aliases to `sku`. Rule: the identity column is chosen
   by the operator in a dedicated "Identify products by" step (pre-filled suggestion, mandatory click),
   independent of mapping confidence. Two headers suggesting the identity target → no pre-fill.

## 7. NON-BLOCKING
- Snapshot reads inside the row tx (e.g. creator's category/brand existence checks) see the row-start
  snapshot (milliseconds old); writers re-check under locks. Acceptable.
- InnoDB auto-increment is not rolled back → IDs of rolled-back rows are never reused; any "created IDs"
  cache must still be filled only after commit (relevant to v1.1 family creation).

---

## 8. Recommended frozen contract (≤ 20 bullets)

1. Zero new Composer packages; OpenSpout reads CSV/XLSX; Filament import runtime unused.
2. `product_import_runs` / `product_import_run_rows` with `workspace_id`; `UNIQUE(run_id,row_number)`.
3. Preview is read-only, invokes no writer; Apply re-reads the stored file and verifies SHA-256.
4. One row = one top-level `DB::transaction(attempts: 3)`; lock prefix: run row → workspace row →
   target rows (id asc); then writers in canonical order (columns, then dynamic by binding id).
5. Writers run inside one import-owned nested transaction (savepoint).
6. Known domain failures and unknown `Throwable`s → savepoint rollback + terminal `failed` outcome in the
   **same** transaction; run continues.
7. Concurrency (40001/1213/1205), `DeadlockException`, lost connection → never caught in the row; full
   rollback; retried.
8. Claim = `INSERT … 'processing'` inside the row tx; existing row ⇒ skip. No separate failure transaction.
9. All PHP state (counters, caches) is produced inside the retried closure and applied after commit.
10. Identity: merchant chooses **Master Product ID | Exact SKU** in a mandatory "Identify products by"
    step; resolver = union over `products.sku` + `product_variants.sku` → distinct Master Products.
11. Rules: >1 Product → `ambiguous_identity`; Variant-level field without determined Variant →
    `variant_not_determined`; Product-level field on a Variant row of a multi-Variant Product →
    `product_field_on_variant_row`; same target twice in file → `duplicate_target_in_file` (pre-pass).
12. Create-only requires SKU absent from both SKU columns; Update-only never writes identifiers.
13. Source-owned targets (Product or any Variant `onec_guid`) → row `skipped: source_owned`.
14. Category: existing ID or exact active full path; no path creation in v1.
15. Execution: continuation jobs `{run_id, segment_seq}`, stop at 500 rows or 60 s; CAS on
    `segment_seq` dedupes; sweeper re-dispatches stale runs; `database_connectors` queue `imports`.
16. Fresh `manage_products` authorization at Apply admission and each segment start.
17. Cancellation takes effect at the next row boundary; committed rows are not undone (UI states this).
18. Out of v1: ODS, media/images/URLs, price/stock, configurable-family creation, category path creation, upsert.
19. Mandatory MySQL suite (`phpunit.mysql.xml`): Q1-a, Q1-b, Q2-a, Q2-b, Q3-a, segmentation 1–4.

## 9. Product Owner decisions

- **E1 Source-owned (1C) products in v1** — A (recommended): whole row skipped. B: allow non-1C fields
  (needs a field-level authority decision first).
- **E2 Category on Update rows** — A (recommended): add one small category-assignment writer in v1.
  B: category only on Create rows in v1.