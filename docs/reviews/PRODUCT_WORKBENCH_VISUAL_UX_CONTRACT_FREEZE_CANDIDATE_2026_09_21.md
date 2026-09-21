# Product Workbench Visual UX Contract — Freeze Candidate — 2026-09-21

> **STATUS: READY FOR PRODUCT-OWNER APPROVAL — NOT [Resolved] YET**
>
> Structural authority: `PRODUCT_WORKBENCH_STRUCTURAL_CONTRACT_2026_09_21.md` [Resolved].
> Visual evidence: corrected Hybrid prototype at commit `7df5fce9b08d20ad3f96f9b5c202fbd1016c8882`.

## Goal

Freeze the merchant-facing Product Workbench shell and first-scope interaction hierarchy before implementation.

## 1. Shell

- Page title: `Magento`; connected account name secondary.
- Compact connection/catalogue freshness line.
- `Оновити каталог` is secondary utility.
- Stable system Views: `Огляд / Публікація / Зв'язки`.
- One short View-purpose line may be shown; it explains row universe, not technical architecture.
- Search is the primary table control; Filters/Columns are secondary.

## 2. Density

Target normal operational Filament density, approximately:

- body text ~14px;
- rows ~40–44px;
- compact badges;
- no giant hero cards;
- no B-style oversized catalogue rows;
- no A-style tiny spreadsheet text.

Exact CSS pixels are implementation detail; the Hybrid proportions are the visual reference.

## 3. Огляд

Row universe: current successful Magento Remote Catalogue.

Initial projection columns:

- SKU;
- Назва;
- Тип;
- Стан у Magento;
- Зв'язок;
- Оновлено;
- row action only where current capability exists (`Пов'язати`, open details).

Do not show Thumbnail/Brand/Category until Projection V2 provides them.
Do not show generic `Проблеми` until independent remote/problem evidence exists.
Do not show selection checkboxes in first scope.

Projection V2 target may add:

- thumbnail;
- brand;
- category breadcrumb/tree filtering;
- other cheaply/scalably projected fields proved by that campaign.

## 4. Публікація

Row universe: local Master Products selected/linked for this Magento account.

Principal membership action: `Вибрати товари`.

Table target:

- Фото when locally available;
- SKU;
- Назва;
- Категорія Magento;
- Набір характеристик;
- Готовність;
- Проблеми;
- Наступна дія;
- Останній результат.

First-scope Publication has **no row checkboxes** unless a real safe bulk operation is present.

Preview/Live execution is **configuration-level**, shown above the table as one causal action derived from current state:

- `Перевірити`;
- `Перевірити знову`;
- `Передати зміни` only when current admitted evidence allows it.

Row `Наступна дія` is remediation/detail only, never per-Product Live execution.
`Ще немає в Magento` is a quiet state with no CREATE action until CREATE is certified.

## 5. Вибрати товари

Use a scalable table-oriented selector:

- search;
- representative filters (Category/Brand/ProductType where supported);
- multi-select;
- clear membership confirmation.

First implementation may route to the current ProductResource channel-context grid if that is the shortest safe path.
Do not freeze a tiny card/two-row picker as target UX.

## 6. Зв'язки

Show factual trust state only:

- Magento SKU/name;
- `Пов'язано / Не пов'язано`;
- trusted Master Product when one exists;
- row action `Пов'язати` / open existing link.

No similarity/confidence/pre-ranked candidate evidence appears until a real ranking service exists.
Opening `Пов'язати` may let the merchant search/select a Product and then use the existing review/confirmation boundary.
No bulk auto-trust in first scope.

## 7. Product drawer

Use a wider calm slide-over so the table context remains visible.

First-scope tabs:

- `Основне`;
- `Magento`.

`Основне` respects existing Product field ownership and displays core 1C/catalogue-owned values read-only in this Workbench context.

`Magento` is the target home for:

- Category tree;
- Attribute Set / `Набір характеристик`;
- sparse override state;
- `Повернути автоматичний вибір`.

Those classification controls become executable only when Decision-B runtime exists.
Future Content/SEO/Media/History tabs are roadmap, not empty first-scope UI.

## 8. Status semantics

Never collapse into one mega-status:

- Magento provider state;
- trusted-link state;
- publication readiness;
- problems;
- last governed publication result;
- Product active/inactive when relevant.

## 9. Capability gating

- No enabled Magento CREATE.
- No remote-only import action before certification.
- No fuzzy matching claims before a real service exists.
- No Projection V2 fields before Projection V2.
- No Product-level Live execution.

## 10. Reference artifact

Normative visual reference: Hybrid screens/source under:

`docs/prototypes/product-workbench-visual-ux/`

Required reference screenshots:

- `hybrid_overview_current_projection.png`;
- `hybrid_overview_projection_v2.png`;
- `hybrid_publication.png`;
- `hybrid_links.png`;
- `hybrid_product_drawer.png`;
- `hybrid_select_products.png`.

Historical A/B images are research evidence only and are not implementation targets.

## Freeze gate

On explicit product-owner approval:

1. mark this document `[Resolved — 2026-09-21]`;
2. mark Hybrid as the authoritative visual implementation reference;
3. update Documentation Map accordingly;
4. create/open a docs/prototype PR only; no runtime code in the visual-freeze commit;
5. after merge, start UX-1 from fresh `origin/develop`.