# Brand + Assets UX Closure — 2026-10-08

> **STATUS: [Resolved — 2026-10-08] — PRODUCT OWNER APPROVED**
>
> Base: `origin/develop @ da54452e49e0e98206c20bf9a843f4c580102516`.
>
> This document is a UX/application-layer continuation of
> `MASTER_BRAND_ENTITY_2026_10_07.md` and
> `MASTER_ASSETS_V1_CORE_2026_10_08.md`.
> It does not reopen Brand identity, MediaAsset identity, ProductMedia/VariantMedia
> ownership, or the Magento media contract.

## Goal

Close the merchant-facing Brand logo flow and remove the first usability gaps found
during real production Assets smoke.

## Accepted UX decisions

### 1. Image previews must fit, never crop or overflow

Asset preview surfaces use a bounded frame and `object-fit: contain`.

- Assets grid/list: the whole image must remain visible inside the thumbnail frame
  regardless of aspect ratio.
- Assets Details: the whole image must remain inside the preview area and must not
  expand beyond the content column.
- Brand list logo preview follows the same contain rule.
- This is presentation only; the Original bytes are never resized/re-encoded.

### 2. Storage wording

Technical `Managed / External` labels are replaced in merchant UI with:

- `У платформі` — bytes are stored on the BabyPark managed filesystem;
- `Зовнішнє` — the asset currently resolves through `source_url`.

The database/domain names remain unchanged.

### 3. Technical status wording

`diagnosis_status` is technical asset health, not publication readiness.

- `ready` → `Перевірено`;
- `pending` → `Ще не перевірено`;
- `attention` → `Потребує уваги`;
- `failed` → `Помилка`.

A healthy `ready` badge is not shown as noise in the Assets card grid. Details
show the technical status explicitly. Non-ready states include merchant-readable
guidance.

### 4. Usage is relational, not a static asset category

`Товари / Варіанти / Логотипи брендів` remain filters over real references.
They do not become separate media libraries, tabs, asset types, or persisted flags.

Asset Details adds `Використовується в` with concrete links to:

- Brand;
- Product;
- Variant (linking to its owning Product editor until a dedicated Variant route exists).

A single MediaAsset may appear in multiple usage kinds simultaneously.

### 5. Brand logo authoring

Brand logo remains `brands.logo_media_asset_id -> MediaAsset`.

The Brand form exposes:

- bounded contain-fit preview of the current selected logo;
- `Завантажити новий логотип`;
- `Обрати з Assets`;
- `Прибрати логотип`.

A newly uploaded logo is ingested through the shared
`OriginalImageIngestService`, becomes/reuses an ordinary Original MediaAsset, and
is then assigned through `BrandManager`.

No Brand-specific storage, media table, hash logic, or upload service is introduced.

### 6. Deployment bootstrap

Production smoke proved that Managed assets require Laravel's public storage link.
Deployment must therefore execute the official idempotent `php artisan storage:link`
step so a fresh environment cannot reproduce broken Managed previews.

## Research / integration gate

No new dependency is required. The implementation uses existing Filament 5
ImageColumn/ImageEntry/FileUpload/Select/Actions primitives and the already-resolved
MediaAsset/Brand services. Installing a media-manager dependency would reintroduce the
second-media-authority conflict already rejected by Assets v1 research.

## Acceptance evidence

- very wide and very tall images render fully inside Assets grid/list preview;
- the same images remain bounded in Assets Details;
- Brand list logo preview uses contain behavior and the shared placeholder;
- Brand can upload a new logo and the resulting/reused MediaAsset is canonical;
- Brand can select an existing Original from Assets;
- clearing logo association does not delete the MediaAsset;
- source labels render as `У платформі / Зовнішнє`;
- healthy grid cards do not show a redundant `Перевірено` badge;
- Details show explicit technical status and guidance for non-ready states;
- Details list concrete Brand/Product/Variant usage links;
- all usage queries remain workspace-scoped;
- deploy script includes official storage-link bootstrap;
- existing Brand, Assets, Product media, Variant media and Magento media regressions remain green.

## Routing

Risk: **YELLOW/ORANGE boundary**, treated as ORANGE for final review because the Brand
upload path touches workspace-scoped reusable asset identity. Architecture remains
existing/frozen; no schema change is allowed.
