# Playwright browser smoke — GitHub runner

## Goal

Obtain real Chromium verification for each pull request targeting `develop`
without ever starting a browser or an E2E database on the production B2B Droplet.

## Research First / Integration First

| Area | Official/existing solution | Result |
|---|---|---|
| Browser automation | Already-installed `playwright` SDK, Apache-2.0 | PASS |
| Test execution | Node 22 built-in `node:test`, no additional test runner package | PASS |
| Runner | GitHub-hosted `ubuntu-latest` | PASS |
| Application database | Existing GitHub MySQL service pattern, ephemeral database | PASS |
| Browser setup | Official `npx playwright install --with-deps chromium` | PASS |
| Failure evidence | Playwright's built-in trace/screenshot and `actions/upload-artifact` | PASS |
| Production integration | Not required; all URLs bound to `127.0.0.1` | PASS |

No new production dependency, application schema, external connector or custom
browser service is introduced.

## Scope and isolation

- Workflow: `.github/workflows/playwright-browser-smoke.yml`.
- On every PR to `develop` and on explicit workflow dispatch.
- Clean Ubuntu runner, locally built Laravel frontend, local MySQL 8 service.
- Database `babypark_e2e` is populated only by project migrations and test
  seeders; CI sample account is declared exclusively for that isolated database.
- Laravel's test webserver listens on `127.0.0.1:18767`, never a remote URL.
- Script refuses any non-loopback base URL.
- One headless Chromium process; no external tunnel or production secrets.
- On failure, collect screenshot, Playwright trace and local app logs as a
  7-day Actions artifact.

## Acceptance evidence

Phase 1 foundation: seeded administrator logs in, navigates to Assets and Brands,
both pages return HTTP 200, and no uncaught JavaScript exception is observed.
GitHub run at exact PR HEAD must be successful. Tests and checks that are not
yet implemented must not be claimed as passing.

Phase 2: add real-browser scenario coverage for Brand logo reuse, unchanged
image notices, shared Asset replacement confirmation/cancellation and list
refresh in the separate #283 capability, without hitting a production workspace.

## Risk / merge policy

GREEN/YELLOW isolated CI infrastructure; independent review plus exact-head CI.
No changes to production DB, authorization, asset identity or runtime. A new
required branch-protection check is a separate governance change only after the
workflow proves stable. Never merge/deploy without owner OK.

Related issues: #281 (CI baseline), #283 (media UX scenarios).
