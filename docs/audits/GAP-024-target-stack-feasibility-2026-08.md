# GAP-024 — Target-State Feasibility & Migration Path Audit

**Target stack under evaluation:** Laravel 13 + Filament 5 + Livewire 4 + Tailwind CSS 4

---

## Report status and verification timestamp

> **UTC verification window: 2026-08-07T13:16:00Z – 2026-08-07T13:35:00Z**
>
> Every upstream version number, support date, dependency constraint, advisory ID
> and solver result in this report was fetched or executed inside that window.
> **Dependency information is time-sensitive.** Re-verify before acting on this
> report; Packagist, npm and the Laravel/Filament/Livewire release lines all
> published new versions within days of this audit.
>
> **Correction pass applied 2026-08-08 (UTC).** This pass incorporated
> independently verified corrections without re-running the full research
> programme: the Composer advisory-policy conclusion (§4.2), the custom-theme
> direction (§9.1), Tailwind's move to the Filament 3→4 checkpoint (§17 PR3),
> Vite-major deferral (§9.4, §12), UUIDv4 preservation (§10.3), live-filter
> preservation (§7.7), the authorization inventory (§7.8), the Filament
> published-asset mechanism (§9.5), the polling-test scope (§8.1) and the PR5
> no-deferred-defects rule (§17). Commands executed during the correction pass
> are dated 2026-08-08 inline. `origin/develop` was re-verified unchanged at
> `9713d03` before editing.
>
> A same-day micro-correction (2026-08-08) fixed two internal inconsistencies:
> PR5 is optional and not intrinsically required for GAP-024 closure (PR6
> follows PR1–PR4, and follows PR5 only if PR5 is actually performed before
> closure), and Discovery Overview UI becomes technically ready after PR4 but
> begins only after the PR6 truth-sync merges (§17, §18).

**Repository state audited**

| Item | Value |
|---|---|
| Repository | `Stanislavikus/babypark-b2b` |
| Base branch | `origin/develop` |
| Audited commit | `9713d03d862a549bc5738071a55e58fab0b2e647` (`9713d03`) |
| Commit subject | `Merge pull request #106 from Stanislavikus/cursor/docs-truth-sync-discovery-runtime-2d63` |
| Audit branch | `cursor/gap-024-target-stack-feasibility-audit` |

Ancestry precondition required by the task was verified before any research:

```console
$ git merge-base --is-ancestor 9713d03 origin/develop && echo ANCESTOR_OK
ANCESTOR_OK

$ git log --oneline -1 origin/develop
9713d03 Merge pull request #106 from Stanislavikus/cursor/docs-truth-sync-discovery-runtime-2d63
```

`9713d03` is both an ancestor of and currently the tip of `origin/develop`. No
newer `develop` commits existed during the audit window.

**This report is non-normative research evidence for an already-approved target
direction.** It does not perform the migration, does not close GAP-024, does not
modify `docs/07-TECH_STACK.md` to claim the new stack is active, does not
authorize production deployment, and does not authorize Discovery Overview UI
implementation. GAP-024 remains **Open**
(`docs/IMPLEMENTATION_GAPS.md`, "GAP-024 — Laravel 11 framework upgrade required
for connector production-readiness").

Filament 5 as the target UI framework generation is a **closed human decision**
and is not reopened here. Filament 4 appears in this report only as a
*temporary migration bridge*, never as a long-term architecture.

---

## PRE-CODE ARCHITECTURAL ALIGNMENT

* **Task Type:** research / compatibility / migration-planning (documentation only — no application code, dependency, migration, config, CI or infrastructure change)
* **Docs Checked:** `docs/Project_Documentation_Map.md` (full); `docs/00-WHY.md`; `docs/01-PRODUCT_VISION.md`; `docs/02-ATTRIBUTE_DICTIONARY.md`; `docs/03-DOMAIN_MODEL.md`; `docs/04-ARCHITECTURE_PRINCIPLES.md` (including the current `## Architecture Review Checklist` section, items 1–22, and the `## Filament form validation standard` section, physically read in this session); `docs/05-AI_WORKING_AGREEMENT.md` (`## Strict Alignment Pathway`, `## PRE-CODE ARCHITECTURAL ALIGNMENT`, `## Architecture Review Checklist Enforcement`, `## Primary Sources and Standards Check`); `docs/06-UI_DESIGN_SYSTEM.md`; `docs/07-TECH_STACK.md` (full, including `## Connector runtime (Resolved — Task 4B-2-0)`, `### Queue timeout alignment (Resolved)`, `### Connector secret lifecycle (Resolved)`); `docs/IMPLEMENTATION_GAPS.md` (GAP-024 section and cross-references at lines 404–439)
* **Affected Domain Contexts:** none mutated. Compatibility surface touches Connectors (queue runtime, discovery, HTTP transport, secret lifecycle), B2B Channel (cabinet panel UI), Product Catalogue (admin tables/forms), Pricing (Price Inspector page), Workspace (UUID primary keys on 18 workspace-owned models), Users and Permissions (`spatie/laravel-permission`, Filament panel auth middleware)
* **Primary Sources & Standards:** Laravel official docs (`laravel.com/docs/13.x/releases`, `laravel.com/docs/13.x/upgrade`, `laravel.com/docs/12.x/upgrade`); Filament official docs and GitHub repository (`filamentphp.com/docs/5.x/upgrade-guide`, `raw.githubusercontent.com/filamentphp/filament/{5.x,4.x}/docs/14-upgrade-guide.md`, Filament 5 styling docs, `filament/upgrade` package source at tags `v5.7.6` / `v4.12.6`, Filament v5.7.6 monorepo tarball); Livewire official docs (`livewire.laravel.com/docs/upgrading`, v4.x); Packagist metadata API (`repo.packagist.org/p2/*.json`); npm registry (`registry.npmjs.org`); Composer solver output (`composer why-not`, `composer prohibits`, `composer update --dry-run -W`, `composer audit --locked`); Rector dry-run output from the official `filament/upgrade` configs
* **Architecture Checklist Result:** No checklist item is *violated* by this task, because the task adds one Markdown file and changes no schema, model, service, policy, UI or dependency. Verified as unaffected-by-construction: items 1, 2, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 18, 19, 20 (no table, column, attribute definition, alias, domain boundary, variant rule, projection, order/payment field, status matrix, webhook route, reservation, availability formula, order snapshot, client-specific branch, payment field or user-facing terminology is added or altered). Items **3 (Authorization/RBAC)**, **17 (Connector Encapsulation)**, **21 (External URL / SSRF Safety)** and **22 (Connector Secret Handling)** are the checklist items whose *implementations* the eventual migration could regress, so this audit explicitly verifies their compatibility surface rather than marking them non-applicable — see §10 (`spatie/laravel-permission`, Filament panel auth guard/middleware, `WithoutOverlapping`/`Cache::lock`, Guzzle `CURLOPT_RESOLVE` pinning, `encrypted:array` casts and `APP_PREVIOUS_KEYS`). The `## Filament form validation standard` requirement in `04` (every panel form must render `novalidate`) is likewise treated as a first-class migration constraint — see §7.4, where it is identified as a silent-regression risk.
* **Architecture Risks Identified:** (a) silent loss of the mandated `novalidate` form behavior because the Blade views the project forks to achieve it are removed or restructured in Filament 5; (b) silent visual regression in the two panels because 1,658 lines of forked Filament 3 Blade cannot be carried forward; (c) UUID version change (v4 → v7) on 18 workspace-owned models that Laravel 12's `HasUuids` would introduce silently — resolved in this report as a behavior-preserving direction: preserve UUIDv4 via the framework-supported mechanism (§10.3); (d) explicit `VerifyCsrfToken::class` references in both panel providers against a middleware Laravel 13 renames; (e) connector queue lane timing contracts (`retry_after` 90s/1200s, job timeouts 45s/900s, lock `expireAfter` 120s/1100s) must be re-verified, not assumed, after the framework jump; (f) the frontend build is never exercised in CI, so a Vite/Tailwind regression would first surface on the production host inside `deploy.sh`; (g) 22 of the 23 `can*()` overrides across 10 Resources are deny-only rules with no backing policy, and Filament 4 changes their invocation path — an authorization-broadening risk (§7.8)
* **Chosen Technical Approach:** produce a documentation-only audit under `docs/audits/`. All dependency-solver and upgrade-tool experiments were performed in throwaway `git worktree` copies under `/tmp/solver/**`, which were destroyed before commit; the audit branch carries no dependency, lockfile, application, Blade, CSS, migration, config, CI or Docker change. This protects the architecture by keeping an unverified framework migration out of `develop` while still producing executable evidence (literal solver and Rector output) instead of documentation-derived guesswork.
* **Non-Technical Simplicity Check:** no user-facing change. The report explicitly requires that the eventual migration preserve the merchant-facing surfaces defined in `06-UI_DESIGN_SYSTEM.md` (product table defaults, context drawer, quantity/cart, availability wording, Ukrainian labels) and defines the visual-verification gate (§16) that protects them. No enterprise jargon is introduced into any UI string.
* **Stop & Amend Required:** **No** for this audit (documentation only; GAP-024 already exists as the approved project source for why the upgrade is required). **Before the real migration begins**, two items still need external input that this audit cannot provide: (1) the production PHP and Node versions, which are not verifiable from repository evidence (§11, §12); (2) for each vendor Blade fork, whether a supported public Filament extension point can replace it — investigated first, with re-derivation only where no public extension satisfies the normative behavior (§7.4). Two items previously framed as open decisions are now recorded migration directions, not open questions: UUID policy (**preserve UUIDv4** during GAP-024; §10.3) and panel styling (**proper custom Filament themes are the recommended direction**, required by upstream documentation for the project's own Tailwind usage; §9.1).

---

## Executive summary

| Question | Answer |
|---|---|
| **Target-state feasibility** | **GO WITH PREREQUISITES** |
| **Filament migration route** | **Route B — staged `3 → 4 → 5`** (Filament 4 strictly as a temporary bridge) |
| **Biggest verified blocker/risk** | 1,658 lines of forked Filament 3 vendor Blade under `resources/views/vendor/filament-*`, which the official upgrade tooling does not touch and whose upstream counterparts are restructured or removed in Filament 5 — including the two forks that implement the mandated `novalidate` behavior |
| **Is the dependency graph solvable?** | Yes. `composer update --dry-run -W` resolves Laravel 13.24.0 + Filament 5.7.6 + Livewire 4.3.5 on PHP `^8.3` with exit code 0 |
| **Safe to start Discovery Overview UI first?** | **No** |

Nothing in the current dependency graph *prevents* the approved target state. The
classification is `GO WITH PREREQUISITES` rather than `GO` because a specific,
enumerable set of prerequisite changes must land as part of the migration:
PHP 8.3+ floor everywhere, `laravel/tinker ^3.0`, PHPUnit 12, re-derivation of
four published vendor Blade views, proper custom Filament themes for both panels
together with the Tailwind 4 toolchain (on the current Vite major),
behavior-preserving UUIDv4 handling for 18 models, and a Node-version guarantee
in CI and production. These are listed exhaustively in §18.

---

## 1. Exact current baseline

### 1.1 Runtime and tooling actually present in the audit environment

```console
$ php -v
PHP 8.3.6 (cli) (built: May 25 2026 13:12:06) (NTS)

$ composer --version
Composer version 2.10.0 2026-05-28 11:22:08

$ node -v && npm -v
v22.14.0
10.9.7
```

### 1.2 Composer baseline

Root constraints from `composer.json`; locked versions from `composer show --direct`
against `composer.lock`.

| Dependency | Root constraint | Locked | Runtime/dev | ↔ Laravel | ↔ Filament | ↔ Livewire | ↔ PHP | ↔ Tailwind/Vite |
|---|---|---|---|---|---|---|---|---|
| `php` | `^8.2` | 8.3.6 (env) | platform | yes | yes | yes | — | no |
| `filament/filament` | `^3.2` | `v3.3.52` | runtime | yes | — | yes | yes | yes |
| `laravel/framework` | `^11.31` | `v11.54.0` | runtime | — | yes | yes | yes | no |
| `laravel/tinker` | `^2.9` | `v2.11.1` | runtime | yes | no | no | yes | no |
| `livewire/livewire` | `^3.0` | `v3.8.0` | runtime | yes | yes | — | yes | no |
| `predis/predis` | `^3.4` | `v3.4.2` | runtime | no | no | no | yes | no |
| `spatie/laravel-permission` | `^6.25` | `6.25.0` | runtime | yes | no | no | yes | no |
| `fakerphp/faker` | `^1.23` | `1.24.1` | dev | no | no | no | yes | no |
| `laravel-lang/lang` | `^15.32` | `15.32.0` | dev | yes | no | no | yes | no |
| `laravel/pail` | `^1.1` | `1.2.7` | dev | yes | no | no | yes | no |
| `laravel/pint` | `^1.13` | `1.29.1` | dev | no | no | no | yes | no |
| `laravel/sail` | `^1.26` | `1.61.0` | dev | yes | no | no | yes | no |
| `mockery/mockery` | `^1.6` | `1.6.12` | dev | no | no | no | yes | no |
| `nunomaduro/collision` | `^8.1` | `8.9.4` | dev | yes | no | no | yes | no |
| `phpunit/phpunit` | `^11.0.1` | `11.5.55` | dev | yes | no | no | yes | no |

`composer.json` has **no** `config.platform` block, **no** `.php-version` file and
**no** `.nvmrc`; `package.json` has **no** `engines` field. Verified:

```console
$ node -e "const c=require('./composer.json');console.log('config.platform =', JSON.stringify(c.config.platform||null))"
config.platform = null
$ node -e "const p=require('./package.json');console.log('engines =', JSON.stringify(p.engines||null))"
engines = null
$ ls -a | grep -E "nvmrc|php-version|tool-versions" || echo none
none
```

### 1.3 Transitive dependencies that become material during solver experiments

These are not root dependencies but changed state in the target-resolution
dry-run, and are therefore part of the migration surface:

| Package | Locked now | Target state | Note |
|---|---|---|---|
| `doctrine/dbal` | `4.4.3` | **removed** | required by `filament/support` v3 (`doctrine/dbal: ^3.2\|^4.0`); Filament 4/5 dropped it. The Filament v4 upgrade guide notes it must be re-added explicitly if the *application* still needs it. This project has no direct `doctrine/dbal` usage. |
| `spatie/color` | `1.8.0` | **removed** | v3-only `filament/support` dependency |
| `guzzlehttp/guzzle` | `7.10.6` | `7.15.3` | SSRF transport depends on the cURL handler and raw `curl` option array (§10.4) |
| `guzzlehttp/psr7` | `2.10.4` | `2.13.0` | `07-TECH_STACK.md` records PSR-7 v2 as a binding constraint for the OAuth1 signer decision |
| `nesbot/carbon` | `3.11.4` | `3.13.1` | Carbon 3 already satisfied, so the Laravel 12 "Carbon 3" breaking change is already absorbed |
| `league/commonmark` | `2.8.2` | `2.9.0` | 6 advisories at the locked version (§14) |
| `anourvalar/eloquent-serialize` | `1.3.8` | `1.3.11` | pulled in by `filament/actions`; appears in the blocked-resolution output (§4.2) |
| `kirschbaum-development/eloquent-power-joins` | `4.3.2` | `4.3.3` | `filament/support` dependency |
| `danharrin/livewire-rate-limiting` | `v2.2.0` | `v2.2.1` | direct `filament/filament` v3 dependency; in v5 it moved into `filament/support` as `^2.0` |
| `filament/schemas`, `filament/query-builder` | *absent* | newly locked | **new Filament packages that do not exist in v3** — the `Schemas` package is the root of the v3→v4 API break (§7) |
| `pragmarx/google2fa*`, `chillerlan/php-qrcode` | *absent* | newly locked | new `filament/filament` v5 dependencies (built-in MFA) |
| `nette/php-generator`, `league/uri-components`, `spatie/invade` | partial | newly locked / bumped | new `filament/support` v5 dependencies |

### 1.4 npm baseline

`package.json` declares only `devDependencies`; locked versions read from
`package-lock.json` (`lockfileVersion` 3):

| Package | Root constraint | Locked | Latest stable (2026-08-07) |
|---|---|---|---|
| `tailwindcss` | `^3.4.13` | `3.4.19` | `4.3.3` |
| `vite` | `^6.0.11` | `6.4.3` | `8.2.1` |
| `laravel-vite-plugin` | `^1.2.0` | `1.3.0` | `3.1.3` |
| `postcss` | `^8.4.47` | `8.5.15` | `8.5.26` |
| `autoprefixer` | `^10.4.20` | `10.5.0` | `10.5.4` |
| `axios` | `^1.7.4` | `1.16.1` | `1.19.0` |
| `concurrently` | `^9.0.1` | `9.2.1` | `10.0.4` |

```console
$ npm outdated --package-lock-only
Package              Current  Wanted  Latest
autoprefixer          10.5.0  10.5.4  10.5.4
axios                 1.16.1  1.19.0  1.19.0
concurrently           9.2.1   9.2.4  10.0.4
laravel-vite-plugin    1.3.0   1.3.0   3.1.3
postcss               8.5.15  8.5.26  8.5.26
tailwindcss           3.4.19  3.4.19   4.3.3
vite                   6.4.3   6.4.3   8.2.1
```

### 1.5 Frontend / panel configuration baseline

* `vite.config.js` — single `laravel()` plugin, `input: ['resources/css/app.css', 'resources/js/app.js']`, `refresh: true`. **No Tailwind plugin.**
* `tailwind.config.js` — Tailwind **v3** JS config: `content` array with 5 globs, `theme.extend.fontFamily.sans` (Figtree), `theme.extend.colors.primary` (6 amber stops), `plugins: []`.
* `postcss.config.js` — `plugins: { tailwindcss: {}, autoprefixer: {} }` (Tailwind v3 PostCSS plugin form).
* `resources/css/app.css` — 4 lines: `@import './design-tokens.css';` then `@tailwind base; @tailwind components; @tailwind utilities;`.
* `resources/css/design-tokens.css` — 38 lines of **plain CSS custom properties** (`--color-primary-*`, `--bp-muted-*`) plus a `.dark { … }` block and four `.bp-muted-*` classes. Contains no `@apply`, no `@layer`, no `theme()`.
* `resources/js/app.js` — `import './bootstrap';` only.
* `@vite(...)` appears in exactly **2** Blade files: `resources/views/layouts/cabinet.blade.php:7` and `resources/views/welcome.blade.php:15`.
* **Neither Filament panel registers a custom theme.** `->viteTheme(` occurrence count across the repository (excluding `vendor/`, `node_modules/`): **0**.
* `/public/build` is gitignored (`.gitignore:6`), so Vite assets are built at deploy time.
* **But 16 Filament 3 published JavaScript assets *are* committed** under `public/js/filament/**` — `filament/{app,echo}.js`, `forms/components/{color-picker,date-time-picker,file-upload,key-value,markdown-editor,rich-editor,select,tags-input,textarea}.js`, `notifications/notifications.js`, `support/support.js`, `tables/components/table.js`, `widgets/components/chart.js`, `widgets/components/stats-overview/stat/chart.js`. These are the output of `php artisan filament:assets` (referenced in `DEPLOY.md:309`) and are version-locked to Filament 3.3.52. Five of them contain `Livewire.hook` / `$wire.` calls (`tables/components/table.js`, `notifications/notifications.js`, `forms/components/{markdown-editor,color-picker}.js`, `widgets/components/chart.js`) — the `Livewire.hook` API that Livewire 4 deprecates. See §9.5.

### 1.6 Filament panel providers

`app/Providers/Filament/AdminPanelProvider.php` — `->default() ->id('admin')
->path('admin') ->login() ->brandName() ->colors(['primary' => Brand::primaryColor()])`,
two `->renderHook(...)` calls, `->discoverResources() ->discoverPages() ->pages([Pages\Dashboard::class])
->discoverWidgets() ->widgets([Widgets\AccountWidget::class])`, a 9-entry
`->middleware([...])` array and `->authMiddleware([Authenticate::class])`.

`app/Providers/Filament/CabinetPanelProvider.php` — `->id('cabinet') ->path('cabinet')
->authGuard('customer') ->login(Login::class)`, the same colors/brand,
**three** `->renderHook(...)` calls (the third scoped to `ListProducts::class` and
rendering `@livewire('cabinet.cart-toolbar')` at
`TablesRenderHook::TOOLBAR_TOGGLE_COLUMN_TRIGGER_AFTER`),
`->discoverResources()`, `->pages([Pages\Dashboard::class])`, a
`->navigationItems([NavigationItem::make('Кошик')…])` cart badge, the same
9-entry middleware array and `->authMiddleware([Authenticate::class])`.

Both middleware arrays contain `Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class`
explicitly — see §10.1.

### 1.7 Docker / CI / deployment baseline

* `docker/php/Dockerfile` — `FROM php:8.3-fpm-alpine`; extensions `pdo_mysql mbstring exif pcntl bcmath gd intl zip xml opcache` plus `redis` via PECL; `COPY --from=composer:2.7`; installs `nodejs npm` from the Alpine repositories with **no version pin**.
* `docker-compose.yml` — services `app`, `nginx` (`nginx:1.25-alpine`), `db` (`mysql:8.0`), `redis` (`redis:7-alpine`), `queue` (`php artisan queue:work --sleep=3 --tries=3 --max-time=3600`), `connector-queue` (`php artisan queue:work database_connectors --queue=connectors --sleep=3 --tries=3 --timeout=900 --max-time=3600`), `scheduler`.
* `.github/workflows/mysql-tests.yml` — `shivammathur/setup-php@v2` with `php-version: '8.3'` and extensions `mbstring, xml, curl, sqlite3, pdo_sqlite, pdo_mysql, bcmath, intl`; MySQL 8.0 service; runs three `--filter` test passes, the full suite, `vendor/bin/pint --test` and `git diff --check`. **There is no Node/npm step and no `npm run build` step.**
* `.github/workflows/deploy.yml` — SSH into the pilot host and run `./deploy.sh` on push to `develop`.
* `deploy.sh` — `git pull; composer install --no-dev --optimize-autoloader; npm ci; npm run build; php artisan optimize:clear; php artisan queue:restart`.

---

## 2. Target stack verified from live primary sources

### 2.1 Laravel 13

Source: `https://laravel.com/docs/13.x/releases` and Packagist
`repo.packagist.org/p2/laravel/framework.json`.

* Latest stable 13.x at verification time: **`v13.24.0`**, published `2026-08-04T15:54:59+00:00`.
* `laravel/framework` `v13.24.0` requires **`php: ^8.3`**. The release notes state: "Laravel 13.x requires a minimum PHP version of 8.3."
* Supported PHP range per the support-policy table: **8.3 – 8.5**.
* Support lifecycle (verbatim from the release-notes table):

| Version | PHP | Release | Bug fixes until | Security fixes until |
|---|---|---|---|---|
| 11 | 8.2 – 8.4 | March 12th, 2024 | September 3rd, 2025 | **March 12th, 2026** |
| 12 | 8.2 – 8.5 | February 24th, 2025 | August 13th, 2026 | February 24th, 2027 |
| 13 | 8.3 – 8.5 | March 17th, 2026 | Q3 2027 | March 17th, 2028 |

The page renders Laravel 11 as **End of life**. As of the audit date
(2026-08-07) Laravel 11 has been past its security-support end date for
roughly five months. This corroborates the `docs/IMPLEMENTATION_GAPS.md`
GAP-024 statement that "Laravel 11 security support ended **2026-03-12**".

Note also that Laravel 12's bug-fix window ends **August 13th, 2026** — six days
after this audit. Any plan that treats Laravel 12 as a resting place would land
on a line that is already security-fixes-only.

Canonical Laravel 13 skeleton (`raw.githubusercontent.com/laravel/laravel/13.x/composer.json`):
`php ^8.3`, `laravel/framework ^13.17`, `laravel/tinker ^3.0`; dev:
`phpunit/phpunit ^12.5.12`, `nunomaduro/collision ^8.6`, `mockery/mockery ^1.6`,
`fakerphp/faker ^1.23`, `laravel/pint ^1.27`, plus a new `laravel/pao ^1.0.6`.

Both sequential upgrade guides were reviewed as required (§10).

### 2.2 Filament 5

Source: `filamentphp.com/docs/5.x/upgrade-guide`,
`raw.githubusercontent.com/filamentphp/filament/5.x/docs/14-upgrade-guide.md`,
Packagist, and the `v5.7.6` monorepo tarball.

* Current stable 5.x: **`v5.7.6`**, published `2026-08-05T20:52:57+00:00`.
* Requirements, verbatim from the v5 upgrade guide's `## New requirements`:
  * PHP 8.2+
  * Laravel v11.28+
  * Livewire v4.0+
  * Tailwind CSS v4.0+
* `filament/filament` `v5.7.6` `require`: `php ^8.2`, `chillerlan/php-qrcode ^5.0`, the eight `filament/*` sibling packages at `self.version` (now **including `filament/schemas`**), `pragmarx/google2fa ^8.0|^9.0`, `pragmarx/google2fa-qrcode ^3.0|^4.0`.
* `filament/support` `v5.7.6` `require` (the package that pins the UI runtime): `php ^8.2`, `ext-intl`, `illuminate/contracts ^11.28|^12.0|^13.0`, **`livewire/livewire ^4.1`**, `danharrin/livewire-rate-limiting ^2.0`, `kirschbaum-development/eloquent-power-joins ^4.0`, `nette/php-generator ^4.0`, `league/uri-components ^7.0`, `ryangjchandler/blade-capture-directive ^1.0`, `spatie/invade ^2.0`, `spatie/laravel-package-tools ^1.9`, `symfony/html-sanitizer ^7.0|^8.0`, `symfony/console ^7.0|^8.0`, `blade-ui-kit/blade-heroicons ^2.5`.
* **Official support status:** 5.x is the current documented major (`filamentphp.com/docs/5.x/…` serves without a legacy-version banner). `filamentphp.com/docs/4.x/upgrade-guide` remains published as the v3→v4 guide.
* **Official upgrade procedure** (verbatim from the v5 guide):

  ```bash
  composer require filament/upgrade:"^5.0" -W --dev
  vendor/bin/filament-v5
  # Run the commands output by the upgrade script, they are unique to your app
  composer require filament/filament:"^5.0" -W --no-update
  composer update
  ```

* **Plugin-compatibility warning** (verbatim from the v5 guide): "Some plugins you're using may not be available in v5 just yet. You could temporarily remove them from your `composer.json` file until they've been upgraded, replace them with a similar plugins that are v5-compatible, wait for the plugins to be upgraded before upgrading your app, or even write PRs to help the authors upgrade them."
* **Tailwind requirement is unconditional in v5.** The v4 guide qualifies it — "Tailwind CSS v4.1+, **if you're currently using Tailwind CSS v3.0 with Filament. This doesn't apply if you're just using a Filament panel without a custom theme CSS file.**" — but the v5 guide states "Tailwind CSS v4.0+" with no such carve-out.
* The Filament 5 styling documentation adds a constraint that matters for this repository (`docs/08-styling/01-overview.md`, verbatim): "**A custom theme is required to use Tailwind CSS classes in your own code.** Filament's default compiled stylesheet does not include arbitrary Tailwind classes - it only contains the styles needed for Filament's own UI components." See §9.3.

For reference, `filament/filament` `v4.12.6` (published `2026-08-05T20:40:40+00:00`)
requires `illuminate/contracts ^11.28|^12.0|^13.0` and — critically —
`filament/support` v4.12.6 requires **`livewire/livewire ^3.5`**, *not* v4.
Filament 4 is a Livewire-3 generation. This governs the staging order in §6.

Latest stable `filament/filament` in the 3.x line is **`v3.3.54`**
(`2026-06-12T16:57:16+00:00`), whose `illuminate/*` constraints are
`^10.45|^11.0|^12.0|**^13.0**` — Filament 3.3.53+ was forward-ported to Laravel 13.
The repository is locked at `v3.3.52`, which stops at `^12.0`. This fact enables
the runnable intermediate state described in §6.3 and §17.

### 2.3 Livewire 4

Source: `livewire.laravel.com/docs/upgrading` (v4.x) and Packagist.

* Current stable 4.x: **`v4.3.5`**, published `2026-08-03T04:09:44+00:00`.
* `livewire/livewire` `v4.3.5` `require`: `php ^8.1`; `illuminate/{database,routing,support,validation} ^10.0|^11.0|^12.0|**^13.0**`; `symfony/console ^6.0|^7.0|^8.0`; `symfony/http-kernel ^6.2|^7.0|^8.0`; `laravel/prompts ^0.1.24|^0.2|^0.3`; `league/mime-type-detection ^1.9`.
* The guide's own framing: "Livewire v4 introduces several improvements and optimizations while maintaining backward compatibility wherever possible… Most applications can upgrade to v4 with minimal changes. The breaking changes are primarily configuration updates and method signature changes that only affect advanced usage."
* Official v3 → v4 breaking changes are enumerated and mapped onto this repository in §8.

### 2.4 Tailwind CSS 4

Source: npm registry metadata for `tailwindcss`, `@tailwindcss/vite`,
`@tailwindcss/postcss`, `vite`, `laravel-vite-plugin`.

| Package | Latest | Published | `engines` | Notable peer deps |
|---|---|---|---|---|
| `tailwindcss` | `4.3.3` | 2026-07-16 | — | — |
| `@tailwindcss/vite` | `4.3.3` | 2026-07-16 | — | `vite: ^5.2.0 \|\| ^6 \|\| ^7 \|\| ^8` |
| `@tailwindcss/postcss` | `4.3.3` | 2026-07-16 | — | — |
| `vite` | `8.2.1` | 2026-08-06 | `node: ^20.19.0 \|\| >=22.12.0` | — |
| `laravel-vite-plugin` | `3.1.3` | 2026-07-13 | `node: ^20.19.0 \|\| >=22.12.0` | `vite: ^8.0.0`, `fontaine: ^0.8.0` |

Tailwind 4 is delivered either as a first-class Vite plugin
(`@tailwindcss/vite`, which is Vite-6-compatible and therefore does **not**
force a Vite 8 jump) or as a PostCSS plugin (`@tailwindcss/postcss`, replacing
the `tailwindcss` PostCSS entry). Filament-specific Tailwind requirements are
covered in §9.

**Upstream change vs. the task's framing.** The task named "Laravel 13,
Filament 5, Livewire 4, Tailwind CSS 4" as the current stable releases within
the target majors, and that is exactly what upstream published: no target major
has been superseded, and no approved target needs revision. Two upstream facts
worth reporting because they were not knowable when the task was written:
`laravel-vite-plugin` has moved to a **3.x** line that peer-requires **Vite 8**,
and Filament's own 3.x line gained Laravel 13 support in `v3.3.53`.

---

## 3. Target-state feasibility — mandatory answer

### Can this repository reach all four simultaneously?

```text
Laravel 13
Filament 5
Livewire 4
Tailwind CSS 4
```

## Final classification: **GO WITH PREREQUISITES**

**Why not `BLOCKED`.** Every dependency in the graph — including all 14 direct
Composer dependencies and every Filament-, Livewire- and Laravel-coupled
transitive dependency — has a version that satisfies the four target majors
simultaneously. This is not inferred from package documentation; it is
established by the solver, which resolved the complete graph with exit code 0
(§4.3). No dependency requires replacement or removal to reach the target.

**Why not `GO`.** The target is unreachable by dependency bumps alone. The
following prerequisites are mandatory, each verified against repository
evidence:

1. **PHP floor 8.3+ in every execution environment.** `laravel/framework v13.24.0` requires `php ^8.3`; `composer.json` currently declares `php: ^8.2` (§11).
2. **`laravel/tinker` must move to `^3.0`.** The locked `v2.11.1` caps `illuminate/*` at `^12.0` and is a hard solver blocker for Laravel 13 (§4.1).
3. **PHPUnit must move to `^12.0`.** Both the Laravel 13 upgrade guide and the 13.x skeleton specify PHPUnit 12; PHPUnit 13 requires PHP `>=8.4.1` and is therefore *not* the right target on PHP 8.3 (§4.3, §15).
4. **Four published vendor Blade views must be re-derived, not carried forward.** Their Filament 5 counterparts are restructured (1,286 → 2,604 lines), collapsed (315 → 19 lines) or removed entirely. Two of them are the sole mechanism implementing the `novalidate` mandate in `04-ARCHITECTURE_PRINCIPLES.md` (§7.4).
5. **The Tailwind 4 migration plus proper custom Filament themes for both panels.** Filament 5 lists Tailwind CSS v4.0+ as an unconditional requirement; Filament 4 requires Tailwind v4.1+ once a custom theme CSS file exists; and both the v4 and v5 styling docs require a custom theme for the project's own Tailwind usage in panel Blade/PHP — which this repository has in volume while owning no theme today (§9.1, §9.2). This lands at the Filament 3→4 checkpoint (§17 PR3), on the **current Vite major** (§9.4).
6. **Behavior-preserving UUID handling** for the 18 models using `HasUuids`: preserve UUIDv4 semantics via the Laravel-supported UUIDv4 mechanism (`HasVersion4Uuids` or the exact current equivalent verified at execution time), so the framework major does not silently change identifier generation (§10.3).
7. **A Node-version guarantee in CI and production.** CI has no Node step at all and the production Node version is not verifiable from repository evidence, while the Tailwind 4 toolchain (including the official upgrade tool) requires a modern Node (§12). This is required regardless of the Vite major, and the Vite 8 / `laravel-vite-plugin` 3 jump itself is explicitly **out of GAP-024 scope** (§9.4).

None of these depend on an external event. All are inside the project's control.

---

## 4. Composer solver proof

All solver work below was executed with Composer 2.10.0. Read-only inspections
ran against the real checkout; every experiment that required mutating
`composer.json` ran inside a disposable `git worktree` under `/tmp/solver/**`
that was destroyed afterwards (§4.6).

### 4.1 `why-not` / `prohibits` against the real repository

```console
$ composer why-not laravel/framework '^13.0'
laravel/laravel        dev-develop requires         laravel/framework (^11.31)
filament/actions       v3.3.52     requires         illuminate/contracts (^10.45|^11.0|^12.0)
filament/actions       v3.3.52     requires         illuminate/database (^10.45|^11.0|^12.0)
filament/actions       v3.3.52     requires         illuminate/support (^10.45|^11.0|^12.0)
filament/filament      v3.3.52     requires         illuminate/auth (^10.45|^11.0|^12.0)
… (filament/filament, forms, infolists, notifications, support, tables: 33 lines total) …
laravel/tinker         v2.11.1     requires         illuminate/console (^6.0|^7.0|^8.0|^9.0|^10.0|^11.0|^12.0)
laravel/tinker         v2.11.1     requires         illuminate/contracts (^6.0|^7.0|^8.0|^9.0|^10.0|^11.0|^12.0)
laravel/tinker         v2.11.1     requires         illuminate/support (^6.0|^7.0|^8.0|^9.0|^10.0|^11.0|^12.0)
laravel/framework      13.x-dev    requires         guzzlehttp/guzzle (^7.15.2)
laravel/laravel        dev-develop does not require guzzlehttp/guzzle (but 7.10.6 is installed)
```

```console
$ composer why-not livewire/livewire '^4.0'
laravel/laravel  dev-develop requires livewire/livewire (^3.0)
filament/support v3.3.52     requires livewire/livewire (^3.5)
```

```console
$ composer why-not filament/filament '^5.0'
laravel/laravel   dev-develop requires         filament/filament (^3.2)
filament/filament v5.7.6      requires         filament/actions (self.version)
laravel/laravel   dev-develop does not require filament/actions (but v3.3.52 is installed)
… (repeated for forms, infolists, notifications, support, tables, widgets) …
```

`composer prohibits` returned identical output for all three targets.

As the task anticipated, these commands are limited by the *existing* root
constraints: they correctly identify `filament/*` v3.3.52, `laravel/tinker`
v2.11.1 and the root constraints themselves as blockers, but they cannot show
whether the target graph is solvable. That required the disposable-worktree
experiments below.

### 4.2 An unexpected, high-severity blocker: the current graph is no longer resolvable at all

Before modelling the target, the *unmodified* repository was dry-run resolved in
a pristine worktree. It **fails**:

```console
$ cd /tmp/solver/pristine   # untouched composer.json from 9713d03
$ composer update --dry-run -W --no-scripts --no-plugins
Loading composer repositories with package information
Updating dependencies
Your requirements could not be resolved to an installable set of packages.

  Problem 1
    - Root composer.json requires laravel/framework ^11.31, found laravel/framework[v11.31.0, ..., v11.55.0]
      but these were not loaded, because they are affected by security advisories
      ("PKSA-m5cs-t1y6-qpcs", "PKSA-3r5d-mb8f-1qw9", "PKSA-mdq4-51ck-6kdq",
       "PKSA-8qx3-n5y5-vvnd", "PKSA-q46n-4fdk-zjr4", "PKSA-qzrn-rnz3-85w1").
  Problem 2
    - Root composer.json requires filament/filament ^3.2 -> satisfiable by filament/filament[v3.3.52, v3.3.53, v3.3.54].
    - filament/filament v3.3.52 requires filament/forms v3.3.52 -> found filament/forms[v3.3.52]
      but these were not loaded, because they are affected by security advisories ("PKSA-n7tx-gkfb-14yj").
    …
EXIT=2
```

Composer's default advisory policy refuses to load **every**
`laravel/framework` 11.x release and the exact locked `filament/forms` version
when the full graph is re-resolved. The precise operational consequences are:

* `composer install` from the committed lockfile still works, so CI and `deploy.sh` are not broken today:

  ```console
  $ composer install --dry-run --no-scripts --no-plugins
  … - Installing spatie/laravel-permission (6.25.0)
  EXIT=0
  ```
* **Full dependency re-resolution of the current Laravel-11 graph — and any update that requires re-resolving the advisory-blocked Laravel 11 line — is blocked** by the current Composer security policy, as the EXIT=2 output above shows literally.
* **Partial updates that leave the advisory-affected locked packages untouched still work.** Composer's 2.9.2 changelog (released 2025-11-19) explicitly records: "Fixed partial updates failing when another package in the lock file has a known security advisory" (composer/composer #12626). Verified against this repository during the correction pass (2026-08-08), on the untouched `composer.json`/`composer.lock`:

  ```console
  $ composer update predis/predis --dry-run --no-scripts --no-plugins
  Lock file operations: 0 installs, 1 update, 0 removals
    - Upgrading predis/predis (v3.4.2 => v3.5.1)
  Found 21 security vulnerability advisories affecting 5 packages.
  EXIT=0
  ```

  It is therefore **too broad to claim that no dependency whatsoever can be updated** on `develop`. Routine partial updates of packages outside the blocked lines remain possible; what is blocked is the full re-solve and anything that must re-resolve `laravel/framework` 11.x or the advisory-affected `filament/forms` v3.3.52.
* Composer also provides a `--no-security-blocking` flag (added in 2.9.2, same changelog). It is noted here only as an available Composer mechanism for exceptional situations — it is **not** the migration strategy, and it is not a reason to stay on Laravel 11.

This corrected framing does not weaken GAP-024. Laravel 11 itself carries
advisories with **no fix on the 11.x line** (§14.3) and is out of security
support; the advisory policy blocking its full re-resolution is a symptom of
that, and the remediation is the framework upgrade, not policy suppression.

### 4.3 Target-state resolution — **succeeds**

Disposable worktree `/tmp/solver/target`, modelling the intended target root
constraints (`php ^8.3`, `laravel/framework ^13.0`, `filament/filament ^5.0`,
`livewire/livewire ^4.0`, `laravel/tinker ^3.0`, `phpunit/phpunit ^12.0`;
everything else untouched):

```console
$ composer update --dry-run -W --no-scripts --no-plugins
Loading composer repositories with package information
Updating dependencies
Lock file operations: 14 installs, 79 updates, 6 removals
  - Removing doctrine/dbal (4.4.3)
  - Removing doctrine/deprecations (1.1.6)
  - Removing psr/cache (3.0.0)
  - Removing sebastian/code-unit (3.0.3)
  - Removing sebastian/code-unit-reverse-lookup (4.0.1)
  - Removing spatie/color (1.8.0)
  - Upgrading filament/actions (v3.3.52 => v5.7.6)
  - Upgrading filament/filament (v3.3.52 => v5.7.6)
  - Upgrading filament/forms (v3.3.52 => v5.7.6)
  - Upgrading filament/infolists (v3.3.52 => v5.7.6)
  - Upgrading filament/notifications (v3.3.52 => v5.7.6)
  - Locking filament/query-builder (v5.7.6)
  - Locking filament/schemas (v5.7.6)
  - Upgrading filament/support (v3.3.52 => v5.7.6)
  - Upgrading filament/tables (v3.3.52 => v5.7.6)
  - Upgrading filament/widgets (v3.3.52 => v5.7.6)
  - Upgrading laravel/framework (v11.54.0 => v13.24.0)
  - Upgrading laravel/tinker (v2.11.1 => v3.0.2)
  - Upgrading livewire/livewire (v3.8.0 => v4.3.5)
  - Upgrading phpunit/phpunit (11.5.55 => 12.5.33)
  - Upgrading guzzlehttp/guzzle (7.10.6 => 7.15.3)
  - Upgrading guzzlehttp/psr7 (2.10.4 => 2.13.0)
  - Upgrading nesbot/carbon (3.11.4 => 3.13.1)
  - Upgrading league/commonmark (2.8.2 => 2.9.0)
  … (93 lock operations total) …
  - Installing spatie/laravel-permission (6.25.0)
8 package suggestions were added by new dependencies
EXIT=0
```

**The target dependency graph is solvable.** Laravel `v13.24.0` +
Filament `v5.7.6` + Livewire `v4.3.5` on PHP `^8.3`, with
`spatie/laravel-permission` remaining at its currently locked `6.25.0`.

### 4.4 Additional solver scenarios

Run in `/tmp/solver/bridge` to establish which orderings are viable.

| # | Modelled root constraints | Result | Resolved versions of interest |
|---|---|---|---|
| 1 | `php ^8.3`, Laravel `^13.0`, tinker `^3.0`, PHPUnit `^12.0`, **Filament `^3.2` untouched**, Livewire `^3.0` untouched | **EXIT=0** | `laravel/framework v13.24.0`, `filament/* v3.3.54`, `livewire/livewire v3.8.3`, `doctrine/dbal 4.4.4` retained |
| 2 | Laravel `^11.31` untouched, PHP `^8.2` untouched, **Filament `^4.0`**, Livewire `^3.0` | **EXIT=2** | Fails on the Laravel 11 advisory block from §4.2 — *not* on a Filament constraint |
| 3 | `php ^8.3`, Laravel `^13.0`, tinker `^3.0`, PHPUnit `^12.0`, **Filament `^4.0`**, Livewire `^3.0` (**bridge state**) | **EXIT=0** | `laravel/framework v13.24.0`, `filament/* v4.12.6`, `livewire/livewire v3.8.3`; `doctrine/dbal`, `spatie/color` removed |
| 4 | Target state **plus `spatie/laravel-permission ^8.0`** | **EXIT=0** | Same as §4.3 with permission at 8.x |

Scenario 1 is the most operationally valuable result in this audit: **Laravel 13
can be reached with zero Filament or Livewire code changes**, because Filament
`v3.3.54` already declares `illuminate/* ^13.0`. Scenario 2 shows the *reverse*
ordering (Filament first, framework later) is not available — not for a Filament
reason, but because that update requires re-resolving the advisory-blocked
Laravel 11 line, which Composer's security policy refuses (§4.2).

### 4.5 Per-blocker record

| Blocking package | Installed | Constraint that blocks | Root or transitive | Latest stable | Does updating resolve it? | Replacement/removal required? |
|---|---|---|---|---|---|---|
| `laravel/framework` | `v11.54.0` | root `^11.31`; also blocked by 6 advisories from all of 11.x | root | `v13.24.0` | Yes — bump root to `^13.0` | No |
| `laravel/tinker` | `v2.11.1` | `illuminate/* ^6.0…^12.0` | root (`^2.9`) | `v3.0.2` | Yes — `^3.0` adds `^13.0` | No |
| `filament/filament` + 7 siblings | `v3.3.52` | `illuminate/* ^10.45\|^11.0\|^12.0` | root (`^3.2`) → transitive siblings | `v5.7.6` | Yes. Also resolvable *within v3* by moving to `v3.3.54` (adds `^13.0`) | No |
| `filament/forms` | `v3.3.52` | advisory `PKSA-n7tx-gkfb-14yj` blocks the exact locked version | transitive | `v5.7.6` | Yes — any of `v3.3.53`, `v3.3.54`, v4, v5 | No |
| `filament/support` | `v3.3.52` | `livewire/livewire ^3.5` | transitive | `v5.7.6` | Yes — v5's `filament/support` requires `livewire ^4.1` | No |
| `phpunit/phpunit` | `11.5.55` | root `^11.0.1` | root (dev) | `13.3.0` (needs PHP `>=8.4.1`) | Yes — target `^12.0` (`12.5.33`, PHP `>=8.3`) | No |
| `guzzlehttp/guzzle` | `7.10.6` | Laravel 13 requires `^7.8.2`; 9 advisories at the locked version | transitive | `7.15.3` | Yes | No |
| `doctrine/dbal` | `4.4.3` | v3-only `filament/support` dependency | transitive | — | Removed by the target resolution | No — but re-add explicitly if the application ever needs it (per the Filament v4 guide) |
| `spatie/laravel-permission` | `6.25.0` | **not a blocker** — `6.25.0` already declares `illuminate/* ^8.12\|…\|^13.0` | root (`^6.25`) | `8.3.0` (`php ^8.3`, `illuminate ^12.0\|^13.0`) | Not required. §4.4 scenario 4 confirms `^8.0` also resolves | No |
| `anourvalar/eloquent-serialize` | `1.3.8` | appears in the §4.2 blocked output only because it transitively references `laravel/framework`; `1.3.6+` already allow `^13.0` | transitive (via `filament/actions`) | `1.3.11` | Yes, automatically | No |

**Every remaining direct dependency is already Laravel-13-clean at a currently
published version.** Verified from Packagist `require` blocks:
`laravel/pail 1.2.7` (`illuminate/* ^10.24|^11.0|^12.0|^13.0`),
`laravel/sail 1.65.0` (`…|^13.0`),
`danharrin/livewire-rate-limiting 2.2.1` (`illuminate/support ^9.0|…|^13.0`),
`kirschbaum-development/eloquent-power-joins 4.3.3` (`^11.42|^12.0|^13.0`),
`laravel/pint 1.30.4` (`php ^8.2.0`, framework-independent),
`predis/predis 3.5.1` (`php ^7.2 || ^8.0`),
`mockery/mockery 1.6.12` (`php >=7.3`),
`nunomaduro/collision 8.9.5` (`php ^8.2.0`, `symfony/console ^7.4.14 || ^8.1.1`),
`fakerphp/faker 1.24.1` (`php ^7.4 || ^8.0`),
`laravel-lang/lang 15.34.0` (`php ^8.2`, framework-independent via `laravel-lang/publisher ^16.0`).

### 4.6 Disposable-environment hygiene

Four worktrees were created and all four destroyed:

```console
$ git worktree list      # after cleanup
/workspace  9713d03 [cursor/gap-024-target-stack-feasibility-audit]

$ git status --short     # audit branch
(empty)

$ git diff --stat origin/develop...HEAD   # before writing the report
(empty)
```

No experimental Composer, lockfile, Rector or application change survives on the
audit branch.

---

## 5. Third-party dependency blockers

### 5.1 Filament plugins

**There are none.** The project depends on `filament/filament` only; no
third-party `filament/*` plugin and no non-Filament package that requires a
`filament/*` package appears in `composer.json`. This was independently
confirmed by the official upgrade tool's own detector, which enumerates
third-party plugins by scanning `vendor/{package}/composer.json` for a
`filament/` requirement (`packages/upgrade/src/check-compatibility.php`,
lines 79–112) and reported no incompatible plugins.

This is a significant de-risking factor: the single most common cause of a
stalled Filament major upgrade — an unmaintained plugin with no v5 release —
does not apply here. It also means the plugin-compatibility warning quoted in
§2.2 has no target in this repository, and no plugin needs to be removed,
replaced or escalated for a later decision.

The first-party Filament packages that the target resolution adds
(`filament/schemas`, `filament/query-builder`) are `self.version` siblings, not
third-party plugins.

### 5.2 Every Laravel/Filament/Livewire/PHP-coupled dependency, with maintenance health

| Category | Package | Locked | Latest stable | Filament 5 support | Laravel 13 support | Livewire 4 support | Release health (latest publish) |
|---|---|---|---|---|---|---|---|
| Permissions | `spatie/laravel-permission` | `6.25.0` | `8.3.0` | n/a (framework-level) | **Yes at both `6.25.0` and `8.3.0`** | n/a | Active — v6 line still published `2026-03-17`; v8 `2026-07-03` |
| UI framework | `filament/filament` | `v3.3.52` | `v5.7.6` | — | Yes (v5 `illuminate ^11.28\|^12\|^13`) | Yes (`filament/support` v5 → `livewire ^4.1`) | Very active — v5 and v4 both published `2026-08-05`; v3 last `2026-06-12` |
| UI runtime | `livewire/livewire` | `v3.8.0` | `v4.3.5` | Required by Filament 5 | Yes (`illuminate/* …\|^13.0`) | — | Very active — v4 `2026-08-03`, v3 `2026-07-31` |
| Framework | `laravel/framework` | `v11.54.0` | `v13.24.0` | Yes | — | Yes | Very active — `2026-08-04` |
| Laravel helper | `laravel/tinker` | `v2.11.1` | `v3.0.2` | n/a | **Only at `^3.0`** | n/a | Active — `2026-03-17` |
| Laravel helper | `laravel/pail` | `1.2.7` | `1.2.7` | n/a | Yes (`^13.0`) | n/a | Active — `2026-05-20` |
| Laravel helper | `laravel/sail` | `1.61.0` | `1.65.0` | n/a | Yes (`^13.0`) | n/a | Active — `2026-08-03` |
| Localization | `laravel-lang/lang` | `15.32.0` | `15.34.0` | n/a | Framework-independent (`php ^8.2` + `laravel-lang/publisher ^16.0`) | n/a | Very active — `2026-08-03` |
| Queue/runtime | `predis/predis` | `v3.4.2` | `v3.5.1` | n/a | Framework-independent | n/a | Active — `2026-06-11` |
| Filament transitive | `danharrin/livewire-rate-limiting` | `v2.2.0` | `v2.2.1` | Yes (`filament/support` v5 → `^2.0`) | Yes (`illuminate/support …\|^13.0`) | Yes | Active — `2026-08-06` |
| Filament transitive | `kirschbaum-development/eloquent-power-joins` | `4.3.2` | `4.3.3` | Yes (`^4.0`) | Yes (`^11.42\|^12\|^13`) | n/a | Active — `2026-07-23` |
| Testing | `phpunit/phpunit` | `11.5.55` | `13.3.0` | n/a | **`^12.0`** is the Laravel-13 target; `13.3.0` requires `php >=8.4.1` | n/a | Very active — 12.5.33 `2026-07-28` |
| Testing | `mockery/mockery` | `1.6.12` | `1.6.12` | n/a | Framework-independent (`php >=7.3`) | n/a | **Stale — last publish `2024-05-16`.** Still the version the Laravel 13 skeleton specifies (`^1.6`), so not a blocker; worth watching |
| Dev tooling | `laravel/pint` | `1.29.1` | `1.30.4` | n/a | Framework-independent | n/a | Very active — `2026-08-05` |
| Dev tooling | `nunomaduro/collision` | `8.9.4` | `8.9.5` | n/a | Yes — `^8.6` is the Laravel 13 skeleton target; no v9 line exists | n/a | Active — `2026-07-15` |
| Dev tooling | `fakerphp/faker` | `1.24.1` | `1.24.1` | n/a | Framework-independent | n/a | **Stale — last publish `2024-11-21`.** Still the skeleton target (`^1.23`); not a blocker |

**No dependency requires removal or replacement, and no dependency needs to be
escalated as an unresolvable blocker.** The only two stale packages
(`mockery/mockery`, `fakerphp/faker`) are stale *upstream* and are the exact
versions the official Laravel 13 skeleton specifies, so their staleness is not
project-specific migration debt.

### 5.3 Optional dependency changes the migration may consider (not required)

* `spatie/laravel-permission ^8.0` — resolves cleanly (§4.4 scenario 4) but requires `php ^8.3` and is a **two-major** jump (6 → 7 → 8) with its own breaking changes. Since `6.25.0` already supports Laravel 13, this should be a **separate, later decision**, not bundled into the framework migration.
* `laravel/pao ^1.0.6` (latest `v1.1.3`, `2026-07-29`, `php ^8.3`) — a new dev dependency in the Laravel 13 skeleton ("Agent-optimized output for PHP testing tools"). Optional.
* `laravel/boost ^2.0` — the Laravel 13 upgrade guide's "Upgrading Using AI" section references it (`/upgrade-laravel-v13` slash command). Optional tooling, not a dependency of the target state.

---

## 6. Filament 3 → Filament 5 migration route

### Decision: **Route B — technically staged `Filament 3 → Filament 4 → Filament 5`**, with Filament 4 held only as a migration bridge.

Route B was **not** chosen because version 4 exists. It was chosen on the
strength of four independent pieces of evidence, the first of which is decisive.

### 6.1 Decisive evidence: the official v5 upgrade tool automates nothing on a v3 codebase

`filament/upgrade` is Rector-based. Its behavior is entirely determined by
`src/rector.php`. Comparing the two published configs:

```console
$ wc -l rector-v5.php rector-v4.php
   10 rector-v5.php      # from filamentphp/filament v5.7.6
  343 rector-v4.php      # from filamentphp/filament v4.12.6
```

The **entire** v5 Rector config
(`packages/upgrade/src/rector.php` at tag `v5.7.6`):

```php
<?php

use Filament\Upgrade\Rector;
use Rector\Config\RectorConfig;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->rules([
        Rector\SimpleMethodChangesRector::class,
    ]);
};
```

That single rule, read from
`packages/upgrade/src/Rector/SimpleMethodChangesRector.php` at the same tag,
does exactly one thing: it widens the `$action` parameter type to
`string|UnitEnum` on `Filament\Resources\Resource::getAuthorizationResponse()`,
`::can()` and `::authorize()`.

The v4 config, by contrast, carries **231 `RenameClassRector` mappings**, **19
`RenameStringRector` Blade-view-name mappings**, one `RenamePropertyRector`,
**20 `RenameMethodRector` method renames**, an `AddInterfaceByTraitRector` and an
`AddTraitByTraitRector` block, plus six bespoke Rector rules
(`AddPanelParamToRouteMethodsRector`, `ReplaceStringPanelParamWithPanelParamRector`,
`SimpleMethodChangesRector`, `SimplePropertyChangesRector`,
`RenameSchemaParamToMatchTypeRector`,
`ConvertStaticConfigurationToConfigureUsingFunction`).

Every mapping this project actually needs lives **only** in the v4 config —
`Filament\Forms\Form` → `Filament\Schemas\Schema`,
`Filament\Infolists\Infolist` → `Filament\Schemas\Schema`,
`Filament\Tables\Actions\*` → `Filament\Actions\*`,
`Schema::schema()` → `Schema::components()`,
`Table::actions()` → `recordActions()`,
`Table::bulkActions()` → `toolbarActions()`.

This was confirmed empirically, not just by reading the configs. Both configs
were run in dry-run mode against this repository's real `app/` directory (§13):

* v4 config: **`[OK] 115 files would have been changed (dry-run) by Rector`**
* v5 config: **`[OK] Rector is done!`** — zero files changed

Running only `vendor/bin/filament-v5` on this Filament 3 codebase would
therefore perform essentially no migration, and would leave 115 files requiring
manual conversion.

### 6.2 Supporting evidence: the v5 upgrade guide presupposes a v4 starting point

The v5 guide (55 lines total) contains only `## New requirements`, `## Running
the automated upgrade script` and `## Upgrading Livewire`. It has **no**
"Breaking changes that must be handled manually" section. The v4 guide is 785
lines and contains the entire manual breaking-change catalogue, the
`php artisan filament:upgrade-directory-structure-to-v4` command, and the
`filament-config` / `file_generation` compatibility-flag mechanism used to opt
out of v4's new file layout.

Additionally, the v5 tool's own preflight
(`packages/upgrade/src/check-compatibility.php`) validates only PHP `>= 8.2` and
`laravel/framework >= 11.28.0` — it does **not** verify that the application is
already on Filament 4. So the tool will run happily against a v3 codebase and
silently under-migrate it. The guide's mechanics are safe only when its stated
precondition (a v4 app) actually holds.

### 6.3 Supporting evidence: the intermediate state is independently testable

Route B produces a genuinely runnable, verifiable intermediate state, confirmed
by §4.4 scenario 3: Laravel `v13.24.0` + Filament `v4.12.6` + Livewire `v3.8.3`
resolves with exit code 0. That means the enormous v3→v4 API surface (115 files)
can be landed and regression-tested **while Livewire stays on v3**, isolating
Filament API breakage from Livewire runtime breakage. Route A would force the
Filament API rewrite, the Livewire 4 runtime change and the Tailwind 4 change to
be debugged simultaneously against a single unverifiable diff.

### 6.4 Supporting evidence: Livewire generation boundaries force the split anyway

`filament/support v4.12.6` requires `livewire/livewire ^3.5`;
`filament/support v5.7.6` requires `livewire/livewire ^4.1`. Filament 4 is
structurally a Livewire-3 generation and Filament 5 a Livewire-4 generation.
The Livewire major bump is therefore *inherently* coupled to the 4→5 step, not
the 3→4 step. Route B aligns the PR decomposition with a boundary upstream has
already drawn.

### 6.5 Plugin compatibility is not a factor either way

Because there are zero third-party Filament plugins (§5.1), the usual
plugin-driven argument for staging does not apply here. The route decision rests
entirely on upgrade tooling coverage, sequential breaking-change volume and
intermediate testability.

### 6.6 Explicit statement of the target

Filament 4 is a **temporary migration bridge only**. It must not be presented
as, or allowed to become, the project's resting UI framework generation. The
approved long-term stack remains **Filament 5**. The staged plan in §17 requires
that the 4→5 step be scheduled as part of the same migration programme, not
deferred indefinitely.

---

## 7. Filament breaking-change impact on this repository

Scope inspected: `app/Filament/**` (85 PHP files), both panel providers,
15 Resource classes, 51 resource Page classes, 5 RelationManagers, 4 standalone
Pages, `app/Support/Brand.php`, `app/Support/FilamentTableToolbar.php`,
`app/Support/ProductLightbox.php`, `resources/views/filament/**` (13 Blade files),
`resources/views/components/filament/**`, `resources/views/vendor/filament-*`
(4 published overrides), and 23 test files that reference Filament.

Structural counts:

```console
Resource classes                    15
RelationManager classes              5
Page classes (app/Filament/Pages)    4
Resource Page classes               51
Total app/Filament php files        85
```

### 7.1 Namespace and schema-unification impact (the bulk of the work)

| Official breaking change (v3→v4) | Project paths | Occurrences | Upgrader support | Manual work | Regression risk |
|---|---|---|---|---|---|
| `Filament\Forms\Form` → `Filament\Schemas\Schema`; `form(Form $form)` → `form(Schema $schema)` | `app/Filament/**` Resources, Pages, RelationManagers | **19** imports / **19** signatures | **Automated** (`RenameClassRector` + `RenameSchemaParamToMatchTypeRector`) | Verify closures typed against `Form` | Low |
| `Filament\Infolists\Infolist` → `Filament\Schemas\Schema`; `infolist(Infolist $infolist)` → `infolist(Schema $schema)` | Resources incl. `ConnectorAccountResource.php` | **6** imports / **5** signatures | **Automated** | Same | Low |
| `Schema::schema()` → `Schema::components()` (top-level only; nested `->schema()` on layout components stays) | `app/Filament/**` | **54** total `->schema(` calls | **Automated** (`RenameMethodRector` on `Schema::class`) | Review each of the 54 to confirm the rule fired only on top-level calls | **Medium** — a missed or over-eager rewrite silently empties a form/infolist |
| `Filament\Infolists\Components\*` → `Filament\Schemas\Components\*` (Section, Grid, Group, Tabs, Fieldset, View, Split→Flex, Livewire) | inline `Infolists\Components\` references | **68** | **Automated** | Entry components (`TextEntry`, `IconEntry`) stay in `Filament\Infolists` — split must be correct | Low |
| `Filament\Forms\Components\*` layout classes → `Filament\Schemas\Components\*` | inline `Forms\Components\` references | **135** | **Automated** | Field components stay in `Filament\Forms\Components` | Low |
| `Filament\Tables\Actions\*` → `Filament\Actions\*` | inline `Tables\Actions\` in **22 files** | **41** | **Automated** | `app/Filament/Resources/TagResource/Support/GuardedDeleteTagAction.php:8` aliases `Filament\Tables\Actions\DeleteAction as TableDeleteAction` — aliased imports need review | **Medium** |
| `Table::actions()` → `recordActions()`; `bulkActions()` → `toolbarActions()`; also `pushActions`, `actionsColumnLabel`, `actionsAlignment`, `actionsPosition`, `pushBulkActions` | `app/Filament/**` | `->actions(` **19**, `->bulkActions(` **19**, `->headerActions(` **5** | **Automated** | Toolbar-action *placement* semantics changed in v4 — visual verification required | **High (visual)** |
| `Filament\Forms\Get` / `Set` → `Filament\Schemas\Components\Utilities\{Get,Set}` | `app/Filament/**` | **6** | **Automated** | — | Low |
| `Filament\Tables\Columns\*` (unchanged namespace) | inline `Tables\Columns\` | **140** | n/a | Enum moves: `TextColumn\TextColumnSize` → `Support\Enums\TextSize`, `IconColumn\IconColumnSize` → `Support\Enums\IconSize` | Low |
| `Filament\Tables\Filters\*` (unchanged namespace); `BaseFilter::form()` → `schema()` | inline `Tables\Filters\` | **25** | **Automated** (method rename) | — | Low |
| `InteractsWithForms` / `InteractsWithTable` / `InteractsWithInfolists` now additionally require `HasActions` + `InteractsWithActions` | custom Pages / Livewire-backed Filament components | — | **Automated** (`AddInterfaceByTraitRector`, `AddTraitByTraitRector`) | Verify no duplicate trait/interface | Low |
| `Filament\Pages\Auth\Login` → `Filament\Auth\Pages\Login` | `app/Filament/Cabinet/Pages/Auth/Login.php`; referenced from `CabinetPanelProvider.php:5` | 1 class + 1 reference | **Automated** | Cabinet login is the `customer`-guard entry point — must be functionally retested | **High** |
| `Filament\Support\Enums\MaxWidth` → `Width`; `ActionSize` → `Size`; `Filament\Tables\Enums\ActionsPosition` → `RecordActionsPosition` | as used | — | **Automated** | — | Low |
| `Filament\Widgets\StatsOverviewWidget\Card` → `Stat`; `Widget::$filters` → `$pageFilters` | `Filament\Widgets` imports: **1** | 1 | **Automated** | Only `Widgets\AccountWidget::class` is registered (admin panel) | Low |

### 7.2 Static-property → method / type-widening impact

Filament 4 widened or converted a large set of `protected static` Resource and
Page properties. Actual declarations in `app/Filament/**`:

| Static property | Declarations | Change | Upgrader |
|---|---|---|---|
| `$resource` | 51 | unchanged | n/a |
| `$navigationIcon` | **19** | type widened to `string \| \BackedEnum \| null` | **Automated** (`SimplePropertyChangesRector`) — confirmed in the dry-run diff for `ConnectorAccountResource.php` |
| `$navigationSort` | 18 | unchanged | n/a |
| `$navigationGroup` | **17** | type widened to `string \| \UnitEnum \| null` | **Automated** |
| `$model` | 15 | unchanged | n/a |
| `$pluralModelLabel` | 14 | unchanged | n/a |
| `$modelLabel` | 14 | unchanged | n/a |
| `$title` | 9 | unchanged | n/a |
| `$navigationLabel` | 8 | unchanged | n/a |
| `$view` | 5 | unchanged | n/a |
| `$relationship` | 5 | unchanged | n/a |
| `$slug` | 1 | unchanged | n/a |
| `$hasTitleCaseModelLabel` | 1 | review against v4 config defaults | Partial |

`ConvertStaticConfigurationToConfigureUsingFunction` (v4 Rector) additionally
converts static configuration blocks into `configureUsing()` closures where
applicable.

### 7.3 Panel-provider and directory-structure impact

* `AddPanelParamToRouteMethodsRector` and `ReplaceStringPanelParamWithPanelParamRector` (v4 Rector) rewrite route-method signatures; both panel providers appeared in the dry-run's changed-file list (`app/Providers/Filament` — 2 files).
* `TablesRenderHook::TOOLBAR_TOGGLE_COLUMN_TRIGGER_AFTER` — used at `CabinetPanelProvider.php:50` — **still exists in Filament 5** but as a deprecated alias:

  ```php
  // packages/tables/src/View/TablesRenderHook.php @ v5.7.6
  const TOOLBAR_COLUMN_MANAGER_TRIGGER_AFTER = 'tables::toolbar.toggle-column-trigger.after';
  const TOOLBAR_TOGGLE_COLUMN_TRIGGER_AFTER = self::TOOLBAR_COLUMN_MANAGER_TRIGGER_AFTER;
  ```

  Manual, low-risk: rename to `TOOLBAR_COLUMN_MANAGER_TRIGGER_AFTER`.
* `PanelsRenderHook::BODY_END` and `PanelsRenderHook::STYLES_AFTER` (used by `ProductLightbox::bodyEndHook()` and `FilamentTableToolbar::stylesHookName()`) must be re-verified against the v5 `PanelsRenderHook` constant list.
* `app/Support/Brand.php` returns `Color::Amber` — a **constant**, not `Color::hex()`/`Color::rgb()`. The v4 Rector renames `Color::hex` → `generateV3Palette` and `Color::rgb` → `generateV3Palette`; **neither applies here** (`Color::` occurrences in `app/`: 1). No palette-regeneration risk.
* Filament 4 introduced a new default resource/cluster directory layout. The project can either keep the v3 layout via `filament-config`'s `file_generation.flags` (`PANEL_RESOURCE_CLASSES_OUTSIDE_DIRECTORIES`, `EMBEDDED_PANEL_RESOURCE_SCHEMAS`, `EMBEDDED_PANEL_RESOURCE_TABLES`, `PARTIAL_IMPORTS`) or migrate with `php artisan filament:upgrade-directory-structure-to-v4`. The guide warns that command "is not able to perfectly update any references to classes in the same namespace". **Recommendation: keep the v3 layout via config flags** during the migration, to hold the diff to semantics rather than file moves; treat the layout migration as separate optional cleanup.
* `filament-config`'s `default_filesystem_disk` changed from `FILAMENT_FILESYSTEM_DISK` to `FILESYSTEM_DISK` in v4. The project has no published `config/filament.php`, so this must be published and pinned to preserve v3 behavior.

### 7.4 Published vendor Blade overrides — **the largest verified blocker**

The repository forks four upstream Filament 3 Blade templates. Diffed against
the installed `vendor/filament/**` sources at `v3.3.52`:

| Published override | Lines | Upstream v3 source | Changed lines vs upstream | Purpose of the fork |
|---|---|---|---|---|
| `resources/views/vendor/filament-tables/index.blade.php` | **1287** | `vendor/filament/tables/resources/views/index.blade.php` (1286) | **65** | Toolbar layout: search becomes a direct `flex-1` child instead of sitting inside the `ms-auto` group; filters/column-toggle move to a `shrink-0` right group with `px-4 sm:px-6` |
| `resources/views/vendor/filament-actions/components/modals.blade.php` | **315** | `vendor/filament/actions/resources/views/components/modals.blade.php` (315) | **10** | Adds `novalidate` to 5 `<form wire:submit.prevent="…">` elements |
| `resources/views/vendor/filament-tables/components/search-field.blade.php` | **41** | upstream 46 | **9** | Removes the `magnifying-glass` `inline-prefix` so the input's left edge aligns with the first table column |
| `resources/views/vendor/filament-panels/components/form/index.blade.php` | **15** | upstream 14 | **1** | Adds `novalidate` |
| **Total** | **1,658** | — | **~85** | — |

In Filament 5 these four upstream templates have been restructured beyond
mechanical reconciliation:

| View | Filament 3 upstream | Filament 5 upstream | Status |
|---|---|---|---|
| `filament-tables::index` | 1,286 lines | **2,604 lines** (`packages/tables/resources/views/index.blade.php`) | Rewritten and roughly doubled |
| `filament-actions::components.modals` | 315 lines, contains 5 `<form wire:submit.prevent>` | **19 lines** (`packages/actions/resources/views/components/modals.blade.php`) — a `wire:partial` wrapper that loops `$action->toModalHtmlable()`; contains **no `<form>` at all** | Collapsed; forms moved to `action-modal.blade.php` |
| `filament-panels::components.form.index` | 14 lines with the panel `<form>` | **does not exist** — there is no `components/form` directory under `packages/panels/resources/views` in v5 | Removed |
| `filament-tables::components.search-field` | 46 lines | 46 lines | Still present; content must be re-diffed |

Two consequences make this the single largest risk in the migration:

1. **~85 lines of intentional divergence must be re-derived from 2,600+ lines of rewritten upstream Blade**, entirely by hand. The Rector-based upgrade tool processes PHP under `app/` only; it does not read, rewrite or even warn about `resources/views/vendor/**`.
2. **The `novalidate` mandate can regress silently.** `docs/04-ARCHITECTURE_PRINCIPLES.md`, `## Filament form validation standard`, requires that "Every Filament panel form must render with `novalidate` on the `<form>` element so browser-native constraint validation never overrides application locale and inline field errors." Filament 5 provides **no** native option for this — `novalidate` occurrences in Filament 5's PHP/Blade source (excluding bundled `dist/` JavaScript): **0**, exactly as in Filament 3. Meanwhile the two views the project forks to achieve it are removed or emptied of forms. A stale published override at a view path that no longer resolves is **dead code that fails silently**: no error, no warning, and every panel form quietly regains browser-native validation — breaking the locale-correct inline-error behavior that `04` mandates and that the project's own validation-message design depends on.

   In Filament 5 the `<form>` elements the project needs to reach have moved: `packages/support/resources/views/components/modal/index.blade.php:196` emits `wire:submit.prevent="{!! $wireSubmitHandler !!}"`, and `packages/actions/resources/views/action-modal.blade.php:60` sets `:wire:submit.prevent="$actionLivewireCallMountedActionName"`. The re-derivation therefore targets a different package (`filament/support`, `filament/actions`) and a different view namespace than today.

**A regression test for this already exists and is the migration's tripwire.**
`tests/Feature/FilamentFormValidationTest::test_panel_forms_render_with_novalidate`
(lines 220–231) performs real HTTP `GET` requests against `/admin/users/create`
and `/admin/price-inspector` and asserts `->assertSee('novalidate', false)` on
each. That is exactly the assertion that would fail the moment a stale published
override stops resolving — so the silent-regression risk described above is
**already instrumented**, and the correct instruction is *keep this test green
through both Filament steps*, not "write a new test".

Two gaps in its current coverage should be closed as part of the migration
rather than after it: it exercises only the `/admin` panel (not the
`customer`-guard `/cabinet` panel) and only page-level forms (not the modal
forms that `filament-actions::components.modals` governs — the fork whose
upstream counterpart drops from 315 lines to 19 and loses its `<form>`
elements entirely). Extending it to both panels and to at least one modal form
is the cheapest way to make the tripwire cover the full surface the four forks
own.

**The re-derivation itself remains an explicit, human-reviewed migration step,
not a mechanical file copy.** For each of the four forks the migration must
first investigate whether a **supported public Filament extension point** (a
render hook, a component slot, a configuration method, or a smaller targeted
view override) can satisfy the normative behavior — and re-derive a large
vendor-view fork **only where no public extension can**. Reproducing a
1,000+-line fork against a rewritten upstream template is the last resort, not
the default, because every re-derived line re-acquires the silent-staleness
failure mode described above.

### 7.5 Custom Filament Blade views

13 files under `resources/views/filament/**` plus
`resources/views/components/filament/data-list-toolbar.blade.php` (114 lines) and
`resources/views/components/price-inspector-nav-link.blade.php`. Largest:
`filament/pages/field-matrix.blade.php` (245), `filament/pages/price-inspector.blade.php`
(196), `filament/pages/governance.blade.php` (166),
`filament/resources/customer-resource/pages/preview-as-customer.blade.php` (113).

Filament Blade component usage in project views (each requires v5 API verification):

```console
      9 x-filament::button          5 x-filament::input.wrapper     2 x-filament::modal
      9 x-filament::badge           4 x-filament::icon              2 x-filament::input
      5 x-filament-panels::page     3 x-filament::input.select      2 x-filament::icon-button
      2 x-filament::section         1 x-filament::dropdown          1 x-filament-actions::modals
```

The 19 `RenameStringRector` mappings in the v4 Rector config rewrite
`filament-forms::components.*` / `filament-infolists::components.*` view names to
`filament-schemas::components.*` — but **only inside PHP files**. Blade files are
not processed. All 45 component usages above need manual verification.

`app/Support/FilamentTableToolbar.php` injects
`resources/css/design-tokens.css` inline via `file_get_contents()` plus the
`filament.partials.table-toolbar-overrides` view (56 lines) at
`PanelsRenderHook::STYLES_AFTER`. Because those overrides target Filament's own
`fi-ta-*` CSS class names, and Filament 5's compiled stylesheet is a Tailwind-4
rebuild, **every selector in that partial must be re-validated against v5 markup**.

**Three of the 13 custom Filament views are orphaned** — `filament.product-photo-entry`
(44 lines), `filament.product-image-modal` (20) and `filament.product-image-lightbox`
(21) have **0** references anywhere in `app/` or `resources/`. That is 85 lines of
dead Blade which must **not** be re-derived or re-validated during the migration;
delete them in PR1 so they do not inflate the review surface or the visual-check
list.

**Two Filament couplings live outside `app/Filament/**` and belong in the
migration scope:**

* `app/Models/User.php` implements `Filament\Models\Contracts\FilamentUser` (line 7) and declares `canAccessPanel(Panel $panel): bool` (line 57). This is the Filament panel-access entry point for the **`/admin`** panel (`User` principal on the `web` guard). It must be re-verified against the v5 contract signature.
* `app/Models/Customer.php` must implement `Filament\Models\Contracts\FilamentUser` for the **`/cabinet`** panel (`Customer` principal on the `customer` guard). Access is controlled through `Customer::canAccessPanel()` and must allow only the `cabinet` panel for active customers. **Pre-upgrade baseline defect (discovered by the feasibility/smoke audit, not a migration regression):** before this correction, `Customer` did not implement `FilamentUser`, and Filament 3 middleware returns `403` for authenticated non-`FilamentUser` models when `config('app.env') !== 'local'` — a production-only failure masked in `local`/test environments.
* `app/Support/Filament/RevalidatesOnUpdate.php` is the concrete implementation of the `04-ARCHITECTURE_PRINCIPLES.md` requirement that "a required-field error for a specific input must disappear once the user supplies a valid value — without resubmitting the whole form". It calls `->live()` plus `$livewire->validateOnly($component->getStatePath())` inside `afterStateUpdated`, typed against `Filament\Forms\Components\Component`, `Filament\Forms\Contracts\HasForms` and `Filament\Forms\Set`. **All three of those imports move in Filament 4** (`Forms\Components\Component` → `Schemas\Components\Component`, `Forms\Set` → `Schemas\Components\Utilities\Set`), and the class deliberately implements a documented architectural standard — so its migration is Rector-automatable but its *behaviour* must be re-verified by the 24 existing form-error assertions, not assumed.

### 7.6 Filament-related tests

25 of 149 test files reference Filament, and the interaction coverage is
substantially deeper than a bare `Livewire::test(` count suggests. The dominant
idiom is `Livewire::actingAs($user)->test(SomeFilamentClass::class)`, so counting
only `Livewire::test(` (2 occurrences) badly understates it. Literal counts
across `tests/`:

| Filament/Livewire test helper | Occurrences |
|---|---|
| `Livewire::actingAs` | **206** |
| `->test(` | **206** |
| `fillForm` | **37** |
| `Filament::setCurrentPanel` | **22** (across 21 files) |
| `assertCanSeeTableRecords` | **18** |
| `assertHasNoFormErrors` | **14** |
| `assertHasFormErrors` | **10** |
| `callTableAction` | **8** |
| `assertTableColumnFormattedState*` | **5** |
| `novalidate` | **4** |
| `mountTableAction` | **1** |
| `assertActionExists` | **1** |
| `assertFormSet`, `mountAction`, `assertTableActionExists` | 0 each |

This changes the assessment materially, in the project's favour. Form filling,
form-error assertions, table-record assertions, table-action invocation and
column-state formatting are all genuinely exercised, so the suite will catch a
large share of Filament 4/5 API breakage at the **interaction** level, not merely
at compile time. The `04-ARCHITECTURE_PRINCIPLES.md` form-validation contract in
particular is already guarded by `assertHasFormErrors`/`assertHasNoFormErrors`
(24 assertions) plus the `novalidate` test in §7.4.

What the suite still cannot catch is unchanged: **toolbar layout, spacing,
palette, dark-mode contrast and every other purely visual property** owned by the
four vendor forks and Filament's compiled stylesheet. That is the gap §16 exists
to close, and it remains the reason visual verification is a mandatory gate
rather than an optional one.

### 7.7 Filter behavior — Filament 4 defers filters by default; preserve live filters explicitly

Filament 4 changed the table-filter default: filters are **deferred** by
default, requiring the user to press an Apply button before they take effect
(the v3 `deferFilters()` opt-in became the v4 default). The official API for
preserving the current behavior is `->deferFilters(false)`.

Repository state (verified 2026-08-08): `deferFilters` occurrences in `app/`:
**0** — every one of the **20** `table(Table $table)` definitions and **25**
inline `Tables\Filters\` usages currently relies on the v3 live-filter default.
The `06-UI_DESIGN_SYSTEM.md` Data List filter contract (slide-over panel,
active-count badges, removable indicators) is written around filters that apply
immediately; nothing in it specifies Apply-button semantics.

**Migration direction: preserve the current live-filter behavior.** This is a
framework migration, not a UX redesign — "live vs Apply" must not change as an
incidental side effect. Apply `deferFilters(false)` through the smallest shared
public-Filament mechanism that avoids per-table drift (a single
`Table::configureUsing(fn (Table $table) => $table->deferFilters(false))` in a
service provider is the natural shape; the implementation PR chooses the exact
placement), rather than pasting the call into all 20 tables. If the product
later *wants* Apply-button filter semantics, that is a separate UI-design task
that deliberately updates `06-UI_DESIGN_SYSTEM.md` — not part of GAP-024.

### 7.8 Authorization surface — explicit high-risk coverage

Filament 4 changed how Resource authorization is resolved: the undocumented
`can*()` static overrides "aren't always called in v4" (official v4 upgrade
guide), with policies and `get*AuthorizationResponse()` methods as the
supported customization points. That change lands on a repository whose
authorization posture depends heavily on exactly those overrides.

Fresh inventory (verified 2026-08-08 on `9713d03`): **23 `can*()` overrides
across 10 Resources**, plus **6 `canAccess()` methods** on standalone
pages/resources:

| Resource | Overrides | Body |
|---|---|---|
| `app/Filament/Cabinet/Resources/ProductResource.php` | `canCreate`, `canEdit`, `canDelete` | all `return false` (read-only buyer catalogue) |
| `app/Filament/Resources/CategoryResource.php` | `canCreate`, `canDelete` | both `return false` |
| `app/Filament/Resources/ConnectorAccountResource.php` | `canCreate`, `canEdit`, `canDelete` | all `return false` (creation/settings UI deliberately absent per GAP-006) |
| `app/Filament/Resources/CustomerResource.php` | `canCreate` | `return false` |
| `app/Filament/Resources/FieldDefinitionResource.php` | `canCreate`, `canDelete` | `canCreate` `return false`; `canDelete` conditional — `$record instanceof FieldDefinition && $record->scope !== AttributeScope::System` (the Attribute Dictionary "system fields cannot be deleted" rule) |
| `app/Filament/Resources/OrderResource.php` | `canCreate` | `return false` |
| `app/Filament/Resources/ProductResource.php` | `canCreate`, `canDelete` | both `return false` |
| `app/Filament/Resources/ReservationResource.php` | `canCreate`, `canEdit`, `canDelete` | all `return false` |
| `app/Filament/Resources/StockResource.php` | `canCreate`, `canEdit`, `canDelete` | all `return false` (read-only per project spec) |
| `app/Filament/Resources/SyncLogResource.php` | `canCreate`, `canEdit`, `canDelete` | all `return false` (read-only log) |

`canAccess()` methods: `FieldMatrix`, `Governance`, `PriceInspector`,
`WorkspaceTaxSettings` (role/permission checks on the `User` model),
`ConnectorDefinitionResource` (`PlatformAdminAuthorization::canManage()`),
`CustomerResource\Pages\PreviewAsCustomer`.

Why this is high-risk rather than routine:

* **22 of the 23 `can*()` overrides are deny-only rules (`return false`), and only `ConnectorAccountResource` has a backing policy** (`app/Policies/ConnectorAccountPolicy.php` — the only file in `app/Policies/`). For the other 9 Resources these overrides are the *sole* mechanism suppressing Create/Edit/Delete. If Filament 4 stops consulting an override on any invocation path, the failure direction is **access broadening** — a hidden Create button appearing, a Delete action becoming callable — not a lockout that users would report.
* The one conditional override (`FieldDefinitionResource::canDelete`) enforces an Attribute Dictionary integrity rule (Architecture Review Checklist item 4): system-scope field definitions must not be deletable.
* Role/permission-sensitive behavior beyond CRUD gating is concentrated in `ConnectorAccountPolicy` and `ConnectorAccountMerchandiserPresentation`: the Merchandiser role matrix (safe-fields-only `viewAny`/`view`, `runDiscovery` on enabled accounts, no management abilities), connector-account rendered-field security (`store_code`, `tenant_context`, credentials never rendered to Merchandiser), and workspace isolation (cross-workspace access → 404). These are already covered by rendered-HTML and Livewire-payload assertions in `tests/Feature/Connectors/ConnectorAccountMerchandiserPresentationTest.php` and `ConnectorAccountResourceTest.php`, which must stay green through both Filament steps.

**Migration requirement (PR3 merge blocker).** For every override in the table
above: verify whether Filament 4 still consults it on every invocation path
(table actions, header actions, bulk actions, page mounting, URL access); where
it does not, migrate the rule to a policy or the `get*AuthorizationResponse()`
API; and verify the migrated form **does not broaden access** for any role.
The testing principle is **test security behavior, not implementation-method
cardinality**: inventory all affected methods, map them onto the existing
authorization tests (the Merchandiser presentation suite, the
`WorkspaceTaxDefaultsFeatureTest`-style role tests, and the
`assertCanSeeTableRecords`/action-visibility assertions), retain those tests,
and add **only the missing regression cases needed to prove the
role/permission matrix is unchanged** — one new test per method merely to match
the count of 23 is explicitly not required. Any known authorization regression
is a **merge blocker for PR3**, not a deferrable finding.

---

## 8. Livewire 3 → 4 impact

Verified against `livewire.laravel.com/docs/upgrading` (v4.x). Repository
inventory (occurrence counts from `rg -o` across `resources/` and `app/`):

| Migration item from the official guide | Impact per guide | Repository evidence | Classification |
|---|---|---|---|
| **Config file updates** (`layout`→`component_layout`, `lazy_placeholder`→`component_placeholder`, `smart_wire_keys` default `true`, new `component_locations`/`component_namespaces`/`make_command`/`csp_safe`) | High | **`config/livewire.php` does not exist** — the project uses framework defaults throughout | **Not used.** The single highest-impact v4 change does not apply. If a config is published later, `make_command.type` must be set to `'class'` to preserve v3 generator behavior |
| **Routing: `Route::get($uri, Component::class)` → `Route::livewire(...)`** | High | **4 full-page components are registered as route classes** in `routes/web.php`: `Route::get('/login', Login::class)`, `/dashboard` → `Dashboard::class`, `/catalog` → `Catalog::class`, `/catalog/{product}` → `ProductDetail::class`. All four use `#[Layout('layouts.cabinet')]` rather than the `layout` config key | **Backward-compatible** — the guide states the old form "still works but not recommended", and `Route::livewire()` is required only for single-file and multi-file components, which this project does not use. **Recommended (not required) manual change:** move all four to `Route::livewire()`. Note these are the `customer`-guard cabinet entry points behind `guest:customer` and `CustomerAuthenticated` middleware, so any change here needs functional auth testing |
| **`wire:model` ignores child events by default (add `.deep` to restore)** | High | `wire:model` total **22**, all on real form inputs (`login.blade.php` 3, `catalog.blade.php` 5, `quantity-order.blade.php` 1, `governance.blade.php` 1, `field-matrix.blade.php` 4, `preview-as-customer.blade.php` 6, forked `search-field.blade.php` 1). No `wire:model` on a container element | **Backward-compatible.** Guide: "Standard form input bindings (inputs, selects, textareas) are unaffected." |
| **`wire:model.blur` / `.change` now gate client-side sync; use `.live.blur` for v3 behavior** | Medium | `wire:model.blur` occurrences: **1**, at `resources/views/vendor/filament-tables/components/search-field.blade.php:13` — inside a **forked vendor view**: `$wireModelAttribute = $onBlur ? 'wire:model.blur' : "wire:model.live.debounce.{$debounce}"` | **Manual code change**, and it lands in the vendor fork that must be re-derived anyway (§7.4). `.change`: **0** occurrences |
| **`wire:model.lazy` unchanged** | — | **3** occurrences: `livewire/cabinet/catalog.blade.php:496`, `:633`, `filament/cabinet/columns/quantity-order.blade.php:29` — all quantity inputs | **Backward-compatible** (guide: "`wire:model.lazy` continues to work as it did in v3—no migration needed") |
| **`wire:transition` now uses the View Transitions API; all modifiers removed** | Medium | **0** occurrences | **Not used** |
| **`wire:scroll` → `wire:navigate:scroll`** | High | **0** occurrences of `wire:scroll`; **0** of `wire:navigate` | **Not used** |
| **Component tags must be self-closed** | High | **0** occurrences of `<livewire:`. Only `@livewire(` — **2**: `resources/views/layouts/cabinet.blade.php:31` (`@livewire('cabinet.cart-indicator')`) and `app/Providers/Filament/CabinetPanelProvider.php:52` (`Blade::render('@livewire(\'cabinet.cart-toolbar\')')`) | **Not used** — the breaking change is specific to tag syntax |
| **Asset/endpoint URLs move from `/livewire/…` to `/livewire-{hash}/…`** | Low | `docker/nginx/default.conf` contains **0** references to `livewire`; no firewall/CDN path rules in the repository | **Backward-compatible** — but flag as a **production infrastructure check**: any host-level rule outside the repo (WAF, CDN, reverse proxy) that matches `/livewire/` would break |
| **`Livewire::setUpdateRoute` signature adds `$path`** | Low | **0** occurrences | **Not used** |
| **`stream()` parameter order (`to:` → `el:`)** | Medium | **0** occurrences | **Not used** |
| **`LivewireManager::mount()` gains a `$slots` parameter** | Medium | No `Livewire::component(`, `Livewire::setUpdateRoute`, `Livewire::forceAssetInjection`, `Livewire\Mechanisms` or `Livewire\Features` usage. `mount()` is never called directly and `LivewireManager` is never extended. **However, the `Livewire` facade *is* used to reach the manager: `Livewire::current()` appears 9 times** — `app/Filament/Resources/ProductResource.php` (lines 179, 183, 368, 372), `app/Filament/Cabinet/Resources/ProductResource.php` (lines 129, 135, 323, 331) and `resources/views/filament/cabinet/columns/quantity-order.blade.php:14`, all reading `?->marginFormat` off the current component | **Not used** for the `mount()` signature change specifically. `Livewire::current()` is **not** listed as a breaking change in the v4 guide, but because it reaches into the live component instance from inside Filament column closures it is a **regression-test-required** item: the margin-format toggle must be verified in both product tables after the upgrade |
| **`commit` / `request` JS hooks deprecated in favour of `interceptMessage` / `interceptRequest`** | Low | **0** occurrences of `Livewire.hook`, `Livewire.on`, `Livewire.dispatch`, `$wire.$js(`, `$js(` | **Not used** (deprecated, still functional regardless) |
| **`$wire.` usage** | — | **17** occurrences, all inside the three forked vendor views (`filament-tables/index.blade.php` 3, `search-field.blade.php` 1, `filament-actions/components/modals.blade.php` 13) | Covered by the §7.4 re-derivation |
| **Volt migration** | — | `livewire/volt` is not a dependency | **Not used** |
| **`@livewireStyles` / `@livewireScripts`** | — | 1 each, both in `resources/views/layouts/cabinet.blade.php` | **Backward-compatible** |

`app/Livewire/**` contains 6 components: `Cabinet/CartIndicator.php`,
`Cabinet/CartToolbar.php`, `Cabinet/Catalog.php`, `Cabinet/Dashboard.php`,
`Cabinet/Login.php`, `Cabinet/ProductDetail.php`. Per `07-TECH_STACK.md`,
"Legacy cabinet Livewire code must not be revived or extended unless the human
explicitly approves it" — so these must be migrated for compatibility only, with
no feature work.

**Overall: the Livewire 3 → 4 step is the *lowest*-risk of the four major bumps
for this repository.** The absence of `config/livewire.php`, of `<livewire:` tags,
of `wire:transition`, of `wire:scroll` and of any Livewire JS hook or internal
manager usage eliminates every high-impact item in the official guide except
`wire:model` child-event semantics, which the guide itself says does not affect
standard input bindings.

### 8.1 Polling and the connector operational UI — explicit investigation

Because `07-TECH_STACK.md` records a connector operational UI that relies on
polling, every polling mechanism was located:

| Mechanism | Exact location | Interval |
|---|---|---|
| Filament table poll | `app/Filament/Resources/ConnectorAccountResource.php:127` — `->poll('5s')` | 5s |
| Filament table poll | `app/Filament/Resources/ConnectorAccountResource/RelationManagers/ConnectionChecksRelationManager.php:48` — `->poll('5s')` | 5s |
| Raw `wire:poll` | `resources/views/filament/connector-accounts/runtime-state.blade.php:16` — `wire:poll.5s="refreshConnectionState"` | 5s |
| Raw `wire:poll` (vendor fork) | `resources/views/vendor/filament-tables/index.blade.php:289` — `wire:poll.{{ $pollingInterval }}` | dynamic |

**Livewire 4 polling semantics.** The official guide's `### Performance
improvements` section states, verbatim: "**Non-blocking polling**: `wire:poll` no
longer blocks other requests or is blocked by them" and "**Parallel live
updates**: `wire:model.live` requests now run in parallel". It then states:
"These improvements happen automatically—no changes needed to your code."

Assessment for this repository:

* **No code change is required** by the polling change itself. `wire:poll.5s` and Filament's `->poll('5s')` keep working.
* **The domain invariant at stake is precisely scoped**: concurrent dispatches must not produce duplicate active operations or violate lifecycle consistency. That invariant belongs to the dispatch-service locking/idempotency layer, not to the UI. It is important to distinguish the two request kinds the client can now interleave: **poll = state read/refresh** — `refreshConnectionState` (`app/Filament/Resources/ConnectorAccountResource/Pages/ViewConnectorAccount.php:36`) only re-resolves the record and re-loads presentation relations, mutating nothing — versus **dispatch = state-changing operation**, which goes through the authorized dispatch services.
* **The server-side idempotency contract already covers the invariant, and it is already tested.** `07-TECH_STACK.md`, `### Connector idempotency and overlap locking (Resolved)`, specifies the `(workspace_id, connector_account_id, operation_kind)` lock-and-check, implemented via `Cache::lock($lockKey, 30)->block(5, …)` at `app/Services/Connectors/ConnectorConnectionCheckDispatchService.php:59` and `app/Services/Connectors/ConnectorDiscoveryRunDispatchService.php:70`. The existing suites prove it at the owning layer (verified 2026-08-08): `ConnectorConnectionCheckDispatchServiceTest` and `ConnectorDiscoveryRunDispatchServiceTest` each carry `second_dispatch_returns_same_row_and_does_not_push_second_job`, `dispatch_failure_compensates_row_to_failed` and `stale_queued_row_is_recovered_and_new_dispatch_is_allowed` — duplicate-dispatch prevention, compensation, and stale-row recovery.
* **Classification: existing coverage is mandatory; a new UI concurrency test is conditional, not automatic.** The migration must (a) keep the service-level concurrency/idempotency tests above green, (b) keep the existing connector polling/render assertions green, and (c) perform a runtime/manual polling smoke under Livewire 4 (observe one poll-driven state transition while triggering a dispatch). A **new automated concurrency test is required only if that work identifies a real uncovered mutable race** — a state-changing path reachable from the polling surface that bypasses the dispatch-service lock. Tests must not be created merely because the client can now issue requests concurrently: the poll handler is a pure read, and the mutation path is already lock-serialized and tested.

**This audit changes no connector behavior.** The finding above is compatibility
verification only.

---

## 9. Tailwind 4 / frontend migration impact

### 9.1 Does this project have a custom Filament theme? **No.**

Verified: `->viteTheme(` occurrences outside `vendor/` and `node_modules/`: **0**.
`@vite(...)` appears in exactly 2 Blade files, neither of which is a Filament
panel layout:

* `resources/views/layouts/cabinet.blade.php:7` — the **legacy cabinet Livewire layout**
* `resources/views/welcome.blade.php:15`

Both Filament panels therefore render Filament's **precompiled default
stylesheet**. `resources/css/app.css` — and with it the entire Tailwind 3 build —
is loaded only by the legacy cabinet Blade layout and the welcome page.

The panels get their project-specific styling instead from
`app/Support/FilamentTableToolbar.php`, which at
`PanelsRenderHook::STYLES_AFTER` inlines `resources/css/design-tokens.css` via
`file_get_contents()` and renders
`resources/views/filament/partials/table-toolbar-overrides.blade.php`. That is
hand-written CSS, not Tailwind output.

This is the most important frontend finding, and it cuts both ways:

* **It shrinks the Tailwind 4 porting surface.** There is no existing Filament theme CSS file to port, no `@source` graph to rebuild, no Filament-preset upgrade.
* **But it collides with a documented constraint of both target-side Filament majors.** Filament 5's styling docs state: "**A custom theme is required to use Tailwind CSS classes in your own code.** Filament's default compiled stylesheet does not include arbitrary Tailwind classes… Without a custom theme, any Tailwind classes you add to your code will simply not work." Filament **4**'s styling docs (`docs/08-styling/01-overview.md` at `4.x`, verified 2026-08-08) carry the **identical statement**, adding: "If you want to use Tailwind CSS utility classes (like `text-primary-600`, `bg-gray-100`, `p-4`, etc.) in your own Blade views, Livewire components, or PHP files, **you must create a custom theme first**." The project's Filament-panel Blade views *do* use Tailwind utility classes (per `07-TECH_STACK.md`: "Use Tailwind utility classes for layout and spacing"). Today those classes render only insofar as Filament 3's compiled Tailwind-3 stylesheet happens to contain them. Filament 4/5 stylesheets are **different Tailwind-4 builds**, so the set of incidentally-available utilities changes.

**Recommendation — resolved, no longer an open decision: use proper custom
Filament themes.** The upstream requirement is unambiguous for both Filament 4
and 5, and the repository's extensive Tailwind usage in project-owned Filament
views/PHP means continued reliance on incidental utilities inside Filament's
precompiled internal stylesheet is exactly the pattern that breaks silently
across a major. Recommended architecture:

* one **thin theme entrypoint per panel** — one for `/admin`, one for `/cabinet` (`php artisan make:filament-theme` per panel, registered via `->viteTheme(...)`);
* **shared CSS / design-token / `@source` definitions** reused by both entrypoints where technically sensible (the existing `design-tokens.css` and a common `@source` set covering `app/Filament/**`, `resources/views/filament/**`, `resources/views/components/**`, `resources/views/livewire/**`, `app/Livewire/**`) — do **not** duplicate the entire styling layer between panels;
* preserve the panels' **different visibility / user-context policies** (`07-TECH_STACK.md`, `## Current Panels`) — theme sharing is a build concern and must not leak admin-only surface styling assumptions into the buyer panel;
* preserve **Light / Dark / System** behavior (`07-TECH_STACK.md`, `## Application Stack`), including the `.dark`-tuned `--bp-muted-*` tokens.

Cost: two new theme build inputs, a real Tailwind 4 build in the deployment
pipeline, and the strongest possible need for visual regression review — all
absorbed into PR3 (§17), where §16's visual verification is mandatory.

### 9.2 Tailwind v3-specific configuration that must change

| Item | Current state | Required for Tailwind 4 |
|---|---|---|
| `tailwind.config.js` | Tailwind v3 JS config: `content` (5 globs), `theme.extend.fontFamily.sans` (Figtree), `theme.extend.colors.primary` (6 stops), `plugins: []` | Tailwind 4 is CSS-first. `content` globs become `@source` directives; `theme.extend` becomes `@theme` CSS custom properties. The JS config file becomes optional/legacy |
| `postcss.config.js` | `plugins: { tailwindcss: {}, autoprefixer: {} }` | Either replace `tailwindcss` with `@tailwindcss/postcss`, or drop PostCSS for Tailwind entirely and use `@tailwindcss/vite`. `autoprefixer` is no longer needed (Tailwind 4 handles prefixing via Lightning CSS) |
| `resources/css/app.css` | `@import './design-tokens.css';` + `@tailwind base; @tailwind components; @tailwind utilities;` | The three `@tailwind` directives become a single `@import "tailwindcss";` |
| `vite.config.js` | `laravel()` plugin only | Add `@tailwindcss/vite` (the recommended delivery, §9.4) and add the two panel-theme CSS entrypoints to `input` (§9.1) |
| `resources/css/design-tokens.css` | 38 lines of plain CSS custom properties, a `.dark` block, four `.bp-muted-*` classes. **No `@apply`, no `@layer`, no `theme()`** | **No change required.** It is Tailwind-version-agnostic and is injected by `file_get_contents()`, not compiled by Tailwind |

Verified absence of Tailwind-version-sensitive CSS constructs:

```console
$ rg -n "@apply|@layer|theme\(" resources/css/ resources/views/
(no matches)
```

### 9.3 Utility-level migration surface — small

Every Tailwind-v4-removed or renamed utility class was searched across
`resources/` and `app/`:

| Removed/renamed in Tailwind v4 | Occurrences |
|---|---|
| `bg-opacity-`, `text-opacity-`, `border-opacity-`, `divide-opacity-`, `ring-opacity-`, `placeholder-opacity-` | **0** each |
| `flex-shrink-`, `flex-grow-` | **0** each |
| `overflow-ellipsis`, `decoration-slice`, `decoration-clone` | **0** each |

**However, the utilities Tailwind 4 *renamed* (rather than removed) are present in
volume.** Tailwind 4 shifted the shadow/radius/blur scale down one step
(`shadow-sm` → `shadow-xs`, bare `shadow` → `shadow-sm`, `rounded-sm` →
`rounded-xs`, `blur-sm` → `blur-xs`) and changed `outline-none` semantics.
Counts across `resources/` and `app/`, **excluding** `resources/views/vendor/**`:

| Renamed / changed in Tailwind v4 | First-party occurrences |
|---|---|
| `shadow-sm` | **22** |
| `rounded-sm` | **12** |
| `outline-none` | **20** |
| `space-y-` (behavior change) | **24** |
| `shrink-` (already the modern form — no change needed) | 19 |
| `space-x-`, `blur-sm` | 0 each |

That is **78 occurrences requiring migration attention**, versus 0 for the
removed-utility set. These are silent visual changes — a `shadow-sm` that becomes
one step heavier and a `rounded-sm` that becomes one step rounder will render,
just differently — which is precisely why §16's visual verification is mandatory
rather than advisory.

Other measurements:

* `dark:` variants: **190** occurrences across `resources/` and `app/` (**182** first-party, excluding the vendor forks), concentrated in `filament/pages/price-inspector.blade.php` (22), `filament/pages/governance.blade.php` (18), `filament/pages/field-matrix.blade.php` (17) and `filament/resources/customer-resource/pages/preview-as-customer.blade.php` (11).
* Arbitrary-value utilities (`w-[…]`, `bg-[#…]`, `text-[…]`): **57** occurrences across `welcome.blade.php`, the vendor tables fork, `livewire/cabinet/{login,catalog,cart-toolbar,cart-indicator}.blade.php`, and `filament/pages/field-matrix.blade.php`. Supported in Tailwind 4, but under a different custom-property resolution model, so these need visual spot-checking rather than mechanical rewriting.
* `resources/css/design-tokens.css` defines `--color-primary-50` … `--color-primary-700`, but `var(--color-primary…)` is referenced **0** times. Primary styling flows through Tailwind's `primary-*` classes (**61** first-party occurrences) generated from `tailwind.config.js`, while the panels get their primary from `Filament\Support\Colors\Color::Amber` via `app/Support/Brand.php`. The `--bp-muted-*` variables *are* consumed, by the `.bp-muted-*` classes (**21** first-party occurrences). **The primary palette is therefore defined three times and the CSS-variable copy is dead** — worth consolidating during the Tailwind 4 `@theme` conversion, since a v3→v4 config rewrite touches exactly that definition.
* `resources/views/welcome.blade.php:18` embeds a **precompiled Tailwind v3.4.17 stylesheet inline** (`/* ! tailwindcss v3.4.17 | MIT License */`) as a fallback for when `public/build/manifest.json` and `public/hot` are both absent. It is not processed by Vite and will not be regenerated by the Tailwind 4 migration, so it becomes a stale v3 artifact. Low risk (a scaffold page), but it must not be mistaken for live Tailwind output.

### 9.5 Committed Filament 3 JavaScript assets — an overlooked migration item

The 16 files under `public/js/filament/**` (§1.5) are Filament published-asset
output committed at Filament 3.3.52. They are **not** produced by `npm run build`
and are **not** covered by `/public/build` being gitignored — but they are
**not without a supported refresh mechanism either**. `composer.json` already
contains `@php artisan filament:upgrade` inside `post-autoload-dump`, and
Filament's official documentation states this is exactly its purpose: "After
any updates, all Laravel caches need to be cleared, and frontend assets need to
be republished. You can do this all at once using the `filament:upgrade`
command, which should have been added to your `composer.json` file when you ran
`filament:install` the first time" (Filament installation docs, `Upgrading`
section; verified 2026-08-08).

Two consequences for the migration:

* **Keep the existing `post-autoload-dump` asset-upgrade mechanism — do not invent a second parallel asset-publishing process.** After each Filament-major Composer operation (PR3 and PR4), **verify** that the hook regenerated the 16 committed files and commit the result, and verify that **no Filament-3-generation browser asset survives** the major step. If a stale file did survive, the browser would load Filament 3 component JavaScript against Filament 4/5 server-rendered markup — a class of breakage that renders without any PHP error and that no existing test would catch. Manual `php artisan filament:assets` remains available as a diagnostic/repair command if the hook proves insufficient, but it is not the default architecture while the supported `filament:upgrade` hook is functioning.
* **Five of them call `Livewire.hook`** (`tables/components/table.js`, `notifications/notifications.js`, `forms/components/markdown-editor.js`, `forms/components/color-picker.js`, `widgets/components/chart.js`), the API Livewire 4 deprecates in favour of `interceptMessage` / `interceptRequest`. This is *vendor* code, so the project does not migrate it by hand — but it does mean the committed assets are Livewire-3-generation artifacts, and re-publishing from Filament 5 is the only correct remedy. Note that the repository's own first-party code uses `Livewire.hook` **0** times (§8), so no application-side hook migration is required.

Worth stating plainly because it is easy to misread: the earlier `Livewire.hook`
and `$wire.` counts in §8 are scoped to `resources/` and `app/`. Including
`public/js/filament/**` raises `$wire.` from 17 to **30** and `Livewire.hook`
from 0 to **2 files**. Every one of those additional occurrences is vendor-published
JavaScript, not project code.

**Dark mode / design tokens.** `design-tokens.css` defines its dark values under
a plain `.dark { … }` selector, matching Filament's own dark-mode class strategy.
This should survive unchanged, but is a required visual-verification item (§16)
because Filament 5's dark surface colours are a Tailwind-4 palette rebuild and
the comment in `design-tokens.css` explicitly tunes `--bp-muted-*` to be
"Clearly visible on Filament's near-black panel background."

### 9.4 Vite / build-pipeline changes

* `/public/build` is gitignored (`.gitignore:6`), so assets are built at deploy time — no committed build artifacts to reconcile.
* `@tailwindcss/vite@4.3.3` peer-accepts `vite ^5.2.0 || ^6 || ^7 || ^8`, so **Tailwind 4 does not force a Vite major bump**. The project could adopt Tailwind 4 on its current `vite@6.4.3`.
* Moving to `laravel-vite-plugin@3.1.3` *would* force `vite@^8.0.0` (its peer dependency) plus a new `fontaine ^0.8.0` peer. That is an independent decision from Tailwind 4 and should be treated as optional.
* **Recommendation — Vite 8 is explicitly out of GAP-024 migration scope.** Adopt Tailwind 4 via `@tailwindcss/vite` on the existing Vite 6 / `laravel-vite-plugin` 1.3.0 line, and keep the current Vite major and current compatible Laravel Vite plugin during the Filament/Tailwind migration **unless real solver/tooling evidence at implementation time makes this impossible**. The Vite 8 / `laravel-vite-plugin` 3 jump is a separate later modernization task. Rationale: do not combine an unrelated frontend-toolchain major with the highest-risk Filament/Tailwind migration when the target stack does not require it — `@tailwindcss/vite`'s peer range (`^5.2.0 || ^6 || ^7 || ^8`) proves it does not.

---

## 10. Laravel 11 → 13 migration impact

Both sequential upgrade guides were read in full, as required:
`laravel.com/docs/12.x/upgrade` (Upgrading To 12.0 From 11.x) and
`laravel.com/docs/13.x/upgrade` (Upgrading To 13.0 From 12.x). Laravel's own
effort estimates are "5 Minutes" and "10 Minutes" respectively; Laravel 13's
notes state "most Laravel applications may upgrade to Laravel 13 without
changing much application code."

The repository uses the **Laravel 11 slim skeleton**: `bootstrap/app.php` +
`bootstrap/providers.php`, with no `app/Http/Kernel.php` and no
`app/Console/Kernel.php`. That removes a large class of upgrade friction.

### 10.1 High-impact: Request Forgery Protection

The Laravel 13 guide marks this **High**: "Laravel's CSRF middleware has been
renamed from `VerifyCsrfToken` to `PreventRequestForgery`, and now includes
request-origin verification using the `Sec-Fetch-Site` header. `VerifyCsrfToken`
and `ValidateCsrfToken` remain as deprecated aliases, but direct references
should be updated."

This repository has **4 direct references, in both panel providers**:

```console
$ rg -n "VerifyCsrfToken" app/ bootstrap/ config/ tests/ routes/
app/Providers/Filament/AdminPanelProvider.php:19:use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
app/Providers/Filament/AdminPanelProvider.php:60:                VerifyCsrfToken::class,
app/Providers/Filament/CabinetPanelProvider.php:23:use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
app/Providers/Filament/CabinetPanelProvider.php:71:                VerifyCsrfToken::class,
```

Both panels list the middleware explicitly inside `->middleware([...])`. Because
the alias is retained the app will not break on upgrade, but the new
`Sec-Fetch-Site` origin verification **changes runtime behavior for both `/admin`
and `/cabinet`**, including the `customer`-guard cabinet login POST. Manual
change: switch both to `PreventRequestForgery::class`. Regression risk: **High**
— authentication and every form POST in both panels. This must be functionally
tested, not just compiled.

No `withoutMiddleware([...])` CSRF exclusions exist anywhere in `app/`,
`bootstrap/`, `config/`, `tests/` or `routes/`, so there is no test-side
exclusion list to update.

### 10.2 Medium-impact: cache `serializable_classes`

The Laravel 13 guide marks this **Medium**. Resolution for this repository:

* `config/cache.php` (a Laravel 11 config) has **no** `serializable_classes` key.
* Laravel 13 reads it defensively — `src/Illuminate/Cache/CacheManager.php:473`: `return $this->app['config']['cache.serializable_classes'] ?? null;`
* The `false` default the guide describes lives in the **13.x skeleton config** (`laravel/laravel@13.x` `config/cache.php:134`), not in the framework fallback.

Because this application ships its own `config/cache.php` without the key, the
resolved value is `null` and cache-unserialization behavior is unchanged.
Independently, the only cache usage in `app/` is `Cache::lock(...)` (2 call
sites), with no `Cache::put`/`remember`/`forever` of PHP objects. **Classification:
no change required**, but adding `'serializable_classes' => false` explicitly is
a recommended hardening step, consistent with `04`'s security posture.

### 10.3 Medium-impact: `HasUuids` and UUIDv7 — resolved direction: preserve UUIDv4

The Laravel 12 guide marks this **Medium**: "The `HasUuids` trait now returns
UUIDs that are compatible with version 7 of the UUID spec (ordered UUIDs). If
you would like to continue using ordered UUIDv4 strings for your model's IDs, you
should now use the `HasVersion4Uuids` trait."

`HasUuids` is used on **18 models**, covering the connector runtime, the Field
Foundation, Pricing and Availability, and the tenant root itself:

```
app/Models/ConnectorAccount.php              app/Models/ConnectorSchemaSource.php
app/Models/ConnectorConnectionCheck.php      app/Models/FieldBinding.php
app/Models/ConnectorDefinition.php           app/Models/FieldDefinition.php
app/Models/ConnectorDiscoveryRun.php         app/Models/InventoryLocation.php
app/Models/ConnectorSchemaDiff.php           app/Models/InventoryRecord.php
app/Models/ConnectorSchemaDiffItem.php       app/Models/PriceList.php
app/Models/ConnectorSchemaSnapshot.php       app/Models/PriceListItem.php
app/Models/ConnectorSchemaSnapshotField.php  app/Models/Tag.php
                                             app/Models/Workspace.php
                                             app/Models/WorkspaceImportAlias.php
```

Existing rows keep their UUIDv4 values; new rows would silently get UUIDv7. The
result would be **mixed UUID versions within the same primary-key columns**
across 18 workspace-owned tables — including `workspaces`, the tenant-isolation
root (Architecture Review Checklist items 1 and 2).

**Migration direction — resolved, no longer an open decision: preserve UUIDv4
behavior during GAP-024.** A framework major upgrade must not silently change
identifier-generation semantics across 18 existing models. The Laravel 13
implementation task uses the current Laravel-supported UUIDv4 mechanism —
`Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids` (verified present on
the `laravel/framework` `13.x` branch, 2026-08-08; it layers over `HasUuids`
and generates via `Str::orderedUuid()`) or the exact current equivalent
re-verified at execution time.

The reasoning, recorded so the trade-off is not re-litigated accidentally:

* existing UUID values stay valid under either choice — nothing breaks retroactively;
* allowing new UUIDv7 values is technically possible and would gain time-ordered index locality on the append-heavy connector-history and inventory-ledger tables;
* but changing identifier semantics is **unrelated to GAP-024's objective** (framework security support), and mixed UUID versions across 18 tables would increase the migration's blast radius — anything that infers creation order or version from the key would need re-checking — without helping that objective;
* UUIDv7 therefore remains available as a **separate future architectural decision**, to be taken only if evidence shows its index-locality benefit justifies a deliberate behavior change.

`HasVersion7Uuids` was removed in Laravel 12; the repository does not use it
(`HasVersion7Uuids` occurrences: 0), so there is no removal to handle. This
correction task modifies no models — the trait swap happens in PR2.

### 10.4 Connector runtime compatibility verification

`07-TECH_STACK.md` records a substantial, already-shipped connector runtime.
Each contract was verified against Laravel 13 rather than assumed.

**Connection-check and discovery jobs.** `app/Jobs/Connectors/` contains
`ConnectorConnectionCheckJob.php`, `ConnectorDiscoveryRunJob.php` and two
sanitized execution-exception classes.

| Contract | Connection check | Discovery | Laravel 13 status |
|---|---|---|---|
| `public int $timeout` | `45` (line 23) | `900` (line 25) | Unchanged; not mentioned in either upgrade guide |
| `public int $maxExceptions` | `1` (line 27) | `1` (line 29) | Unchanged |
| `retryUntil(): DateTimeInterface` | line 36 | line 41 | Unchanged. Laravel 13 *adds* `#[Tries]`, `#[Backoff]`, `#[Timeout]`, `#[FailOnTimeout]` attributes as an alternative — additive, not breaking |
| `WithoutOverlapping` + `->shared()` + `->releaseAfter(30)` + `->expireAfter(N)` | lines 47–50, `expireAfter(120)` | lines 52–55, `expireAfter(1100)` | **Verified present in Laravel 13.** `src/Illuminate/Queue/Middleware/WithoutOverlapping.php` @ `13.x` declares `handle()`, `releaseAfter()`, `dontRelease()`, `expireAfter()`, `shared()` |
| `SerializesModels` | line 21 | line 23 | Constructor payloads carry only scalar IDs and an int `retryUntilTimestamp`, never a model or credential — so Laravel 13's "Collection Model Serialization Restores Eager-Loaded Relations" (**Low**) does not apply |
| `failed(?\Throwable)` | line 122 | line 140 | Unchanged |
| `ShouldBeUnique` | deliberately absent per `07-TECH_STACK.md` | absent | Unchanged |

**Database queue behavior and lock store.** `Cache::lock($lockKey, 30)->block(5, …)`
at `ConnectorConnectionCheckDispatchService.php:59` and
`ConnectorDiscoveryRunDispatchService.php:70`, on the `database` cache driver
backed by the standard `cache_locks` table. `Cache::lock` is not touched by
either upgrade guide. Laravel 13's `Cache::touch()` addition is additive.

**Queue timeout alignment.** `07-TECH_STACK.md` records the verified production
values: `database.retry_after` = 90s with a 45s job timeout and 120s lock
expiry; `database_connectors.retry_after` = 1200s
(`CONNECTOR_QUEUE_RETRY_AFTER`) with a 900s job timeout and 1100s lock expiry;
worker commands in `docker-compose.yml` (`queue`, `connector-queue`). Neither
upgrade guide changes `retry_after` semantics or `--timeout` handling. However,
`07-TECH_STACK.md` explicitly instructs that "The exact production values must
be verified against `config/queue.php` and the process-manager
(`supervisor`/`docker-compose`) worker command — do not assume defaults are
already aligned." **The migration must re-run that verification after the
framework bump**, because the guidance is a standing project requirement, not a
one-time check.

**Failed-job behavior.** `07-TECH_STACK.md` records that the jobs wrap `handle()`
in sanitized execution exceptions "because Laravel's `failed_jobs.exception`
stores raw exception text regardless of `#[\SensitiveParameter]`". Neither guide
changes this, so the mitigation remains necessary and remains sufficient.

**Queue events.** `JobAttempted::$exceptionOccurred` → `$exception` and
`QueueBusy::$connection` → `$connectionName` are both **Low** impact in the
Laravel 13 guide. Repository listeners for either: **0**. Not applicable.

**HTTP client — the connector transport does not use Laravel's HTTP client at all.**
`Http::` facade occurrences in `app/`: **0**. `Illuminate\Http\Client\*` imports in
`app/`: **0**. The connector transport instead depends on **Guzzle directly**, with
11 `GuzzleHttp\*` imports across 5 files:
`app/Support/Connectors/Transport/Internal/ConnectorRequestSenderImpl.php` (5 —
`Client`, `Exception\{ConnectException,RequestException,TransferException}`,
`Promise\PromiseInterface` in an `on_headers` callback),
`app/Support/Connectors/Transport/Curl/DefaultCurlClientFactory.php` (3 —
`Client`, `Handler\CurlHandler`, `HandlerStack`),
`app/Support/Connectors/Transport/Curl/CurlClientFactory.php` (1),
`app/Support/Connectors/AdobePaaS/AdobePaaSDiscoveryRequestFactory.php` (1) and
`AdobePaaSConnectionCheckRequestFactory.php` (1). `DefaultCurlClientFactory::create()`
builds `new Client(array_merge(['handler' => HandlerStack::create(new CurlHandler)], …))`
after an `extension_loaded('curl')` guard, and `ConnectorRequestSenderImpl:101` sets
`$options['curl'][\CURLOPT_RESOLVE]` — exactly the mechanism `07-TECH_STACK.md`
specifies.

Two consequences, pulling in opposite directions:

* **Laravel 13's `Response::throw`/`throwIf` signature change is definitively not applicable** — the project never touches `Illuminate\Http\Client`. That removes the HTTP-client row from the framework migration surface entirely.
* **But the transport is more directly exposed to the Guzzle `7.10.6 → 7.15.3` bump than a `Http`-facade consumer would be**, because it depends on Guzzle's own `HandlerStack`, `CurlHandler`, `on_headers` streaming callback and raw `curl` option passthrough rather than on Laravel's stable wrapper. Guzzle **7.15.2** is the release that fixed `PKSA-gcrk-3vtt-1r14` / CVE-2026-69246, "Noncanonical host can bypass host-based checks" (§14.1) — precisely the bug class an SSRF host-validation layer depends on being absent. **Classification: compatible, and the Guzzle bump is security-desirable here rather than merely incidental — but the fail-closed `CurlHandler`-vs-`StreamHandler` assertion is exactly the behavior a Guzzle minor bump can perturb, so its existing test must be re-run** (Architecture Review Checklist item 21).

**Guzzle is a direct code dependency but not a direct Composer dependency.**
`composer.json` declares `guzzlehttp/guzzle` in neither `require` nor
`require-dev`; the package is present only transitively via `laravel/framework`.
The solver output in §4.1 states this literally:

```console
laravel/laravel  dev-develop does not require guzzlehttp/guzzle (but 7.10.6 is installed)
```

Because `app/Support/Connectors/**` imports `GuzzleHttp\*` classes in 11 places,
the SSRF-safe transport's correctness currently rests on a version constraint the
project does not control. Laravel 13 requires `guzzlehttp/guzzle ^7.8.2`, so the
target state happens to be satisfied — but a future framework change to that
constraint, or a framework release that drops Guzzle, would silently move the
transport's floor. **`guzzlehttp/guzzle` should be promoted to an explicit direct
`require` entry as part of PR2**, pinned to at least `^7.15.2` so the
host-canonicalization fix is a stated project requirement rather than an accident
of Laravel's own dependency tree. This is the same class of mistake
`07-TECH_STACK.md` already guards against for the OAuth1 signer (depending on a
third-party library's classes rather than an owned port).

**Connector secret lifecycle.** `encrypted:array` casts, `APP_PREVIOUS_KEYS`
rotation and the "jobs carry IDs, not decrypted credentials" rule are untouched
by both guides (Architecture Review Checklist item 22). No change required.

**Supervisor.** `deploy.sh` runs `php artisan queue:restart` after
`optimize:clear`. `DEPLOY.md` documents `babypark-queue` as the live Supervisor
program and `babypark-connector-queue` as planned-but-not-installed. Neither
guide changes `queue:restart` semantics. **But the Supervisor program's PHP
binary path must point at PHP 8.3+ after the upgrade** (§11), and per
`07-TECH_STACK.md` the discovery worker must be confirmed `RUNNING` via
`supervisorctl status` — a standing activation gate that the framework upgrade
neither satisfies nor removes.

**This audit does not redesign the connector runtime.** All of the above is
compatibility verification only.

### 10.5 Remaining guide items, resolved against the repository

| Guide item | Impact | Repository evidence | Classification |
|---|---|---|---|
| L13 `upsert` requires non-empty `uniqueBy` | Medium | `->upsert(` / `::upsert(` in `app/`, `database/`, `tests/`: **0** | Not applicable |
| L13 MySQL `DELETE … JOIN` with `ORDER BY`/`LIMIT` | Low | No joined deletes | Not applicable |
| L13 `symfony/polyfill-php85` global `array_first()`/`array_last()` conflicts | Low | `array_first(` / `array_last(`: **0**; `laravel/helpers` is not a dependency | Not applicable |
| L13 cache/Redis prefix and session-cookie fallback change | Low | `config/cache.php:106` defines `'prefix' => env('CACHE_PREFIX', …)` (`.env.example:46` sets `CACHE_PREFIX=babypark_`); `config/database.php:150` defines the Redis prefix; `config/session.php:130-133` defines `'cookie' => env('SESSION_COOKIE', Str::slug(env('APP_NAME','laravel'),'_').'_session')`. All three come from application config, so the framework-level fallback change does not apply | Not applicable — **no cache-key churn and no session invalidation on deploy** |
| L13 `Container::call` nullable class defaults | Low | No method-injection reliance on container-resolved nullable class params | Not applicable |
| L13 `Manager::extend` callback binding | Low | No custom driver `extend` closures in `app/Providers/**` | Not applicable |
| L13 domain-route precedence | Low | No `->domain()` routes | Not applicable |
| L13 `withScheduling()` deferral | Very Low | **Applies.** `bootstrap/app.php:13` uses `->withSchedule(fn ($schedule) => $schedule->command('reservations:expire')->everyMinute()->withoutOverlapping())`. `routes/console.php` contains only the stock `inspire` command and registers no schedule. Laravel 13's `ApplicationBuilder` declares `public function withSchedule(callable $callback)` (line 375) and has no separate `withScheduling()` method, so the guide's wording refers to this same builder entry point | **Applies, but no change required.** The guide's condition is "if your application relied on immediate schedule registration timing during bootstrap" — this registration is purely declarative and reads nothing at bootstrap. **Regression check:** confirm `reservations:expire` still fires every minute and still honours `withoutOverlapping()` after the upgrade, since deferred registration changes *when* the closure runs |
| L13 pagination Bootstrap-3 view renames | Low | No direct `pagination::default` references | Not applicable |
| L13 `Str` factories reset between tests | Low | No custom `Str::createUuidsUsing`-style factories | Not applicable |
| L13 model booting / nested instantiation `LogicException` | Very Low | No model instantiation inside `boot*()` | Not applicable |
| L13 polymorphic pivot table-name pluralization | Low | No custom morph-pivot model classes | Not applicable |
| L13 password-reset subject string change | Very Low | No test or translation override asserts "Reset Password Notification" | Not applicable |
| L13 contract additions (`Store::touch`, `Dispatcher::dispatchAfterResponse`, `ResponseFactory::eventStream`, `MustVerifyEmail::markEmailAsUnverified`, Queue size methods) | Very Low | No custom implementations of any of these contracts | Not applicable |
| **L12** Carbon 3 required | Low | Already on `nesbot/carbon 3.11.4` | **Already satisfied** |
| **L12** `Schema::getTables()`/`getTableListing()` multi-schema | Low | `Schema::getTables` / `getTableListing` / `getViews` / `getTypes` / `getIndexes` / `getForeignKeys`: **0**. (97 `Schema::`-family calls exist but are `hasTable`/`hasColumn`/`getColumnListing`, which are unaffected) | Not applicable |
| **L12** `image` validation excludes SVG | Low | No `'image'` validation rule; the 3 `image` string matches are enum/registry values (`AttributeDataType::Image`, Adobe normalizer mapping, canonical-registry allow-list) | Not applicable |
| **L12** local disk root → `storage/app/private` | Low | `Storage::disk('local')`: **0**; `config/filesystems.php` defines `local` explicitly | Not applicable |
| **L12** `$request->mergeIfMissing()` dot-notation | Low | No `mergeIfMissing` usage | Not applicable |
| **L12** `DatabaseTokenRepository` constructor seconds | Very Low | No custom token repository | Not applicable |
| **L12** `Blueprint`/`Grammar` constructor signatures | Very Low | No custom grammars, schema builders or DB drivers | Not applicable |
| **L12** route-name precedence unification | Low | No duplicate route names relied upon | Not applicable |
| **L12** `Concurrency::run` keyed results | Low | No `Concurrency` usage | Not applicable |

Also verified: **`env()` calls in `app/`, `bootstrap/`, `routes/` and `database/`: 0**,
so `config:cache` remains safe in production — the standing requirement recorded in
`07-TECH_STACK.md` ("never call `env()` directly outside the config file") still
holds for all runtime code. There is exactly **one** `env()` call outside
`config/**` anywhere in the repository, at
`tests/Feature/Connectors/ConnectorQueueRuntimeAlignmentTest.php:48`
(`env('DB_QUEUE_TABLE', 'jobs')`), which is test-only and cannot affect a cached
production config.

**Migration driver branching is the sharpest database-compatibility risk.** Across
**39** migration files there are **37** `getDriverName()` checks and **18**
`DB::statement` calls, concentrated in the connector foundation, pricing,
availability and the contractors→customers rename migrations. None of the
Laravel 12/13 schema changes listed above touch that code, but a framework jump is
exactly when hand-written MySQL-vs-SQLite branches diverge silently. This is why
verification layer **V7 requires `migrate:fresh --seed` on both SQLite *and*
MySQL 8**, not just the default driver, and why the two MySQL-isolated migration
tests (§15.2) matter disproportionately.

**Net assessment: the Laravel 11 → 13 step is low-risk for this codebase.**
Exactly one High-impact item applies (`VerifyCsrfToken` → `PreventRequestForgery`,
4 references) and exactly one Medium-impact item requires an explicit
implementation step (`HasUuids` on 18 models — preserve UUIDv4 per the recorded
direction in §10.3). Every other documented breaking change across
both guides is verifiably not applicable.

---

## 11. PHP / runtime feasibility

Laravel 13 sets the floor: `laravel/framework v13.24.0` requires **`php: ^8.3`**;
the support-policy table lists PHP **8.3 – 8.5**. Filament 5 requires PHP 8.2+
and Livewire 4 requires PHP 8.1+, so **Laravel 13 is the binding constraint**.

| Environment | Current evidence | Required change |
|---|---|---|
| `composer.json` platform | `"php": "^8.2"` | → `"^8.3"` (or `"^8.3\|^8.4"`) |
| Local Docker | `docker/php/Dockerfile:1` — `FROM php:8.3-fpm-alpine` | **Already compliant.** Optionally pin to a patch tag |
| CI | `.github/workflows/mysql-tests.yml` — `shivammathur/setup-php@v2`, `php-version: '8.3'` | **Already compliant** |
| Audit/dev environment | `PHP 8.3.6 (cli)` | Compliant |
| CLI / queue workers (Docker) | Same image as `app`; `queue`, `connector-queue`, `scheduler` services all build `docker/php/Dockerfile` | Compliant via the shared image |
| **Production (pilot host)** | **Not verifiable from repository evidence.** `DEPLOY.md` describes a "bare Ubuntu host, native PHP" with Supervisor-managed workers and instructs reading the live config from `/etc/supervisor/conf.d/babypark-queue.conf` rather than trusting documented values. No `.php-version`, no `composer.json` `config.platform`, no pinned PHP version anywhere in the repository | **Required pre-upgrade deployment check.** Must confirm the host PHP CLI/FPM version and the exact PHP binary path used by each Supervisor program *before* the Laravel 13 PR merges |
| Composer `config.platform` | `null` | Consider setting it to the production PHP version so CI resolution cannot drift ahead of production |

### Extension requirements

`laravel/framework v13.24.0` requires `ext-ctype`, `ext-filter`, `ext-hash`,
`ext-mbstring`, `ext-openssl`, `ext-session`, `ext-tokenizer`.
`filament/support v5.7.6` additionally requires **`ext-intl`**.

| Extension | Docker image | CI | Note |
|---|---|---|---|
| `mbstring`, `intl`, `bcmath`, `xml`, `zip`, `gd`, `exif`, `opcache`, `pdo_mysql` | present (`docker/php/Dockerfile`) | `mbstring, xml, curl, sqlite3, pdo_sqlite, pdo_mysql, bcmath, intl` | `ext-intl` satisfied in both |
| **`pcntl`** | present in `docker/php/Dockerfile` | **not in the CI extension list** | `07-TECH_STACK.md` mandates: "production workers must have the `pcntl` PHP extension installed, or connector jobs must fail deployment-readiness checks (Laravel requires `pcntl` to enforce job timeouts at all)". `07-TECH_STACK.md` records `pcntl` as verified on the pilot host. **Re-verify on the production host after any PHP version change** — a PHP upgrade can silently drop a compiled extension, and without `pcntl` the 45s and 900s connector job timeouts stop being enforced |
| `sqlite3`, `pdo_sqlite` | **absent from `docker/php/Dockerfile`** | present in CI | Not a production requirement; SQLite is the Cloud/dev database per `AGENTS.md`. Worth noting because the local Docker image cannot run the SQLite suite |

**This audit mutates no production configuration.**

---

## 12. Node / build-tool feasibility

| Item | Current | Target requirement |
|---|---|---|
| Node — dev/audit environment | `v22.14.0` | `vite@8` and `laravel-vite-plugin@3` require `^20.19.0 \|\| >=22.12.0` → **satisfied** |
| npm | `10.9.7` | No constraint found |
| Node — declared expectation | **none.** No `.nvmrc`, no `engines` in `package.json` | Add one — this is the only mechanism that would make the requirement enforceable |
| Node — local Docker | `docker/php/Dockerfile` installs `nodejs npm` from Alpine repos, **unpinned** | Whatever Alpine ships for the base image at build time. **Unverifiable and drift-prone.** Must be pinned if the frontend build runs in this image |
| Node — CI | **no Node step at all.** `.github/workflows/mysql-tests.yml` has no `actions/setup-node`, no `npm ci`, no `npm run build` | A Node setup + build step is required if the frontend build is to be verified before deploy |
| Node — production | **not verifiable from repository evidence.** `deploy.sh:7-8` runs `npm ci && npm run build` on the pilot host with no version guarantee | **Required pre-upgrade deployment check** |
| Vite | `6.4.3` | Tailwind 4 works on Vite 6 via `@tailwindcss/vite` (peer `^5.2.0 \|\| ^6 \|\| ^7 \|\| ^8`). Vite 8 is optional |
| `laravel-vite-plugin` | `1.3.0` | `3.1.3` would force `vite@^8` and add a `fontaine ^0.8.0` peer. Optional |
| Tailwind | `3.4.19` | `4.3.3` via `@tailwindcss/vite` or `@tailwindcss/postcss` |
| Build commands | `npm run build` → `vite build`; `npm run dev` → `vite` | Unchanged |

### The material finding

**The frontend build is currently verified in exactly one place: the production
host, inside `deploy.sh`.** CI never runs it. That is tolerable while the
frontend is a Tailwind 3 build feeding two legacy Blade pages. It is **not**
tolerable during a Tailwind 3 → 4 migration on an unpinned production Node
version, because the first place a build regression would surface is a live
deploy that has already run `git pull` and `composer install`.

**Adding a Node setup + `npm ci` + `npm run build` step to CI is a prerequisite
of the frontend migration, not an optional improvement.** It is the cheapest
single item in this entire audit and it removes the most dangerous unverified
path.

---

## 13. Upgrade-tool experiment

Performed entirely inside the disposable worktree `/tmp/solver/upgrade`, which
was destroyed afterwards. **The audit branch was never touched by any upgrade
tool.**

### 13.1 Setup

The bridge state from §4.4 scenario 3 was fully installed (Laravel 13.24.0 +
Filament 4.12.6 + Livewire 3.8.3 + `filament/upgrade ^4.0`), with
`policy.advisories.block` disabled *in the throwaway copy only* so the
intermediate graph could be materialized, and with `--no-scripts` so the
Filament 3 artisan hooks could not run against Filament 4 vendor code:

```console
$ composer update -W --no-scripts --no-interaction --prefer-dist
… 162 packages installed …
No security vulnerability advisories found.
EXIT=0
```

Installing Filament 4's vendor code first is what makes the experiment
meaningful: Rector's type-based rules (`RenameMethodRector` keyed on
`Filament\Tables\Table::class`, `AddInterfaceByTraitRector`, etc.) can only fire
if the target classes are resolvable.

### 13.2 What the v4 tool proposes for this actual repository

```console
$ vendor/bin/rector process app --config vendor/filament/upgrade/src/rector.php \
    --dry-run --clear-cache --no-progress-bar
…
 [OK] 115 files would have been changed (dry-run) by Rector
EXIT=2
```

Distribution of the 115 files:

| Area | Files |
|---|---|
| `app/Filament/**` (Resources, Pages, RelationManagers, Support, Cabinet) | **58** |
| `app/Support/Connectors/**` (incl. `AdobePaaS`, `Transport`, `Exceptions`) | 32 |
| `app/Services/Connectors/**` | 5 |
| `app/Jobs/Connectors/**` | 3 |
| `app/Providers/Filament/**` (both panel providers) | 2 |
| `app/Support/**` (Migrations, Filament), `app/Console/Commands/**` | 15 |

### 13.3 Classification of the proposed modifications

**Category A — genuine Filament API migration (handled automatically, must be reviewed).**
Verbatim excerpt from the dry-run diff for
`app/Filament/Resources/ConnectorAccountResource.php`:

```diff
+use Filament\Schemas\Schema;
+use Filament\Schemas\Components\Section;
+use Filament\Infolists\Components\TextEntry;
+use Filament\Schemas\Components\View;
+use Filament\Tables\Columns\TextColumn;
+use Filament\Actions\ViewAction;
-use Filament\Infolists\Infolist;

-    protected static ?string $navigationIcon = 'heroicon-o-link';
+    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-link';

-    public static function infolist(Infolist $infolist): Infolist
+    public static function infolist(Schema $schema): Schema
-        return $infolist
-            ->schema([
-                Infolists\Components\Section::make(__('connectors.ui.sections.account'))
+        return $schema
+            ->components([
+                Section::make(__('connectors.ui.sections.account'))
-                        Infolists\Components\TextEntry::make('connectorDefinition.name')
+                        TextEntry::make('connectorDefinition.name')
-                        Infolists\Components\View::make('filament.connector-accounts.runtime-state')
+                        View::make('filament.connector-accounts.runtime-state')
```

This is precisely the mechanical work the project needs, correctly applied,
including the `Infolists\Components\Section` → `Schemas\Components\Section`
versus `Infolists\Components\TextEntry` → `Infolists\Components\TextEntry`
split.

**Category B — unrelated cosmetic churn (must NOT be applied blindly).**
The v4 Rector config enables `$rectorConfig->importNames()` and
`$rectorConfig->importShortClasses()`, which rewrite fully-qualified global
class references throughout every processed file — including files with no
Filament involvement whatsoever. Two verbatim examples:

```diff
# app/Jobs/Connectors/ConnectorConnectionCheckJobExecutionException.php
 namespace App\Jobs\Connectors;
-final class ConnectorConnectionCheckJobExecutionException extends \RuntimeException
+use RuntimeException;
+
+final class ConnectorConnectionCheckJobExecutionException extends RuntimeException
```

```diff
# app/Support/Migrations/FieldFoundationMigrator.php
+use BackedEnum;
-            $expValue = $exp instanceof \BackedEnum ? $exp->value : $exp;
+            $expValue = $exp instanceof BackedEnum ? $exp->value : $exp;
```

**This is why the tool must not be trusted blindly.** Roughly half the proposed
diff (57 of 115 files) lands in the connector runtime, Adobe PaaS adapter, SSRF
transport and Field Foundation migrator — code that has nothing to do with
Filament and that `04-ARCHITECTURE_PRINCIPLES.md` protects most carefully
(checklist items 17, 21, 22). Mixing import normalization into the same commit as
a Filament major upgrade would make the diff unreviewable at exactly the point
where review matters most.

**Mitigation:** run the tool with a narrowed path set (`app/Filament`,
`app/Providers/Filament`, and the specific `app/Support` Filament helpers)
rather than all of `app`, and treat any repository-wide import normalization as
a separate, opt-in, Pint-adjacent commit.

**Category C — requires project-specific judgment (not automatable).**

* The four published vendor Blade overrides (§7.4) — the tool processes PHP only; `resources/views/vendor/**` is invisible to it.
* All 45 `<x-filament*::…>` Blade component usages (§7.5) — the `RenameStringRector` view-name mappings apply only inside PHP.
* Every selector in `resources/views/filament/partials/table-toolbar-overrides.blade.php` targeting Filament's `fi-ta-*` classes.
* The `filament-config` `file_generation` flag choice and the `default_filesystem_disk` env-key change (§7.3).
* Whether to run `php artisan filament:upgrade-directory-structure-to-v4`.
* Toolbar-action placement semantics after `bulkActions()` → `toolbarActions()`.

### 13.4 The staged-path comparison

The same disposable environment was used to run the **v5** Rector config against
the *same* Filament 3 `app/`:

```console
$ vendor/bin/rector process app --config /tmp/rector-v5-config.php \
    --dry-run --clear-cache --no-progress-bar

 [OK] Rector is done!
EXIT=0
```

**Zero files changed.** Side by side:

| Upgrade tool | Files it would change in this repository |
|---|---|
| `filament/upgrade` **v4** (`vendor/bin/filament-v4`) | **115** |
| `filament/upgrade` **v5** (`vendor/bin/filament-v5`) | **0** |

This is the empirical core of the Route B decision in §6. All automation for the
API surface this project actually uses lives in the v4 tool.

### 13.5 Cleanup

```console
$ git worktree list
/workspace  9713d03 [cursor/gap-024-target-stack-feasibility-audit]
```

The `/tmp/solver/upgrade` worktree — with its Filament 4 vendor tree, modified
`composer.json`/`composer.lock`, and Rector caches — was removed. `git status
--short` on the audit branch is empty.

---

## 14. Security / dependency health

No fixes were applied. Both audits are read-only.

### 14.1 Composer

```console
$ composer audit --locked
Found 21 security vulnerability advisories affecting 5 packages
EXIT=1
```

| Package | Locked | Advisories | Highest severity | Notable |
|---|---|---|---|---|
| `laravel/framework` | `v11.54.0` | **3** | high | `PKSA-3r5d-mb8f-1qw9` (high, CRLF injection in default email rule, affected `<12.60.0\|>=13.0.0,<=13.9.0`); `PKSA-mdq4-51ck-6kdq` / **CVE-2026-48019** (affected `>=11.0.0,<12.0.0` — i.e. *all* of 11.x); `PKSA-m5cs-t1y6-qpcs` (medium, temporary signed URL path confusion) |
| `guzzlehttp/guzzle` | `7.10.6` | **9** | high | `PKSA-gcrk-3vtt-1r14` / CVE-2026-69246 (high, noncanonical host bypasses host-based checks — **directly relevant to the SSRF transport**, fixed in `7.15.2`); plus 8 medium (cookie scope/domain, `Proxy-Authorization` leakage to origin, HTTPS proxy downgrade, unbounded response cookies, `Referer` fragment disclosure) |
| `league/commonmark` | `2.8.2` | **6** | high | 4 high + 2 medium DoS/filter-bypass advisories, all fixed in `2.9.0`; reported `2026-08-06` — one day before this audit |
| `filament/forms` | `v3.3.52` | **1** | high | `PKSA-n7tx-gkfb-14yj` / **CVE-2026-55409** — "Filament: Disabled RichEditor field state can be used for XSS", affected `>=3.0.0,<=3.3.52`. **The locked version is the last affected version**; fixed in `v3.3.53` |
| `guzzlehttp/psr7` | `2.10.4` | **2** | medium | `PKSA-vznr-tgp9-fd7d` / CVE-2026-59882 (host confusion via weak URI host validation, fixed `2.12.3`); `PKSA-7qs6-zvnz-h66r` / CVE-2026-55766 (CRLF injection in start-line serialization, fixed `2.12.1`) |

### 14.2 npm

```console
$ npm audit --package-lock-only
5 vulnerabilities (3 high, 2 critical)
EXIT=1
```

| Package | Locked | Severity | Advisories |
|---|---|---|---|
| `shell-quote` (via `concurrently 9.2.1`) | `<=1.8.4` | **critical** | GHSA-w7jw-789q-3m8p (unescaped newlines in object `.op` values); GHSA-395f-4hp3-45gv (quadratic-complexity DoS in `parse()`) |
| `axios` | `1.16.1` | high | **10** advisories: `formDataToJSON` recursion DoS, prototype-pollution paths, `maxBodyLength` bypasses (fetch `ReadableStream` and HTTP/2), `NO_PROXY` bypass for `0.0.0.0`, inherited proxy after interceptor cloning, form-serializer `maxDepth` bypass |
| `form-data` | `4.0.0 – 4.0.5` | high | GHSA-hmw2-7cc7-3qxx (CRLF injection via unescaped multipart field names/filenames) |
| `postcss` | `8.5.15` | high | GHSA-r28c-9q8g-f849 and GHSA-fxqj-rqcc-2cmp (path traversal via attacker-controlled `sourceMappingURL` → arbitrary `.map` disclosure) |

Note that `concurrently` and `axios` are **devDependencies** used only in local
development (`composer.json`'s `dev` script and `resources/js/bootstrap.js`);
`postcss` is a build-time dependency. None ships to the browser as-is. That
lowers exploitability but does not remove the advisories.

### 14.3 Risk separation, as required

| Category | Content |
|---|---|
| **Unsupported framework risk (no advisory needed)** | Laravel 11 passed its security-support end date on 2026-03-12 (`laravel.com/docs/13.x/releases`). *Future* vulnerabilities in 11.x will not receive patches. This is a lifecycle risk, distinct from any specific advisory. Laravel 12's bug-fix window also ends 2026-08-13. |
| **Actual published advisories with a fix available** | `guzzlehttp/guzzle` (9), `guzzlehttp/psr7` (2), `league/commonmark` (6), `filament/forms` (1, fixed in `v3.3.53`) — 18 Composer advisories plus all 5 npm findings. All have fixed versions the project could reach. |
| **Actual published advisories with *no* fix available on the current line** | `laravel/framework` — `PKSA-3r5d-mb8f-1qw9` is fixed in `12.60.0` / `13.10.0`, and `PKSA-mdq4-51ck-6kdq` (CVE-2026-48019) lists `>=11.0.0,<12.0.0` as affected in its entirety. **Because Laravel 11 is EOL, there is no 11.x release that fixes these.** This is the sharpest security argument for the migration: the only remediation path is the major upgrade itself. |
| **Outdated dependency (no advisory)** | `laravel/tinker` 2.11.1, `laravel/pint` 1.29.1, `laravel/sail` 1.61.0, `laravel-lang/lang` 15.32.0, `predis/predis` 3.4.2, `nunomaduro/collision` 8.9.4, `tailwindcss` 3.4.19, `vite` 6.4.3, `laravel-vite-plugin` 1.3.0, `autoprefixer` 10.5.0 |
| **Migration debt (no advisory, no CVE)** | 1,658 lines of forked Filament 3 vendor Blade; Tailwind 3 configuration idioms; Filament 3 namespace usage across 85 files; no Node pin; no CI frontend build; unverified production PHP/Node versions |

To be explicit about a distinction the task demands: **Laravel 11 being
unsupported is not itself a CVE.** But in this case there *are* real advisories
against the locked Laravel 11 version whose fixes exist only in 12.x/13.x — so
the two categories reinforce each other here rather than being conflated.

---

## 15. Testing impact

### 15.1 Current baseline

Established on the audit branch immediately before writing this report
(SQLite, per `AGENTS.md`):

```console
$ php artisan test
  Tests:    2 skipped, 1303 passed (30183 assertions)
  Duration: 51.12s
```

149 test files, totalling **1305** test methods (`php artisan test --list-tests`):
80 under `tests/Unit`, 67 under `tests/Feature`, and **2 under
`tests/Integration/MySql`** (`ProductTagWorkspaceForeignKeyTest`,
`WorkspaceTaxDefaultsMigrationTest`).

Note that `phpunit.mysql.xml` defines a deliberately **isolated** `MySQLMigration`
suite containing only `tests/Feature/CustomerRenameMigrationTest.php` and
`tests/Feature/FieldFoundationMigrationTest.php`, with a committed comment
explaining why: "MySQL migration tests that run raw DDL must not share a PHPUnit
run with ordinary `RefreshDatabase` tests: DDL on MySQL implicitly commits any
open transaction and breaks isolation for every subsequent test in the same run."
The CI workflow reaches those tests via `--filter` rather than by using
`phpunit.mysql.xml`, so **the isolation contract that file documents is currently
enforced by convention, not by CI configuration** — worth preserving deliberately
when the migration adds or reorders MySQL test steps.

### 15.2 Classification of the affected test surface

| Layer | Files / evidence | Migration exposure |
|---|---|---|
| **Filament Resource tests** | 23 test files reference `Filament\` / `filament` | **High.** Namespace unification (`Form`→`Schema`, `Infolist`→`Schema`, `Tables\Actions\*`→`Actions\*`) breaks imports and action-name assertions. Mostly compile-time, so failures will be loud |
| **Livewire tests** | **206** `Livewire::actingAs(...)->test(...)` chains across 22 files, plus 37 `fillForm`, 24 form-error assertions, 18 `assertCanSeeTableRecords`, 8 `callTableAction` | **High, but well covered.** Livewire 4 runtime changes will be observed by the suite at the interaction level. The residual gap is visual, not behavioural |
| **Connector UI tests** | `ConnectorAccountResource` + `ConnectionChecksRelationManager` render tests; `tests/Feature/ConnectorAccountFoundationTest.php` | **High.** Both classes carry `->poll('5s')`, which moves from Filament 3 tables to Filament 5 tables *and* onto Livewire 4's non-blocking poll implementation |
| **Authorization / rendered-view tests** | e.g. `WorkspaceTaxDefaultsFeatureTest` ("admin can access…", "manager without permission cannot access…") | **High.** `VerifyCsrfToken` → `PreventRequestForgery` plus Filament 5 panel auth changes; Checklist item 3 |
| **Localization tests** | `lang/**` (uk/ru/en); GAP-019 tracks partial localization | **Medium.** Filament ships its own translations; a major bump can change vendor translation keys. `04`'s `validation.required` message contract must still hold |
| **Theme / UI tests** | `tests/Feature/UiDesignSystemDocumentationTest.php`, `GovernancePageTest.php` | **Medium.** Assertions coupled to Filament markup or class names |
| **Documentation tests** | `ConnectorAccountDocumentationTest.php` (asserts on `04`, `07`, `Project_Documentation_Map.md`, `IMPLEMENTATION_GAPS.md` incl. a GAP-024 section regex), `ImplementationGapsTest.php`, `CanonicalRegistryIntegrityTest.php`, `ChannelDecisionValidatorTest.php`, `CoreFieldNamingMigrationTest.php`, `DomainModelCatalogProjectionReferencesTest.php` | **Low for the migration, but a hard gate for GAP-024 closure.** `ConnectorAccountDocumentationTest::gap024Section()` matches `/## GAP-024 —.*?(?=\n## GAP-021 —)/s`, so any future edit to the GAP-024 entry must preserve that structure. **No documentation test references `docs/audits/`, and none requires a new file to be registered in an index** — see §19 |
| **Full SQLite suite** | 1303 passing | The primary regression gate |
| **MySQL CI** | `.github/workflows/mysql-tests.yml` — 3 targeted `--filter` runs, full suite, `pint --test`, `git diff --check` | **Required.** Confirms Laravel 12/13 grammar changes against the production driver |
| **Frontend build** | **no CI coverage at all** | **Critical gap** (§12) |
| **Visual verification** | none | **Critical gap** (§16) |

### 15.3 Proposed upgrade verification matrix

Composer resolution is necessary but nowhere near sufficient. Each proposed
implementation PR (§17) must clear every applicable row before merge.

| # | Verification layer | Command / method | Gate | PR1 | PR2 | PR3 | PR4 | PR5 |
|---|---|---|---|---|---|---|---|---|
| V1 | Dependency resolution | `composer update --dry-run -W` then real `composer update` | exit 0 | ● | ● | ● | ● | ● |
| V2 | Composer advisories | `composer audit --locked` | no advisory without an available fix | ● | ● | ● | ● | ● |
| V3 | Autoload / boot sanity | `php artisan about`, `config:cache`, `route:cache`, `view:cache` | all succeed | ● | ● | ● | ● | ● |
| V4 | Code style | `vendor/bin/pint --test` | clean | ● | ● | ● | ● | ● |
| V5 | Full SQLite suite | `php artisan test` | ≥ 1303 passing, 0 failing | ● | ● | ● | ● | ● |
| V6 | MySQL 8 suite | `.github/workflows/mysql-tests.yml` on the PR | green | ● | ● | ● | ● | ● |
| V7 | Migrations on a clean DB | `php artisan migrate:fresh --seed` (SQLite **and** MySQL) | succeeds | ● | ● | — | — | — |
| V8 | **npm build** | `npm ci && npm run build` **in CI** | succeeds; added by PR1 | ● | ● | ● | ● | ● |
| V9 | Panel smoke — `/admin` | authenticated render of login, dashboard, product table, a form, a resource view page | HTTP 200, no console error | — | ● | ● | ● | ● |
| V10 | Panel smoke — `/cabinet` | `customer`-guard login, catalogue table, card view, cart drawer, order submit | HTTP 200, functional | — | ● | ● | ● | ● |
| V11 | **`novalidate` assertion** | **`tests/Feature/FilamentFormValidationTest::test_panel_forms_render_with_novalidate` already exists** — keep it green; extend it to cover `/cabinet` and a modal form | present (per `04`) | — | — | ● | ● | ● |
| V12 | **Connector dispatch idempotency + polling smoke** | existing service-level tests stay green (`second_dispatch_returns_same_row_and_does_not_push_second_job`, compensation, stale-row recovery in both dispatch-service suites) plus a runtime/manual polling smoke under Livewire 4; a new automated concurrency test **only if** a real uncovered mutable race is identified (§8.1) | no duplicate/orphan `queued` row | — | — | ● | ● | ● |
| V13 | Connector queue lane alignment | re-verify `config/queue.php` `retry_after` (90 / 1200) vs job `$timeout` (45 / 900) vs lock `expireAfter` (120 / 1100) against the process manager | matches `07-TECH_STACK.md` | ● | ● | — | — | ● |
| V14 | `pcntl` present on workers | `php -m` on the host / in the worker image | present | ● | — | — | — | ● |
| V15 | SSRF transport fail-closed | existing Guzzle cURL-handler assertion test after the `7.15.3` bump | still fails closed | ● | — | — | — | ● |
| V16 | Encrypted credential round-trip | `encrypted:array` decrypt after upgrade; `APP_PREVIOUS_KEYS` path | succeeds | ● | — | — | — | ● |
| V17 | Localization | uk/ru/en assertions; `validation.required` message contract from `04` | unchanged | — | ● | ● | ● | ● |
| V18 | **Visual regression** | §16 before/after comparison on both panels | human sign-off | — | — | ● | ● | ● |
| V19 | Docs tests | `php artisan test --filter=Documentation`, `--filter=ImplementationGaps` | green | ● | ● | ● | ● | ● |
| V20 | **Filament published JS assets regenerated** | verify the existing `post-autoload-dump` → `@php artisan filament:upgrade` hook regenerated the 16 files under `public/js/filament/**` after the Filament-major Composer operation, and commit the result (`php artisan filament:assets` only as diagnostic/repair; §9.5) | no Filament-3-generation asset survives | — | — | ● | ● | ● |
| V21 | **Margin-format toggle via `Livewire::current()`** | exercise the margin toggle in both admin and cabinet product tables (9 call sites) | percent/absolute switch still works | — | — | ● | ● | ● |
| V22 | **Authorization matrix unchanged** | the §7.8 inventory (23 `can*()` overrides, 6 `canAccess()`, `ConnectorAccountPolicy`, Merchandiser presentation) verified against the migrated invocation path; existing authorization tests green plus only the missing role/permission regression cases | **no access broadening; merge blocker** | — | — | ● | ● | ● |
| V23 | **Live filter behavior preserved** | filters still apply immediately (no Apply button) via the shared `deferFilters(false)` mechanism (§7.7) | behavior identical to Filament 3 | — | — | ● | ● | ● |

---

## 16. Visual regression requirement

The project has two Filament panels with different users, permissions and
visibility rules (`07-TECH_STACK.md`, `## Current Panels`): `/admin` (merchant /
staff) and `/cabinet` (B2B buyer). Filament 5 rebuilds the compiled stylesheet on
Tailwind 4, restructures the tables template (1,286 → 2,604 lines) and changes
toolbar-action placement — and this project forks the very template that governs
its toolbar layout. Automated tests will not catch any of that.

**Screens that must be visually compared before and after the upgrade.** Each
must be captured in light, dark and system appearance (`07-TECH_STACK.md`:
"Appearance: Light / Dark / System mode through Filament and Tailwind
dark-mode support") and at desktop plus the `06-UI_DESIGN_SYSTEM.md` mobile
breakpoints.

| # | Surface | Why it is high-value | Specific risk to look for |
|---|---|---|---|
| 1 | `/admin` login | Filament 5 moved `Filament\Pages\Auth\Login` → `Filament\Auth\Pages\Login` | Layout/branding regression; `PreventRequestForgery` POST failure |
| 2 | `/cabinet` login | Custom `App\Filament\Cabinet\Pages\Auth\Login` on the `customer` guard | Same, plus custom page breakage |
| 3 | Navigation / sidebar / topbar | 19 `$navigationIcon`, 17 `$navigationGroup`, 18 `$navigationSort`, 8 `$navigationLabel` declarations; `Filament\Support\Concerns\HasExtraSidebarAttributes` moved to `Filament\Navigation\Concerns` | Group ordering, icon rendering, collapsed state |
| 4 | Admin product table (default view) | `06-UI_DESIGN_SYSTEM.md` specifies admin product table defaults, toolbar and column-visibility behavior; the forked `filament-tables::index` implements the toolbar contract | **Highest risk.** Search-field position, filter/column-toggle grouping, `px-4 sm:px-6` padding, row action zones |
| 5 | Shared data-list toolbar | `resources/views/components/filament/data-list-toolbar.blade.php` (114 lines). `07-TECH_STACK.md` mandates a one-row `md` responsive contract: "The main header row must not use `flex-col`, `flex-wrap`, or a different mode-switch breakpoint" | The `md` overflow-panel behavior must survive exactly; verify at and just below `md` |
| 6 | Product context drawer / modal | `06-UI_DESIGN_SYSTEM.md` context-drawer spec; `filament-actions::components.modals` fork collapses 315 → 19 lines and `<form>` moves to `filament/support`'s modal component | Drawer width, header, footer action alignment, **`novalidate` presence** |
| 7 | Admin forms (product edit, price list item, delivery setting) | 19 `form(Form $form)` signatures; `->schema()` → `->components()` across 54 call sites; `filament-panels::components.form.index` fork targets a **removed** view | Section/grid/tab layout, `columnSpan`, **`novalidate` presence**, inline error placement |
| 8 | Price Inspector page | `resources/views/filament/pages/price-inspector.blade.php` (196 lines); the one fully-localized surface per GAP-019 | Custom Blade + Tailwind classes without a Filament theme (§9.1) |
| 9 | Field Matrix + Governance pages | 245 and 166 lines of custom Blade, 4 and 1 `wire:model` bindings, 57 arbitrary-value utilities concentrated here | Tailwind 4 arbitrary-value resolution; `dark:` variants |
| 10 | Connector account **list** | `->poll('5s')` at `ConnectorAccountResource.php:127`; status badges via `ConnectorUiFormatter` | Badge colours (`.bp-muted-badge` from `design-tokens.css`), poll refresh not flickering |
| 11 | Connector account **detail** (infolist) | `infolist(Infolist $infolist)` → `Schema`; embeds `filament.connector-accounts.runtime-state` via `Schemas\Components\View` | Section layout; `wire:poll.5s="refreshConnectionState"` still updating |
| 12 | Connector connection-check **history** relation manager | `->poll('5s')` at `ConnectionChecksRelationManager.php:48`; the five documented disabled states plus the hidden manual-trigger deployment gate | Relation-manager table layout; **the manual discovery trigger must remain *hidden* (not disabled) while `CONNECTOR_DISCOVERY_MANUAL_TRIGGER_ENABLED=false`**, exactly as `07-TECH_STACK.md` requires |
| 13 | B2B catalogue table view (`/cabinet`) | Cabinet `ProductResource` `ListProducts`; the `TOOLBAR_TOGGLE_COLUMN_TRIGGER_AFTER` render hook injects `@livewire('cabinet.cart-toolbar')` | Cart toolbar still rendering in the right slot after the constant rename |
| 14 | B2B catalogue card/grid view | `06-UI_DESIGN_SYSTEM.md` grid/list/table modes | Card layout, image thumbnails, `ProductLightbox` `BODY_END` hook |
| 15 | Quantity selector + cart drawer + checkout | `filament/cabinet/columns/quantity-order.blade.php` (`wire:model.lazy`); `SessionCart`; `NavigationItem::make('Кошик')` badge | Stepper interaction under Livewire 4; cart badge count |
| 16 | Availability + pricing display | `06-UI_DESIGN_SYSTEM.md` availability colour system and role-based pricing rules | Availability colour tokens against Filament 5's rebuilt palette |
| 17 | Product photo lightbox | `ProductLightbox::bodyEndHook()` at `PanelsRenderHook::BODY_END`; inline-styled markup in `layouts/cabinet.blade.php` | Render-hook constant still valid; z-index/overlay |
| 18 | Mobile / responsive both panels | `06-UI_DESIGN_SYSTEM.md` breakpoints and B2B buyer bottom navigation | Toolbar `md` collapse (see #5); bottom nav |
| 19 | Dark / light / system | 190 `dark:` variants; `design-tokens.css` `.dark` block tuned to "Filament's near-black panel background" | `--bp-muted-*` contrast after the palette rebuild |
| 20 | Toasts / notifications | 11 `Filament\Notifications\` imports; `06-UI_DESIGN_SYSTEM.md` position and duration rules | Position, duration, stacking |

**Method.** Capture the "before" set on `develop` *first* — the comparison
baseline must exist before the migration branch changes anything. Because there
is no visual-regression harness today, the migration should establish one
(a scripted authenticated screenshot pass over the 20 surfaces above, or manual
capture with a documented checklist) as part of PR1's tooling work.

**No visual migration is performed in this audit.** This section defines what
must be checked later.

---

## 17. Migration decomposition

Ordering constraints established by evidence, not preference:

1. Full re-resolution of the current Laravel-11 graph is blocked by Composer's advisory policy (§4.2), and Filament 4 on Laravel 11 fails for exactly that reason (§4.4 scenario 2). **The framework must move before, or together with, any Filament change.**
2. Laravel 13 can be reached with Filament and Livewire untouched, because Filament `v3.3.54` already declares `illuminate/* ^13.0` (§4.4 scenario 1). **A runnable, testable Laravel-13-on-Filament-3 state exists.**
3. Filament 4 requires Livewire `^3.5` and Filament 5 requires Livewire `^4.1` (§2.2). **The Livewire major bump belongs to the 4→5 step, not the 3→4 step.**
4. All v3→v4 automation lives in the v4 tool; the v5 tool changes 0 files on this codebase (§13.4). **The Filament work must be split at the 3→4 boundary.**
5. The Filament 4 upgrade guide requires **Tailwind CSS v4.1+** when a custom theme CSS file is in use, and custom themes are the recommended (upstream-required) direction for this project's own Tailwind usage (§9.1). **Tailwind 3 → 4.1+ and the custom themes therefore belong to the Filament 3→4 checkpoint (PR3)**, not to the 4→5 step — PR4 inherits an already-supported Tailwind 4.x and performs no further Tailwind major migration.

The decomposition the task proposed is therefore technically possible and is
adopted, with one refinement: **the Laravel 13 step should be its own PR that
deliberately leaves Filament on 3.3.54**, because the solver proves that state is
valid and it converts the highest-severity risk (an EOL framework with
unfixable advisories) into a shipped improvement long before any UI risk is
taken.

### PR1 — Runtime, toolchain and verification prerequisites

*No framework or UI change. Leaves the repository fully runnable.*

* Verify and, if needed, raise production and Docker PHP to 8.3+ (§11). Record the verified production PHP version and each Supervisor program's PHP binary path in `DEPLOY.md`.
* Confirm `pcntl` on all worker runtimes (`07-TECH_STACK.md` requirement; §11).
* Add a Node version pin (`.nvmrc` and/or `package.json` `engines`) and pin the Alpine Node install in `docker/php/Dockerfile` (§12).
* **Add a Node setup + `npm ci` + `npm run build` step to `.github/workflows/mysql-tests.yml`** (§12) and `sqlite3`/`pdo_sqlite` to the Docker image if the local SQLite suite is wanted there.
* Establish the visual-regression baseline capture over the 20 surfaces in §16, on `develop`, before anything changes.
* Delete the three orphaned Blade views identified in §7.5 (85 lines, 0 references) — removal is behavior-neutral by evidence — so they do not enter the migration review surface. No other cleanup or redesign.
* Extend the **existing** `novalidate` test (V11) to cover the `/cabinet` panel and at least one modal form, while still green on Filament 3. The connector dispatch-idempotency suites already exist (§8.1) and simply remain part of the gates — no new concurrency test is written speculatively.
* Ensure the current baseline remains green throughout (V5/V6).
* Gates: V1–V8, V13–V16, V19.

### PR2 — Laravel 11 → 13 (Filament stays on 3.3.54, Livewire on 3.x)

*Solver-proven state (§4.4 scenario 1). Fully runnable and testable.*

* `composer.json`: `php ^8.3`, `laravel/framework ^13.0`, `laravel/tinker ^3.0`, `phpunit/phpunit ^12.0`. Allow `filament/*` to float to `v3.3.54` — which also clears the `filament/forms` XSS advisory `PKSA-n7tx-gkfb-14yj` (§14.1).
* `VerifyCsrfToken::class` → `PreventRequestForgery::class` in both panel providers (4 references; §10.1). Functionally test both logins and form POSTs.
* **Preserve UUIDv4 behavior** on the 18 `HasUuids` models via the current Laravel-supported UUIDv4 mechanism (`HasVersion4Uuids` or the exact equivalent re-verified at execution time; §10.3) — the recorded behavior-preserving direction, not an open decision.
* Optionally add `'serializable_classes' => false` to `config/cache.php` as hardening (§10.2).
* Resolve Laravel breaking changes only — no Filament UI migration in this PR.
* Re-verify the connector queue lane alignment against `config/queue.php` and the process manager, per the standing `07-TECH_STACK.md` instruction (§10.4) — queue-runtime invariants must be preserved, not assumed.
* **Promote `guzzlehttp/guzzle` to an explicit direct `require` entry** at `^7.15.2` or higher (§10.4): the direct `GuzzleHttp\*` imports were re-verified during the correction pass (2026-08-08: 10 `use GuzzleHttp` statements importing 11 classes across 5 files under `app/Support/Connectors/**`) while the package remains only a transitive Laravel dependency.
* Re-run the connector transport / SSRF regression coverage — the fail-closed `CurlHandler` assertion and the encrypted-credential round-trip — after the Guzzle `7.15.3` bump.
* Gates: V1–V10, V13–V17, V19.

**After PR2 the project is on a fully supported framework with the unfixable
Laravel 11 advisories resolved — the core of GAP-024's stated impact — while the
UI has not been touched at all.**

### PR3 — Filament 3 → 4 + Tailwind 3 → 4.1+ + custom themes (Livewire stays on 3.x)

*Solver-proven state (§4.4 scenario 3). Explicitly a bridge, not a destination.
Intermediate state: Laravel 13 + Filament 4 + Livewire 3.x + Tailwind 4.1+ with
custom admin and cabinet themes.*

* Run `filament/upgrade ^4.0` / `vendor/bin/filament-v4` with a **narrowed path set** (`app/Filament`, `app/Providers/Filament`, the Filament helpers in `app/Support`), keeping the import-normalization churn out of the connector runtime (§13.3).
* Review all 115 proposed changes; apply Category A, reject or separate Category B, hand-resolve Category C.
* **Create the proper custom Filament themes** (§9.1): one thin entrypoint per panel, shared design-token/`@source` definitions, panel visibility policies and Light/Dark/System behavior preserved.
* **Migrate Tailwind 3 → 4.1+** in the same checkpoint — required by the Filament 4 upgrade guide once custom themes exist: `@tailwindcss/vite` on the **current Vite major** (§9.4); `@import "tailwindcss"` in `app.css`; `content` globs → `@source`; `theme.extend` → `@theme`; drop `autoprefixer` (§9.2). Audit the **78 renamed-scale utility occurrences** (`shadow-sm` 22, `rounded-sm` 12, `outline-none` 20, `space-y-` 24) and consolidate the triple-defined primary palette (§9.3).
* **Authorization review per §7.8** — verify every `can*()`/`canAccess()` rule against the v4 invocation path, migrate to policy/authorization-response API where needed, and add only the missing role/permission regression tests. **Any authorization regression is a merge blocker.**
* **Preserve live filter behavior** via the shared `deferFilters(false)` mechanism (§7.7).
* Publish `config/filament.php`; pin `default_filesystem_disk` to `FILAMENT_FILESYSTEM_DISK` and set the `file_generation.flags` to preserve the v3 directory/embedding style (§7.3).
* **Reconcile the four published vendor Blade overrides against Filament 4** (§7.4) — public extension points investigated first, re-derivation only where none suffices — with the `novalidate` test (V11) as the gate and its extended `/cabinet`/modal coverage kept green.
* Rename `TOOLBAR_TOGGLE_COLUMN_TRIGGER_AFTER` → `TOOLBAR_COLUMN_MANAGER_TRIGGER_AFTER`; re-verify the `BODY_END` and `STYLES_AFTER` hooks.
* Verify all 45 `<x-filament*::…>` Blade component usages and every `fi-ta-*` selector in `table-toolbar-overrides.blade.php`.
* **Verify the 16 committed Filament JS assets were regenerated by the existing `post-autoload-dump` `filament:upgrade` hook** and commit the result (§9.5).
* Gates: V1–V6, V8–V12, V17–V23. **V18 (visual) is mandatory here.** **PR3 may not merge with a known UI, security, authorization, `novalidate`, filter-behavior or required-visual regression.**

### PR4 — Filament 4 → 5 + Livewire 3 → 4

*Final target state: Laravel 13 + Filament 5 + Livewire 4 + Tailwind 4.x.
Tailwind is already on a supported 4.x from PR3 — this PR performs **no further
Tailwind major migration** (a Tailwind 4 patch/minor bump is permitted only if
solver/security evidence at execution time requires it).*

* `filament/filament ^5.0`, `livewire/livewire ^4.0`; run `vendor/bin/filament-v5` (which will make the `Resource::can()`/`authorize()`/`getAuthorizationResponse()` `$action` signature change).
* Livewire 4: change `wire:model.blur` → `wire:model.live.blur` in the reconciled `search-field` override if it still exists after PR3 (§8). Optionally move the 4 cabinet route registrations to `Route::livewire()`. Everything else in the guide is not-used or backward-compatible.
* Re-check the vendor Blade overrides **again** against Filament 5's restructured templates — including the fact that `filament-panels::components.form.index` no longer exists and `filament-actions::components.modals` is now 19 lines with no `<form>` (§7.4). Verify `novalidate` (V11).
* Verify connector polling behavior under Livewire 4's non-blocking `wire:poll` (§8.1, V12) and the margin-format / `Livewire::current()` surfaces (V21) if still applicable.
* **Verify the Filament JS assets were regenerated by the existing Composer hook** and commit the result (§9.5).
* **No Vite major modernization** unless forced by fresh implementation-time evidence (§9.4).
* Gates: V1–V6, V8–V12, V17–V23. **V18 is mandatory and is the primary gate.** **PR4 may not merge with a known regression.**

### PR5 — Optional hardening and cleanup (no deferred defects)

*Only after PR1–PR4 are green. **PR5 is optional and is not intrinsically
required to close GAP-024** — PR1–PR4 are the mandatory migration path; PR5
exists only if hardening/cleanup work is actually identified and wanted before
closure. If PR5 is skipped, PR6 follows PR4 directly. **PR5 is not a defect
backlog for PR3 or PR4** —
any known regression in authorization, workspace isolation, security, form
validation, `novalidate`, filtering behavior, panel login/auth, connector
runtime behavior, required business behavior, or a visual contract included in
the applicable migration gate must have been fixed inside the PR that
introduced it, before that PR merged.*

PR5 may contain only:

* optional hardening (e.g. the `'serializable_classes' => false` explicit pin if not already added in PR2);
* deprecation cleanup that is proven behavior-neutral;
* extra runtime verification — e.g. re-running the full connector runtime contract set end to end (queue lane timings, `WithoutOverlapping` shared locks, `retryUntil()` deadlines, dispatch-failure compensation, stale-row recovery, sanitized failed-job exceptions, SSRF fail-closed transport, `encrypted:array` round-trip, `APP_PREVIOUS_KEYS`) and confirming the discovery manual-trigger gate still behaves per `07-TECH_STACK.md` (hidden, not disabled, while `CONNECTOR_DISCOVERY_MANUAL_TRIGGER_ENABLED=false`; refused at the dispatch service even when called directly);
* removal of temporary compatibility scaffolding if proven safe;
* non-blocking technical debt identified after all mandatory gates were already green.

Gates: V1–V23 (as applicable to what PR5 actually touches).

### PR6 — Documentation and GAP-024 closure (truth sync)

*Mandatory. Follows the mandatory PR1–PR4 path once the target state is fully
implemented and verified — and follows PR5 as well only if PR5 is actually
performed before closure. If PR5 is skipped, PR6 follows PR4 directly.*

* Update `docs/07-TECH_STACK.md` to describe the now-active Laravel 13 / Filament 5 / Livewire 4 / Tailwind 4 stack — **only after PR1–PR4 (plus PR5, if performed) have merged and been verified**, per `05-AI_WORKING_AGREEMENT.md`'s prohibition on prematurely rewriting project truth.
* Move GAP-024 to Closed in `docs/IMPLEMENTATION_GAPS.md`, preserving the section structure that `ConnectorAccountDocumentationTest::gap024Section()` matches (`/## GAP-024 —.*?(?=\n## GAP-021 —)/s`; §15.2).
* Update `DEPLOY.md` with the verified production PHP/Node versions and any changed Supervisor commands.
* Gates: V4, V5, V6, V19.

**Discovery Overview UI becomes technically ready after PR4 (the target-stack
completion gate), but implementation begins only after this PR6 truth-sync /
GAP-024 closure is merged** — `docs/07-TECH_STACK.md` is the implementation
guardrail future UI tasks must read before writing code, and
`05-AI_WORKING_AGREEMENT.md` requires documentation and implementation to stay
synchronized, so no new panel UI may be started while `07-TECH_STACK.md` still
describes the pre-migration active stack. Canonical sequence:
`PR1 → PR2 → PR3 → PR4 → [PR5 if needed] → PR6 → Discovery Overview UI`
(§18, "Discovery UI gate").

**No PR in this decomposition is implemented by this audit.**

---

## 18. Required final conclusion

### Target-state feasibility

**Can we move this repository now to Laravel 13 + Filament 5 + Livewire 4 + Tailwind 4?**

## **GO WITH PREREQUISITES**

The dependency graph is solvable — `composer update --dry-run -W` resolves
`laravel/framework v13.24.0` + `filament/filament v5.7.6` +
`livewire/livewire v4.3.5` on PHP `^8.3` with exit code 0, requiring no package
replacement or removal (§4.3). No external event is needed to unblock the
target. But it is not reachable by dependency bumps alone; the prerequisites
below must land as part of the migration.

### Migration route

**Staged `3 → 4 → 5`**, with Filament 4 as a temporary bridge only.

The evidence is empirical, not stylistic. The official `filament/upgrade` v5
Rector config contains exactly one rule (a parameter-type widening on three
`Resource` authorization methods) and, run against this repository's real `app/`,
changes **0 files**. The v4 config contains 231 class renames, 19 Blade-view-name
renames, 20 method renames, six bespoke rules and trait/interface injection, and
changes **115 files** — including every rename this project actually needs
(`Forms\Form`→`Schemas\Schema`, `Infolists\Infolist`→`Schemas\Schema`,
`Tables\Actions\*`→`Actions\*`, `Schema::schema()`→`components()`,
`Table::actions()`→`recordActions()`, `bulkActions()`→`toolbarActions()`). The v5
upgrade guide is 55 lines with no manual breaking-change section; the v4 guide is
785 lines and carries the entire catalogue. Going straight to v5 would forfeit all
of that automation. Two further facts reinforce the split: Filament 4 requires
Livewire `^3.5` while Filament 5 requires `^4.1`, so upstream itself draws the
Livewire boundary at 4→5; and §4.4 scenario 3 proves the intermediate state is
independently installable and testable.

### Biggest blocker

**The 1,658 lines of forked Filament 3 vendor Blade under
`resources/views/vendor/filament-*`.**

Four upstream templates are forked to preserve roughly 85 lines of intentional
divergence. The Rector-based upgrade tooling processes PHP under `app/` only and
never sees these files. Their Filament 5 counterparts have been restructured
past mechanical reconciliation: `filament-tables::index` grew 1,286 → 2,604
lines; `filament-actions::components.modals` collapsed 315 → 19 lines and no
longer contains a `<form>` at all; `filament-panels::components.form.index` **no
longer exists**.

What makes this the *biggest* risk rather than merely the largest is the failure
mode. `docs/04-ARCHITECTURE_PRINCIPLES.md`'s `## Filament form validation
standard` requires every panel form to render `novalidate`, and two of these four
forks are the only mechanism delivering it. Filament 5 offers no native
alternative — `novalidate` occurrences in Filament 5's PHP/Blade source: **0**.
A stale published override at a view path that no longer resolves produces no
error and no warning; it simply stops applying, and every panel form silently
regains browser-native validation, breaking the locale-correct inline-error
behavior the architecture mandates. The same forks also own the documented
toolbar layout contract, so a botched re-derivation degrades the admin and
cabinet product tables visually without failing a single test.

### Dependency blockers

**Must be upgraded (root):** `laravel/framework` `^11.31` → `^13.0`;
`laravel/tinker` `^2.9` → `^3.0` (2.11.1 caps `illuminate/*` at `^12.0`);
`filament/filament` `^3.2` → `^4.0` → `^5.0`; `livewire/livewire` `^3.0` → `^4.0`;
`php` `^8.2` → `^8.3`; `phpunit/phpunit` `^11.0.1` → `^12.0`.

**Must be upgraded (npm):** `tailwindcss` `^3.4.13` → `^4.3`, plus adding
`@tailwindcss/vite` (or `@tailwindcss/postcss`) and removing the `tailwindcss`
PostCSS plugin entry; `autoprefixer` becomes unnecessary.

**Upgrade automatically as transitive consequences:** `filament/*` siblings;
`guzzlehttp/guzzle` → `7.15.3` (clears 9 advisories, including CVE-2026-69246
which is directly relevant to the SSRF transport); `guzzlehttp/psr7` → `2.13.0`
(2 advisories); `league/commonmark` → `2.9.0` (6 advisories);
`danharrin/livewire-rate-limiting`; `kirschbaum-development/eloquent-power-joins`;
`nesbot/carbon`; `anourvalar/eloquent-serialize`; plus newly locked
`filament/schemas`, `filament/query-builder`, `pragmarx/google2fa*`,
`chillerlan/php-qrcode`, `nette/php-generator`, `league/uri-components`.

**Removed automatically:** `doctrine/dbal`, `doctrine/deprecations`, `psr/cache`,
`spatie/color` — all v3-only `filament/*` dependencies. The Filament v4 guide
notes `doctrine/dbal` must be re-added explicitly if the *application* needs it;
this project has no direct usage.

**Must be promoted to a direct dependency:** `guzzlehttp/guzzle` — imported
directly by `app/Support/Connectors/**` in 11 places, but declared in neither
`require` nor `require-dev`. Add it explicitly at `^7.15.2`+ so the
host-canonicalization fix behind CVE-2026-69246 is a stated project requirement
rather than an accident of Laravel's dependency tree (§10.4).

**Must be investigated, not silently changed:** `spatie/laravel-permission` —
**not a blocker** (the locked `6.25.0` already declares `illuminate/* …|^13.0`),
but `^8.0` also resolves (§4.4 scenario 4) and would require `php ^8.3` plus a
two-major jump. Keep at `^6.25` during the migration; treat 7/8 as a separate
later decision. `mockery/mockery` and `fakerphp/faker` are stale upstream but
are exactly the versions the Laravel 13 skeleton specifies — watch, do not act.

**None required to be removed or replaced. No Filament plugin exists to block
the upgrade** — the single most common cause of a stalled Filament major upgrade
does not apply to this repository (§5.1).

### Runtime prerequisites

**PHP:** floor rises to 8.3 (`laravel/framework v13.24.0` requires `php ^8.3`).
Docker (`php:8.3-fpm-alpine`) and CI (`php-version: '8.3'`) are already
compliant. **The production PHP version cannot be verified from repository
evidence** — no `.php-version`, no `composer.json` `config.platform`, no pinned
version in `DEPLOY.md` — so confirming the pilot host's PHP CLI/FPM version and
each Supervisor program's PHP binary path is a **required pre-upgrade deployment
check**. `pcntl` must be re-confirmed on every worker runtime, because without it
Laravel does not enforce the 45s and 900s connector job timeouts at all.

**Node:** the repository declares no Node version anywhere (no `.nvmrc`, no
`engines`), and `docker/php/Dockerfile` installs Alpine's unpinned `nodejs npm`.
**The production Node version cannot be verified from repository evidence** —
a required pre-upgrade deployment check, and a Node pin must be established
regardless of the Vite major, because the Tailwind 4 toolchain requires a
modern Node. The Vite 8 / `laravel-vite-plugin` 3 jump (which would require
Node `^20.19.0 || >=22.12.0`) is **explicitly out of GAP-024 scope** (§9.4):
keep the current Vite major and the current compatible Laravel Vite plugin
during the Filament/Tailwind migration unless real solver/tooling evidence at
implementation time makes that impossible.

**CI:** `.github/workflows/mysql-tests.yml` has **no Node step and no
`npm run build`**. The frontend build is currently verified only on the
production host inside `deploy.sh`. Adding a Node setup + `npm ci` +
`npm run build` step is a prerequisite of the frontend migration, not an
optional improvement — it is the cheapest item in this audit and removes its
most dangerous unverified path.

**Production:** no infrastructure change is mandated by the framework bump
itself. `deploy.sh` and the Supervisor topology stay as documented, but per
`07-TECH_STACK.md` the queue lane timings (`retry_after` 90 / 1200 vs job
timeouts 45 / 900 vs lock `expireAfter` 120 / 1100) must be re-verified against
`config/queue.php` and the live process manager rather than assumed. The
`babypark-connector-queue` activation gate is unaffected and remains open.

### Application migration scope

**Filament (largest):** 85 files in `app/Filament/**`; 15 Resources (14 admin +
1 cabinet), 51 resource Pages, 5 RelationManagers, 4 standalone admin Pages,
1 custom auth Page, 1 custom resource Page, **0 custom widgets**
(`app/Filament/Widgets/` does not exist, so the admin panel's
`->discoverWidgets()` call targets a missing directory and only the built-in
`Widgets\AccountWidget` is registered); both panel providers. Namespace
and API counts: 19 `Forms\Form` imports and signatures, 6 `Infolists\Infolist`
imports with 5 signatures, 54 `->schema(` calls, 135 inline
`Forms\Components\`, 140 inline `Tables\Columns\`, 68 inline
`Infolists\Components\`, 41 inline `Tables\Actions\` across 22 files, 25 inline
`Tables\Filters\`, 19 `->actions(`, 19 `->bulkActions(`, 5 `->headerActions(`,
6 `Forms\Get`/`Set`, 11 `Notifications\` imports. Static properties needing type
widening: 19 `$navigationIcon`, 17 `$navigationGroup`. Plus
`app/Support/FilamentTableToolbar.php`, `app/Support/ProductLightbox.php`,
`app/Support/Brand.php`, `app/Support/Filament/RevalidatesOnUpdate.php`,
`app/Models/User.php` (`FilamentUser` + `canAccessPanel()` for `/admin`),
`app/Models/Customer.php` (`FilamentUser` + `canAccessPanel()` for `/cabinet`), and
`TagResource/Support/GuardedDeleteTagAction.php`, which imports **both**
`Filament\Actions\DeleteAction` and `Filament\Tables\Actions\DeleteAction as TableDeleteAction`
— the dual-namespace pattern Filament 4 unifies, and the one place where a
Rector rename could collapse two distinct imports into one.

**Vendor Blade forks (highest risk):** the four files totalling 1,658 lines
listed under "Biggest blocker".

**Custom Blade:** 13 files under `resources/views/filament/**` (largest:
`pages/field-matrix.blade.php` 245, `pages/price-inspector.blade.php` 196,
`pages/governance.blade.php` 166), `components/filament/data-list-toolbar.blade.php`
(114), `filament/partials/table-toolbar-overrides.blade.php` (56, targets Filament's
`fi-ta-*` classes), and 45 `<x-filament*::…>` component usages.

**Laravel:** 4 `VerifyCsrfToken` references in the two panel providers
(**High**); 18 models using `HasUuids` (**Medium** — resolved direction:
preserve UUIDv4 via the framework-supported mechanism, §10.3). Every
other documented breaking change across both sequential guides is verifiably not
applicable — verified individually in §10.5, including 0 `upsert`, 0
`array_first`/`array_last`, 0 `Schema::getTables`, 0 `Storage::disk('local')`,
0 `JobAttempted`/`QueueBusy` listeners and 0 `env()` calls outside `config/`.

**Livewire:** 6 components in `app/Livewire/Cabinet/**`, **all 4 page-level ones
registered as route classes in `routes/web.php`** (recommended, not required, move
to `Route::livewire()`); 22 `wire:model` bindings; exactly **1**
`wire:model.blur` needing `.live.blur` (and it sits inside a vendor fork); 4
polling sites (2 Filament `->poll('5s')`, 2 `wire:poll`); 9 `Livewire::current()`
calls in the two `ProductResource` classes and `quantity-order.blade.php`
(regression-test the margin-format toggle); 17 first-party `$wire.` references,
all inside vendor forks. Not used at all: `config/livewire.php`, `<livewire:`
tags, `wire:transition`, `wire:scroll`, `wire:navigate`, `setUpdateRoute`,
`stream()`, first-party Livewire JS hooks, Volt.

**Frontend:** `tailwind.config.js`, `postcss.config.js`, `vite.config.js`,
`resources/css/app.css`. **`resources/css/design-tokens.css` needs no change** —
38 lines of plain CSS custom properties with no `@apply`, `@layer` or `theme()`,
injected via `file_get_contents()` rather than compiled (though its
`--color-primary-*` block is dead code worth consolidating). Zero occurrences of
every Tailwind-v4-*removed* utility checked, but **78 occurrences of v4-*renamed*
scale utilities** (`shadow-sm` 22, `rounded-sm` 12, `outline-none` 20, `space-y-`
24) plus 190 `dark:` variants and 57 arbitrary-value utilities needing visual
spot-checking. **16 committed Filament 3 JS assets under `public/js/filament/**`
must be verified as regenerated (via the existing `post-autoload-dump`
`filament:upgrade` hook) and committed at each Filament major step** (§9.5) —
they are not covered by `npm run build`, not covered by the gitignored
`public/build`, and invisible to every existing test.

**Tests:** 149 files (67 Feature, 80 Unit), 1303 passing / 2 skipped at
baseline. 25 reference Filament, with deep interaction coverage (206
`Livewire::actingAs(...)->test(...)` chains, 37 `fillForm`, 24 form-error
assertions — §7.6) that must be preserved. The `novalidate` tripwire already
exists (`FilamentFormValidationTest::test_panel_forms_render_with_novalidate`)
and needs its `/cabinet` and modal coverage gaps closed in PR1; the connector
dispatch-idempotency invariant is already tested at the service layer (§8.1),
so a new UI concurrency test is written only if a real uncovered mutable race
is identified.

### Upgrade decomposition

Four mandatory migration PRs (PR1–PR4), followed by **optional** PR5 if
hardening/cleanup is actually needed, and mandatory PR6 truth-sync / GAP-024
closure — each leaving the repository runnable and testable. Canonical
sequence: `PR1 → PR2 → PR3 → PR4 → [PR5 if needed] → PR6 → Discovery Overview
UI`.

1. **Runtime, toolchain and verification prerequisites / safety net** — PHP/Node/`pcntl` verification, Node pin, **CI frontend build step** (`npm ci && npm run build`), visual baseline capture, extension of the existing `novalidate` test to `/cabinet` and a modal form, and removal of the three verified-orphaned Blade views — all while still green on Filament 3, with no unrelated redesign.
2. **Laravel 11 → 13**, deliberately leaving Filament on `3.3.54` and Livewire on 3.x — solver-proven (§4.4 scenario 1). Includes `PreventRequestForgery`, **UUIDv4 preservation** (§10.3), Tinker 3, PHPUnit 12, the Guzzle direct-dependency promotion, and connector transport/SSRF regression coverage. Laravel breaking changes only — no Filament UI migration. **This PR alone resolves the unfixable Laravel 11 advisories and the "unsupported framework" core of GAP-024, with zero UI risk.**
3. **Filament 3 → 4 + Tailwind 3 → 4.1+ + custom themes** (Livewire stays 3.x) — the 115-file Rector migration with a narrowed path set, `config/filament.php` compatibility flags, the custom admin/cabinet themes, the Tailwind 4.1+ migration on the current Vite major, the §7.8 authorization review, `deferFilters(false)` behavior preservation, and the first reconciliation of the four vendor Blade forks. First mandatory visual gate; **may not merge with a known UI/security/authorization regression**.
4. **Filament 4 → 5 + Livewire 3 → 4** — the coupled Filament/Livewire generation jump onto the already-supported Tailwind 4.x, with the second reconciliation of the vendor forks against Filament 5's restructured templates, connector-polling and `novalidate` verification. Primary visual gate; **may not merge with a known regression**. No Tailwind major, no Vite major.
5. **Optional hardening and cleanup** — behavior-neutral only; **not intrinsically required for GAP-024 closure**; **no deferred defects from PR3/PR4**; extra end-to-end connector runtime verification.
6. **Documentation and GAP-024 closure / truth sync (mandatory)** — `07-TECH_STACK.md`, `IMPLEMENTATION_GAPS.md`, `DEPLOY.md` updated with actually verified runtime information, documentation tests run — only after the mandatory PR1–PR4 are merged and verified (and after PR5 as well, only if PR5 is actually performed before closure; if PR5 is skipped, PR6 follows PR4 directly).

The ordering is dictated by evidence: full re-resolution of the Laravel 11
graph is blocked by Composer's advisory policy (§4.2), Filament 4 on Laravel 11
fails for that reason (§4.4 scenario 2), Laravel 13 on Filament 3.3.54 succeeds
(§4.4 scenario 1), Filament 4 requires Livewire 3 while Filament 5 requires
Livewire 4 (§2.2), and Filament 4 with custom themes requires Tailwind 4.1+
(§2.2, §9.1) — which places Tailwind at the PR3 checkpoint.

### Discovery UI gate

**Is it safe to begin the new Discovery Overview UI *before* the target-stack
migration is complete?**

## **No.**

The audit found no compelling technical reason to depart from the expected
architectural direction, and found four concrete reasons to confirm it:

1. **New Filament 3 UI code is guaranteed rework.** Discovery Overview would be built with `Filament\Forms\Form`, `Filament\Infolists\Infolist`, `Filament\Tables\Actions\*`, `->schema()`, `->bulkActions()` and static `$navigationIcon`/`$navigationGroup` — every one of which is renamed or restructured by the v3→v4 Rector migration. The tool already proposes changes to 58 files under `app/Filament/**`; each new Resource, Page or RelationManager adds directly to that number and to the manual review burden.
2. **Discovery is the polling-heaviest surface in the product**, and polling semantics change. Livewire 4 makes `wire:poll` non-blocking and runs `wire:model.live` requests in parallel. The existing connector UI already polls at 5s in three places. Building a new polling-heavy surface against Livewire 3 semantics, then migrating it, means validating concurrency behavior twice — and the second validation would happen on brand-new code that has no established visual or behavioral baseline.
3. **New panel Tailwind usage is on unstable ground.** Filament 4's and 5's docs are explicit that arbitrary Tailwind classes do not work without a custom theme, and this project has none yet (§9.1). Any Tailwind utility written into a new Discovery Blade view today works only by incidental inclusion in Filament 3's compiled stylesheet, and has no guarantee of surviving the Tailwind 4 rebuild. The recommended custom themes (§9.1) should exist — built in PR3 — *before* new panel UI is written against them, not after.
4. **It would enlarge the visual-regression surface at exactly the wrong moment.** §16 already lists 20 high-value surfaces across three appearance modes and two breakpoint ranges, with no visual-regression harness in place. Adding an unfinished Discovery Overview to that set before PR1 establishes the baseline means the new screens have no "before" state to compare against — they would be migrated and visually validated simultaneously, which is the specific combination this audit recommends avoiding everywhere else.

Two distinct gates govern the start, and both must be stated to avoid a
documentation/implementation split:

* **Technical target-stack gate — PR4.** Once PR4 completes and is verified,
  Laravel 13 + Filament 5 + Livewire 4 + Tailwind 4 exist in code, and
  Discovery Overview UI becomes **technically ready** to build natively on the
  target stack.
* **Project-truth / implementation gate — PR6.** `05-AI_WORKING_AGREEMENT.md`
  requires documentation and implementation to stay synchronized, and
  `docs/07-TECH_STACK.md` is the implementation guardrail every future Cursor
  UI task must read before writing code. Between PR4 and PR6 that file still
  describes the pre-migration active stack, so **Discovery Overview UI must
  not begin between PR4 and PR6** — implementation starts only after the PR6
  truth-sync / GAP-024 closure is merged.

The recommended sequencing is therefore:
`PR1 → PR2 → PR3 → PR4 → [PR5 if needed] → PR6 → Discovery Overview UI`,
with the UI built natively on Filament 5 + Livewire 4 + Tailwind 4. The one
nuance worth recording is that PR2 (Laravel 13 on Filament 3.3.54) resolves the
*security and support* dimension of GAP-024 without touching the UI — so if
Discovery Overview becomes commercially urgent, the schedule conversation is
about how quickly PR3–PR4 and the PR6 truth-sync can land, not about starting
UI work early on Filament 3.

**No Discovery Overview UI work was begun in this task.**

---

## 19. Verification performed for this audit

### Report location and repository convention

`docs/audits/` did not exist before this report. The existing research/report
convention under `docs/proposals/` holds two task-scoped **decision** documents
(`task-4b2-0-runtime-decisions.md`, `task-4b2a-1-oauth1-signer-decision.md`),
both of which promote binding decisions into `07-TECH_STACK.md`. This report is
non-normative research evidence and promotes nothing, so it is not a proposal in
that sense. The task-specified path was therefore used:
`docs/audits/GAP-024-target-stack-feasibility-2026-08.md`.

### Documentation-test search for the chosen location

Nine test files read files under `docs/`:
`tests/Feature/ConnectorAccountDocumentationTest.php`,
`tests/Feature/UiDesignSystemDocumentationTest.php`,
`tests/Feature/ImplementationGapsTest.php`,
`tests/Feature/GovernancePageTest.php`,
`tests/Feature/CoreFieldNamingMigrationTest.php`,
`tests/Unit/CanonicalRegistryIntegrityTest.php`,
`tests/Unit/CanonicalRegistryValidatorTest.php`,
`tests/Unit/ChannelDecisionValidatorTest.php`,
`tests/Unit/DomainModelCatalogProjectionReferencesTest.php`.

Every one reads a **specific named file** (`docs/IMPLEMENTATION_GAPS.md`,
`docs/Project_Documentation_Map.md`, `docs/04-ARCHITECTURE_PRINCIPLES.md`,
`docs/07-TECH_STACK.md`, `docs/CANONICAL_PRODUCT_FIELD_REGISTRY.md`,
`docs/data/*.csv`). **None enumerates `docs/`, none references `docs/audits`,
and none requires a new document to be registered in an index.** Adding this
file therefore neither breaks a test nor obliges an index edit, so the committed
diff is a single new file with no `Project_Documentation_Map.md` change.

### Test results

```console
$ php artisan test
  Tests:    2 skipped, 1303 passed (30183 assertions)
  Duration: 51.12s
```

```console
$ php artisan test --filter=Documentation
  Tests:    57 passed (471 assertions)
  Duration: 1.01s

$ php artisan test --filter=ImplementationGaps
  Tests:    3 passed (10 assertions)
  Duration: 0.28s
```

Both suites remain green with this report present, confirming that adding a file
under `docs/audits/` does not affect any documentation test.

### Branch cleanliness

```console
$ git worktree list
/workspace  9713d03 [cursor/gap-024-target-stack-feasibility-audit]

$ git status --short
(empty — before staging this report)

$ git diff --check origin/develop...HEAD
(no whitespace errors)
```

All four disposable worktrees (`/tmp/solver/{target,bridge,pristine,upgrade}`)
were removed and `/tmp/solver` deleted. **No dependency file, lockfile,
application file, Blade template, CSS, migration, configuration, CI workflow or
Docker file is modified on this branch.** The committed diff contains exactly one
new documentation file.

### Correction-pass verification (2026-08-08)

Before editing, `origin/develop` was re-fetched and re-verified unchanged:

```console
$ git fetch origin --prune
$ git merge-base --is-ancestor 9713d03 origin/develop && echo ANCESTOR-OK
ANCESTOR-OK
$ git rev-parse origin/develop
9713d03d862a549bc5738071a55e58fab0b2e647
```

No integration with a newer `develop` was required. All ten required project
documents, `composer.json`, the complete current Architecture Review Checklist
in `04-ARCHITECTURE_PRINCIPLES.md` (items 1–22 plus the Filament form
validation standard), and this entire report were read at that commit before
editing.

New verification commands executed during the correction pass, with results
recorded inline where cited: the untouched-graph partial-update proof
(`composer update predis/predis --dry-run` → EXIT=0; §4.2), the `can*()`
override inventory (23 overrides / 10 Resources / 22 deny-only; §7.8), the
`deferFilters` absence check (0 occurrences; §7.7), the dispatch-service
idempotency test inventory and the read-only `refreshConnectionState` body
(§8.1), the direct-Guzzle-import recount (10 `use GuzzleHttp` statements, 11
classes, 5 files; §17 PR2), and the `HasVersion4Uuids` presence check on
`laravel/framework` `13.x` (§10.3).

Documentation tests and the full suite were re-run with the corrected report
present:

```console
$ php artisan test --filter=Documentation
  Tests:    57 passed (471 assertions)

$ php artisan test --filter=ImplementationGaps
  Tests:    3 passed (10 assertions)

$ php artisan test
  Tests:    2 skipped, 1303 passed (30183 assertions)
```

The correction-pass diff modifies only this report; no dependency, code, test,
CI, Docker, deployment or configuration file changed.

---

## 20. Source index

Every externally changing claim in this report traces to one of the following,
each checked inside the UTC window declared at the top.

**Laravel (official)**
* `https://laravel.com/docs/13.x/releases` — support-policy table, PHP 8.3 requirement, `PreventRequestForgery`, queue routing, PHP attributes, `Cache::touch`
* `https://laravel.com/docs/13.x/upgrade` — Upgrading To 13.0 From 12.x (full)
* `https://laravel.com/docs/12.x/upgrade` — Upgrading To 12.0 From 11.x (full)
* `https://raw.githubusercontent.com/laravel/laravel/13.x/composer.json` — canonical 13.x skeleton dependencies
* `https://raw.githubusercontent.com/laravel/laravel/13.x/config/cache.php` — `serializable_classes` default (line 134)
* `https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Cache/CacheManager.php` — `serializable_classes` fallback (line 473)
* `https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Queue/Middleware/WithoutOverlapping.php` — `shared()`, `releaseAfter()`, `expireAfter()`, `dontRelease()`, `handle()` still present

**Filament (official docs + GitHub)**
* `https://filamentphp.com/docs/5.x/upgrade-guide` and `https://raw.githubusercontent.com/filamentphp/filament/5.x/docs/14-upgrade-guide.md` — v5 requirements, automated script, plugin warning (55 lines)
* `https://raw.githubusercontent.com/filamentphp/filament/4.x/docs/14-upgrade-guide.md` — v4 requirements, `doctrine/dbal` note, directory-structure command, `file_generation` flags, manual breaking-change catalogue (785 lines)
* `https://raw.githubusercontent.com/filamentphp/filament/v5.7.6/packages/upgrade/src/rector.php` — the 10-line v5 Rector config
* `https://raw.githubusercontent.com/filamentphp/filament/v4.12.6/packages/upgrade/src/rector.php` — the 343-line v4 Rector config
* `https://api.github.com/repos/filamentphp/filament/tarball/v5.7.6` — v5.7.6 monorepo, used for: `packages/upgrade/bin/filament-v5`, `packages/upgrade/src/check-compatibility.php`, `packages/upgrade/src/Rector/SimpleMethodChangesRector.php`, `packages/tables/resources/views/index.blade.php` (2,604 lines), `packages/tables/src/View/TablesRenderHook.php`, `packages/actions/resources/views/components/modals.blade.php` (19 lines), `packages/actions/resources/views/action-modal.blade.php`, `packages/support/resources/views/components/modal/index.blade.php`, `packages/panels/resources/views/**` (absence of `components/form`), `docs/08-styling/01-overview.md` (custom-theme-required statement), `packages/panels/src/FilamentServiceProvider.php`
* Local `vendor/filament/**` at `v3.3.52` — upstream baselines for the four published-override diffs

**Livewire (official)**
* `https://livewire.laravel.com/docs/upgrading` (v4.x) — full v3→v4 breaking-change list, non-blocking polling and parallel-live-update statements, v4.1 `wire:model` modifier change, endpoint hash change, JS deprecations

**Tailwind / npm**
* `https://registry.npmjs.org/tailwindcss`, `.../@tailwindcss%2Fvite`, `.../@tailwindcss%2Fpostcss`, `.../vite`, `.../laravel-vite-plugin` — latest versions, `engines`, `peerDependencies`

**Packagist metadata (`https://repo.packagist.org/p2/{package}.json`)**
* `laravel/framework`, `laravel/tinker`, `laravel/pail`, `laravel/sail`, `laravel/pint`, `laravel/pao`, `filament/filament`, `filament/support`, `filament/forms`, `filament/tables`, `filament/schemas`, `filament/actions`, `filament/upgrade`, `livewire/livewire`, `spatie/laravel-permission`, `laravel-lang/lang`, `predis/predis`, `phpunit/phpunit`, `mockery/mockery`, `nunomaduro/collision`, `fakerphp/faker`, `danharrin/livewire-rate-limiting`, `kirschbaum-development/eloquent-power-joins`

**Correction-pass primary sources (checked 2026-08-08)**
* `https://getcomposer.org/changelog/2.9.2` — "Fixed partial updates failing when another package in the lock file has a known security advisory" (composer/composer #12626); `--no-security-blocking` flag addition
* `https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Database/Eloquent/Concerns/HasVersion4Uuids.php` — UUIDv4-preserving trait present on the 13.x branch
* `https://raw.githubusercontent.com/filamentphp/filament/4.x/docs/08-styling/01-overview.md` — Filament 4 custom-theme-required statement ("A custom theme is required to use Tailwind CSS classes in your own code… you must create a custom theme first")
* `https://raw.githubusercontent.com/filamentphp/filament/3.x/packages/panels/docs/01-installation.md` — `filament:upgrade` in `post-autoload-dump` keeps caches cleared and published assets current
* `composer update predis/predis --dry-run --no-scripts --no-plugins` on the untouched repository — EXIT=0 (§4.2)

**Composer / npm / Rector command output (executed during this audit)**
* `composer why-not` and `composer prohibits` for `laravel/framework ^13.0`, `filament/filament ^5.0`, `livewire/livewire ^4.0` — §4.1
* `composer update --dry-run -W` on the unmodified repository (EXIT=2, advisory block) and `composer install --dry-run` (EXIT=0) — §4.2
* `composer update --dry-run -W` for the target state and four additional scenarios — §4.3, §4.4
* `composer audit --locked` (21 advisories, 5 packages) — §14.1
* `npm audit --package-lock-only` (5 vulnerabilities) and `npm outdated --package-lock-only` — §14.2, §1.4
* `vendor/bin/rector process app --config vendor/filament/upgrade/src/rector.php --dry-run` (115 files) and the same against the v5 config (0 files) — §13.2, §13.4
* `php artisan test` (1303 passed, 2 skipped, 30183 assertions) — §15.1
* `php -v`, `composer --version`, `node -v`, `npm -v`, `composer show --direct` — §1.1, §1.2

**Project documentation (exact paths)**
* `docs/Project_Documentation_Map.md`; `docs/00-WHY.md`; `docs/01-PRODUCT_VISION.md`; `docs/02-ATTRIBUTE_DICTIONARY.md`; `docs/03-DOMAIN_MODEL.md`; `docs/04-ARCHITECTURE_PRINCIPLES.md` (`## Architecture Review Checklist` items 1–22 at lines 1136–1231; `## Filament form validation standard` at lines 1233–1239; `## Connector operational security (reusable)` at lines 1101–1126); `docs/05-AI_WORKING_AGREEMENT.md` (lines 201–318); `docs/06-UI_DESIGN_SYSTEM.md`; `docs/07-TECH_STACK.md` (`## Application Stack`, `## Current Panels`, `## Existing Shared Patterns to Prefer`, `## Connector runtime (Resolved — Task 4B-2-0)` at lines 211–497, `### Queue timeout alignment (Resolved)` at lines 350–411); `docs/IMPLEMENTATION_GAPS.md` (GAP-024 at lines 835–859; cross-references at lines 404–439; GAP-019 at lines 907–927)

**Repository files cited for project-specific claims**
* `composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `.gitignore`, `.env.example`
* `vite.config.js`, `tailwind.config.js`, `postcss.config.js`, `resources/css/app.css`, `resources/css/design-tokens.css`, `resources/js/app.js`
* `app/Providers/Filament/AdminPanelProvider.php`, `app/Providers/Filament/CabinetPanelProvider.php`, `app/Providers/AppServiceProvider.php`, `app/Providers/ConnectorTransportServiceProvider.php`
* `app/Support/Brand.php`, `app/Support/FilamentTableToolbar.php`, `app/Support/ProductLightbox.php`, `app/Support/Migrations/FieldFoundationMigrator.php`
* `app/Filament/**` (85 files), `app/Livewire/Cabinet/**` (6 files), `app/Models/**` (18 with `HasUuids`)
* `app/Jobs/Connectors/{ConnectorConnectionCheckJob,ConnectorDiscoveryRunJob,ConnectorConnectionCheckJobExecutionException,ConnectorDiscoveryRunJobExecutionException}.php`
* `app/Services/Connectors/{ConnectorConnectionCheckDispatchService,ConnectorDiscoveryRunDispatchService}.php`
* `config/cache.php`, `config/database.php`, `config/session.php`, `config/queue.php`, `config/connectors.php`
* `resources/views/vendor/filament-tables/index.blade.php`, `resources/views/vendor/filament-tables/components/search-field.blade.php`, `resources/views/vendor/filament-actions/components/modals.blade.php`, `resources/views/vendor/filament-panels/components/form/index.blade.php`
* `resources/views/filament/**` (13 files), `resources/views/components/filament/data-list-toolbar.blade.php`, `resources/views/layouts/cabinet.blade.php`, `resources/views/welcome.blade.php`
* `routes/web.php` (4 Livewire route-class registrations), `resources/views/livewire/cabinet/**` (6 views), `public/js/filament/**` (16 committed Filament 3 published assets)
* `docker/php/Dockerfile`, `docker/nginx/default.conf`, `docker-compose.yml`, `deploy.sh`, `DEPLOY.md`, `scripts/cloud-setup.sh`
* `.github/workflows/mysql-tests.yml`, `.github/workflows/deploy.yml`, `phpunit.xml`, `phpunit.mysql.xml`, `tests/**` (149 files)
