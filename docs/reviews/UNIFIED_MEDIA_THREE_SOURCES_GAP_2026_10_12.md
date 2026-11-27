# Unified Media: three functional sources / GAP research — 2026-10-12

Base: develop cbe893e6b28d20cbc4d8dd25f95f697b87112344. Owner-approved UI is one responsive dashed Media area with a working upload, existing-asset picker, URL import, preview and guidance. Brand single-select, later Product multi-select through existing product writer. No new MediaAsset identity.

## Verified causes

- FilePond root is limited to 172×46px while the parent dashed zone spans far more. Native dropOnElement=true means the visually advertised drop zone is not the true drop target. Remove that mismatch. Prove real drag/drop near edges of the drawn area with Chromium.
- MediaAssetCardColumns uses a viewport-responsive three-segment Split, not modal container responsive. The right-side collapsible filters reduce available space and push badges outside cards. Keep image containment, fit/reflow without horizontal scroll.
- Filter must open right of search, be closable and not shrink or obstruct media cards. Magento Remote Catalog uses native Filament Modal slideOver; validate nested chooser behavior rather than assume.
- URL import must materialize a managed Original through existing OriginalImageIngestService; URL cannot become a new media identity or bypass workspace auth and SHA dedup.

## Research-first candidate assessment

| Candidate | Function | License (commercial) | Activity/updates | Integration | Result |
| --- | --- | --- | --- | --- | --- |
| Native Filament 5.7.6 FileUpload/FilePond + Actions | PASS local upload/drop and Actions; PARTIAL same-surface URL and composition | PASS MIT | PASS official active | PASS PHP 8.3 | ADOPT with narrowly bounded presentation changes |
| Filament ModalTableSelect | PASS existing media chooser | PASS MIT | PASS official active | PASS current MediaAsset | ADOPT |
| Existing SSRF-safe ConnectorHttpTransport | PASS bounded DNS-pinned network fetch, no auto redirects | Existing code | Existing regression tests | PARTIAL technical namespace under Connectors; only transport interface reused | REUSE with boundary review |
| happenv-com/filament-multi-source-upload v1.0 to 1.2 | PASS local + URL + FilePond | PASS MIT | PARTIAL very young (2026-09) | FAIL PHP ^8.5; current prod PHP 8.3.6 | REJECT until unrelated PHP upgrade |
| Curator, Spatie, Tomato media managers | PASS standalone DAM | PASS MIT per frozen Assets research | PASS | FAIL second media persistence identity | REJECT per Resolved |

Sources: https://filamentphp.com/docs/5.x/forms/file-upload ; https://filamentphp.com/plugins/happenv-com-multi-source-upload ; https://github.com/happenv-com/filament-multi-source-upload/blob/v1.2.0/composer.json ; https://pqina.nl/filepond/docs/patterns/api/filepond-instance/ ; verified local app/Services/Media/OriginalImageIngestService.php and app/Support/Connectors/Transport.

## Strict architecture / safety

Docs: 03-DOMAIN_MODEL, 04-ARCHITECTURE_PRINCIPLES checklist 1–3, 8, 17–18, 20–21, 05-AI_WORKING_AGREEMENT, 06-UI_DESIGN_SYSTEM, MASTER_ASSETS_V1_CORE. Tenant, RBAC, existing original identity, no duplicate storage authority or new database tables. URL import requires explicit workspace authorization before outbound traffic; only HTTP(S) public destinations, DNS pinning, CA verification, proxy disabled, 20 MiB response cap, up to 3 individually validated redirects, no HTTPS downgrade, request timeouts, 25 MP/content MIME checks using canonical OriginalImageIngestService. Fail-closed on blocked URLs; never reveal fetched content in error notices.

## Acceptance gates before owner can approve merge

- Local-file select, genuine drag/drop at center and edges of entire advertised dashed area; saved Brand logo after Save/reopen.
- URL success via isolated stub transport, blocked local/private/redirect, oversize and non-image, cross-workspace unauthorized, dedup idempotency.
- Search/picker works with native selection; filter closable, never shifts badges/images out of card; desktop and mobile screenshots from GitHub Chromium.
- Native hover/focus controls, exact Ukrainian/Russian/English labels, URL action genuinely working (never a decorative disabled button).
- Frozen HEAD with targeted tests + full MySQL/Pint + Playwright and visual acceptance. No production changes before merge and deploy permissions.