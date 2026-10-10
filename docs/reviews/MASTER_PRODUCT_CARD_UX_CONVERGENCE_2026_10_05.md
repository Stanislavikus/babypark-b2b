# Master Product Card UX Convergence

> **STATUS: [Resolved — 2026-10-05; amended 2026-10-06 after visual merchant acceptance] — PRODUCT OWNER APPROVED**
>
> Original base at freeze: `origin/develop @ 3e4bd476c139e22ec96d5f4cf3305fa96be5d8ff`.
> Interaction amendment base: `origin/develop @ d9608fe84fd39aecc0e7da0079820a191ea2b208`.
>
> Reopen only for conflict with a newer authoritative document, a proven implementation blocker,
> or new material evidence. General OSS/UI research alone is not grounds to reopen this decision.

## Goal

Converge Product creation and Product editing into one coherent Master Product Card without
changing the already-resolved Product, ProductType, connector, pricing, inventory, media, or
identity foundations.

The merchant should understand one thing: there is one Master Product. A Product may later be
prepared for one or more channels, but channel-specific classification and publication state do
not replace or duplicate Master data.

## Frozen semantic boundaries

1. **One Master Product Card.** Create and Edit use the same visible card shell. The current
   abbreviated Create form is not a separate product concept.
2. **One explicit Save; no artificial first-step card.** Create and Edit use the same section
   structure. Direct Master fields that are safe before identity exists are editable immediately.
   Capabilities whose existing governed writers require Product/Variant identity stay in the same
   section and become interactive after the explicit first Save; do not render duplicate locked
   replacement sections and do not invent hidden autosave.
3. **Draft creation requires only Name.** Merchant-facing copy must state:
   **"Для чернетки достатньо заповнити лише «Назва». Решту даних можна додати пізніше."**
4. **Real lifecycle.** Master lifecycle is `draft | active | archived`. Publication/readiness is
   separate per goal/channel. The historical `products.is_active` boolean remains a compatibility
   boundary during migration and must not be treated as the full lifecycle model.
5. **ProductType is structural, not topology.** ProductType defines the admissible Product/Variant
   field structure and variant-axis candidates. It does not classify a Product as
   Simple/Configurable and is not equivalent to any provider schema such as Magento Attribute Set.
6. **Simple/Configurable is Product state.** Variant topology is determined by the concrete
   Product's axes/Variants, not by ProductType identity.
7. **Merchant Type is non-structural.** `products.merchant_type` is an optional free-form merchant
   classification label. It does not control fields, variants, completeness, readiness, or
   provider classification.
8. **Master Category remains workspace-owned.** `Category` is the shared Master/B2B navigation
   tree. Magento Category is separate account-scoped channel classification/mapping.
9. **Brand is Master data.** Current string storage remains in this campaign. Converting Brand to
   a reference entity is separate normalization work and must not block this UX convergence.
   **[Resolved amendment — 2026-10-07]:** that separate normalization is now the Master Brand
   campaign in `docs/reviews/MASTER_BRAND_ENTITY_2026_10_07.md`; after its migration,
   `products.brand_id` is the sole Product→Brand authority and the legacy string column is removed.
10. **Tags are Master organizational data.** They are not provider taxonomy.
11. **Actual site URL is channel/destination-owned.** The generic factual "URL товару на сайті"
    must not be presented as one universal Master URL. Master SEO may later hold defaults/slug
    proposals; concrete published URLs belong to a destination/store/locale context.
12. **Duplicate Product names are allowed.** Name is a human label, never Product identity.
    Product identity remains internal Product identity plus governed external identity where
    applicable.
13. **Channel selection is multi-select by nature.** A Product may be selected for multiple
    destinations concurrently. Deselecting a destination removes publication membership but must
    not destroy previously configured channel classification/mapping state.
14. **Only real channel capabilities are interactive.** The current implementation connects only
    real Magento functionality in this card. Future Shopify/Google controls are not shown as fake
    working channels. The native B2B storefront remains a resolved sales channel, but a distinct
    `B2BChannel` configuration entity is not yet implemented and is not faked in this slice.

## Create / Edit interaction contract

### Before first save

The full Master Product Card shell is visible immediately.

The merchant sees one stable card before and after the first Save. There is no separate
"draft form" followed by a second full form.

Direct Master fields (including physical/shipping fields) are editable before first persistence
and are submitted with the same explicit Save. Existing transactional/domain capabilities whose
writers require persisted Product/Variant identity remain in their normal section and may show an
inline identity prerequisite until the first Save. They must not be represented by a second
duplicate locked section.

Primary actions:

- **Зберегти**
- **Зберегти й додати ще товар**

Master lifecycle is selected independently in the right-hand **Статус / Стан у Master** control:
`draft | active | archived`. Saving data and selecting lifecycle are separate concepts; channel
publication remains separate from Master lifecycle.

After first Save the merchant remains in the same conceptual card; identity-bound controls become
interactive without changing the card structure.

### Field naming

Merchant-facing labels:

- ProductType: **Сімейство товару**
- `merchant_type`: **Внутрішня класифікація**

The merchant-type helper text must make clear that this field does not determine Product
characteristics/variants.

## Channel overlay

Master content never becomes Magento-owned when Magento is selected.

For current scope, Magento-specific UI may expose only Magento-owned concerns, for example:

- Magento Category classification;
- effective Attribute Set / override state;
- provider-required attributes/options;
- Magento readiness/findings;
- Preview / Live publication actions;
- concrete remote/store URL only when actually known for that destination.

This overlay must reuse the existing resolved Product selection, classification,
Preview/Live and ExternalRecordLink contracts. It must not create a second Product database or
duplicate Master fields.

Deselecting Magento changes selection/revision/publication eligibility. Existing
`adobe_product_category_overrides`, `adobe_product_attribute_set_overrides`, mappings and
trusted external identity are not deleted merely because selection membership is removed.

## Import vs single-Product enrichment

Bulk ingestion belongs on the Product list/workspace:

- **Імпортувати товари** — CSV/XLSX/multi-product ingestion.

Inside one Product Card, a future:

- **Заповнити з файлу**

means enrichment of this single Product from a supplier document/image/file. It is not bulk
product creation.

## AI entry point

The card reserves a prominent **✨ Створити з AI** entry point.

This does not authorize unrestricted LLM writes.

AI remains proposal-governed:

- ProductType/Category/Brand/Tags proposals resolve only to admitted Master targets;
- factual or regulated Product facts require source evidence and may not be invented;
- marketing copy may be generated under the content policy;
- Magento Category / Attribute Set proposals belong to the Magento overlay;
- merchant acceptance/policy remains the authority before persisted consequential changes.

The full AI runtime may be delivered in a subsequent capability slice; the UX entry point may
ship earlier only if it is clearly marked as pending/non-destructive.

## Completeness / readiness

Decorative Product completion percentages are prohibited by the existing Attribute Dictionary.

Do not show merchant-facing values such as "Характеристики 100%" when the practical state is
"0 required fields".

Use operational language instead, for example:

- "Обов’язкових характеристик немає"
- "Потрібно заповнити: 3"

Target readiness remains goal-specific and contextual:

- B2B Ready;
- Magento publish readiness;
- SEO Ready;
- future provider readiness.

Readiness must appear when it helps an operation, not as permanent gamification noise.

## Lifecycle migration boundary

The current runtime still depends on `products.is_active` for native B2B eligibility and other
legacy consumers. Therefore lifecycle introduction must be backward-compatible.

Implementation must:

1. add an explicit Product lifecycle status;
2. backfill existing `is_active=true` Products to `active` and
   `is_active=false` Products to `archived`;
3. create newly authored Master Products as `draft`;
4. preserve current B2B behavior until each consumer is intentionally migrated;
5. never make a draft customer-visible merely because a compatibility boolean exists;
6. add real MySQL regression coverage for migration/backfill and current B2B eligibility.

Do not silently redefine `is_active` to mean three states.

## Documentation correction: draft SKU

The historical Attribute Dictionary sentence saying that a name-only draft automatically
generates an "internal SKU" is not current runtime truth and must not be used to invent a SKU.

A draft receives internal Product identity. SKU/GTIN may remain absent until explicitly supplied
or produced by a separately resolved identity policy.

## Delivery order

1. Commit this Resolved contract and documentation corrections.
2. Implement real Master lifecycle with backward-compatible migration/tests.
3. Converge Create/Edit to one visible shell; no autosave.
4. Apply copy/action-name corrections and field-label corrections.
5. Remove decorative completeness percentages from merchant UX.
6. Move bulk Excel/CSV entry to Product list; keep single-product file enrichment concept separate.
7. Remove the ambiguous universal factual Product URL from Master SEO presentation.
8. Embed/reuse the existing Magento-specific classification/publication overlay in the card.
9. Add the visible governed AI entry point without bypassing proposal/authority rules.
10. Run focused tests, real MySQL gates, full suite/CI, then visual merchant review.

## Explicit non-goals

- no Product architecture rewrite;
- no replacement PIM;
- no Brand-entity migration in this campaign;
- no Shopify/Google connector implementation;
- no fake B2BChannel entity;
- no automatic SKU guessing;
- no hidden autosave;
- no provider schema promoted into ProductType;
- no merge/deploy without explicit Product Owner approval.
