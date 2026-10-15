# Product File Import v1 — Independent Adversarial Architecture Study

> **STATUS: Research / Proposal — NOT Resolved.** Requires Product Owner approval of the
> decisions in §9 before any implementation task is issued.
> Author: Claude (Opus 5.5), independent verifier session. No code, migrations or PR produced.

## 0. Verified base

| Item | Value |
|---|---|
| Repo / branch | `Stanislavikus/babypark-b2b` / `origin/develop` |
| Base SHA | `06bf85e9c1d6335a3f69bf0c7cee3499cf3094af` (2026-10-10 08:14 +0200, "fix: use native Filament Asset replace flow (#275)") |
| `composer.lock` | `filament/actions` v5.7.6, `openspout/openspout` v4.32.0, `league/csv` 9.28.0, `laravel/framework` v13.24.0, `livewire/livewire` v4.3.5 |
| Vendor source read | upstream tags `filamentphp/filament@v5.7.6` (`packages/actions`) and `openspout/openspout@v4.32.0` (vendor/ is not committed; tags match the lock) |
| Runtime DB / queue | `.env.example`: `DB_CONNECTION=mysql`, `QUEUE_CONNECTION=database`; tests: SQLite + `sync` (`phpunit.xml:29,33`); `phpunit.mysql.xml` exists |

Docs read: `Project_Documentation_Map.md`, `05-AI_WORKING_AGREEMENT.md`, `02-ATTRIBUTE_DICTIONARY.md`
(Smart Import §§ L356–405), `03-DOMAIN_MODEL.md` (Anti-Duplication & Smart Import Layer L1975+,
`workspace_import_aliases` boundary L5500+, Superseded concepts L9367–9374),
`IMPLEMENTATION_GAPS.md` (GAP-004), `reviews/MASTER_PRODUCT_CARD_UX_CONVERGENCE_2026_10_05.md`
(Import vs enrichment), `reviews/CATEGORY_CLASSIFICATION_ARCHITECTURE_2026_10_07.md` [Resolved].

Brief facts checked:
- **`ImportDispatcher` does not exist** in Filament 5.7.6 — confirmed. Dispatch lives inline in
  the `ImportAction` action closure (`ImportAction.php:186–330`).
- No Filament `imports` / `failed_import_rows` tables exist in `database/migrations`; no class in
  `app/` extends `Importer`. Reuse would mean *adding* those vendor tables.
- `docs/03-DOMAIN_MODEL.md:9373`: "Spreadsheet/file import may still use a specialized import flow
  later; it must not redefine the connector sync domain relationship" → a dedicated file-import run
  entity is permitted; it must not be named/positioned as a revived `ImportJob` sync entity.

---

## 1. Verdict on the candidate architecture

**AMEND.** The direction (zero new runtime deps, OpenSpout, BabyPark-controlled
upload/mapping/preview, domain writers only) survives. The clause *"reuse safe parts of Filament
Importer/job/import persistence"* does **not** survive: after removing every unsafe part, nothing
of value remains to reuse. Recommendation: **reuse zero Filament import runtime**; dedicated
`product_import_runs` + `product_import_run_rows`, one sequential job per run, one DB transaction
per row.

### Filament 5.7.6 public extension points (exact)

| Extension point | Where | Usable for v1? |
|---|---|---|
| `ImportAction::importer(class)` / `job(?string)` / `chunkSize()` / `maxRows()` / `options()` / `fileValidationRules` | `ImportAction.php:504–531` | **No** — the action's reader is `League\Csv\Reader` only (`:114, :163, :193`). XLSX cannot pass through it. Using our own upload means we never reach `job()`. |
| `Importer::__invoke(array $data)` (public, overridable) | `Importer.php:61` | Yes in principle (savepoint wrap), but only meaningful inside `ImportCsv` |
| `Importer` hooks `beforeValidate…afterUpdate`, `resolveRecord()`, `fillRecord()`, `saveRecord()` | `Importer.php:61–94, 242–289` | Model-centric (`$record->save()` + `saveRelationships`); conflicts with domain writers |
| Container binding of `Import` / `FailedImportRow` (`app(Import::class)`, `app(FailedImportRow::class)`) | `ImportAction.php:~222`, `Import.php:57–59` | Possible, see §2.2 |
| `Importer::getJobMiddleware/RetryUntil/Backoff/Tags/Queue/Connection/BatchName` | `Importer.php:346–384` | Only when `ImportCsv` is used |

---

## 2. Findings

### BLOCKER-1 — RowAtomicity: native `ImportCsv` commits partial rows (invariant **RowAtomicity**: *a failed row leaves zero writes*)

**Trace (source-proven):** `ImportCsv::handle()` opens one `DB::transaction` for the whole chunk
(`Jobs/ImportCsv.php:82`), calls `($this->importer)($row)` per row (`:87`) and **catches every
`Throwable`** (`:89–97`), then commits the chunk (`:127`).

Counterexample (MySQL InnoDB and SQLite both roll back the *statement*, not the transaction):
1. Row 7: `MasterProductDraftCreator::create()` succeeds → Product + default Variant inserted
   (`MasterProductDraftCreator.php:111–132`).
2. Same row: `GovernedDynamicFieldValueWriter::set()` throws `InvalidSelectOptionException`.
3. `ImportCsv` logs row 7 as failed, continues, commits the chunk → **orphan Product/Variant
   persists while the row is reported failed**.
4. Operator fixes the file and re-imports → Create fails forever on `unique(workspace_id, sku)`
   (`2026_07_08_100002…:22,30`). The system now contradicts its own failed-rows report.

Native `Importer::saveRecord()` reproduces the same pattern with no BabyPark code at all:
`$record->save()` then per-column `saveRelationships()` (`Importer.php:265–289`).

**Shortest fix without fork:** nested `DB::transaction` around the whole row — Laravel creates a
**savepoint** for nested transactions and rolls back to it on exception. In Filament terms:
override `Importer::__invoke()` → `DB::transaction(fn () => parent::__invoke($data))`.
**But this fix is insufficient** — see BLOCKER-2. The real minimum is "one top-level transaction
per row", which requires our own job.

**Regression test (mandatory):** a row whose 1st writer succeeds and 2nd writer throws →
assert `products`, `product_variants`, `product_field_values` (and every table the row touches)
row counts are unchanged, run-row outcome = `failed`, and an immediate re-apply of the corrected
row succeeds as **Create**. Run on SQLite **and** `phpunit.mysql.xml`.

### BLOCKER-2 — Chunk transaction holds the workspace row lock (invariant **WorkspaceWriteLiveness** / concurrency)

Every sanctioned writer locks the workspace row: `Workspace::query()->whereKey(..)->lockForUpdate()`
(`MasterProductDraftCreator.php:93–96`; same pattern in `BrandManager`, `CategoryTreeMutationService`,
`ProductVariantStructureService`, `MasterProductLifecycleMutationService`). Inside `ImportCsv`'s
chunk transaction, a savepoint release does **not** release InnoDB row locks → the workspace row
stays `FOR UPDATE`-locked for the full chunk (100 rows × N writers). Result: every other
admin write in the workspace (product card save, brand edit, Magento Apply) blocks behind the
import, up to `innodb_lock_wait_timeout`, then fails.

Secondary (MySQL-specific, **not yet reproduced by test — must be**): a deadlock (1213) inside the
chunk rolls back the *entire* InnoDB transaction while `ImportCsv` keeps counting earlier rows as
`successful_rows` → silent loss with a lying counter.

**Minimal safe fix:** own job; **each row = its own top-level `DB::transaction`**, committed
before the next row starts. No chunk transaction.

**Regression test:** MySQL suite — while an import run is mid-apply (row hook pauses), a
concurrent `BrandManager::update()` in the same workspace completes within one row's duration,
not the whole run.

### BLOCKER-3 — Workspace isolation of Filament import persistence (invariants **WorkspaceIsolation**, GAP-004 decision "every new table must include `workspace_id` from its first migration")

Vendor `imports` / `failed_import_rows` have no `workspace_id`. Path-by-path:

| Path | Finding |
|---|---|
| Creation | `app(Import::class)` → bindable to a `BelongsToWorkspace` subclass, but the creating hook takes `WorkspaceContext::id()`, which is **default-only** (`WorkspaceContext.php:22–25`, `@todo GAP-004`). No setter exists. |
| Relations | `Import::failedRows()` resolves `app(FailedImportRow::class)` — bindable; OK. |
| Implicit route binding | `DownloadImportFailureCsv(Request, Import $import)` — Laravel resolves the type via the container, so a binding + global scope would apply… |
| Failed-row download | …but the route (`filament/actions/routes/web.php`, prefix `filament`) runs **outside panel middleware**; authorization = policy `view` or `import->user()->is($user)` (`DownloadImportFailureCsv.php:34–36`). Ownership is per-user, not per-workspace. A user in two workspaces is not prevented from reading another workspace's rows by any workspace check. |
| Notification | Signed URL carries only `import` id + `authGuard` (`ImportAction.php:317`). |
| Queue serialization | `SerializesModels` re-fetches `Import` in the worker, where context = default workspace. `auth()->setUser($import->user)` (`ImportCsv.php:72–74`) sets a user, never a workspace. |
| Payload | Raw row data in `jobs.payload` and `failed_jobs.payload` (no `workspace_id`, no retention policy). |

Single-tenant today ⇒ not an active leak, but adopting these tables **creates new GAP-004 debt by
construction**, which the GAP-004 decision forbids for new tables.

**Minimal safe fix:** dedicated `product_import_runs` / `product_import_run_rows`, both with
`workspace_id` (FK) from the first migration; every query uses the established writer pattern
`::withoutWorkspaceScope()->where('workspace_id', $run->workspace_id)` with an **explicit**
`Workspace` passed to writers (all inventoried writers already take explicit `Workspace`/`workspaceId`).
No reliance on `WorkspaceContext` inside the job. Error-report download via a panel route with
fresh `manage_products` permission + `run.workspace_id` membership check.

**Regression test:** two workspaces fixture (test-only second workspace): a run in W1 cannot be
read, downloaded, applied or resumed by a member of W2 only; a W1 row referencing a W2 Product ID
yields `not_found` (not `forbidden` — no existence leak).

### BLOCKER-4 — "Exact SKU" is not a unique identity (invariant **Identity**: *one key → at most one target*)

`unique(workspace_id, sku)` exists **separately** on `products` and on `product_variants`
(`2026_07_08_100002…:22,30`); both columns are nullable since `2026_09_30_160000…:14,19`.
There is no cross-table uniqueness.

Counterexample: Product P1 `sku=ABC` (simple) and Product P2 (configurable) with Variant V2
`sku=ABC`. An Update row "SKU=ABC" resolved by "exact SKU" matches two different Products. Any
resolver that picks one silently updates the wrong product.

**Minimal safe fix:** the resolver key is a **namespaced** identity, chosen explicitly per run:
`master_product_id` | `product_sku` (products.sku) | `variant_sku` (product_variants.sku).
If the value matches in the other namespace on a **different** Product → row error
`ambiguous_identity`. Blank key → row error (never "create instead").
**Regression test:** the P1/P2 fixture above → row fails `ambiguous_identity`, zero writes.

### BLOCKER-5 — Global alias can auto-map an identity column (invariants **Identity**, **Mapping evidence**)

`docs/data/canonical_product_field_aliases.csv` maps `Код`, `Code`, `Артикул`, `SKU`, `Item Code`
→ `sku`. In 1C-style exports `Код` is the internal item code and `Артикул` the article; a file with
both columns yields **two headers → one target**, or the wrong column becomes the identity key —
without any user action, because the doc chain runs global alias **before** workspace memory
(`02-ATTRIBUTE_DICTIONARY.md:387`).

**Minimal safe fix (does not change the [documented] chain order):**
(a) any auto-match onto an identity target (`sku`, `master_product_id`, `variant_sku`) requires
explicit operator confirmation in the mapping step; (b) two source headers resolving to one target
→ both demoted to manual mapping; (c) a workspace alias that conflicts with a global alias is shown
as a conflict, not silently overridden.
**Regression test:** headers `Код` + `Артикул` → mapping state `needs_manual` for both; Apply is
not admissible until resolved.

### BLOCKER-6 — Formulas: XLSX imports formula text, ODS imports cached results silently (invariant **No computed/unsourced values**)

Verified in OpenSpout v4.32.0: XLSX `<f>` produces `FormulaCell('='.$f, …, $computedValue)`
(`Reader/XLSX/Helper/CellValueFormatter.php:99–107`); `Row::toArray()` returns `getValue()`
(`Common/Entity/Row.php:148–152`) → naive code imports the literal `=VLOOKUP(...)` as a name/SKU.
The ODS reader has **no formula handling at all** (no `formula` reference in `Reader/ODS`) → it
returns the cached value; formulas are undetectable through OpenSpout.

**Minimal safe fix:** XLSX — inspect cell type; `FormulaCell` in a **mapped** column → row error
`formula_not_allowed`; unmapped → ignored; `getComputedValue()` never used. **ODS out of v1**
(no zero-dependency fail-closed path without a custom `content.xml` pre-scan).
**Regression test:** XLSX fixture with `=1+1` in mapped `name` → row error, zero writes; same in an
unmapped column → row succeeds; `.ods` upload → rejected at upload.

### SHOULD FIX

| # | Item | Fix |
|---|---|---|
| S1 | **Archive/size limits.** OpenSpout reads `zip://` streams with `LIBXML_NONET` (`Reader/Wrapper/XMLReader.php:36`) and spills large shared-string tables to temp files (`CachingStrategyFactory.php:69–76`) — no uncompressed-size, entry-count or ratio limit. Livewire upload rule allows 25 MB (`config/livewire.php:11`). | Before opening: `ZipArchive` pre-scan — sum of `statIndex()['size']` ≤ 100 MB, entries ≤ 1 000, per-entry ratio ≤ 100, required parts `[Content_Types].xml` + `xl/workbook.xml`; reject `vbaProject.bin` / macro content types. Upload ≤ 10 MB. Rows ≤ 10 000, columns ≤ 200, cell ≤ 10 000 chars, counted while streaming; first visible sheet only. |
| S2 | **Extension/MIME mismatch.** | Magic-byte check: XLSX = ZIP with the parts above; CSV = no NUL bytes, valid UTF-8 (or explicit Windows-1251 option via already-installed `league/csv` `CharsetConverter`). Reject `.xls`, `.xlsm`, `.xlsb`, `.ods`. |
| S3 | **Numeric identity cells.** XLSX numeric `00123` → `123`; EAN → float `4.6E+12`. | Identity columns accept only string cells or integer-valued numerics canonicalised without exponent; otherwise row error. |
| S4 | **Within-file duplicate keys** (same SKU twice) make results order-dependent. | Preview and Apply: all occurrences → `duplicate_in_file` error. |
| S5 | **Preview/Apply validation drift.** `MasterProductDraftCreator` validates inside `create()` (`:16–78`); preview cannot call it. | Extract a pure, read-only `validate(Workspace, array)` used by both preview and the writer itself (one source of truth). Apply still re-validates. |
| S6 | **CSV/formula injection on the way out.** Filament's protection is *off* by default (`Importer.php:313–329`). | BabyPark error-report CSV must always neutralise `= + - @ \t \r` prefixes. |
| S7 | **Column permission bypass.** `manage_product_cost` exists (`WorkspacePermissions.php:31`). | Mapping a permission-gated field requires that permission at Apply time (fresh check), else mapping refused. Moot if v1 excludes pricing (recommended). |
| S8 | **Global alias store is docs-only.** Global aliases exist only in `docs/data/canonical_product_field_aliases.csv` (37 rows, 26 `import_header/global/verified`, no ambiguous normalized alias) checked by `CanonicalRegistryValidator`; no runtime table. | Ship them as a generated read-only runtime registry (validated by the existing validator in CI), not by reading `docs/` at runtime. |
| S9 | **Long jobs vs `retry_after`.** `database` connection `retry_after=90` (`config/queue.php:41`) → a long run is re-delivered and executed twice concurrently. | Run on `database_connectors`-style connection (1200 s) **plus** `WithoutOverlapping(run_id)` **plus** per-row exactly-once (see §3). |

### NON-BLOCKING

- **N1 Queue payload.** Filament: `base64(serialize(100 rows))` per job (`ImportAction.php:250`) → ~150–300 KB/job, 10 000 rows ≈ 100 jobs ≈ 20–30 MB in `jobs`, retained indefinitely in `failed_jobs`. Dedicated design: payload = `run_id` only.
- **N2 Source retention.** Filament stores `getRealPath()` of the Livewire temp file (`ImportAction.php:227`) — lost after temp cleanup; retries after that fail. Dedicated design: private disk, `workspaces/{id}/imports/{run}/source`, 30-day retention + purge job.
- **N3 Media.** `OriginalImageIngestService` accepts only `UploadedFile` (`:32, :186–198`); image URLs need a new HTTP fetch + SSRF path → **out of v1** (confirmed).
- **N4 Configurable creation.** `ProductVariantStructureService::promoteSimple/addAxis/addVariant` exist but require explicit axes and `assertSourceNeutral`. Variant *creation* through files is a v1.1 candidate; v1 updates existing variants by `variant_sku` / variant ID only.

---

## 3. Durable run & idempotency model

```
product_import_runs
  id (uuid), workspace_id (FK), created_by_user_id
  original_filename, stored_path, file_sha256, file_size, format (csv|xlsx), sheet_name
  mode (create_only | update_only), identity_key (master_product_id | product_sku | variant_sku)
  mapping_snapshot (json: raw_header, normalized_header, target field_binding_id, match_source
                    exact|global_alias|label|workspace_alias|manual, confirmed_by, confidence)
  status: uploaded → mapped → previewed → applying → completed | completed_with_errors | failed | cancelled
  preview_counts (json), apply_counts (json), last_committed_row (int)
  previewed_at, apply_started_at, finished_at, timestamps
product_import_run_rows
  id, workspace_id, run_id, row_number, outcome (created|updated|unchanged|skipped|failed),
  target_product_id, target_variant_id, error_code, error_message, raw_row (json, failed only)
  UNIQUE(run_id, row_number)
```

Semantics:
- **Preview** = stream file → normalise → resolve identity (read-only queries) → pure validation
  → counters + first 50 rows. **No writer is called, not even under rollback** (rollback still takes
  workspace locks and fires model events). Nothing is persisted except counts.
- **Apply** = CAS `previewed → applying` (one admission), re-read the stored file, verify
  `file_sha256`, re-resolve and re-validate every row. No stored mutation plan.
- **Per row:** one top-level `DB::transaction` containing the writer calls **and** the
  `product_import_run_rows` insert. `UNIQUE(run_id,row_number)` makes a re-delivered job a no-op for
  committed rows (exactly-once per row). `last_committed_row` is a resume hint, not the guarantee.
- **Retry** = resume the same run. **Replay** = new run on the same file; same `file_sha256` in the
  workspace → warning "already applied on …", not a block.
- **Repeat import idempotency:** Create-only on existing key → `skipped: already_exists`;
  Update-only with identical values → `unchanged` (writers' `setIfCurrentValue` paths), zero writes.
- Operator-visible: run list with status, counts, filename, author, time; downloadable error report
  (neutralised CSV).

---

## 4. Writers inventory (actual `develop`)

| Domain | Sanctioned writer | Explicit workspace | Own transaction | v1 use |
|---|---|---|---|---|
| Product create (+ default Variant) | `Catalog/MasterProductDraftCreator::create` | yes | yes | Create rows |
| Product `name`, `description` | `Catalog/GovernedProductVariantColumnMutationService::set/setIfCurrentValue/clear` (allowlist only `name`, `description` — `GovernedProductVariantColumnEligibility.php`) | yes | yes | Update rows |
| Dynamic field values | `Fields/GovernedDynamicFieldValueWriter::set/setIfCurrentValue/clear…` | yes | yes | Create/Update |
| Brand assign | `Catalog/BrandManager::assign` (exact existing active brand) | yes | yes | Update rows |
| Category create node | `Catalog/CategoryTreeMutationService::create` | yes | yes | path creation |
| Variants | `Catalog/ProductVariantStructureService::addVariant` etc. | yes | yes | v1.1 |
| Lifecycle | `Catalog/MasterProductLifecycleMutationService::transition` | yes | yes | optional |
| Price / stock | `Pricing/MasterOfferMutationService`, `Availability/MasterInventoryMutationService` | (not audited here) | — | **out of v1 (decision D2)** |

**Missing writer boundaries actually needed by v1:**
1. **Product category assignment on an existing Product** — no service; only the Create path and
   tree-move (`CategoryTreeMutationService.php:187`) write `products.category_id`.
2. **Category path resolve-or-create** ("A > B > C", exact per-level name match inside the workspace,
   no fuzzy) — permitted for explicit Master file import by `02-ATTRIBUTE_DICTIONARY.md:101` /
   Category Classification [Resolved], but no service exists.
3. **Pure validation API** for `MasterProductDraftCreator` (S5).
4. Identifier columns (`sku`, `barcode_ean`) on existing records: **no governed writer** → v1 must
   treat identifiers as immutable through import (identity key is read, never written on Update).

---

## 5. OSS / "GitHub first" candidates

| Candidate | Functionality | License | Activity | Upgrade | Integration | Verdict |
|---|---|---|---|---|---|---|
| Filament 5.7.6 native Import (`ImportAction`+`ImportCsv`) — installed | FAIL (CSV-only; chunk transaction; no preview) | PASS (MIT) | PASS | PASS | FAIL (no workspace_id, user-scoped download, payload rows) | **Reject for runtime** |
| `openspout/openspout` v4.32.0 — installed | PASS (streaming CSV/XLSX) / PARTIAL (ODS formulas invisible, no zip limits) | PASS (MIT) | PASS | PASS | PASS | **Adopt** (reader only) |
| `league/csv` 9.28 — installed (via Filament) | PASS (CSV + charset conversion) | PASS (MIT) | PASS | PARTIAL (transitive, not a direct require) | PASS | Optional for 1251 CSV; declare as direct require if used |
| `spatie/simple-excel` (last commit 2026-06-15, MIT, wraps openspout ^4.30, illuminate ≤13) | PASS | PASS | PASS | PASS | PARTIAL (new dep, adds only a LazyCollection wrapper; still no formula/zip policy) | Not worth a new dependency |
| `maatwebsite/excel` (SpartnerNL, last commit 2026-09-14, MIT, phpspreadsheet ^5.9) | PASS | PASS | PASS | PARTIAL | FAIL (new heavy dep, PhpSpreadsheet memory model, own chunk/transaction model) | Reject |
| `eightynine/filament-excel-import` | — | — | not reachable on GitHub from this session | — | wraps Filament ImportAction + maatwebsite | Reject (inherits all Filament BLOCKERs) |

No OSS component supplies workspace-aware runs, namespaced identity, per-row atomicity through
domain writers, or the Attribute Dictionary mapping chain — those are BabyPark domain logic by nature.

---

## 6. Shortest safe architecture — Import v1

1. **Upload** (Products list action "Імпортувати товари", `manage_products`): CSV/XLSX only, ≤10 MB,
   magic-byte + zip pre-scan (S1/S2), SHA-256, store on private disk, create run (`uploaded`).
2. **Mapping**: `ImportHeaderNormalizer` → chain exact code → global alias (runtime registry, S8) →
   localized label → `workspace_import_aliases`; identity targets require confirmation; duplicate
   targets → manual; raw header kept in `mapping_snapshot`. Confirmed manual mappings saved to
   `workspace_import_aliases` (workspace-only). Operator chooses mode + identity key.
3. **Preview** (sync for ≤ N rows or queued): read-only resolve + pure validate; counters
   create/update/unchanged/skip/error + first 50 rows. No writers.
4. **Apply**: CAS admission → one queued job per run on long-retry connection with
   `WithoutOverlapping(run)`; stream file again; per row one top-level transaction (writers + row
   outcome); checkpoint; final status + error report.
5. **v1 field scope**: name, description, brand (existing, exact), category (path resolve-or-create),
   dynamic fields via `GovernedDynamicFieldValueWriter`; identity key read-only.
   Out: images/URLs, ODS, price/stock (D2), variant creation, EAN/name/fuzzy identity.
6. **Template/export**: downloadable template containing `master_product_id`, `sku`, mapped columns.

---

## 7. Mandatory regression suite (minimum)

1. RowAtomicity partial-writer failure → zero writes (SQLite + MySQL).
2. Workspace lock released per row (MySQL).
3. Two-workspace isolation for run read/apply/download/resume and cross-workspace IDs.
4. Ambiguous SKU across `products`/`product_variants` → `ambiguous_identity`.
5. `Код`+`Артикул` → manual mapping, Apply blocked.
6. XLSX formula mapped/unmapped; ODS rejected.
7. Zip bomb (high ratio), >1 000 entries, macro workbook, `.xlsx` that is actually CSV → rejected before parse.
8. Job re-delivery after N committed rows → no duplicate writes, run completes.
9. Repeat import: Create-only → `skipped`; Update-only same values → `unchanged`, zero writes.
10. Preview executes zero INSERT/UPDATE (query-log assertion) and zero workspace `FOR UPDATE`.
11. Error-report CSV neutralises formula prefixes.

---

## 8. Doc discrepancies found

- `03-DOMAIN_MODEL.md:9830` lists `import_jobs` in the table inventory while `:9367` supersedes
  `ImportJob` as a sync entity. Proposal: annotate L9830 as historical; file import uses
  `product_import_runs` (separate from SyncRun per `:9373`). Backlog, doc-only.
- `02-ATTRIBUTE_DICTIONARY.md:387` chain is not marked [Resolved]; BLOCKER-5 adds a confirmation
  gate without reordering it.

## 9. Product Owner decisions required (A/B, recommendation first)

- **D1 Identity contract** — A (recommended): Create-only requires SKU; Update-only by explicitly
  chosen `master_product_id` | `product_sku` | `variant_sku`; no upsert in v1. B: add Upsert by SKU.
- **D2 Price/stock in v1** — A (recommended): out of v1, v1.1 via `MasterOfferMutationService` /
  `MasterInventoryMutationService` with cost-permission gating. B: include in v1 (+ ~1 task, S7 becomes BLOCKER).
- **D3 Limits** — A (recommended): 10 MB / 10 000 rows / 200 columns / first sheet. B: other values.