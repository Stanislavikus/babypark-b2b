# Product → Channel Selection + Remote Catalogue Projection Contract

**Status:** FROZEN — STOP-AND-AMEND 2026-09-15
**Date:** 2026-09-15
**Scope:** universal Product-to-destination selection, merchant channel workspaces,
remote-catalogue projection, Preview→Live selection evidence, and future SEO/AI/media
compatibility. Magento / Adobe Commerce V1 is the first concrete implementation target.

## Authority and relationship to existing contracts

This document is normative for the new selection and remote-catalogue concepts introduced
here. It amends the historical first-slice `selection.mode = all_products` constraint in
`docs/03-DOMAIN_MODEL.md`; it does not rewrite historical run evidence or claim runtime
implementation already exists.

It preserves without reopening:

- one shared platform `Product` / `ProductVariant` catalogue;
- `SyncConfiguration` as connector/domain/context configuration owner;
- account-scoped `ExternalRecordLink` as external identity/trust authority;
- FieldMapping / Preview / Entity Trust / Live as separate domain truths;
- Magento V1 **LINK / UPDATE-ONLY** capability truth;
- deterministic Canonical Field / Mapping foundations;
- governed AI proposal semantics already frozen in Product Structure AI contracts;
- Media as a separate Product Data domain.

The current runtime may continue to execute `all_products` until the implementation campaign
for this contract lands. That is an implementation gap, not permission to reinterpret this
contract as optional.

## Product goal

A merchant must understand, without connector education:

1. which Products belong to a destination/channel;
2. which of those Products already correspond to remote records;
3. which Products need data/setup fixes;
4. what one next action will make progress;
5. what exists remotely even when it has not yet become a governed platform Product.

The architecture must support the same master catalogue across Magento, Google, Shopify,
BigCommerce, Amazon and future destinations without provider-specific Product copies.

## 1. One Master Catalogue; channel membership is a projection

`Product` remains the only merchant-owned Product truth.

A Product may be selected for zero, one, or many destinations at the same time. Channel
membership is configuration state; it is not Product identity and must not be represented as
provider-specific Product columns such as `product.magento`, `product.shopify`, etc.

Do not create editable copies such as `magento_products`, `shopify_products`, or a generic
second channel-product database.

The first configurable selection mode is:

```text
selection.mode = explicit_products
```

The owning boundary is the relevant `SyncConfiguration`. Selection applies to the
configuration's data domain + external context and participates in its stable configuration
revision.

### First membership granularity

First implementation freezes Product-level membership.

- selecting a Product selects its applicable ProductVariant family for that channel run;
- variants do not receive independent channel membership in this slice;
- variant-specific selection is a future additive capability only after a real provider/product
  requirement proves it necessary.

This keeps configurable Magento parents/children coherent and avoids inventing an unproven
partial-family publication model.

## 2. Selection persistence and workspace integrity

The first physical implementation should use a normalized SyncConfiguration-owned Product
membership relation rather than storing an unbounded Product-ID array in Product rows or a
mutable JSON field.

Minimum structural invariants:

- every membership row is workspace-owned;
- one Product may appear at most once in one SyncConfiguration selection;
- `(workspace_id, sync_configuration_id)` must reference the parent SyncConfiguration;
- `(workspace_id, product_id)` must reference the Product;
- the shared `workspace_id` makes a cross-workspace configuration/Product membership
  structurally unrepresentable;
- no membership mutation bypasses the approved SyncConfiguration mutation boundary;
- add/remove/bulk replacement of membership serializes against the same SyncConfiguration
  revision owner used by other configuration mutation;
- every effective selection change advances `SyncConfiguration.configuration_revision`.

Exact table/class names are implementation details, but these invariants are not.

Bulk assignment from Products and assignment from a channel workspace must call the same
underlying mutation service. UI entry point must never create a second membership mechanism.

## 3. Preview selection freeze and exact Live source

Configurable selection must not weaken the existing Preview-first safety contract.

### Preview admission

Preview admission freezes:

- `configuration_revision`;
- the run-effective selection descriptor (`explicit_products`);
- all other configuration-owned run inputs already required by the Sync Domain.

A queued Preview must not silently execute a later selection revision. Before resolving its
Product execution set, execution must prove the current SyncConfiguration revision still
matches the admitted revision. A stale queued run fails/aborts safely rather than adopting the
new membership.

### Preview execution set

At Preview execution start the effective Product ID set is resolved exactly once under the
admitted revision/workspace boundary.

After resolution starts, later Product-to-channel membership changes must not expand or shrink
that run. The implementation may use a bounded in-memory ID set, normalized run-selection
materialization, or another proven mechanism; it must not repeatedly query the mutable
membership relation as the iteration authority.

A Completed Preview's `SyncRunItem` rows are the durable per-Product evidence for the exact set
that was actually previewed.

### Live must bind to one concrete Preview

Consequential Live admission must reference one concrete Completed Preview for:

- the same workspace;
- the same SyncConfiguration;
- the same semantic operation;
- the current `configuration_revision`.

A durable relation such as `source_preview_run_id` (or an equivalent explicit relation) is
required; an existence query for "some Preview with this revision" is not sufficient.

The Live Product set is the Product set evidenced by that source Preview's `SyncRunItem` rows.
Live must not re-resolve membership from the current selection relation and thereby add a
Product that was never in the source Preview.

Selection mutation advances configuration revision and therefore invalidates the previous
Preview as Live admission evidence.

Product field/value changes remain a distinct concern: Live must still perform the existing
fresh per-Product readiness, identity, and remote-state checks before any consequential write.
Binding the Product set does not turn Preview into a frozen payload replay.

## 4. Merchant information architecture

After a connector account is successfully verified, ordinary product work proceeds through a
stable channel/account workspace, not by dropping a merchant directly into a raw Mapping or
Preview table.

`Інтеграції` remains the connection/account entry surface. The channel workspace is the daily
Product work surface for that connected destination.

### Empty selection

When zero local Products are selected, the channel workspace explains the model before any
technical setup:

```text
Для цього магазину ще не вибрано товари.
Виберіть товари з вашого каталогу, які потрібно підготувати для Magento.
```

Primary CTA concept:

`Вибрати товари для Magento`

Do not use `Add to Magento` / `Додати в Magento` for selection membership: Magento V1 does not
create missing remote Products and such wording falsely implies a remote create operation.

### Two entry points, one operation

The merchant may manage the same selection from either:

1. Master Products — channel badges/filters + bulk `Додати до каналу` / remove actions;
2. Channel workspace — `Вибрати товари` / edit selection action.

Both are lenses over the same membership relation.

### Main channel worklist

The primary Product table shows local Products selected for that channel. Recommended identity
cluster:

- thumbnail when available;
- title;
- SKU;
- GTIN when present/useful.

Do not render every finding as a permanent multiline block. Preserve separate merchant-visible
truth dimensions at least for:

- remote correspondence (`Пов'язано`, `Потрібно підтвердити`, `Не знайдено` or equivalent);
- readiness (`Готово`, blocker/recommendation counts);
- one causal next action.

Do not persist one generic mega-status that merges membership, identity, readiness, and run
result.

A Product row may summarize findings as, for example:

`4 блокуючі · 1 рекомендація`

with progressive disclosure for the actual list.

Full Product editing must preserve the merchant's channel work context. First implementation
may open the Product editor in a separate browser tab; do not replace the channel worklist and
force the merchant to reconstruct filters/position after every fix.

## 5. Root-cause-first remediation stays above Product detail

The 2026-09-14 Magento merchant-journey freeze remains authoritative:

- one configuration root cause affecting many Products is one merchant task with an affected
  count;
- Product rows contain Product/Variant/Pricing/identity findings that genuinely differ by item;
- one root cause has one causal remediation action;
- historical per-item evidence remains available underneath.

The channel workspace may later provide `Товари | Проблеми` projections when issue volume
justifies it. The domain evidence remains shared; do not build a second issue database merely
for presentation.

## 6. Remote Catalogue Projection is not a second Product truth

Many connected stores already contain Products before this SaaS is connected. The platform
therefore needs an account/target-scoped read projection of what the provider currently exposes.

This Remote Catalogue Projection is:

- read-only provider observation;
- navigation/search/matching evidence;
- distinct from Master Product membership;
- distinct from `ExternalRecordLink` trust;
- distinct from canonical Product data ownership.

It is NOT:

- a Product table owned by the merchant;
- permission to edit remote-only Products through generic Product writers;
- automatic import/adoption;
- identity trust merely because a candidate exists.

### Ownership boundary

Remote catalogue state belongs to the remote account/target context, not to a
SyncConfiguration selection.

Conceptually its owner is:

```text
Workspace
+ ConnectorAccount
+ data domain (Products)
+ applicable external/target context
```

Two SyncConfigurations that reference the same account/remote Product universe must not create
independent editable copies of that universe merely because their selections differ.

## 7. Immutable successful snapshots; failed scans never become current

Remote catalogue enumeration is a background read process.

Use a scan/snapshot lifecycle conceptually equivalent to:

```text
RemoteCatalogueScan
    -> complete candidate item set
    -> RemoteCatalogueSnapshot
         -> RemoteCatalogueSnapshotItem(s)
```

A failed, cancelled, incomplete, or pagination-invalid scan MUST NOT partially replace the
latest successful remote catalogue projection.

Only a fully successful enumeration may be published as the new current/latest successful
snapshot. If a scan fails after 43,000 of 100,000 rows, the previously successful snapshot
remains the merchant/read authority.

Do not require one long DB transaction around network enumeration. Candidate items may be
written in bounded chunks; publication happens only after completeness/integrity checks pass.

A remote record absent from the next fully successful snapshot is absent from the current
projection. Historical snapshots may preserve prior evidence; first implementation does not
need an additional mutable tombstone truth unless a concrete feature requires one.

## 8. Remote item identity and ExternalRecordLink boundary

Provider remote identity is connector-specific.

For Magento V1 Product records, logical Magento `entity_id` remains the authoritative remote
identity where the existing Entity Trust contract requires it. SKU is valuable matching and
addressing evidence, but is not a substitute for remote logical identity authority.

A Remote Catalogue item may therefore expose candidate identity/matching metadata without
creating trust.

`ExternalRecordLink` remains the sole persisted platform authority that a governed Product or
Variant is trusted as corresponding to a concrete remote entity for consequential operations.

Never infer or persist `ExternalRecordLink` merely because Remote Catalogue matching found the
same name, SKU, GTIN, image, or another similarity signal.

## 9. Remote-only Product behavior

A Product existing only in Magento remains a remote-only record until the merchant explicitly
links it to an existing Master Product or a future Import-to-Catalogue capability explicitly
creates a governed Product.

Remote-only records may be shown in a separate secondary surface such as:

```text
Знайдено в Magento: 1 026
Пов'язано з вашим каталогом: 31
Ще не пов'язано: 995
```

and may be browsed/searched for matching.

They must not be mixed into the main local Product worklist as if they were Master Products.

Current Magento V1 does not authorize remote Product -> new internal Product creation. A future
`Імпортувати до каталогу` action requires its own Receive/Product-creation contract and governed
Product writers.

## 10. Target binding and credential independence

Remote catalogue snapshots are bound to the non-secret remote target context they describe.

For Magento V1 the existing target semantics remain `base_url + store_code`.
OAuth credentials are access material, not target identity. Rotating credentials for the same
`base_url + store_code` must not make the remote catalogue a different store.

If target identity legitimately changes before the existing Entity Trust target-freeze rule
applies, snapshots for the old target must no longer be presented as current for the new target;
a fresh full scan is required.

After merchant-confirmed trusted links exist, the existing Magento rule remains unchanged:
changing `base_url` / `store_code` on that ConnectorAccount is prohibited; use a new account for
a different store.

Do not invent a credential-derived `target_fingerprint`.

## 11. Lightweight index, lazy full read, fresh consequential read

The current remote projection should be lightweight enough for tens of thousands of records.
Candidate fields include only evidence proven useful for listing, matching, navigation and
future analysis, for example:

- remote logical identifier;
- SKU;
- name/title;
- provider Product type;
- provider status;
- remote updated timestamp where reliable;
- primary thumbnail/media locator metadata;
- provider storefront locator evidence;
- account/store context inherited from the snapshot.

Do not copy all discovered custom fields into the remote index merely because they exist.

Opening a specific remote Product may trigger a bounded fresh/full Product read for detailed
comparison. Before Receive Apply, Entity Trust-sensitive comparison, or consequential WRITE,
existing contracts requiring a fresh remote read remain authoritative; the lightweight snapshot
is never permanent mutation authority.

## 12. URL / storefront locator semantics

Remote navigation/SEO may require provider-owned locator evidence, but provider route keys are
not automatically canonical Product URLs.

In particular:

- Magento `url_key` is a route/slug input, not raw equality with canonical absolute `Product.url`;
- provider `canonical_url`, storefront URL, route key, suffix or rewrite data keep their own
  semantics;
- the Remote Catalogue may retain the minimum provider locator evidence needed for navigation,
  matching, or future SEO analysis without promoting it into Master Product truth.

The first Magento implementation must prove the cheapest safe read shape for the required
locator evidence; do not fetch every custom attribute of a 100k Product catalogue solely to
obtain one route key.

## 13. Remote media is evidence, not imported Media ownership

Remote catalogue items may store provider-native thumbnail/media locators and bounded metadata
needed to render a small preview.

Do not automatically download original image bytes during full catalogue enumeration.
Do not turn a remote URL/path into canonical Product Media merely by caching it.

A future explicit Product/Media import may copy provider assets into the platform's Media domain
under its own provenance/lifecycle rules.

Future AI image enhancement (original -> derived asset -> provenance/model/version -> merchant
approval -> active representation -> channel export) requires a separate Media Stop-and-Amend.
This contract must not block it, but does not invent `AssetDerivation` persistence now.

## 14. Large-catalogue enumeration contract

Existing Adobe schema-discovery pagination correctness patterns are evidence, not a reusable
catalogue-size limit.

Reuse the proven safety invariants where applicable:

- bounded request/response size and timeout;
- stable total/count evidence where the provider can truthfully supply it;
- duplicate remote-identity rejection;
- unexpected empty/incomplete page rejection;
- no partial successful publication;
- explicit failed/incomplete scan state.

Do NOT inherit the current schema-discovery `MAX_PAGES` / `MAX_FIELDS = 10,000` boundary for a
Product catalogue.

Magento catalogue enumeration must support real stores above 10k. Before runtime implementation
is called complete, the chosen full-enumeration method must be proven against the actual Magento
API/search-engine behavior at representative scale.

Keyset/partition traversal such as ordered `entity_id > last_seen_id` is a candidate to test,
not a frozen claim of provider support. Do not assume `currentPage` alone solves 50k/100k
catalogues.

Enumeration must be memory-safe and resumable/fail-safe at the scan level. Persist candidate
items in chunks; do not require accumulating a 100k Product payload array in PHP memory before
persistence.

## 15. Provider-neutral SEO/Search Evidence boundary

Future SEO/search analytics may combine multiple external evidence providers simultaneously,
for example DataForSEO plus one or more SERP/ranking/keyword/competitor providers.

Do not place provider-specific SEO columns on `Product`, Remote Catalogue, or Magento-specific
tables.

The future domain boundary is conceptually:

```text
SEO/Search evidence provider(s)
    -> provider adapters
    -> normalized observations/evidence + raw provenance
    -> merchant/AI analysis
    -> proposal
    -> governed Product mutation after explicit acceptance
```

A ranking/search observation may need dimensions such as target reference/URL, query/keyword,
search engine, region/location, language, device, observed time, rank/result evidence, and
provider identity. Exact persistence is deliberately NOT frozen in this contract.

Remote-only Magento records may later be analysis targets without having `product_id`.
That does not grant Product mutation authority. AI/content changes require the target to be
linked to or imported as a governed Master Product unless a future separately-approved
capability explicitly changes that rule.

## 16. AI governance compatibility

AI is a cross-domain proposal mechanism, not a connector-specific writer.

The already-reviewed `AIProposalRun` / `AIProposal` contract remains the intended governance
shape for future suggestions involving:

- Product Type / category / optional groups;
- FieldMapping / Option Mapping candidates;
- missing or questionable Product field values;
- description and SEO enrichment;
- normalization/enrichment;
- future Media derivations.

Evidence may come from multiple providers at once. Preserve provider/model/version/provenance in
reviewable evidence; do not create DataForSEO-specific or Magento-specific AI proposal tables
merely because those providers supplied evidence.

Current repository status must be stated truthfully: the AI proposal architecture is docs-frozen
but its generic persistence/runtime is not yet implemented unless a later merge proves otherwise.

AI must never silently:

- establish ExternalRecordLink trust;
- accept its own mapping;
- bypass FieldDefinition/FieldBinding or another domain owner;
- overwrite merchant Product truth;
- issue consequential external writes.

## 17. Connector reuse boundary

The universal invariant is:

```text
Master Product Catalogue
    -> per-SyncConfiguration destination selection
    -> readiness/issues
    -> connector-specific remote state when that provider exposes one
```

Do not force every provider into an identical Remote Catalogue shape.

Magento, Shopify and BigCommerce may expose rich remote Product catalogues. Google Merchant and
Amazon may expose offer/listing/product states with different authority and identity semantics.
A connector implements Remote Catalogue Projection only when the provider has a useful,
authoritative remote record universe that can be read safely.

The common selection mechanism remains platform-owned; provider-specific remote observation
remains connector-owned.

## 18. Merchant acceptance scenarios

The implementation derived from this contract is incomplete unless it can demonstrate at least:

1. zero selected Products -> channel workspace explains selection and offers one `Вибрати товари`
   action;
2. bulk assigning Products from Master Products makes the same membership visible in the channel
   workspace;
3. removing membership increments configuration revision and invalidates old Preview-to-Live
   eligibility;
4. a queued stale-revision Preview does not silently adopt a changed selection;
5. a Completed Preview records only the exact selected Product set it evaluated;
6. Live can execute only the Product IDs from its concrete current-revision source Preview;
7. a Product added after that Preview cannot enter Live without a fresh Preview;
8. remote-only Magento Products appear separately and never auto-create Master Products;
9. one successful complete remote scan atomically supersedes the prior successful projection;
10. failed/incomplete scan leaves the prior successful projection current;
11. credential rotation on the same Magento target does not change remote target identity;
12. old-target projection is never presented as current after a legitimate target change;
13. cross-workspace selection and remote snapshot/item references are rejected by DB/service
    invariants, including real MySQL tests;
14. a Magento catalogue above 10k is not silently truncated; the chosen enumeration method has
    real-target evidence before support is claimed;
15. remote media/locator metadata remains observation evidence and does not mutate Product/Media;
16. remote-only SEO analysis cannot bypass Master Product / AIProposal governance into direct
    Product or Magento mutation.

## 19. Implementation boundary and sequencing

This docs-only Stop-and-Amend authorizes no migration or runtime support flip by itself.

Recommended implementation campaign checkpoints, kept on one coherent branch/PR unless risk
requires otherwise:

1. Selection persistence + revision/mutation invariants + exact Preview membership.
2. Concrete Preview→Live source binding and Live exact-set enforcement.
3. Master Products channel assignment/filtering + channel workspace empty/selected states.
4. Remote catalogue scan/snapshot persistence with workspace/account/target isolation.
5. Magento full-catalogue enumeration proof + implementation, including >10k behavior.
6. Remote-only catalogue merchant read surface + linking candidate integration.
7. Progressive Product table/remediation polish (thumbnail, compact issues, preserved context).

Each checkpoint follows implement -> test -> inspect diff -> fix -> continue. Do not call the
campaign complete from unit tests alone; Magento full-catalogue enumeration requires real-target
evidence before production-readiness claims.

## Final freeze

**[Resolved — 2026-09-15]**

The platform has one Master Product catalogue. `SyncConfiguration` owns configurable destination
selection; the first configurable mode is Product-level `explicit_products`. Preview evaluates
one exact selected Product set and Live is bound to one concrete current-revision Completed
Preview set. A provider's existing remote catalogue is a separate immutable successful
read-projection owned by ConnectorAccount/target context; it is neither Master Product truth nor
ExternalRecordLink trust. Incomplete scans never become current. Large Magento catalogues must be
enumerated with a separately proven >10k-safe strategy. SEO/search evidence remains
provider-neutral and AI remains proposal/governed-writer based. Remote-only records and remote
media remain observations until explicit link/import/acceptance creates governed platform truth.
