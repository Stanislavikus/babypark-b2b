#!/usr/bin/env node
/**
 * GAP-024 PR4 — Filament 5 / Livewire 4 visual baseline capture (144 WebP).
 *
 * Prerequisites:
 *   php artisan migrate:fresh --seed
 *   npm run build
 *   php artisan serve --host=127.0.0.1 --port=8765
 *
 * Usage:
 *   node scripts/gap-024-pr4-visual-capture.mjs
 *   node scripts/gap-024-pr4-visual-capture.mjs --only=s06,s15
 *   node scripts/gap-024-pr4-visual-capture.mjs --skip-comparison
 */

import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = join(__dirname, '..');

const BASE_URL = process.env.GAP024_BASE_URL ?? 'http://127.0.0.1:8765';
const ADMIN_EMAIL = 'admin@babypark.ua';
const ADMIN_PASSWORD = 'password';
const CABINET_LOGIN = 'dytiachyi-svit';
const CABINET_PASSWORD = 'password';

const OUTPUT_REPO = join(ROOT, 'docs/audits/visual-baselines/gap-024-filament5-pr4');
const OUTPUT_ARTIFACTS = '/opt/cursor/artifacts/gap-024-pr4-filament5-visual';
const BASELINE_PR3 = join(ROOT, 'docs/audits/visual-baselines/gap-024-filament4-pr3');
const MANIFEST_BASELINE = join(ROOT, 'docs/audits/visual-baselines/gap-024-filament3');

const VIEWPORTS = {
  desktop: { width: 1280, height: 900 },
  mobile: { width: 390, height: 844 },
};

const S05_VIEWPORTS = {
  desktop: { width: 1024, height: 900 },
  mobile: { width: 767, height: 900 },
};

const THEMES = ['light', 'dark', 'system'];
const WEBP_QUALITY = 85;

let sharp = null;
try {
  sharp = (await import('sharp')).default;
} catch {
  console.warn('[gap-024] sharp not installed — WebP via Playwright screenshot fallback');
}

const args = process.argv.slice(2);
const onlyFilter = args.find((a) => a.startsWith('--only='))?.split('=')[1]?.split(',') ?? null;
const skipComparison = args.includes('--skip-comparison');

/** @type {{ priceListId: string, connectorAccountId: string }} */
let fixtures = { priceListId: '', connectorAccountId: '' };

// ---------------------------------------------------------------------------
// Filename inventory (parity with PR3 / manifest)
// ---------------------------------------------------------------------------

function loadTargetFilenames() {
  const pr3Dir = BASELINE_PR3;
  if (existsSync(pr3Dir)) {
    return readdirSync(pr3Dir)
      .filter((f) => f.endsWith('.webp'))
      .sort();
  }

  const manifest = join(ROOT, 'docs/audits/GAP-024-pr1-visual-baseline-manifest.md');
  const text = readFileSync(manifest, 'utf8');
  const matches = [...text.matchAll(/`(s\d{2}-[^`]+\.webp)`/g)].map((m) => m[1]);

  return [...new Set(matches)].sort();
}

/**
 * @param {string} file
 */
function parseFilename(file) {
  const m = file.match(
    /^s(\d{2})-(.+)-(light|dark|system)-(desktop|mobile)(?:-(.+))?\.webp$/,
  );
  if (!m) {
    throw new Error(`Cannot parse filename: ${file}`);
  }

  return {
    file,
    surface: Number(m[1]),
    slug: m[2],
    theme: m[3],
    viewport: m[4],
    substate: m[5] ?? null,
  };
}

// ---------------------------------------------------------------------------
// PHP fixture bootstrap (connector account + dynamic IDs)
// ---------------------------------------------------------------------------

function runPhp(code) {
  const tmp = join(OUTPUT_ARTIFACTS, '.fixture-bootstrap.php');
  mkdirSync(dirname(tmp), { recursive: true });
  writeFileSync(tmp, code);
  return execSync(`php ${JSON.stringify(tmp)}`, {
    cwd: ROOT,
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  }).trim();
}

function ensureDatabaseFixtures() {
  console.log('[gap-024] Ensuring connector account fixtures…');

  const root = ROOT.replace(/\\/g, '/');
  const php = `<?php

require '${root}/vendor/autoload.php';
$app = require '${root}/bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

use App\\Enums\\ConnectorAccountConnectionStatus;
use App\\Enums\\ConnectorConnectionCheckStatus;
use App\\Enums\\ConnectorConnectionCheckTrigger;
use App\\Models\\ConnectorAccount;
use App\\Models\\ConnectorConnectionCheck;
use App\\Models\\ConnectorDefinition;
use App\\Models\\PriceList;
use App\\Models\\Workspace;
use App\\Support\\Connectors\\AdobePaaS\\AdobePaaSCredentialMapper;
use App\\Support\\Connectors\\OAuth1\\OAuth1Credentials;
use Illuminate\\Support\\Str;

$workspace = Workspace::query()->where('is_default', true)->firstOrFail();
$definition = ConnectorDefinition::query()->where('code', 'adobe_commerce')->firstOrFail();

$account = ConnectorAccount::withoutWorkspaceScope()->firstOrCreate(
    ['workspace_id' => $workspace->id, 'name' => 'GAP-024 Visual Fixture'],
    [
        'id' => (string) Str::uuid(),
        'connector_definition_id' => $definition->id,
        'auth_profile' => 'adobe_commerce_paas_oauth1_integration',
        'base_url' => 'https://visual-fixture.example.com',
        'store_code' => 'visual-fixture-store',
        'tenant_context' => null,
        'is_enabled' => true,
        'settings' => [],
        'credentials' => AdobePaaSCredentialMapper::toStorageArray(
            new OAuth1Credentials('ck_visual', 'cs_visual', 'at_visual', 'ts_visual'),
        ),
        'connection_status' => ConnectorAccountConnectionStatus::Connected,
    ],
);

$checks = [
    [ConnectorConnectionCheckStatus::Succeeded, ['finished_at' => now(), 'started_at' => now()->subSeconds(2), 'duration_ms' => 2100]],
    [ConnectorConnectionCheckStatus::Failed, [
        'finished_at' => now()->subMinutes(5),
        'started_at' => now()->subMinutes(5)->subSeconds(3),
        'duration_ms' => 3200,
        'cause_category' => App\\Enums\\ConnectorErrorCause::Authorization,
        'actionability' => App\\Enums\\ConnectorErrorActionability::UserActionRequired,
        'user_message_key' => 'connectors.errors.insufficient_permissions',
    ]],
    [ConnectorConnectionCheckStatus::Queued, []],
];

foreach ($checks as [$status, $extra]) {
    $exists = ConnectorConnectionCheck::withoutWorkspaceScope()
        ->where('connector_account_id', $account->id)
        ->where('status', $status)
        ->exists();
    if ($exists) {
        continue;
    }
    ConnectorConnectionCheck::withoutWorkspaceScope()->create(array_merge([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'connector_account_id' => $account->id,
        'trigger' => ConnectorConnectionCheckTrigger::Manual,
        'initiated_by_user_id' => null,
        'status' => $status,
        'execution_attempts' => 0,
        'retry_until_at' => now()->addMinutes(15),
        'next_attempt_at' => null,
        'cause_category' => null,
        'actionability' => null,
        'error_code' => null,
        'http_status' => null,
        'user_message_key' => null,
        'safe_message_parameters' => null,
        'technical_summary' => null,
        'vendor_request_id' => null,
        'started_at' => null,
        'finished_at' => null,
        'duration_ms' => null,
    ], $extra));
}

$priceListId = PriceList::query()->where('is_default', false)->orderBy('name')->value('id')
    ?? PriceList::query()->orderBy('name')->value('id');

echo json_encode([
    'connectorAccountId' => $account->id,
    'priceListId' => (string) $priceListId,
], JSON_THROW_ON_ERROR);
`;

  fixtures = JSON.parse(runPhp(php));
  console.log(`[gap-024] Fixtures: connector=${fixtures.connectorAccountId}, priceList=${fixtures.priceListId}`);
}

// ---------------------------------------------------------------------------
// Capture spec
// ---------------------------------------------------------------------------

/**
 * @param {ReturnType<typeof parseFilename>} meta
 */
function resolveRoute(meta) {
  const { surface, slug, substate } = meta;

  switch (surface) {
    case 1:
      return '/admin/login';
    case 2:
      return '/cabinet/login';
    case 3:
    case 19:
      return '/admin';
    case 4:
    case 6:
      return '/admin/products';
    case 5:
      return '/admin/field-matrix';
    case 7:
      if (substate === 'price-list-item' || slug.includes('price-list-item')) {
        return `/admin/price-lists/${fixtures.priceListId}/edit`;
      }
      if (substate === 'delivery-setting' || slug.includes('delivery-setting')) {
        return '/admin/delivery-settings/1/edit';
      }
      return '/admin/products/1/edit';
    case 8:
      return '/admin/price-inspector';
    case 9:
      if (substate === 'governance' || slug.includes('governance')) {
        return '/admin/governance';
      }
      return '/admin/field-matrix';
    case 10:
      return '/admin/connector-accounts';
    case 11:
    case 12:
      return `/admin/connector-accounts/${fixtures.connectorAccountId}`;
    case 13:
    case 15:
    case 20:
      return '/cabinet/products';
    case 14:
      return '/catalog?viewMode=cards';
    case 16:
      return '/catalog?viewMode=table';
    case 17:
      return '/catalog';
    case 18:
      if (substate === 'cabinet' || slug.includes('cabinet')) {
        return '/catalog';
      }
      return '/admin/products';
    default:
      throw new Error(`Unknown surface ${surface}`);
  }
}

/**
 * @param {ReturnType<typeof parseFilename>} meta
 * @returns {'none' | 'admin' | 'cabinet-filament' | 'cabinet-livewire'}
 */
function resolveAuth(meta) {
  const { surface, substate, slug } = meta;
  if (surface <= 2) {
    return 'none';
  }
  if (surface === 13 || surface === 15 || surface === 20) {
    return 'cabinet-filament';
  }
  if (surface >= 14 && surface <= 17) {
    return 'cabinet-livewire';
  }
  if (surface === 18 && (substate === 'cabinet' || slug.includes('cabinet'))) {
    return 'cabinet-livewire';
  }
  return 'admin';
}

/**
 * @param {ReturnType<typeof parseFilename>} meta
 */
function resolveViewport(meta) {
  if (meta.surface === 5) {
    return S05_VIEWPORTS[meta.viewport];
  }
  return VIEWPORTS[meta.viewport];
}

// ---------------------------------------------------------------------------
// Theme + auth helpers
// ---------------------------------------------------------------------------

/**
 * @param {import('playwright').BrowserContext} context
 * @param {'light' | 'dark' | 'system'} theme
 */
async function applyThemeInit(context, theme) {
  await context.addInitScript((t) => {
    localStorage.setItem('theme', t);
    const resolved =
      t === 'system'
        ? window.matchMedia('(prefers-color-scheme: dark)').matches
          ? 'dark'
          : 'light'
        : t;
    document.documentElement.classList.toggle('dark', resolved === 'dark');
  }, theme);
}

/**
 * @param {import('playwright').Browser} browser
 */
async function buildStorageStates(browser) {
  const adminPath = join(OUTPUT_ARTIFACTS, '.auth-admin.json');
  const cabinetFilamentPath = join(OUTPUT_ARTIFACTS, '.auth-cabinet-filament.json');
  const cabinetLivewirePath = join(OUTPUT_ARTIFACTS, '.auth-cabinet-livewire.json');

  mkdirSync(OUTPUT_ARTIFACTS, { recursive: true });

  {
    const context = await browser.newContext();
    const page = await context.newPage();
    await page.goto(`${BASE_URL}/admin/login`, { waitUntil: 'networkidle' });
    await page.locator('input[type="email"], input[autocomplete="username"]').first().fill(ADMIN_EMAIL);
    await page.locator('input[type="password"]').first().fill(ADMIN_PASSWORD);
    await page.locator('button[type="submit"]').first().click();
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 30_000 });
    await context.storageState({ path: adminPath });
    await context.close();
  }

  {
    const context = await browser.newContext();
    const page = await context.newPage();
    await page.goto(`${BASE_URL}/cabinet/login`, { waitUntil: 'networkidle' });
    await page.locator('input[autocomplete="username"], #login, input[name="login"]').first().fill(CABINET_LOGIN);
    await page.locator('input[type="password"]').first().fill(CABINET_PASSWORD);
    await page.locator('button[type="submit"]').first().click();
    await page.waitForURL(/\/cabinet(?!\/login)/, { timeout: 30_000 });
    await context.storageState({ path: cabinetFilamentPath });
    await context.close();
  }

  {
    const context = await browser.newContext();
    const page = await context.newPage();
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'networkidle' });
    await page.locator('#login').fill(CABINET_LOGIN);
    await page.locator('#password').fill(CABINET_PASSWORD);
    await page.locator('button[type="submit"]').first().click();
    await page.waitForURL(/\/catalog/, { timeout: 30_000 });
    await context.storageState({ path: cabinetLivewirePath });
    await context.close();
  }

  return { adminPath, cabinetFilamentPath, cabinetLivewirePath };
}

// ---------------------------------------------------------------------------
// Interaction setup per surface
// ---------------------------------------------------------------------------

/**
 * @param {import('playwright').Page} page
 * @param {ReturnType<typeof parseFilename>} meta
 */
async function runInteractionSetup(page, meta) {
  const { surface } = meta;

  await page.waitForLoadState('domcontentloaded');

  if ([4, 6, 10, 13, 15, 20].includes(surface)) {
    await page.locator('table tbody tr').first().waitFor({ state: 'visible', timeout: 20_000 });
  } else if (surface === 14 || surface === 16 || surface === 17) {
    await page.locator('main, .catalog, [wire\\:id]').first().waitFor({ state: 'visible', timeout: 20_000 });
  } else {
    await page.locator('.fi-main, .fi-page, .fi-sidebar, main').first().waitFor({ state: 'visible', timeout: 20_000 });
  }

  await page.waitForTimeout(500);

  if (surface === 6) {
    await openProductViewAction(page);
    return;
  }

  if (surface === 12) {
    const heading = page.getByRole('heading', { name: /перевірки з'єднання/i });
    if (await heading.count()) {
      await heading.first().scrollIntoViewIfNeeded();
      await page.waitForTimeout(500);
    } else {
      await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
      await page.waitForTimeout(500);
    }
    return;
  }

  if (surface === 15) {
    await prepareCartDrawerOpen(page);
    return;
  }

  if (surface === 17) {
    await openCatalogLightbox(page);
    return;
  }

  if (surface === 20) {
    await triggerAddToCartToast(page);
    return;
  }
}

/**
 * ViewAction slideOver — manifest neutral row click; Livewire fallback per supplemental-s06.
 * @param {import('playwright').Page} page
 */
async function openProductViewAction(page) {
  const row = page.locator('table tbody tr').first();
  await row.waitFor({ state: 'visible', timeout: 15_000 });
  await page.waitForFunction(() => typeof window.Livewire !== 'undefined');
  await row.click({ timeout: 5000 });
  await page
    .getByRole('heading', { name: /перегляд товар/i })
    .waitFor({ state: 'visible', timeout: 15_000 });
}

/**
 * @param {import('playwright').Page} page
 */
async function prepareCartDrawerOpen(page) {
  const buyButton = page.getByRole('button', { name: /^купити$/i }).first();
  const qtyInput = page.locator('input[type="number"]').first();

  if (await qtyInput.count()) {
    await qtyInput.fill('2');
    await qtyInput.dispatchEvent('change');
    await page.waitForTimeout(300);
  }

  if (await buyButton.count()) {
    await buyButton.click();
    await page.waitForTimeout(800);
  }

  const cartTrigger = page.locator('.bp-cart-toolbar button[title="Кошик"], .bp-cart-toolbar button').first();
  if (await cartTrigger.count()) {
    await cartTrigger.click();
    await page.waitForTimeout(600);
  }
}

/**
 * @param {import('playwright').Page} page
 */
async function openCatalogLightbox(page) {
  const thumb = page.locator('img[cursor="zoom-in"], img[onclick*="bpOpenLightbox"], button img, .catalog img').first();
  if (await thumb.count()) {
    await thumb.click({ timeout: 5000 }).catch(() => {});
  } else {
    await page.evaluate(() => {
      const img = document.querySelector('img[src*="picsum"], img[src*="http"]');
      if (img && typeof window.bpOpenLightbox === 'function') {
        window.bpOpenLightbox(img.src, img.alt || 'Product');
      }
    });
  }

  await page.locator('#bp-photo-lb').waitFor({ state: 'visible', timeout: 10_000 });
}

/**
 * @param {import('playwright').Page} page
 */
async function triggerAddToCartToast(page) {
  const buyButton = page.getByRole('button', { name: /^купити$/i }).first();
  if (await buyButton.count()) {
    await buyButton.click();
  }

  await page
    .locator('.fi-no, [data-notification], .fi-no-notification')
    .filter({ hasText: /додано до кошика/i })
    .first()
    .waitFor({ state: 'visible', timeout: 12_000 });
}

// ---------------------------------------------------------------------------
// Screenshot + WebP
// ---------------------------------------------------------------------------

/**
 * @param {Buffer} png
 * @returns {Promise<Buffer>}
 */
async function pngToWebp(png) {
  if (sharp) {
    return sharp(png).webp({ quality: WEBP_QUALITY }).toBuffer();
  }
  return png;
}

/**
 * @param {import('playwright').Page} page
 * @returns {Promise<Buffer>}
 */
async function captureWebp(page) {
  const png = await page.screenshot({ fullPage: false, type: 'png' });

  if (sharp) {
    return pngToWebp(png);
  }

  const pngPath = join(OUTPUT_ARTIFACTS, '.tmp-capture.png');
  writeFileSync(pngPath, png);
  const webpPath = pngPath.replace('.png', '.webp');
  await page.screenshot({ path: webpPath, type: 'webp', quality: WEBP_QUALITY });
  const webp = readFileSync(webpPath);
  return webp;
}

function saveWebp(filename, buffer) {
  for (const dir of [OUTPUT_REPO, OUTPUT_ARTIFACTS]) {
    mkdirSync(dir, { recursive: true });
    writeFileSync(join(dir, filename), buffer);
  }
}

// ---------------------------------------------------------------------------
// Capture loop
// ---------------------------------------------------------------------------

/**
 * @param {import('playwright').Browser} browser
 * @param {Awaited<ReturnType<typeof buildStorageStates>>} storage
 * @param {string[]} filenames
 */
async function runCaptures(browser, storage, filenames) {
  /** @type {Array<{file: string, status: 'success' | 'fail', error?: string, durationMs: number}>} */
  const results = [];

  for (const file of filenames) {
    const meta = parseFilename(file);
    const surfaceKey = `s${String(meta.surface).padStart(2, '0')}`;

    if (onlyFilter && !onlyFilter.some((f) => file.includes(f) || surfaceKey === f)) {
      continue;
    }

    const started = Date.now();
    const route = resolveRoute(meta);
    const auth = resolveAuth(meta);
    const viewport = resolveViewport(meta);

    const colorScheme =
      meta.theme === 'system' ? 'no-preference' : meta.theme === 'dark' ? 'dark' : 'light';

    const contextOptions = {
      viewport,
      colorScheme,
      deviceScaleFactor: 1,
    };

    if (auth === 'admin') {
      contextOptions.storageState = storage.adminPath;
    } else if (auth === 'cabinet-filament') {
      contextOptions.storageState = storage.cabinetFilamentPath;
    } else if (auth === 'cabinet-livewire') {
      contextOptions.storageState = storage.cabinetLivewirePath;
    }

    const context = await browser.newContext(contextOptions);
    await applyThemeInit(context, meta.theme);

    const page = await context.newPage();

    try {
      const needsInteraction = [6, 12, 15, 17, 20].includes(meta.surface);
      await page.goto(`${BASE_URL}${route}`, {
        waitUntil: needsInteraction ? 'networkidle' : 'domcontentloaded',
        timeout: 60_000,
      });
      await runInteractionSetup(page, meta);

      const webp = await captureWebp(page);
      saveWebp(file, webp);

      results.push({ file, status: 'success', durationMs: Date.now() - started });
      console.log(`[ok] ${file} (${Date.now() - started}ms)`);
    } catch (error) {
      const message = error instanceof Error ? error.message : String(error);
      results.push({ file, status: 'fail', error: message, durationMs: Date.now() - started });
      console.error(`[fail] ${file}: ${message}`);
    } finally {
      await context.close();
    }
  }

  return results;
}

// ---------------------------------------------------------------------------
// Comparison + reports
// ---------------------------------------------------------------------------

/**
 * @param {Buffer} a
 * @param {Buffer} b
 */
async function diffRatio(a, b) {
  if (!sharp) {
    return a.equals(b) ? 0 : 1;
  }

  const imgA = sharp(a).ensureAlpha().raw();
  const imgB = sharp(b).ensureAlpha().raw();
  const metaA = await imgA.metadata();
  const metaB = await imgB.metadata();

  if (metaA.width !== metaB.width || metaA.height !== metaB.height) {
    return 1;
  }

  const rawA = await imgA.toBuffer();
  const rawB = await imgB.toBuffer();
  const len = Math.min(rawA.length, rawB.length);
  let diff = 0;
  for (let i = 0; i < len; i += 4) {
    if (rawA[i] !== rawB[i] || rawA[i + 1] !== rawB[i + 1] || rawA[i + 2] !== rawB[i + 2]) {
      diff++;
    }
  }
  const pixels = len / 4;
  return pixels ? diff / pixels : 0;
}

function classifyDifference(surface, ratio, pr3Exists, pr4Exists) {
  if (!pr4Exists) {
    return 'missing-pr4';
  }
  if (!pr3Exists) {
    return 'new-capture';
  }
  if (ratio === 0) {
    return 'identical';
  }
  if (ratio < 0.005) {
    return 'negligible';
  }
  if ([6, 7, 9, 10, 11, 12, 15, 17, 20].includes(surface)) {
    return 'high-risk-delta';
  }
  if ([14, 16, 17].includes(surface)) {
    return 'cabinet-theme-limitation';
  }
  return 'framework-delta';
}

/**
 * @param {Array<{file: string, status: string}>} captureResults
 */
async function writeComparisonReport(captureResults) {
  const rows = [];
  let identical = 0;
  let negligible = 0;
  let framework = 0;
  let highRisk = 0;
  let missing = 0;

  for (const { file, status } of captureResults) {
    if (status !== 'success') {
      missing++;
      rows.push({ file, classification: 'capture-failed', ratio: null });
      continue;
    }

    const meta = parseFilename(file);
    const pr3Path = join(BASELINE_PR3, file);
    const pr4Path = join(OUTPUT_REPO, file);
    const pr3Exists = existsSync(pr3Path);
    const pr4Exists = existsSync(pr4Path);

    let ratio = null;
    if (pr3Exists && pr4Exists) {
      ratio = await diffRatio(readFileSync(pr3Path), readFileSync(pr4Path));
    }

    const classification = classifyDifference(meta.surface, ratio ?? 1, pr3Exists, pr4Exists);
    rows.push({ file, classification, ratio, surface: meta.surface });

    switch (classification) {
      case 'identical':
        identical++;
        break;
      case 'negligible':
        negligible++;
        break;
      case 'high-risk-delta':
        highRisk++;
        break;
      case 'missing-pr4':
      case 'capture-failed':
        missing++;
        break;
      default:
        framework++;
    }
  }

  const md = `# GAP-024 PR4 — Filament 5 / Livewire 4 visual comparison

Compared **PR4 after-captures** (\`docs/audits/visual-baselines/gap-024-filament5-pr4/\`)
against **PR3 Filament 4 baseline** (\`docs/audits/visual-baselines/gap-024-filament4-pr3/\`).

Manifest reference: \`docs/audits/GAP-024-pr1-visual-baseline-manifest.md\`.

Ephemeral copies: \`/opt/cursor/artifacts/gap-024-pr4-filament5-visual/\`.

## Capture method

- Playwright Chromium headless against \`${BASE_URL}\`
- Admin: \`${ADMIN_EMAIL}\` / cabinet: \`${CABINET_LOGIN}\` (B2BSeeder first customer)
- Themes: light / dark / system (Filament \`localStorage.theme\` + \`colorScheme\`)
- Viewports: desktop 1280×900, mobile 390×844 (**s05**: desktop 1024×900, mobile 767×900)
- Interaction states: s06 ViewAction slideOver, s15 cart drawer, s17 lightbox, s20 toast, s10–12 connector fixtures
- Output: WebP quality ${WEBP_QUALITY}${sharp ? ' (sharp)' : ' (Playwright fallback)'}

## Summary counts

| Classification | Count |
|---|---|
| Identical | ${identical} |
| Negligible (<0.5% pixels) | ${negligible} |
| Framework / expected delta | ${framework} |
| High-risk surface delta | ${highRisk} |
| Missing / failed | ${missing} |
| **Total** | **${rows.length}** |

## High-risk surfaces

| Surface | Files with high-risk-delta | Notes |
|---|---|---|
| s06 | ${rows.filter((r) => r.surface === 6 && r.classification === 'high-risk-delta').length} | ViewAction slideOver — compare supplemental-s06 approach |
| s07 | ${rows.filter((r) => r.surface === 7 && r.classification === 'high-risk-delta').length} | Admin forms (product / price list / delivery) |
| s09 | ${rows.filter((r) => r.surface === 9 && r.classification === 'high-risk-delta').length} | Field Matrix + Governance |
| s10–12 | ${rows.filter((r) => r.surface >= 10 && r.surface <= 12 && r.classification === 'high-risk-delta').length} | Connector accounts fixture |
| s15 | ${rows.filter((r) => r.surface === 15 && r.classification === 'high-risk-delta').length} | Quantity + cart drawer |
| s17 | ${rows.filter((r) => r.surface === 17 && r.classification === 'high-risk-delta').length} | Product photo lightbox |
| s20 | ${rows.filter((r) => r.surface === 20 && r.classification === 'high-risk-delta').length} | Toast after add-to-cart |

## Per-file classification

| File | vs PR3 | Pixel diff % | Classification |
|---|---|---:|---|
${rows
  .map((r) => {
    const pct = r.ratio === null ? '—' : (r.ratio * 100).toFixed(2);
    return `| \`${r.file}\` | ${existsSync(join(BASELINE_PR3, r.file)) ? 'yes' : 'no'} | ${pct} | ${r.classification} |`;
  })
  .join('\n')}

## Notes

- **s06**: PR3 supplemental \`supplemental-s06/\` documents the valid ViewAction-open gate; PR4 main-matrix files should match that interaction state.
- **s05**: Captured at manifest md-contract widths (1024 desktop / 767 mobile).
- **Livewire /catalog** (s14, s16, s17, s18 cabinet): dark/system may match light (pre-existing non-Filament limitation).
- **s15**: Checkout UI not implemented — cart dropdown only (same as PR1/PR3).

Generated by \`scripts/gap-024-pr4-visual-capture.mjs\`.
`;

  writeFileSync(join(OUTPUT_REPO, 'COMPARISON.md'), md);
  mkdirSync(OUTPUT_ARTIFACTS, { recursive: true });
  writeFileSync(join(OUTPUT_ARTIFACTS, 'COMPARISON.md'), md);
}

/**
 * @param {Array<{file: string, status: string, error?: string, durationMs: number}>} results
 */
function writeSummaryReport(results) {
  const success = results.filter((r) => r.status === 'success');
  const failed = results.filter((r) => r.status === 'fail');
  const totalMs = results.reduce((s, r) => s + r.durationMs, 0);

  let totalBytes = 0;
  for (const { file } of success) {
    const p = join(OUTPUT_REPO, file);
    if (existsSync(p)) {
      totalBytes += readFileSync(p).length;
    }
  }

  const md = `# GAP-024 PR4 Filament 5 Visual Capture — Summary

## Execution

- **Script:** \`scripts/gap-024-pr4-visual-capture.mjs\`
- **Base URL:** ${BASE_URL}
- **Compared against:** \`docs/audits/visual-baselines/gap-024-filament4-pr3/\` (PR3 Filament 4)

## Results

| Metric | Value |
|---|---|
| Target captures | 144 |
| Successful | ${success.length} |
| Failed | ${failed.length} |
| Total runtime | ${(totalMs / 1000).toFixed(1)}s |
| WebP output size | ~${(totalBytes / 1024 / 1024).toFixed(2)} MB |

## Output locations

1. **Durable:** \`docs/audits/visual-baselines/gap-024-filament5-pr4/\`
2. **Artifacts:** \`/opt/cursor/artifacts/gap-024-pr4-filament5-visual/\`

## Matrix

- 20 §16 surfaces × 3 themes × 2 viewports = 120 core
- +24 extended sub-states (s07 forms, s09 governance, s18 cabinet)
- **Total:** 144 WebP files

## Interaction coverage

| Surface | State |
|---|---|
| s06 | ViewAction slideOver (neutral row click per supplemental-s06) |
| s10–12 | Connector account list / detail / connection-check history |
| s15 | Quantity set + cart dropdown open |
| s17 | \`bpOpenLightbox\` overlay |
| s20 | Success notification after add-to-cart |

## Failures

${
  failed.length === 0
    ? '_None._'
    : failed.map((f) => `- \`${f.file}\`: ${f.error}`).join('\n')
}

See \`COMPARISON.md\` for PR4 vs PR3 classification.
`;

  writeFileSync(join(OUTPUT_REPO, 'SUMMARY.md'), md);
  writeFileSync(join(OUTPUT_ARTIFACTS, 'SUMMARY.md'), md);
}

function writeCaptureResultsJson(results) {
  const payload = {
    capturedAt: new Date().toISOString(),
    baseUrl: BASE_URL,
    outputRepo: OUTPUT_REPO,
    outputArtifacts: OUTPUT_ARTIFACTS,
    baselinePr3: BASELINE_PR3,
    fixtures,
    summary: {
      total: results.length,
      success: results.filter((r) => r.status === 'success').length,
      fail: results.filter((r) => r.status === 'fail').length,
    },
    results,
  };

  const json = JSON.stringify(payload, null, 2);
  writeFileSync(join(OUTPUT_REPO, 'capture-results.json'), json);
  writeFileSync(join(OUTPUT_ARTIFACTS, 'capture-results.json'), json);
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

async function main() {
  console.log('[gap-024] PR4 Filament 5 visual capture');
  console.log(`[gap-024] Base URL: ${BASE_URL}`);

  mkdirSync(OUTPUT_REPO, { recursive: true });
  mkdirSync(OUTPUT_ARTIFACTS, { recursive: true });

  const filenames = loadTargetFilenames();
  console.log(`[gap-024] Target files: ${filenames.length}`);

  if (filenames.length !== 144) {
    console.warn(`[gap-024] Expected 144 files, got ${filenames.length}`);
  }

  ensureDatabaseFixtures();

  const browser = await chromium.launch({ headless: true });
  const storage = await buildStorageStates(browser);
  const results = await runCaptures(browser, storage, filenames);
  await browser.close();

  writeCaptureResultsJson(results);

  if (!skipComparison) {
    await writeComparisonReport(results);
    writeSummaryReport(results);
  }

  const { success, fail } = {
    success: results.filter((r) => r.status === 'success').length,
    fail: results.filter((r) => r.status === 'fail').length,
  };

  console.log(`[gap-024] Done: ${success} success, ${fail} fail`);
  process.exit(fail > 0 ? 1 : 0);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
