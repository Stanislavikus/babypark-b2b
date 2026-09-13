# Magento V1 Receive R4 — Dynamic Select Stop-and-Amend

**Status:** Approved implementation contract — 2026-09-13
**Supersedes:** nothing in R3. R3 Product `name` remains the historical first certified slice.
**Purpose:** add one behavior-class Receive capability for materialized workspace custom Dynamic single-select fields without reopening connector architecture.

## Scope

R4 adds consequential Magento → platform Apply for a mapped field only when all of the following hold:

- the target has an existing merchant-confirmed trusted `ExternalRecordLink`;
- the `FieldMapping` belongs to the current Products `SyncConfiguration`;
- the binding belongs to the explicit Receive target object (`Product` or `ProductVariant`);
- storage is `Dynamic`;
- the definition is active, workspace-owned, `WorkspaceCustom`, non-localizable, single-value `Select`;
- Magento returns the mapped external field in the Product document;
- the external option value resolves through exactly one current `FieldOptionMapping` to a declared internal option code.

No Magento attribute code is allowlisted in R4. Eligibility is behavior-class driven.

## Proposal semantics

`AdobeProductDocument` remains the generic Product/custom-attribute reader. The proposal resolver reverse-resolves external option identity through `FieldOptionMapping`; labels are never identity and fuzzy matching is forbidden.

Supported proposal states for R4 Dynamic Select:

- `Equal` — observation only; not consequential;
- `Differs` — Apply may replace the local dynamic option through compare-and-set;
- `LocalAbsent` — Apply may create the local dynamic value through compare-and-set with expected absence.

`RemoteAbsent` is **not** interpreted as Clear in R4. Missing Magento custom attribute data therefore never deletes a local value. Explicit clear semantics remain deferred.

Missing, ambiguous or internally invalid option mapping is `UnsupportedOrBlocked` and fails closed for consequential Apply.

## Apply semantics

Apply preserves the existing R3 ordering:

1. fresh authorization;
2. consume the opaque proposal once;
3. Live Import admission;
4. fresh Magento Product reread outside the final DB transaction;
5. short final locked validation/mutation transaction.

The final mutation uses `GovernedDynamicFieldValueWriter::setIfCurrentValue(...)`. The writer revalidates binding/definition/storage/value rules and compares the current canonical dynamic value under the slot lock before mutation. A local edit after proposal therefore produces `not_applied`, never overwrite.

Remote option value is re-read and reverse-resolved again before mutation. A remote change after proposal produces `not_applied`.

One proposal may contain historical Product-name entries plus multiple R4 Dynamic Select entries. `Equal` entries are ignored for consequential mutation. All executable actions for the one business Product are applied under one Live Import run and final transaction; any mutation exception rolls the transaction back.

## Explicitly out of R4

- automatic new Product / Variant creation;
- SKU Receive;
- Text / LongText / Number / Decimal / Boolean / Date / URL widening merely because the generic writer supports them;
- Money / Image / Computed;
- MultiSelect;
- remote-absent Clear;
- localization/store-view value policy;
- pricing, availability, media and relations;
- fuzzy option matching or label identity;
- public Adobe Products/Import/Live capability flip;
- merchant Apply UI or unattended Receive.

Future behavior classes widen eligibility, not the connector transport or field-by-field code.

## Verification gate

Required before R4 closure:

- Dynamic writer CAS: matching expected value updates, stale expected value is rejected, expected-absent can create;
- end-to-end Dynamic Select Receive: Magento option ID → `FieldOptionMapping` → internal stable option code → Dynamic writer;
- stale local value → `not_applied`, zero overwrite;
- changed remote option after proposal → `not_applied`, zero local mutation;
- historical R3 Product-name proposal/apply regression remains green;
- full Connector and Sync suites green;
- Pint and `git diff --check` green.
