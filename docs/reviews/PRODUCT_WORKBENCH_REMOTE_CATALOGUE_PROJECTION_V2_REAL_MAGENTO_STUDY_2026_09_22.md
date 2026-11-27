# Product Workbench — Remote Catalogue Projection V2 Real Magento Study — 2026-09-22

> **Status: Lead real-target research — implementation input, not [Resolved] architecture.**
>
> Repo base: `origin/develop @ 25296a81710cb23fa9a14dd026bf3fc31ea9ccaa`.
> Test ConnectorAccount: existing Adobe/Magento test account already used for certification.
> Operation performed: READ only. No Magento writes.

## Goal

Determine whether the useful target Overview can be built from scalable paged Magento Product
reads without per-Product HTTP N+1 calls.

Target Overview needs, at minimum:

- thumbnail;
- SKU;
- name;
- brand/brand-like provider field;
- category/path;
- Attribute Set context;
- provider state;
- freshness;
- data-quality inputs.

## Current scanner limitation

Current `AdobeRemoteCatalogRequestFactory::ITEM_FIELDS` requests only:

`id, sku, name, type_id, status, updated_at`.

Therefore the current projection cannot populate the desired Overview even though Magento's
Product list response can carry more data.

## Real target proof — one Product

A real authenticated `GET /V1/products?pageSize=1` returned HTTP 200.

The Product object contained:

- `id`;
- `sku`;
- `name`;
- `attribute_set_id`;
- `price`;
- `status`;
- `visibility`;
- `type_id`;
- timestamps;
- `extension_attributes`;
- `media_gallery_entries`;
- `custom_attributes`.

Observed extension-attribute keys included:

- `website_ids`;
- `category_links`.

Observed custom-attribute codes included, among others:

- `category_ids`;
- `image`;
- `small_image`;
- `thumbnail`;
- `manufacturer`;
- `meta_title`;
- `meta_description`;
- `meta_keyword`;
- target-specific Product attributes.

The item also had one media-gallery entry in the sampled row.

### Consequence

Thumbnail/media locator, Category IDs, Attribute Set ID and a provider brand-like field can be
observed from the normal Product list payload without one Product-detail GET per row.

## Real target proof — full first page

A READ-only request with `pageSize=200` against the current test target returned all 19 Products:

- status: 200;
- Products: 19;
- response body: 144,484 bytes;
- average: about 7,604 bytes/Product;
- media gallery entries: 40;
- custom-attribute entries: 808;
- category links: 19.

A selective-field request retaining only identity, Attribute Set, type/status/freshness,
category links, media-gallery shape and custom attributes returned:

- Products: 19;
- response body: 133,631 bytes;
- average: about 7,033 bytes/Product.

The reduction is modest because Magento still returns the complete `custom_attributes`
collection once that collection is requested.

## Scale implication

At the observed average, 200 Products would be roughly 1.4 MB before catalogue/product
variance. The current Remote Catalogue transport body ceiling is 2 MB.

Therefore do **not** simply replace the current compact field list and keep a hardcoded
page size of 200.

### Lead recommendation

Projection V2 should begin with a conservative page size around **100** for the richer payload,
keeping the existing keyset/bounded enumeration invariants.

Before final implementation, add a test/guard proving response-size behavior and retain the
existing fail-safe body limit.

Do not store the entire Product JSON merely because it was read.

Persist only the compact normalized projection needed by Overview/filtering.

## Proposed V2 projection data

Per remote Product, compact normalized values should support conceptually:

- remote entity ID;
- SKU;
- name;
- type;
- provider status;
- updated timestamp;
- Attribute Set ID;
- thumbnail/media locator;
- category IDs;
- resolved category breadcrumb/path or references into a provider category dictionary;
- provider brand-like raw value/reference only after its semantic source is resolved.

Do not put SEO/full arbitrary attributes into the Remote Catalogue row merely because they are
present in the list payload.

Those attributes remain available to other channel/readiness/enrichment paths when needed.

## Category path

The Product list gives Category IDs/links, not merchant-friendly breadcrumbs.

Projection V2 needs a provider Category dictionary/tree read at account/target scope, then
resolves Product category IDs into paths without per-Product Category GET calls.

Prefer paged/bounded category metadata if the provider category tree can be large.

Do not flatten provider categories into Master Categories merely for display.

## Brand

The test target exposes `manufacturer`, but the canonical Product Field Registry explicitly
does **not** currently freeze `manufacturer == canonical brand`.

Therefore:

- do not hardcode `manufacturer` as platform brand;
- Overview may show a provider-owned brand/manufacturer field after connector-specific source
  resolution;
- the final column label may be `Бренд` only when the connector-owned field mapping/semantics
  prove that is correct for the target;
- otherwise use the provider label (for example `Виробник`) until mapping is resolved.

Option-ID → label resolution should reuse persisted Adobe attribute/options metadata where
available, not one option endpoint call per Product.

## Thumbnail

The target list payload exposes both:

- media gallery entries;
- image/small_image/thumbnail custom attributes.

Projection V2 should choose one deterministic locator policy and test it against:

- missing thumbnail;
- disabled media;
- multiple images;
- store-view differences.

No full binary/media download is needed for catalogue scanning.

## Attribute Set

`attribute_set_id` is a top-level Product field and is cheap to project.

Resolve the merchant label through the existing observed `AdobeProductAttributeSet` catalogue.

This directly supports:

- Overview filtering;
- existing-Product structural context;
- later Decision-B publication/readiness work.

## Data state / AI implication

Projection V2 can expose enough remote evidence to build a future Magento data-quality profile
without per-row HTTP reads.

However:

- structural ProductType completeness remains a Master Product concept;
- provider-required fields come from Attribute Set/attribute metadata;
- SEO remains a separate profile/domain;
- AI proposals must target governed field owners, never the Remote Catalogue projection itself.

## Risk / routing

### ROUTING DECISION

**Goal:** enrich Remote Catalogue projection enough to support the useful Magento Overview at
catalogue scale.

**Risk:** YELLOW.

**Why:** substantial READ-side connector/runtime change with bounded persistence/projection
migration, but no external writes, auth redesign, transaction semantics or trust mutation.

**Architecture:** existing/frozen scanner + immutable snapshot pattern; small additive projection
schema required.

**Executor:** Codex / Composer 2.5.

**Post-review:** Lead AI, including real-target scan and response-size evidence.

**Escalation:** only if provider category/media/brand semantics require a new identity/trust or
workspace-isolation decision.

**Cost rationale:** no Opus needed; the risk is data-shape/scale implementation, not fundamental
external-write architecture.

## Acceptance evidence for implementation campaign

Must prove:

1. one successful scan still atomically replaces current snapshot only after complete bounded enumeration;
2. failed/incomplete scans do not replace latest successful snapshot;
3. richer page payload stays below safety limits on representative/real target;
4. no per-Product HTTP call is introduced;
5. Overview can show thumbnail, Attribute Set, provider Category path and resolved brand-like
   field when available;
6. missing optional values degrade to `—`, not scan failure;
7. 10k+ enumeration semantics remain keyset/bounded and tested;
8. target-change guard remains intact.
