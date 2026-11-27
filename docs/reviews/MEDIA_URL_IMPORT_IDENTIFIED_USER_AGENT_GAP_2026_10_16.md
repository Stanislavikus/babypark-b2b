# Media URL import / identifiable HTTP client GAP — 2026-10-16

**Classification:** non-normative defect evidence and narrowly scoped repair. No change
to `[Resolved]` Media Policy v1, the upload envelope, commercial storage policy,
or the platform connector transport contract.

**Repository/base:** `Stanislavikus/babypark-b2b`, `develop` at `c82e661abac9414f0092698defaadf062f46914a`.
**Goal:** a publicly accessible HTTPS image may proceed from URL import to
canonical byte/pixel validation, while upstream denial remains fail-closed.

## Root-cause evidence

Owner reported `Сервер зображення не повернув файл. Перевірте URL.` on
`https://upload.wikimedia.org/wikipedia/commons/3/3f/Fronalpstock_big.jpg`,
after PR #297 production deployment. In the current `OriginalImageUrlIngestService`,
that message is emitted only when a non-redirect HTTP response has
`statusCode !== 200`, before the canonical `OriginalImageIngestService` sees
the image. PR #297 fixed inline field wiring and bounded-size text, not upstream
client identification.

Read-only isolated-server bounded-range HTTP probes against that public URL:

| Request identification | Observed HTTP outcome |
| --- | --- |
| Empty `User-Agent` | 403 |
| Generic `GuzzleHttp/7` | 403 |
| `BabyPark-MediaImporter/1.0 (https://babypark.ua)` | 206, 32-byte range |
| Default curl HEAD request | 200, `image/jpeg`, `Content-Length: 14679474` |

The test does **not** assert the production host's exact HTTP status;
the owner-visible error proves only a non-200 branch there. The differential
isolated-server reproducer and the missing header in code identify a supported
likely cause. Exact production end-to-end acceptance remains post-deployment.

Primary authoritative source: Wikimedia's
[User-Agent Policy](https://foundation.wikimedia.org/wiki/Policy:Wikimedia_Foundation_User-Agent_Policy).
Wikimedia may block unidentified/default library clients. Supply a meaningful
HTTP client identity with contact; do not impersonate a browser.

## Minimal supported fix / invariants

Use Guzzle/PSR-7's **native request header** on the existing image URL service
only, including each redirect hop. No custom downloader, no Guzzle fork,
no new runtime dependency, no new provider-specific exception or domain rule.

Keep unchanged: workspace permission before network, SSRF-safe DNS pinning and
network/redirect constraints, TLS checks, response body size bound at 20 MiB,
canonical 25 MP limit, MIME inspection, SHA-256/media identity, disk/DB rollback,
and user-facing non-200 failure behavior. Upstream status 403 must remain a
rejection; do not claim it indicates an image pixel limit.

Regression gates: identify client on both repeat downloads and redirect hops;
403 does not write an asset; previously approved synthetic >25 MP case continues
to render the precise Brand form field error; existing MySQL and Playwright suites.

**State:** evidence captured; fix proposed for review and CI. Not yet production-
validated and not a policy change.
