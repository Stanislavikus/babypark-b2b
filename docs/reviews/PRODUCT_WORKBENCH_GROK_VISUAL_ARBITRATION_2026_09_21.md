# Product Workbench — Grok 4.6 Visual Prototype Arbitration — 2026-09-21

> **Status: Lead visual arbitration — DRAFT until corrected prototype is reviewed.**
>
> Prototype commit reviewed: `e1306768e12d20e5de8892ff1a2897e5880aafb7`.
> Structural authority: `PRODUCT_WORKBENCH_STRUCTURAL_CONTRACT_2026_09_21.md` [Resolved].

## Verdict

**KEEP THE PROTOTYPE, CORRECT IT ONCE, THEN FREEZE A HYBRID.**

Grok preserved the central A+B architecture and produced a useful renderable artifact.
The final product should not choose Variant A or B wholesale.

Lead target:

- **B hierarchy/context** for page title, one-line View purpose, drawer width and attention clarity;
- **A operational density** for catalogue tables, with slightly more breathing room than A;
- contextual causal actions, but only where actual runtime owns that action.

## What is already correct

- `Огляд / Публікація / Зв'язки` remain separate row universes;
- Overview is remote-catalogue-first;
- current Projection does not fake Thumbnail/Brand/Category;
- Projection V2 introduces those fields only as future projection capability;
- refresh is secondary;
- search is prominent;
- provider state and link state are separate;
- Remote rows are not editable Master Product truth;
- sparse Category/Attribute Set override is visualized in Magento drawer;
- Attribute Set mismatch for existing Magento Product is warning/advisory, not silent mutation;
- no enabled Magento CREATE action exists;
- Links has no bulk auto-trust.

These items should not be reopened without new evidence.
## Required corrections

### V-01 — BLOCKER FOR VISUAL FREEZE: Publication checkboxes are decorative

The prototype renders a checkbox on every Publication row and `Select all`, while neither the prototype nor current Workbench runtime provides a corresponding safe bulk operation.

The annotation says “bulk review is a real action here”, but that is not established by current code.

**Correction:** remove Publication checkboxes from the first-scope visual. Add them only when an explicit safe bulk action is implemented and visible in the same state.

### V-02 — MAJOR: `Передати зміни` is shown as a Product row action

Current `ManageAdobeProductsExportPreview::startLive()` admits Live by ConnectorAccount + SyncConfiguration. It does not receive a Product ID. Preview is also configuration-level.

Therefore a Product row button `Передати зміни` implies a per-Product execution capability that does not exist.

**Correction:**

- make `Перевірити / Перевірити знову / Передати зміни` a **Publication-level causal action** derived from the current configuration/run state;
- keep row `Наступна дія` for remediation only (`Вказати категорію`, `Виправити зіставлення`, `Відкрити товар`, etc.);
- a ready row should say `Готово` / no row action, not launch Live by itself.

### V-03 — MAJOR: Links invents similarity evidence

Prototype rows contain `Є схожий товар`, `Близька назва, інший SKU`, and an already proposed candidate.

Current `AdobeRemoteCatalogEntityTrustService::candidateProducts()` does not compute generic similarity/confidence:

- simple remote rows are constrained by exact active Variant SKU;
- configurable rows are searched by explicit merchant search term;
- no current fuzzy/similarity score exists.

**Correction:** first-scope `Зв'язки` must show only factual trust state and current safe row action.
Do not display automatic similarity/candidate evidence until a real candidate-ranking service exists.

### V-04 — MAJOR: Overview `Проблеми` cannot duplicate link state

In the V2 sample the unlinked Avent row receives `Проблеми = 1`, but the prototype contains no independent problem projector that supports that count.

Unlinked is already represented by `Зв'язок`.

**Correction:** omit Overview Problems until a real remote/problem projection exists, or populate it only from separately defined evidence. Never count `Не пов'язано` again as a generic problem merely to fill the column.
### V-05 — MAJOR: Product selector is not scalable enough

The `Вибрати товари` overlay currently demonstrates two simple `Додати` rows.
That does not represent the product goal for large catalogues.

Current `ProductResource` already has search, Category/Brand/ProductType filters, configurable columns and channel bulk actions.

**Correction:** visual target should show a wide table-based selector with search/filter/multi-select, or explicitly use the existing ProductResource channel-context page as the first implementation fallback. Do not freeze a tiny two-row picker as the target.

### V-06 — MAJOR: Basic drawer must not redefine Product edit ownership

The prototype renders Product `Назва` as an editable input.
Current ProductResource presents 1C-owned core fields such as SKU/name/brand/category as disabled, and the Workbench structural contract did not change source ownership.

**Correction:** Basic tab must reuse existing Product editability/ownership semantics. In this visual study show those values read-only unless an already-authoritative Product edit contract says otherwise.

### V-07 — MINOR: Future drawer tabs should not appear as shipped first-scope UI

The drawer displays `Контент / SEO / Медіа / Історія` even though this first Workbench campaign does not ship those integrated surfaces.

**Correction:** first-scope corrected prototype should show `Основне / Magento`. Future sections may remain in annotation/roadmap, not as empty merchant tabs.

### V-08 — MINOR: unavailable CREATE should be quiet

`Створення недоступне` is truthful, but a disabled action in every non-existing row over-emphasizes unavailable functionality.

**Correction:** for current V1 show neutral state `Ще немає в Magento` with no enabled next action. Explain current no-CREATE capability in detail/context when needed. When CREATE is certified, the action appears through capability gating.
## Final visual direction after corrections

### Shell

- larger/clearer header closer to Variant B, but not oversized;
- one purpose line per View;
- secondary `Оновити каталог`;
- wide search + secondary Filters/Columns;
- system tabs remain stable.

### Table density

Use Variant A as the base, but do not keep its very small ~12.5px body / ~33px rows.
Target normal Filament-like operational density: approximately 14px body text and ~40–44px rows.

Variant B's ~57px row height is too spacious for a 1k–100k product operational catalogue.

### Actions

- global/configuration execution action above Publication table;
- row actions only for row-owned remediation/link/detail;
- no decorative selection controls;
- no invented similarity.

### Drawer

Use the wider calmer Variant-B drawer as the base.
First scope: `Основне / Magento` only.
Magento classification corrections remain the main interactive content.

### Navigation

The prototype sidebar is illustrative, not frozen by this review.
A generic `Канали` entry scales better than assuming exactly one `Magento` sidebar item if multiple connector accounts/stores exist.
Do not let sidebar composition block Workbench implementation.

## Lead recommendation

Do one correction pass on the prototype only.
Do not request another broad UX study and do not involve Opus for this visual correction.

After corrected screenshots/source are reviewed, freeze the hybrid visual contract and move to UX-1 implementation.