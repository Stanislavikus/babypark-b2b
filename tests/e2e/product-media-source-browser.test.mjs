import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile, mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';

const baseUrl = new URL(process.env.E2E_BASE_URL ?? 'http://127.0.0.1:18767');
assert.equal(process.env.CI, 'true', 'Product media browser verification is isolated CI only.');
assert.equal(baseUrl.protocol, 'http:');
assert.equal(baseUrl.hostname, '127.0.0.1');
assert.equal(baseUrl.port, '18767');

const root = 'test-results/browser-smoke';
const fixture = JSON.parse(await readFile(`${root}/fixture.json`, 'utf8'));

test('Master Product Media uses approved native source controls and persists a real upload', { timeout: 115_000 }, async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 960 } });
  const page = await context.newPage();
  await context.tracing.start({ screenshots: true, snapshots: true, sources: true });
  let succeeded = false;
  try {
    const login = await page.goto(new URL('/admin/login', baseUrl).href);
    assert.equal(login?.status(), 200);
    await page.locator('input[type="email"]').fill(process.env.E2E_ADMIN_EMAIL);
    await page.locator('input[type="password"]').fill(process.env.E2E_ADMIN_PASSWORD);
    await page.locator('button[type="submit"]').first().click();
    await page.waitForURL(url => url.pathname.startsWith('/admin') && url.pathname !== '/admin/login');

    const target = new URL(`/admin/products/${fixture.mediaProductId}/edit`, baseUrl).href;
    const response = await page.goto(target, { waitUntil: 'domcontentloaded' });
    assert.equal(response?.status(), 200);

    const zone = page.locator('.bp-product-media-source.bp-brand-media-dropzone');
    await zone.getByText('Перетягніть зображення сюди', { exact: true })
      .waitFor({ state: 'visible', timeout: 20_000 });
    await zone.locator('.filepond--root').waitFor({ state: 'attached', timeout: 20_000 });
    await zone.getByRole('button', { name: 'Обрати з медіатеки' })
      .waitFor({ state: 'visible', timeout: 20_000 });
    await zone.getByRole('button', { name: 'Додати за URL' })
      .waitFor({ state: 'visible', timeout: 20_000 });
    await zone.getByRole('button', { name: 'Завантажити' })
      .waitFor({ state: 'visible', timeout: 20_000 });

    const geometry = await zone.evaluate(element => {
      const rect = selector => {
        const node = element.querySelector(selector);
        if (!node) return null;
        const r = node.getBoundingClientRect();
        return { left: r.left, right: r.right, top: r.top, bottom: r.bottom };
      };
      return {
        border: getComputedStyle(element).borderTopStyle,
        containerWidth: element.getBoundingClientRect().width,
        box: rect('.filepond--root'),
        upload: rect('.bp-brand-media-browse-button'),
        library: rect('.bp-brand-media-picker button'),
        url: rect('.bp-brand-media-url-action button'),
      };
    });
    assert.equal(geometry.border, 'dashed');
    assert.ok(geometry.box && geometry.upload && geometry.library && geometry.url,
      'All three real source controls and native FilePond must be inside the common dashed block.');
    // The Product editor is a two-thirds-width column at a 1440px viewport.
    // The approved @container threshold (44rem) stacks controls there, while
    // the full-width Brand editor keeps a single desktop row.
    if (geometry.containerWidth > 704) {
      assert.ok(geometry.upload.right <= geometry.library.left + 4
        && geometry.library.right <= geometry.url.left + 4,
      'Wide Product Media source actions must use the approved horizontal order.');
    } else {
      assert.ok(geometry.upload.bottom <= geometry.library.top + 4
        && geometry.library.bottom <= geometry.url.top + 4,
      'Narrow Product Media source actions must stack without overlap.');
    }

    await mkdir(root, { recursive: true });
    await zone.screenshot({ path: `${root}/product-media-empty.png` });

    await zone.getByRole('button', { name: 'Додати за URL' }).click();
    const dialog = page.locator('.fi-modal.fi-modal-open').filter({
      has: page.getByRole('heading', { name: 'Додати за URL', exact: true }),
    });
    await dialog.getByLabel('Посилання на зображення').waitFor({ state: 'visible' });
    await page.keyboard.press('Escape');

    // Use a real decodable CI-only PNG; the form's Livewire callback must route
    // it to ProductMediaMutationService, never Product.images mass assignment.
    const input = zone.locator('.filepond--root .filepond--browser').first();
    await input.waitFor({ state: 'attached' });
    await input.setInputFiles(`${root}/shared.png`);
    await page.getByText('1 медіа', { exact: false }).first()
      .waitFor({ state: 'visible', timeout: 25_000 });
    await zone.screenshot({ path: `${root}/product-media-uploaded.png` });
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.getByText('1 медіа', { exact: false }).first()
      .waitFor({ state: 'visible', timeout: 20_000 });

    await page.setViewportSize({ width: 390, height: 844 });
    const mobile = page.locator('.bp-product-media-source.bp-brand-media-dropzone');
    await mobile.getByRole('button', { name: 'Обрати з медіатеки' })
      .waitFor({ state: 'visible' });
    await mobile.screenshot({ path: `${root}/product-media-mobile.png` });
    succeeded = true;
  } finally {
    if (!succeeded) {
      await page.screenshot({ path: `${root}/product-media-failure.png`, fullPage: true }).catch(() => {});
      await context.tracing.stop({ path: `${root}/product-media-trace.zip` }).catch(() => {});
    } else {
      await context.tracing.stop();
    }
    await browser.close();
  }
});
