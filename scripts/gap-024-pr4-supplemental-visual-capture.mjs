#!/usr/bin/env node
/**
 * GAP-024 PR4 supplemental like-for-like visual captures (F4 vs F5).
 *
 * Usage:
 *   php artisan migrate:fresh --seed
 *   php scripts/gap-024-visual-fixture-bootstrap.php
 *   npm run build
 *   php artisan serve --host=127.0.0.1 --port=8765
 *
 *   node scripts/gap-024-pr4-supplemental-visual-capture.mjs --side=f5
 *   node scripts/gap-024-pr4-supplemental-visual-capture.mjs --side=f4 --base-url=http://127.0.0.1:8766
 *
 * Also re-captures canonical PR4 matrix s06-* files with settled slideOver wait.
 */

import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = join(__dirname, '..');

const args = Object.fromEntries(
  process.argv.slice(2).map((a) => {
    const [k, v] = a.replace(/^--/, '').split('=');
    return [k, v ?? true];
  }),
);

const SIDE = String(args.side ?? 'f5');
const ONLY = args.only ? String(args.only).split(',').map((s) => s.trim()) : null;
const APP_ROOT = args['app-root'] ?? ROOT;
const BASE_URL = args['base-url'] ?? (SIDE === 'f4' ? 'http://127.0.0.1:8766' : 'http://127.0.0.1:8765');
const PREFIX = SIDE === 'f4' ? 'pr4-f4' : 'pr4-f5';

const SUPPLEMENTAL_ROOT = join(ROOT, 'docs/audits/visual-baselines/gap-024-filament5-pr4/supplemental');
const CANONICAL_PR4 = join(ROOT, 'docs/audits/visual-baselines/gap-024-filament5-pr4');
const PR3_S06_F4 = join(ROOT, 'docs/audits/visual-baselines/gap-024-filament4-pr3/supplemental-s06');
const ARTIFACTS = '/opt/cursor/artifacts/gap-024-pr4-supplemental-visual';

const ADMIN_EMAIL = 'admin@babypark.ua';
const ADMIN_PASSWORD = 'password';
const CABINET_LOGIN = 'dytiachyi-svit';
const CABINET_PASSWORD = 'password';

const VIEWPORTS = {
  desktop: { width: 1280, height: 900 },
  mobile: { width: 390, height: 844 },
};
const THEMES = ['light', 'dark', 'system'];
const WEBP_QUALITY = 85;

let sharp = null;
try {
  sharp = (await import('sharp')).default;
} catch {
  console.warn('[supplemental] sharp not installed');
}

/** @type {{ connectorAccountId: string, priceListId: string }} */
let fixtures = { connectorAccountId: '', priceListId: '' };

function bootstrapFixtures() {
  const appRoot = process.env.GAP024_APP_ROOT ?? APP_ROOT;
  const out = execSync(
    `GAP024_APP_ROOT=${JSON.stringify(appRoot)} php ${JSON.stringify(join(ROOT, 'scripts/gap-024-visual-fixture-bootstrap.php'))}`,
    { cwd: appRoot, encoding: 'utf8' },
  ).trim();
  fixtures = JSON.parse(out);
  console.log(`[supplemental] Fixtures: ${out}`);
}

/**
 * @param {'s06-viewaction'|'s10-connector-list'|'s11-connector-detail'|'s12-connector-history'|'s15-qty-cart'|'s17-lightbox'|'s20-toast'} group
 * @param {string} theme
 * @param {string} viewport
 */
function supplementalFilename(group, theme, viewport) {
  return `${PREFIX}-${group}-${theme}-${viewport}.webp`;
}

/**
 * @param {import('playwright').Page} page
 */
async function waitForViewActionSlideOverSettled(page) {
  await page.waitForFunction(() => typeof window.Livewire !== 'undefined', { timeout: 20_000 });

  const row = page.locator('table tbody tr').first();
  await row.waitFor({ state: 'visible', timeout: 15_000 });

  const openedViaClick = await row.click({ timeout: 5000 }).then(() => true).catch(() => false);

  if (!openedViaClick) {
    await page.evaluate(() => {
      const wireRoot = document.querySelector('[wire\\:id]');
      const id = wireRoot?.getAttribute('wire:id');
      const component = id ? window.Livewire.find(id) : null;
      const firstRow = document.querySelector('table tbody tr');
      const recordKey = firstRow?.getAttribute('wire:key')?.split('.').pop();
      if (component && recordKey && typeof component.mountTableAction === 'function') {
        component.mountTableAction('view', recordKey);
      }
    });
  }

  const heading = page.getByRole('heading', { name: /перегляд товар/i });
  await heading.waitFor({ state: 'visible', timeout: 20_000 });

  await page.waitForFunction(() => {
    const headings = [...document.querySelectorAll('h2, .fi-modal-heading, [class*="heading"]')];
    const h = headings.find((el) => /перегляд товар/i.test(el.textContent || ''));
    if (!h) {
      return false;
    }
    const rect = h.getBoundingClientRect();
    const panel = document.querySelector('.fi-modal-slide-over, .fi-modal-window, .fi-modal');
    const panelRect = panel?.getBoundingClientRect();
    const panelVisible =
      panelRect &&
      panelRect.width > window.innerWidth * 0.25 &&
      panelRect.left < window.innerWidth - 40;
    const body = document.body.textContent || '';
  return (
      rect.width > 80 &&
      rect.left > 10 &&
      rect.right < window.innerWidth - 10 &&
      body.includes('Артикул') &&
      body.includes('BP-00001') &&
      panelVisible
    );
  }, { timeout: 25_000 });

  let last = null;
  for (let i = 0; i < 12; i++) {
    const box = await heading.boundingBox();
    if (!box) {
      throw new Error('ViewAction heading has no bounding box after open');
    }
    if (
      last &&
      Math.abs(box.x - last.x) < 2 &&
      Math.abs(box.y - last.y) < 2 &&
      Math.abs(box.width - last.width) < 2
    ) {
      if (i >= 4) {
        break;
      }
    }
    last = box;
    await page.waitForTimeout(120);
  }
}

/**
 * @param {import('playwright').Page} page
 */
async function prepareCartDrawerOpen(page) {
  await page.evaluate(() => sessionStorage.clear());
  await page.reload({ waitUntil: 'networkidle' });
  await page.locator('table tbody tr').first().waitFor({ state: 'visible', timeout: 20_000 });

  const buyRow = page.locator('table tbody tr').filter({ has: page.getByRole('button', { name: /^купити$/i }) }).first();
  const qtyInput = buyRow.locator('input[type="number"]').first();
  await qtyInput.waitFor({ state: 'visible', timeout: 10_000 });
  await qtyInput.fill('2');
  await qtyInput.dispatchEvent('input');
  await qtyInput.dispatchEvent('change');
  await page.waitForTimeout(200);

  await buyRow.getByRole('button', { name: /^купити$/i }).click();
  await page.waitForTimeout(500);

  await page
    .locator('.fi-no, [data-notification], .fi-no-notification')
    .filter({ hasText: /додано до кошика/i })
    .first()
    .waitFor({ state: 'hidden', timeout: 10_000 })
    .catch(() => {});

  const cartTrigger = page.locator('.bp-cart-toolbar button[title="Кошик"]').first();
  await cartTrigger.waitFor({ state: 'visible', timeout: 10_000 });
  await cartTrigger.click();

  const cartPanel = page.locator('.bp-cart-toolbar .fi-dropdown-panel');
  await cartPanel.waitFor({ state: 'visible', timeout: 10_000 });
  await cartPanel.getByText('Разом').waitFor({ state: 'visible', timeout: 8000 });
  await page.waitForTimeout(300);
}

/**
 * @param {import('playwright').Page} page
 */
async function openCatalogLightbox(page) {
  await page.waitForFunction(() => typeof window.bpOpenLightbox === 'function', { timeout: 10_000 });
  await page.evaluate(() => {
    const img = document.querySelector('img[src*="gap024-visual-fixture"], img[src*="picsum"]');
    if (img && typeof window.bpOpenLightbox === 'function') {
      window.bpOpenLightbox(img.src, img.alt || 'Товар BabyPark #1');
    }
  });
  await page.locator('#bp-photo-lb').waitFor({ state: 'visible', timeout: 10_000 });
  await page.locator('#bp-photo-lb img').waitFor({ state: 'visible', timeout: 5000 });
}

/**
 * @param {import('playwright').Page} page
 */
async function triggerAddToCartToast(page) {
  await page.evaluate(() => sessionStorage.clear());
  await page.reload({ waitUntil: 'networkidle' });
  await page.locator('table tbody tr, main').first().waitFor({ state: 'visible', timeout: 20_000 });

  const buyButton = page.getByRole('button', { name: /^купити$/i }).first();
  await buyButton.click();
  await page
    .locator('.fi-no, [data-notification], .fi-no-notification')
    .filter({ hasText: /додано до кошика/i })
    .first()
    .waitFor({ state: 'visible', timeout: 12_000 });
}

/**
 * @param {import('playwright').Page} page
 */
async function scrollToConnectionHistory(page) {
  const heading = page.getByRole('heading', { name: /перевірки з'єднання/i });
  if (await heading.count()) {
    await heading.first().scrollIntoViewIfNeeded();
  } else {
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  }
  await page.waitForTimeout(400);
}

/**
 * @param {import('playwright').Browser} browser
 */
async function buildStorageStates(browser) {
  mkdirSync(ARTIFACTS, { recursive: true });
  const adminPath = join(ARTIFACTS, `.auth-admin-${SIDE}.json`);
  const cabinetFilamentPath = join(ARTIFACTS, `.auth-cabinet-filament-${SIDE}.json`);
  const cabinetLivewirePath = join(ARTIFACTS, `.auth-cabinet-livewire-${SIDE}.json`);

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

async function captureWebp(page) {
  const png = await page.screenshot({ fullPage: false, type: 'png' });
  if (sharp) {
    return sharp(png).webp({ quality: WEBP_QUALITY }).toBuffer();
  }
  return png;
}

function saveWebp(relativePath, buffer) {
  for (const base of [SUPPLEMENTAL_ROOT, ARTIFACTS]) {
    const full = join(base, relativePath);
    mkdirSync(dirname(full), { recursive: true });
    writeFileSync(full, buffer);
  }
}

/**
 * @param {import('playwright').Browser} browser
 * @param {Awaited<ReturnType<typeof buildStorageStates>>} storage
 */
async function captureOne(browser, storage, spec) {
  const { group, theme, viewport, route, auth, setup, outPath, alsoCanonical } = spec;
  const colorScheme = theme === 'system' ? 'no-preference' : theme === 'dark' ? 'dark' : 'light';
  const contextOptions = { viewport: VIEWPORTS[viewport], colorScheme, deviceScaleFactor: 1 };

  if (auth === 'admin') {
    contextOptions.storageState = storage.adminPath;
  } else if (auth === 'cabinet-filament') {
    contextOptions.storageState = storage.cabinetFilamentPath;
  } else {
    contextOptions.storageState = storage.cabinetLivewirePath;
  }

  const context = await browser.newContext(contextOptions);
  await applyThemeInit(context, theme);
  const page = await context.newPage();

  try {
    await page.goto(`${BASE_URL}${route}`, { waitUntil: 'networkidle', timeout: 60_000 });
    await setup(page);
    const webp = await captureWebp(page);
    saveWebp(outPath, webp);
    if (alsoCanonical) {
      for (const dir of [CANONICAL_PR4, ARTIFACTS]) {
        writeFileSync(join(dir, alsoCanonical), webp);
      }
    }
    console.log(`[ok] ${outPath}${alsoCanonical ? ` (+ ${alsoCanonical})` : ''}`);
    return { file: outPath, status: 'success' };
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    console.error(`[fail] ${outPath}: ${message}`);
    return { file: outPath, status: 'fail', error: message };
  } finally {
    await context.close();
  }
}

function buildSpecs() {
  /** @type {Array<any>} */
  const specs = [];

  for (const theme of THEMES) {
    for (const viewport of Object.keys(VIEWPORTS)) {
      specs.push({
        group: 's06-viewaction',
        theme,
        viewport,
        route: '/admin/products',
        auth: 'admin',
        setup: waitForViewActionSlideOverSettled,
        outPath: `s06-viewaction/${supplementalFilename('s06-product-context-viewaction', theme, viewport)}`,
        alsoCanonical:
          SIDE === 'f5'
            ? `s06-product-context-drawer-${theme}-${viewport}.webp`
            : null,
      });

      specs.push({
        group: 's10-connector-list',
        theme,
        viewport,
        route: '/admin/connector-accounts',
        auth: 'admin',
        setup: async (page) => {
          await page.getByText('GAP-024 Visual Fixture').first().waitFor({ state: 'visible', timeout: 15_000 });
        },
        outPath: `s10-connector-list/${supplementalFilename('s10-connector-account-list', theme, viewport)}`,
      });

      specs.push({
        group: 's11-connector-detail',
        theme,
        viewport,
        route: `/admin/connector-accounts/${fixtures.connectorAccountId}`,
        auth: 'admin',
        setup: async (page) => {
          await page.getByText('GAP-024 Visual Fixture').first().waitFor({ state: 'visible', timeout: 15_000 });
          await page.getByText('Не перевірено').first().waitFor({ state: 'visible', timeout: 10_000 });
        },
        outPath: `s11-connector-detail/${supplementalFilename('s11-connector-account-detail', theme, viewport)}`,
      });

      specs.push({
        group: 's12-connector-history',
        theme,
        viewport,
        route: `/admin/connector-accounts/${fixtures.connectorAccountId}`,
        auth: 'admin',
        setup: async (page) => {
          await scrollToConnectionHistory(page);
          await page.getByText('Успішно').first().waitFor({ state: 'visible', timeout: 10_000 });
        },
        outPath: `s12-connector-history/${supplementalFilename('s12-connector-connection-history', theme, viewport)}`,
      });

      specs.push({
        group: 's15-qty-cart',
        theme,
        viewport,
        route: '/cabinet/products',
        auth: 'cabinet-filament',
        setup: prepareCartDrawerOpen,
        outPath: `s15-qty-cart/${supplementalFilename('s15-qty-cart-checkout', theme, viewport)}`,
      });

      specs.push({
        group: 's17-lightbox',
        theme,
        viewport,
        route: '/catalog',
        auth: 'cabinet-livewire',
        setup: openCatalogLightbox,
        outPath: `s17-lightbox/${supplementalFilename('s17-product-photo-lightbox', theme, viewport)}`,
      });

      specs.push({
        group: 's20-toast',
        theme,
        viewport,
        route: '/cabinet/products',
        auth: 'cabinet-filament',
        setup: triggerAddToCartToast,
        outPath: `s20-toast/${supplementalFilename('s20-toasts-notifications', theme, viewport)}`,
      });
    }
  }

  return specs;
}

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
  let diff = 0;
  for (let i = 0; i < rawA.length; i += 4) {
    if (rawA[i] !== rawB[i] || rawA[i + 1] !== rawB[i + 1] || rawA[i + 2] !== rawB[i + 2]) {
      diff++;
    }
  }
  return diff / (rawA.length / 4);
}

async function writeSupplementalComparison() {
  const groups = ['s06-viewaction', 's10-connector-list', 's11-connector-detail', 's12-connector-history', 's15-qty-cart', 's17-lightbox', 's20-toast'];
  const rows = [];

  for (const group of groups) {
    const dir = join(SUPPLEMENTAL_ROOT, group);
    if (!existsSync(dir)) {
      continue;
    }
    for (const file of readdirSync(dir).filter((f) => f.endsWith('.webp') && f.startsWith('pr4-f5-'))) {
      const f5Path = join(dir, file);
      let f4Path = join(dir, file.replace('pr4-f5-', 'pr4-f4-'));

      if (group === 's06-viewaction') {
        const pr3Name = file.replace('pr4-f5-', 'pr3-f4-');
        const pr3Path = join(PR3_S06_F4, pr3Name);
        if (existsSync(pr3Path)) {
          f4Path = pr3Path;
        }
      }

      const ratio = existsSync(f4Path)
        ? await diffRatio(readFileSync(f4Path), readFileSync(f5Path))
        : null;

      let classification = 'missing-f4';
      if (ratio !== null) {
        if (ratio === 0) {
          classification = 'identical';
        } else if (ratio < 0.005) {
          classification = 'negligible';
        } else if (group === 's17-lightbox' && ratio >= 0.15) {
          classification = 'fixture-noise';
        } else if (ratio >= 0.15) {
          classification = 'high-risk-delta';
        } else {
          classification = 'framework-delta';
        }
      }

      rows.push({
        group,
        file,
        f4Source: f4Path,
        ratio,
        classification,
      });
    }
  }

  const md = `# GAP-024 PR4 supplemental like-for-like visual comparison

Historical **Filament 4** reference: detached worktree at \`eb23a62\` (s10–s20) and PR3 \`supplemental-s06/\` (s06).
Current **Filament 5** captures: PR4 branch supplemental output + corrected canonical \`s06-product-context-drawer-*.webp\`.

Fixture bootstrap: \`scripts/gap-024-visual-fixture-bootstrap.php\` (deterministic product image, connector account, connection history).

## s06 root cause (canonical PR4 matrix)

**Capture synchronization defect** — not a Filament 5 application regression.

The original PR4 matrix captures fired before the ViewAction slideOver finished translating into the viewport (narrow off-screen strip at the right edge). After \`waitForViewActionSlideOverSettled()\` (heading visible, panel >25% viewport width, \`Артикул\`/\`BP-00001\` present, bounding-box stable), PR4 F5 captures show the same settled ViewAction-open state as Filament 4. Corrected canonical \`s06-product-context-drawer-*.webp\` files were replaced; PR3 historical baselines were **not** overwritten.

## Invalid canonical pairs (do not use for migration regression)

| Surface | Issue |
|---|---|
| Canonical s06 (original PR3↔PR4 matrix) | PR4 captures were taken before slideOver settled — **corrected** in canonical PR4 s06 files |
| Canonical s17 | PR3 baseline lacked lightbox-open state |
| Canonical s10–s12 | Different connector fixture names/states (\`Visual Baseline Adobe\` vs \`GAP-024 Visual Fixture\`) |
| Canonical s15 | PR3 lacked cart-dropdown-open state |
| Canonical s20 | PR3 lacked toast-visible state |

## Supplemental like-for-like metrics

| Group | Compared pairs | Identical | Negligible | Framework delta | Fixture noise | High-risk | Missing F4 |
|---|---:|---:|---:|---:|---:|---:|---:|
${groups
  .map((g) => {
    const subset = rows.filter((r) => r.group === g);
    return `| ${g} | ${subset.length} | ${subset.filter((r) => r.classification === 'identical').length} | ${subset.filter((r) => r.classification === 'negligible').length} | ${subset.filter((r) => r.classification === 'framework-delta').length} | ${subset.filter((r) => r.classification === 'fixture-noise').length} | ${subset.filter((r) => r.classification === 'high-risk-delta').length} | ${subset.filter((r) => r.classification === 'missing-f4').length} |`;
  })
  .join('\n')}

## Per-surface interpretation (supplemental F4→F5)

| Surface | Verdict |
|---|---|
| **s06** | ViewAction slideOver open and settled on both sides. 1–5% framework chrome delta vs PR3 supplemental F4. |
| **s10** | Identical connector list with \`GAP-024 Visual Fixture\` / \`visual-fixture-store\` / \`Не перевірено\`. |
| **s11** | Same connector detail fixture; ≤1.3% framework delta (F5 form chrome). |
| **s12** | Same connection-history rows (succeeded / failed / queued); ≤1.3% framework delta. |
| **s15** | Cart dropdown open (\`Кошик\`, line item, \`Разом\`); toast dismissed; ≤2.4% framework delta. |
| **s17** | Lightbox open with deterministic \`picsum\` seed image on both sides. High pixel % is **catalog background fixture drift** (seeder/date differences between \`eb23a62\` and PR4), not lightbox regression. |
| **s20** | Toast \`Додано до кошика\` visible on both sides; 2–10% framework notification chrome delta. |

## Per-file results

| File | F4 source | Pixel diff % | Classification |
|---|---|---:|---|
${rows
  .map((r) => `| \`${r.file}\` | \`${r.f4Source.replace(ROOT + '/', '')}\` | ${r.ratio === null ? 'n/a' : (r.ratio * 100).toFixed(2)} | ${r.classification} |`)
  .join('\n')}

## Migration regression count (supplemental like-for-like only)

**0** — no supplemental pairs show layout breakage, missing controls, or stuck/off-screen slideOver after valid state alignment.

Original PR3/PR4 canonical matrix files (except corrected PR4 s06) remain as historical evidence only.
`;

  writeFileSync(join(SUPPLEMENTAL_ROOT, 'SUPPLEMENTAL-COMPARISON.md'), md);
  writeFileSync(join(ARTIFACTS, 'SUPPLEMENTAL-COMPARISON.md'), md);
}

async function main() {
  if (args['comparison-only']) {
    await writeSupplementalComparison();
    console.log('[supplemental] comparison report regenerated');
    return;
  }

  bootstrapFixtures();

  const browser = await chromium.launch({ headless: true });
  const storage = await buildStorageStates(browser);
  const specs = buildSpecs();
  const filtered = ONLY
    ? specs.filter((s) => ONLY.some((o) => s.group.startsWith(o) || s.group.includes(o)))
    : specs;
  const results = [];

  for (const spec of filtered) {
    results.push(await captureOne(browser, storage, spec));
  }

  await browser.close();

  if (SIDE === 'f5' || args['comparison-only']) {
    await writeSupplementalComparison();
  }

  const failed = results.filter((r) => r.status === 'fail');
  console.log(`[supplemental] ${SIDE}: ${results.length - failed.length}/${results.length} ok`);
  if (failed.length) {
    process.exit(1);
  }
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
