# Master Product Workspace implementation contract — 2026-09-30

STATUS: [Resolved — 2026-09-30] — PRODUCT OWNER APPROVED

This contract supersedes earlier Product Workbench assumptions only where they conflict with the universal Master Product model below. Existing Magento channel workbench contracts remain valid for Magento-specific publication UX; they are not the owner of the global Master Catalogue.

## 1. Product experience

The merchant-facing goal is Shopify-like progressive disclosure over an enterprise-grade internal model.

The default Product Workspace remains intentionally simple:

- Basic information: title/name and description.
- Media.
- Internal classification: Product Type / Family, Category, Brand, Tags.
- Price.
- Inventory.
- Physical/shipping facts when applicable.
- Variants.
- Grouped attributes.
- SEO and optional Search Brief.
- A compact right rail for lifecycle, informational data quality, organization, AI attention items and per-target publication/readiness.

Channel-specific settings live in a channel drawer/target surface, not as permanent fields in the Master form.

Technical terms such as Authority Gateway, Publication ChangeSet, Projection Plan, Remote Normalizer and reconciliation are not merchant-facing vocabulary.

## 2. Product Domain Contract v0.4

### Identity and shape

- Workspace is the hard isolation boundary for identity, matching, uniqueness, source references and projection identities.
- Product has an immutable internal ProductID.
- Every Product has at least one immutable VariantID.
- A simple product is a Product with one Variant and no variant axes; the UI may hide that Variant.
- A variant product has declared axes plus an explicit list of existing Variants. The list is not assumed to be a Cartesian product.
- Variant axes reference typed Attribute Definitions. Enum identity uses a stable code with localized labels. Measurement identity uses canonical typed equality, e.g. 0.05 l equals 50 ml.
- SKU, GTIN/EAN/UPC/ISBN and MPN are business identifiers, not system identity.
- Source references such as 1C GUIDs are distinct from business identifiers.
- Projection identity binds exactly one local subject (Product or Variant) to Connector Account + remote type + remote ID.

Current legacy Product/ProductVariant columns such as onec_guid and Product.sku are compatibility debt. New code must not make them the universal identity model.

### Domains

The Product model uses separate domain semantics rather than one universal coordinate tuple:

1. Product Core.
2. Content & Assets.
3. Offer.
4. Inventory & Fulfillment.
5. Channel Overlay.
6. Intelligence / Derived.

Family/Product Type owns structure: attributes, groups, Product-vs-Variant ownership, allowed axes and validation semantics. Operational capabilities are separate policy: schema constraints/defaults may be refined by Product and Variant where allowed.

### Values, proposals and authority

Accepted values and proposals are separate. Pending AI/import proposals do not replace accepted truth.

A proposal targets a specific revision and becomes stale when that coordinate changes.

Inbound values pass an authority policy before mutation: overwrite, propose, conflict or ignore. Human edits to authoritative ERP-owned fields are explicit change intents and may be rejected, temporarily/persistently overridden, or written back when supported. AI acceptance never silently takes ownership of an ERP-authoritative field.

Semantic content-vs-fact contradictions are evidence-based validation findings and produce reviewable proposals, never silent rewrites.

### Content

Content policy distinguishes marketing, factual/technical and regulated/verbatim fields. AI generation, rewrite and translation permissions follow field policy. Display fallback and publication fallback are distinct; regulated content may forbid publication fallback.

### Offer and inventory

SELL, COMPARE_AT and COST are distinct money roles. Only SELL participates in sale-price resolution. SELL resolution is deterministic over allowed scope combinations; duplicate identical SELL scopes are a data conflict.

Inventory is keyed by VariantID + LocationID, never SKU. Serial, lot/batch and expiry belong to traceability/inventory concerns, not Variant identity.

### Channel overlay and projection

Channel Overlay stores channel-native settings, mappings, bindings, channel-specific content/overrides and publication configuration/state. It does not duplicate Master price, stock, weight, GTIN or brand.

Projection Plan is separate from overlay values. It declares the Variants included in a target and must preserve unambiguous sellable identity. If a channel cannot represent the Master shape, readiness may block, split or use another explicit projection strategy. Master data is never reduced merely to satisfy one provider.

Product and Variant have separate lifecycle from remote publication state. Variant IDs are not physically removed while referenced by projection identity, inventory or history.

## 3. Catalog Change & Publication Contract v0.3

SAVE is not PUBLISH.

- Working Copy contains accepted current state.
- Meaningful committed edits create governed change intents; individual keystrokes do not.
- Entity Resolution precedes authority for inbound data.
- Publication ChangeSet is an immutable subset of semantic coordinates + revisions, not a whole-product snapshot.
- Publication Target is Connector Account + destination context.
- Projection Plan maps the Master shape to that target.
- Durable projection operation/outbox state is written before the external side effect.
- Expected target state and observed target state are distinct.
- Reconciliation is per coordinate/operation and can classify acknowledgement, partial acknowledgement, drift, failure and ambiguity.
- Out-of-order acknowledgement must never regress a newer confirmed coordinate revision.
- Non-idempotent ambiguous operations are not blindly retried; reconcile/read back first unless the provider supplies a safe idempotency contract.
- For full-replace provider APIs, values outside the ChangeSet are materialized from the last confirmed target state, never opportunistically from the current Working Copy. First publication must establish a complete valid initial target state.

Automation is risk-based rather than one global auto-publish switch. Trusted price/stock updates may flow automatically while first AI-created publication, new axes, projection-plan changes and regulated content default to review. The objective is exception-only human attention.

## 4. Media Policy v1

- Ingest preserves the best available Original without quality degradation and performs diagnosis only.
- Transform/encode work starts when a destination or explicit user intent is known.
- Semantic pixel-edited derivatives fan out from Original or an approved high-fidelity parent. Lossy delivery outputs never become parents for later encoding.
- Responsive widths and format variants used by a storefront/CDN are delivery/cache concerns, not Product Media renditions.
- External channel artifacts are stable/versioned publication outputs; changing bytes is a new publication change, not an invisible cache mutation.
- Technical Google/Amazon/storefront outputs do not appear as duplicate creative images in the main Product gallery.
- Quality is judged by effective visual quality as well as native dimensions; optimization seeks the minimum transfer size that preserves the target perceptual quality for its purpose.
- Hard provider requirements cannot be weakened by workspace preferences. Provider recommendations are satisfied when native source quality permits.
- Destination profile owns allowed formats, dimensions, background treatment, color policy and required metadata.
- Background manipulation is a pixel transformation, not merely a format conversion.
- Routine encoding is automatic. The merchant-facing Improve action is reserved for genuinely weak source material or intentional restoration/creative transformation.
- AI/media provenance is retained so channel compliance can emit the correct current metadata.
- A 360 sequence is one logical media presentation in the Product Workspace, not dozens of ordinary gallery items.
- LCP/fetchpriority/lazy-loading are storefront/theme delivery concerns, not Product properties.
- Normal UI shows a simple optimized/ready state; engineering details appear only on demand or when a problem requires attention.

## 5. Product creation entry points

The intended Product creation experience is one Master Product workflow with three entry points:

- Create with AI.
- Import products.
- Add manually.

They converge on the same Product Workspace and the same governed fields. There is no Magento-only Product truth.

The first implementation slice intentionally delivers the source-neutral manual Master draft foundation before AI/import/media processing:

- new Master Products no longer require a 1C GUID;
- SKU may be absent for a draft;
- creation produces exactly one default Variant;
- Basic Product type is assigned automatically by the existing Product Structure foundation;
- existing 1C-owned identity fields remain read-only in the manual Product form;
- product creation uses a dedicated manage_products workspace permission, separate from manage_product_structure.

The temporary Product.sku/default-Variant SKU mirroring is compatibility-only. It must not become a new universal identity invariant.

## 6. Implementation sequence

1. Source-neutral Master Product creation foundation and product mutation permission.
2. Product Workspace shell and progressive-disclosure sections over existing governed data.
3. Rebase/port useful Product Structure + completeness UX from the stale B2a branch onto current develop rather than merging it blindly.
4. Asset/Media domain foundation and ingest diagnosis.
5. AI proposals + Search Brief.
6. Generic Publication ChangeSet / target reconciliation layer adapted to existing connectors.
7. Import/Create-with-AI entity-resolution flow.

Each slice must preserve current certified Magento REST V1 behavior. Standard Adobe/Magento REST V1 remains the baseline connector; Safe Sync stays an optional enhanced-safety profile.
