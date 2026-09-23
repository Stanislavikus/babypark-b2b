# Product Workbench Visual UX Contract — [Resolved — 2026-09-22]

> **STATUS: [Resolved — 2026-09-22] — PRODUCT OWNER APPROVED**
>
> This contract supersedes the 2026-09-21 visual freeze candidate where they differ.
> Structural authority remains:
> `docs/reviews/PRODUCT_WORKBENCH_STRUCTURAL_CONTRACT_2026_09_21.md`
> including its 2026-09-22 A3 Stop-and-Amend.
>
> Visual research evidence remains under:
> `docs/prototypes/product-workbench-visual-ux/`.
>
> **2026-09-23 visual Stop-and-Amend — PRODUCT OWNER APPROVED**
>
> Real-screen review replaces the first-scope local filter rail with the standard table filter
> surface, requires native compact SaaS navigation in Focus Mode, and compacts the Workbench
> header/tabs as specified below. Domain/row-universe semantics are unchanged.

## Goal

Freeze the merchant-facing Workbench foundation so implementation can focus on building the
correct product once, then refine styling without reopening row-universe/domain decisions.

The first product UX must support large catalogues, AI-ready data quality work, channel
preparation, and future CREATE without turning Magento into a second Product catalogue.

---

## 1. Workbench shell

Top-level merchant Views:

```text
[ Огляд ]   [ Публікація ]
```

No permanent top-level `Зв'язки` tab in first scope.

Correspondence work remains available from `Огляд` through:

- `Зв'язок` column;
- link-state filter;
- system filter/view such as `Потребують зв'язку`;
- row action `Пов'язати`.

When a real candidate-ranking/bulk-confirmation engine exists, a dedicated Links work queue may
return without changing the domain model.

### Focus mode

When entering a channel Workbench, the global SaaS navigation should be collapsed by default
or reduced to a compact back/menu affordance.

Reason:

- connector work needs horizontal catalogue space;
- the global menu does not need to consume a permanent wide column during product operations;
- the freed width belongs primarily to the product table.

For the admin panel, Focus Mode uses Filament's native desktop-collapsible sidebar: the wide SaaS
navigation collapses to the normal icon rail with the native expand affordance. Do not create a
second bespoke connector navigation rail.

This is a presentation rule, not permission to remove normal SaaS navigation entirely.

### Header

Desktop first scope uses **one compact horizontal header line**, not stacked information cards.

Show three visually distinct zones in that same row:

- **connector identity** on the left — compact provider badge/icon + `Magento` + connected account/store as secondary identity; do not stack provider/account into another row;
- **status board** in the middle — icon-first compact evidence with concise values and full meaning available through accessible tooltip/label (Overview: remote Product count, unlinked count, last successful catalogue refresh; Publication: selected/total count explicitly meaning `selected for Magento`);
- **View-relevant utility actions** on the right (for example `Оновити`, `Вибрати товари`, `Перевірити готовність`).

Do not turn the status board into prose. Prefer familiar icons plus short values when the tooltip/accessible label can carry the full explanation.

Connection health may be added compactly when it is useful, but must not create another tall
header block.

The `Огляд / Публікація` controls render as a compact **browser-like tab strip** attached visually to the table area rather than inside a separate large card. Use familiar rounded-top page-tab affordance: the active tab visually joins the content surface and the inactive tab recedes.

While refresh is running, retain the latest successful catalogue and show progress/status;
never blank the working grid.

---

## 2. Workbench filters — 2026-09-23 Stop-and-Amend

Do **not** reserve a permanent second left rail for first-scope Workbench filters.

Use the standard Filament table filter surface next to search/column controls. The system
correspondence presets `Усі товари / Потребують зв'язку / Пов'язані` are represented by the
same trusted-link table filter rather than duplicated in a separate rail.

High-value filters may include:

- Category hierarchy/tree;
- Brand/brand-like provider field when resolved;
- Product type;
- Data state percentage/band;
- missing monitored field;
- Magento provider state;
- trusted-link state;
- problem severity;
- updated/freshness.

Filters must be capability-driven: do not expose one until its data source exists.

Search remains visually primary. The Workbench toolbar follows the shared Data List pattern from `docs/06-UI_DESIGN_SYSTEM.md`, with `Матриця полів` as the proven visual reference: search aligned to the list grid on the left, and persistent labelled `Фільтри` / `Стовпці` controls with count badges where applicable on the right. Columns remain configurable/discoverable.

2026-09-23 product-owner clarification: `Фільтри` and `Стовпці` use the same right-side slide-over interaction; their count badges stay inside each trigger immediately after the label, with visible right padding and without intersecting the button outline. The search field aligns to the same left grid inset as the table content. The Workbench connector identity uses the official Magento PNG mark rather than a fabricated letter tile; in the compact operational header it renders at 28 × 28 px so it remains smaller than the outer `Оновити` button box. Sortable `Дія` follows the same single-chevron Filament header grammar as every other sortable column while delegating its priority sort to the real `is_linked` state.

A dedicated local rail may return later only if a materially larger filter set proves that the
standard table filter surface no longer scales; that is not part of first scope.

---

## 3. Огляд — target Product grid

### Row universe

Current successful Magento Remote Catalogue snapshot.

This View answers:

> What is actually in this Magento store now?

### Target columns after Projection V2

```text
Фото | SKU | Назва | Бренд/провайдерне поле | Категорія | Стан даних |
Стан Magento | Зв'язок | Проблеми | Оновлено | Дія
```

Exact default visibility may be tuned during implementation, but the semantics are frozen.

### Data sources

- photo — Remote Catalogue Projection V2;
- SKU/name/provider status/updated — provider Product projection;
- brand — connector-owned resolved provider field; do not hardcode `manufacturer == canonical brand`
  without an explicit mapping/label decision;
- category — provider Category breadcrumb/path;
- `Стан даних` — derived data-quality/completeness profile, never one stored stale percentage;
- `Зв'язок` — ExternalRecordLink truth;
- `Проблеми` — independent findings/evidence, not a duplicate of link state;
- `Дія` — current row-owned action.

### Action column

Use an explicit merchant-facing header `Дія` when text actions are shown.

Examples:

- linked row → `Відкрити`;
- unlinked row → `Пов'язати`.

Do not render `Відкрити` for only an arbitrary sample row: every row with the same supported
state receives the same action semantics.

If later the row owns several actions, a compact action menu may replace text buttons.

### Row / photo interaction — 2026-09-23 clarification

Reuse the established BabyPark B2B table interaction:

- clicking a normal row surface opens the same slide-over detail action as `Відкрити`;
- clicking the Product photo does **not** open the row action; it opens the shared image lightbox;
- cells with their own drill-down/action (for example future `Стан даних`, `Проблеми`, or `Дія`)
  keep their own click behavior and must not accidentally trigger the row drawer;
- the drawer owns the secondary `Відкрити повну картку` navigation when a Master Product identity exists.

For an unlinked remote Magento row, row click may show remote Product detail, but must not invent a
Master Product full-card action before trusted correspondence exists.

### Problems

`Проблеми` represents independent blocker/warning/info findings.

Do not count `Не пов'язано` again as a generic problem merely to fill the column.

Compact target presentation may use severity icons/counts such as:

```text
🔴 1   🟡 2
```

with click/drill-down to causes.

---

## 4. Стан даних

`Стан даних` is a derived merchant-facing data-quality metric.

It must not be confused with:

- `Готовність` — can goal X be executed safely now?;
- `Проблеми` — concrete findings/causes;
- provider status — enabled/disabled in Magento.

Example:

```text
Стан даних: 82%
Готовність: Заблоковано
Проблеми: 1 blocker
```

may all be true at the same time.

### Drill-down

Clicking the percentage should reveal the applicable monitored fields, e.g.:

```text
✓ SKU
✓ Назва
✓ Бренд
✓ Категорія
✓ Основне зображення
✕ Матеріал
✕ Вік
✓ Опис
```

### Filter behavior

The Workbench may filter by:

- percentage/range;
- 100% vs incomplete;
- specific missing monitored field;
- group/profile where supported.

The percentage is derived; do not persist a redundant stale `82` field merely for display.

---

## 5. Data-quality profiles and readiness boundary

Existing Product Structure architecture remains authoritative:

### Basic structural completeness

Owned by ProductType structure:

- only active placements participate;
- only `required_for_completeness=true` placements enter the denominator;
- Product and Variant requirements are evaluated through governed FieldBindings;
- channel-specific requirements do not get written into ProductType structural completeness.

### Magento data-quality profile

The Workbench may present an advisory Magento data-state profile derived from applicable
channel data.

It may consume:

- mapped Product data;
- effective Magento Attribute Set;
- provider-required field metadata;
- target classification;
- media presence;
- explicitly frozen merchant/business quality requirements.

It must remain distinguishable from execution Readiness.

### Magento readiness

Readiness answers:

> Can this Product perform the intended Magento operation safely now?

It may consume:

- completeness/data state;
- target Category / Attribute Set;
- FieldMapping / FieldOptionMapping;
- trusted identity;
- provider required/conditional rules;
- media;
- price/availability when required by the operation;
- Preview/Live evidence.

Do not collapse readiness into the data percentage.

---

## 6. Pублікація

### Row universe

Local Master Products selected/linked for outbound preparation for this Magento account.

This View answers:

> What from our Master Catalogue are we preparing to send to Magento?

It remains separate because future CREATE Products may not exist in Remote Catalogue at all.

### Target columns

```text
Фото | SKU | Назва | Категорія Magento | Набір характеристик |
Стан даних | Готовність | Проблеми | Останній результат | Дія
```

Do not keep a separate permanent `Наступна дія` column if the row-owned remediation can be
represented naturally in `Дія`.

### Row action semantics

Examples:

- `Вказати категорію`;
- `Виправити зіставлення`;
- `Відкрити`.

A ready row does not launch Live by itself.

### Configuration-level execution action

Preview/Live remains configuration-level until runtime proves otherwise.

Show one causal action above the table:

- `Перевірити`;
- `Перевірити знову`;
- `Передати зміни` only after valid admitted Preview evidence.

No per-Product Live button.

### Selection controls

Do not show row checkboxes unless a real safe bulk action exists in that state.

`Вибрати товари` itself uses a scalable searchable/filterable multi-select table because
selection membership is a real bulk operation.

---

## 7. Linking / bulk-link future

First scope:

- link state is visible in `Огляд`;
- unlinked rows can invoke `Пов'язати`;
- current Entity Trust confirmation remains authoritative;
- no fuzzy confidence score is invented.

Future candidate engine may support a system filter such as:

```text
Точний кандидат
```

and then expose selection + a contextual action:

```text
128 вибрано · Підтвердити зв'язки
```

Even then, similarity/evidence does not become trust until the governed confirmation boundary
is satisfied.

---

## 8. Product drawer

Use a wide slide-over/drawer so catalogue context remains visible.

First-scope tabs:

- `Основне`;
- `Magento`.

`Основне` respects actual source ownership. 1C/catalogue-owned core fields remain read-only in
this Workbench when current authority says so.

`Magento` is the target home for:

- Category tree;
- Attribute Set / `Набір характеристик`;
- sparse override state;
- `Повернути автоматичний вибір`.

Do not show future Content/SEO/Media tabs as empty shipped UI.

Those domains may be added once their actual editing/proposal workflows exist.

---

## 9. AI-ready foundation

AI must use the same governed Product field architecture as manual editing.

Do not create AI-only copies of Product data.

AI may later:

- propose values for missing or weak fields;
- propose Category / Attribute Set classification;
- propose descriptions/SEO/media improvements;
- explain why a Product is incomplete/not ready.

AI never silently becomes Product/connector truth.

Existing Product Structure AI contract remains authoritative:

- proposal/run envelope;
- field-target identity;
- evidence/provenance;
- human review/policy boundary;
- governed apply through normal field/domain owners.

The Workbench should therefore expose data state and missing-field details in a form usable by
both human remediation and future AI proposals.

---

## 10. Magento fields: technical requiredness vs business quality

Do not freeze one hardcoded global list called “Magento required fields”.

Requirements depend on:

- Product type;
- effective Attribute Set;
- provider attribute metadata;
- configurable/simple role;
- operation (Update vs future Create);
- store/account rules.

### Known create foundation

Future Magento CREATE must at least resolve the Product identity/structure inputs used by Adobe
Commerce such as:

- SKU;
- name;
- Attribute Set;
- product type;
- status;
- visibility;
- type-specific price/weight/variant/stock requirements as applicable.

Category may be required by our publication policy even when Magento can technically store an
uncategorized Product.

### Business-quality baseline

The platform may intentionally require more than Magento's technical minimum, for example:

- description;
- main image;
- selected ProductType characteristics;
- Category;
- other merchant-defined/curated quality fields.

That policy belongs to a data-quality/readiness profile, not fake provider metadata.

### SEO

Magento supports Product Field Auto-Generation for SKU/meta fields depending on target store
configuration.

Do not rely on that as platform SEO truth.

Current canonical registry still defers final unified ownership/localization/store-view policy
for `meta_title` and `meta_description`.

Therefore:

- SEO is a separate profile/domain;
- Magento auto-generation is an acceptable target fallback;
- later AI SEO proposals target the unified governed SEO fields once their owner is frozen;
- SEO completeness must not silently alter Basic/Magento technical requiredness.

---

## 11. New Master Product creation direction

There must not be a separate “Magento-only Product” creation truth.

A new Product is always a Master Product in the SaaS.

The same creation flow may be launched from:

- `Товари → Створити товар`;
- `Magento → Публікація → Створити товар`.

When launched from Magento, the target channel context is preselected, but the created entity is
still the same Master Product.

Conceptual future flow:

1. Product type / simple vs variant-bearing product;
2. identity/basic classification;
3. governed ProductType fields;
4. variants when applicable;
5. media;
6. Magento target classification;
7. data state/readiness;
8. after separate RED certification: Preview → Create in Magento.

Current `ProductResource` CreateAction is not evidence that this flow already exists: current
core Product fields remain source-owned/read-only in that resource. Master Product creation
ownership requires its own implementation campaign.

---

## 12. Reference visual direction

The 2026-09-21 Hybrid remains useful for density/drawer/table styling, but its permanent
three-tab structure is superseded by this 2026-09-22 contract.

Implementation target:

- Hybrid/B-like hierarchy;
- ~14px operational text;
- ~40–44px rows;
- wide drawer;
- local collapsible filter rail;
- global SaaS navigation collapsed in Focus Mode;
- `Огляд | Публікація` only at the top level.

Historical A/B/Hybrid screenshots are research evidence, not a requirement to preserve every
visible column/control exactly.

---

## 13. Capability gates

Do not expose as working before runtime exists:

- Magento CREATE;
- remote-only → Master import;
- fuzzy candidate scoring;
- bulk trust confirmation;
- Projection V2 fields before Projection V2;
- Decision-B classification edits before Decision-B runtime;
- AI apply before AI proposal/governed apply foundation.

## Implementation order

The next technical dependency is **Remote Catalogue Projection V2**, because the desired
Overview should launch with the useful remote fields rather than invest in a disposable
minimal-grid presentation.

After Projection V2 evidence is frozen:

1. Workbench shell / Focus Mode / Overview;
2. Publication composition on current runtime;
3. Decision-B ORANGE classification/planner runtime;
4. Publication classification/readiness UX;
5. Master Product creation foundation;
6. separate RED Magento CREATE;
7. AI/SEO/Media capabilities on the same Workbench/data-quality foundation.
