# Product File Import v1 — Lead Architecture Synthesis — 2026-10-10

> **STATUS: Research / Proposed Architecture — NOT [Resolved].**
>
> This document preserves the current Lead synthesis so the work survives chat/session loss.
> It is not authorization to implement Product File Import v1. Product Owner approval is
> still required before this document may be promoted to [Resolved].
>
> Repo: `Stanislavikus/babypark-b2b`
> Research base: `origin/develop @ 06bf85e9c1d6335a3f69bf0c7cee3499cf3094af`
>
> This synthesis does not merge or import rules from `babypark-integration`.

## Goal

Deliver a merchant-facing structured Product File Import v1 that can safely ingest CSV/XLSX
into the existing Master Product domain without creating a second catalogue authority,
without bypassing workspace isolation or source authority, and without introducing a new
runtime package when the existing stack already contains the required file reader.

Observable outcome after implementation:

- merchant uploads CSV/XLSX;
- platform maps headers through the governed Product Field dictionary;
- merchant explicitly chooses Create or Update and confirms identity;
- Preview is read-only and shows deterministic create/update/skip/error evidence;
- Apply can resume after worker/process failure;
- every input row is atomic;
- duplicate queue delivery cannot double-apply a committed row;
- existing source-owned/1C authority remains fail-closed.

## Research evidence

The following reports are evidence, not authority:

1. `PRODUCT_FILE_IMPORT_V1_ADVERSARIAL_ARCHITECTURE_STUDY_2026_10_10_OPUS.md`
2. `PRODUCT_FILE_IMPORT_V1_ADVERSARIAL_ARCHITECTURE_STUDY_2026_10_10_GROK.md`
3. `PRODUCT_FILE_IMPORT_V1_NARROW_CLOSURE_STUDY_2026_10_10_OPUS.md`
4. `PRODUCT_FILE_IMPORT_V1_NARROW_CLOSURE_STUDY_2026_10_10_GROK.md`

Evidence SHA-256 at preservation time:

- broad Opus: `a7b7cfdf8b5d746b669df9dbb471e717c139145c04d8b46a94b81774e81b3681`;
- broad Grok: `c570934997d2ebd292de6dee4ee28803def68c6dc38c65519f69031adfd5c834`;
- narrow Opus: `c9bee2bad9a0d749ee423539a16f60b89acb9d678aad3264c4bd4676c37fa088`;
- narrow Grok: `1b8a0445f106f55df9ec4fddf54a8fca34782d0677cd18bf48051e196220426d`.

The broad and narrow studies independently converged on the same core result:
Filament's native import runtime is not a safe execution boundary for BabyPark's
multi-table, workspace-scoped Master Product domain.

## Current state

Already present in the repo:

- `workspace_import_aliases` tenant-isolated import-memory table;
- Product Field / FieldBinding architecture and governed dynamic-field writer;
- Smart Import mapping contract in `docs/02-ATTRIBUTE_DICTIONARY.md`;
- `MasterProductDraftCreator`;
- governed Product/Variant column and dynamic-field writers;
- `BrandManager`;
- OpenSpout v4.32.0 transitively installed by Filament;
- League CSV transitively installed by Filament;
- pending `Імпортувати товари` UI surface;
- MySQL integration-test harness.

Not implemented:

- Product file upload/run persistence;
- runtime header-mapping engine;
- read-only import preview;
- Apply execution runtime;
- category assignment writer for existing Products;
- operator-visible import history/error report.

## Integration-first conclusion

### Adopt

- **OpenSpout v4.32.0** as the existing CSV/XLSX streaming reader/writer.
- Existing Filament form/table/action primitives for UI.
- Existing BabyPark sanctioned domain writers for actual catalogue mutations.

### Reject for Product Import runtime

- Filament `ImportAction` / `ImportCsv` execution.
- Filament vendor `imports` / `failed_import_rows` persistence.
- New importer packages such as Laravel Excel / PhpSpreadsheet wrappers unless a future
  material fact proves they close more BabyPark requirements than the zero-new-dependency path.

Reasons include:

- Filament v5.7.6 reader path is CSV-only;
- native `ImportCsv` executes a chunk inside one DB transaction and catches row failures
  inside that transaction;
- vendor import persistence is not workspace-owned;
- Filament model-centric save flow bypasses BabyPark domain writer boundaries;
- no third-party package found supplies BabyPark-specific workspace identity, row atomicity,
  Smart Import mapping memory, source authority and Product/Variant identity semantics.

## Proposed architecture

```text
CSV/XLSX upload
  -> security preflight
  -> private immutable source file + SHA-256
  -> product_import_runs
  -> governed header mapping
  -> read-only Preview
  -> explicit Apply admission
  -> bounded continuation job
  -> one atomic transaction per input row
  -> sanctioned BabyPark writers
  -> product_import_run_rows durable outcome
  -> run completion / error report
```

No Product rows are serialized into the queue payload.

## Proposed persistence

### product_import_runs

Minimum intent:

- UUID primary key;
- `workspace_id` from first migration;
- actor/user identity;
- original filename;
- private stored path;
- file SHA-256;
- file size;
- format `csv|xlsx`;
- selected sheet where applicable;
- mode `create_only|update_only`;
- identity mode `master_product_id|exact_sku`;
- mapping snapshot preserving raw headers and mapping source;
- preview/apply counters;
- durable run status;
- continuation epoch / heartbeat;
- timestamps and retention metadata.

### product_import_run_rows

Minimum intent:

- workspace_id;
- run_id;
- source row number;
- terminal outcome;
- target Product/Variant IDs when resolved;
- stable error code + human-readable message;
- bounded failed-row evidence only where required;
- `UNIQUE(run_id, row_number)`.

The row ledger is the correctness boundary for duplicate delivery. Continuation epoch is
an operational dedupe/checkpoint mechanism, not the exactly-once authority.

## Proposed run lifecycle

Candidate states:

```text
uploaded -> mapped -> previewed -> applying
                       |             |
                       |             +-> completed
                       |             +-> completed_with_errors
                       |             +-> failed
                       |             +-> cancelled
                       +-> remap/repreview
```

Exact enum names remain implementation-contract detail until Product Owner approval.

## Preview invariant

**PreviewIsReadOnly**

Preview:

- never invokes a catalogue writer;
- never performs a write-then-rollback dry run;
- streams source data;
- normalizes/maps headers;
- resolves identity read-only;
- validates using pure/read-only validators;
- produces create/update/unchanged/skip/error counts and a bounded sample.

Apply:

- reopens the immutable source file;
- verifies SHA-256 before catalogue writes;
- re-resolves and re-validates current state;
- never executes a stored mutation plan from Preview.

## Row transaction invariant

**RowAtomicity**

One input row must commit every catalogue/domain mutation or none.

Proposed boundary:

1. start one top-level `DB::transaction(...)` for the row;
2. lock the import run row and check run state/cancellation;
3. lock the workspace row;
4. claim the source row in `product_import_run_rows`;
5. invoke sanctioned writers in deterministic order inside an import-owned savepoint where
   necessary to classify known business/validation failures;
6. write the terminal row outcome before the outer commit.

Concurrency/deadlock/lock-wait/lost-connection errors must escape to the outer transaction
handler so the **whole row** rolls back and may be retried.

Unknown programming/system `Throwable` is proposed to fail the run after full row rollback;
it must not be silently converted into an ordinary bad-row validation outcome.

Mandatory MySQL tests must prove no partial Product/Variant/dynamic-field changes survive
validation failure, deadlock, timeout, disconnect or worker redelivery.

## Exactly-once invariant

**ExactlyOnceCommittedEffect**

Correctness does not depend on Redis/cache overlap locks.

Proposed rules:

- `UNIQUE(run_id,row_number)`;
- run row lock serializes workers of one run;
- claim and catalogue writes commit together;
- crash before commit leaves no committed claim/effect;
- crash after commit but before queue ACK causes redelivery to observe terminal row and skip;
- duplicate delivery cannot reapply the same committed row;
- cancellation takes effect at a row boundary; committed prior rows are not undone.

`WithoutOverlapping` may exist only as defence-in-depth with bounded TTL.

## Identity proposal

Merchant-facing Update identity choices:

1. **Master Product ID**
2. **Exact SKU**

No fuzzy, name or guessed identity.

### Exact SKU resolver

Inside the current workspace:

- query exact `products.sku`;
- query exact `product_variants.sku`;
- reduce every hit to distinct Master Product IDs.

Result:

- 0 Master Products -> `not_found`;
- exactly 1 -> resolved;
- >1 -> `ambiguous_identity`, zero writes.

This prevents silently choosing between Product SKU and Variant SKU when the same value points
to different Master Products.

For Variant-level fields, a concrete Variant must also be deterministically resolved:

- exact Variant SKU hit -> that Variant;
- otherwise a resolved Product with exactly one Variant -> that Variant;
- otherwise -> `variant_not_determined`.

A Product-level field on a row clearly addressing one Variant of a multi-Variant Product is
proposed to fail closed to avoid last-row-wins conflicts.

A full-file pre-pass must detect duplicate resolved targets before Apply.

## Create proposal

- Create and Update are separate modes.
- No Upsert in v1.
- Bulk Create requires non-empty SKU even though manual Product Draft UI still allows name-only.
- Create SKU must be absent from both Product and Variant SKU namespaces before mutation.
- Create uses `MasterProductDraftCreator`.
- Identifier fields are not mutated during Update.

## Source authority proposal

Until a separate field-level 1C authority matrix is Resolved:

- if Product `onec_guid` exists **or any Variant `onec_guid` exists, the whole Update row is
  `skipped: source_owned`;
- import must not use lower-level writers to bypass UI/source-authority restrictions.

Recommended Product Owner decision: **E1=A**.

## Category proposal

v1:

- existing Category ID or exact existing active full path only;
- no fuzzy category match;
- no category-path creation in v1;
- path creation remains a later capability and this deferral does not prohibit the existing
  [Resolved] rule that Master file import may support path creation.

For Update, recommended decision **E2=A**:

- add one small sanctioned Category Assignment writer;
- same-workspace;
- exact existing/effectively-active Category;
- no provider/Magento write;
- no source-owned bypass.

## Header mapping proposal

Governed priority remains:

```text
exact internal code
-> normalized global alias
-> normalized localized label
-> workspace_import_aliases
-> fuzzy suggestion requiring confirmation
-> manual mapping
```

Requirements:

- raw source header is preserved as evidence;
- confirmed manual/fuzzy mapping may populate `workspace_import_aliases`;
- global alias data needs a runtime registry generated from / validated against canonical docs/data,
  not a second hand-maintained list;
- **identity selection is a separate explicit operator confirmation**, even when `Код`,
  `Code`, `Артикул`, `SKU` maps with high confidence.

## File/security proposal

v1 formats:

- CSV;
- XLSX.

Out of v1:

- ODS;
- PDF/unstructured supplier documents;
- image/media import.

Recommended limits pending Product Owner approval:

- source file <= 10 MiB;
- <= 10,000 data rows;
- <= 200 columns;
- one selected worksheet, defaulting to first visible sheet;
- bounded cell text length;
- ZIP entry count / expansion-size / compression-ratio preflight before OpenSpout opens XLSX;
- macro-enabled workbook rejected;
- MIME/extension mismatch rejected.

Mapped XLSX `FormulaCell` / error cells fail closed. Cached formula results are not imported.

Generated CSV/XLSX error/export files must neutralize spreadsheet formula injection.

Recommended Product Owner decision: **D3=A**.

## Queue/execution proposal

Use existing default database queue technology; do not occupy the connector/discovery worker.

Actual production evidence at research time:

- default database queue `retry_after=90`;
- default worker has no explicit `--timeout` and therefore uses the Laravel worker default;
- connector worker is reserved for `database_connectors --queue=connectors --timeout=900`;
- connector retry_after is 1200.

Proposed v1 continuation:

- payload: `run_id + segment_epoch` only;
- stop after **250 rows OR 30 seconds**, whichever comes first;
- segment dispatch/checkpoint is durable;
- stale/duplicate epoch exits without catalogue writes;
- durable row ledger still owns exactly-once correctness;
- recovery sweep may redispatch stale `applying` runs with no recent heartbeat.

Dedicated import worker is an operational optimization only if real load later proves necessary.

## Scope proposed OUT of v1

- price;
- stock;
- cost;
- media / image URLs;
- ODS;
- PDF/unstructured extraction;
- configurable-family creation;
- category path creation;
- Upsert;
- identifier mutation during Update.

Recommended Product Owner decision: **D2=A** (price/stock in v1.1 via existing sanctioned
offer/inventory writers and their permission gates).

## Template/export requirement

BabyPark's downloadable import/update template must include:

- `master_product_id`;
- SKU column;
- governed field columns selected for the template.

This makes Product-ID round-trip Update usable instead of theoretical.

## Mandatory implementation gates

Before implementation is considered Merge Ready, minimum evidence includes:

1. MySQL partial-writer failure -> zero catalogue writes for failed row.
2. MySQL deadlock/lock-wait -> whole row rollback, safe retry.
3. duplicate queue delivery -> one committed catalogue effect.
4. crash before commit -> no effect; retry succeeds once.
5. crash after commit/before ACK -> redelivery skips terminal row.
6. cancellation at row boundary -> earlier commits remain, later rows stop.
7. ambiguous SKU across Product/Variant namespaces -> zero writes.
8. Variant-level field without unique Variant -> fail closed.
9. duplicate target rows in one file -> deterministic preflight error.
10. Product/Variant source-owned by 1C -> row skipped, zero writes.
11. two-workspace read/apply/history/error-download isolation.
12. identity alias still requires explicit identity confirmation.
13. formula/error cells rejected.
14. malformed/ZIP-bomb-like XLSX rejected before OpenSpout parse.
15. continuation duplicate/stale epoch -> no duplicate work.
16. Update category uses sanctioned writer only.
17. Preview invokes zero mutation writers.

No fake SQLite substitute is acceptable for transaction/concurrency gates.

## Product Owner decisions still pending

This research branch intentionally does **not** mark the following [Resolved].

Lead recommendations:

- **D1=A** — Create requires SKU; Update uses explicit identity; no Upsert.
- **D2=A** — price/stock deferred to v1.1.
- **D3=A** — 10 MiB / 10k rows / 200 columns / bounded single-sheet v1.
- **E1=A** — source-owned/1C target skips the whole row.
- **E2=A** — add a small sanctioned Category Assignment writer for Update.

Additional proposed decisions requiring approval:

- Exact SKU union resolver described above.
- Unknown programming/system error fails the run instead of becoming an ordinary failed row.
- Default-queue continuation budget 250 rows / 30 seconds.
- Category path creation deferred from v1.

## Shortest safe path after approval

1. Merge this evidence/docs campaign after Product Owner approval of what should be preserved.
2. Amend this synthesis to **[Resolved]** with the approved D/E decisions.
3. Create a separate implementation campaign from fresh `origin/develop`.
4. Implement schema/run/file admission first.
5. Implement pure mapping/preview.
6. Implement row-atomic Apply with mandatory MySQL gates.
7. Add UI/history/error report.
8. Real-file CSV/XLSX validation and operational recovery evidence.
9. Independent adversarial review before Merge Readiness.

No production implementation is authorized by this research document itself.
