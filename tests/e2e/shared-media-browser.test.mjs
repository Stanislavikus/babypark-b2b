import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile, mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';

const baseUrl = new URL(process.env.E2E_BASE_URL ?? 'http://127.0.0.1:18767');
assert.equal(process.env.CI, 'true', 'The shared-media browser test runs only in isolated CI.');
assert.equal(baseUrl.protocol, 'http:');
assert.equal(baseUrl.hostname, '127.0.0.1');
assert.equal(baseUrl.port, '18767');

const root = 'test-results/browser-smoke';
const fixture = JSON.parse(await readFile(`${root}/fixture.json`, 'utf8'));
const assetUrl = new URL(`/admin/media-assets/${fixture.sharedAssetId}/edit`, baseUrl).href;
const brandUrl = new URL(`/admin/brands/${fixture.differentBrandId}/edit`, baseUrl).href;


async function uploadViaInitializedFilePond(page, filename) {
  // FilePond replaces the raw Alpine input; wait for its own browser input.
  const input = page.locator('.filepond--root .filepond--browser').first();
  await input.waitFor({ state: 'attached', timeout: 15_000 });
  assert.equal(await page.locator('.filepond--item').count(), 0,
    'The test must start with an empty FilePond to avoid counting a previously processed file.');
  await input.setInputFiles(`${root}/${filename}`);
  await page.locator('.filepond--item[data-filepond-item-state="processing-complete"]')
    .first().waitFor({ state: 'visible', timeout: 20_000 });
}

test('shared Asset confirmation/cancellation and Brand dedup use real Chromium', { timeout: 160_000 }, async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 960 } });
  const page = await context.newPage();
  await context.tracing.start({ screenshots: true, snapshots: true, sources: true });
  const browserErrors = [];
  page.on('pageerror', (error) => browserErrors.push(error.message));
  let success = false;

  try {
    const login = await page.goto(new URL('/admin/login', baseUrl).href);
    assert.equal(login?.status(), 200);
    await page.locator('input[type="email"]').fill(process.env.E2E_ADMIN_EMAIL);
    await page.locator('input[type="password"]').fill(process.env.E2E_ADMIN_PASSWORD);
    await page.locator('button[type="submit"]').first().click();
    await page.waitForURL((url) => url.pathname.startsWith('/admin') && url.pathname !== '/admin/login');

    const assetResponse = await page.goto(assetUrl, { waitUntil: 'domcontentloaded' });
    assert.equal(assetResponse?.status(), 200, 'Shared Asset edit screen must open.');
    assert.equal(page.url(), assetUrl);
    await page.getByText('shared.png', { exact: false }).first().waitFor({ state: 'visible' });

    await uploadViaInitializedFilePond(page, 'replacement.png');
    await page.getByRole('button', { name: 'Зберегти', exact: true }).first().click();

    const dialog = page.locator('.fi-modal.fi-modal-open').filter({ hasText: 'Змінити спільне зображення?' });
    const modalHeading = dialog.locator('h2.fi-modal-heading');
    await modalHeading.waitFor({ state: 'attached', timeout: 20_000 });
    const geometry = await dialog.evaluate((element) => {
      const bounds = (node) => {
        if (!node) return null;
        const rect = node.getBoundingClientRect();
        const style = window.getComputedStyle(node);
        return { width: rect.width, height: rect.height, display: style.display, visibility: style.visibility };
      };
      return {
        outer: bounds(element),
        heading: bounds(element.querySelector('h2.fi-modal-heading')),
        confirm: bounds([...element.querySelectorAll('button')].find((button) => button.textContent?.includes('Замінити'))),
      };
    });
    console.log('FILAMENT_MODAL_GEOMETRY', JSON.stringify(geometry));
    await modalHeading.waitFor({ state: 'visible', timeout: 20_000 });
    assert.match(await dialog.innerText(), /брендів:\s*2/, 'The modal must report two live Brand references.');

    await dialog.getByRole('button', { name: 'Скасувати' }).click();
    await modalHeading.waitFor({ state: 'hidden' });
    await page.getByText('shared.png', { exact: false }).first().waitFor({ state: 'visible' });

    await page.getByRole('button', { name: 'Зберегти', exact: true }).first().click();
    await modalHeading.waitFor({ state: 'visible', timeout: 20_000 });
    await dialog.getByRole('button', { name: 'Замінити', exact: true }).click();
    await page.getByText('replacement.png', { exact: false }).first().waitFor({ state: 'visible', timeout: 20_000 });
    assert.equal(new URL(page.url()).pathname, new URL(assetUrl).pathname, 'Save should remain on the Asset card.');

    // Reload confirms persistence, but visual confirmation above is required before refresh.
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.getByText('replacement.png', { exact: false }).first().waitFor({ state: 'visible' });

    // Content identity: saving the exact same bytes must not ask for confirmation.
    await uploadViaInitializedFilePond(page, 'replacement.png');
    await page.getByRole('button', { name: 'Зберегти', exact: true }).first().click();
    await page.getByText('Зображення вже актуальне. Інших змін немає.', { exact: true })
      .waitFor({ state: 'visible', timeout: 20_000 });
    assert.equal(await modalHeading.isVisible(), false, 'Identical bytes should not open shared replacement modal.');

    const brandResponse = await page.goto(brandUrl, { waitUntil: 'domcontentloaded' });
    assert.equal(brandResponse?.status(), 200, 'Brand edit screen must open.');
    await page.getByText('other.png', { exact: false }).first().waitFor({ state: 'visible' });

    await uploadViaInitializedFilePond(page, 'replacement.png');
    await page.getByRole('button', { name: 'Зберегти', exact: true }).first().click();
    await page.getByText('Зображення вже є в Assets. Використано існуючий файл без дублювання.', { exact: true })
      .waitFor({ state: 'visible', timeout: 20_000 });
    await page.getByText('replacement.png', { exact: false }).first()
      .waitFor({ state: 'visible', timeout: 20_000 });

    // A separate upload after navigation must not inherit FilePond's previously processed item.
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.getByText('replacement.png', { exact: false }).first().waitFor({ state: 'visible' });
    await uploadViaInitializedFilePond(page, 'replacement.png');
    await page.getByRole('button', { name: 'Зберегти', exact: true }).first().click();
    await page.getByText('Зображення вже актуальне. Інших змін немає.', { exact: true })
      .waitFor({ state: 'visible', timeout: 20_000 });

    assert.deepEqual(browserErrors, [], 'No uncaught JS errors during media workflows.');
    success = true;
  } finally {
    if (!success) {
      await mkdir(root, { recursive: true });
      await page.screenshot({ path: `${root}/shared-media-failure.png`, fullPage: true }).catch(() => {});
      await context.tracing.stop({ path: `${root}/shared-media-trace.zip` }).catch(() => {});
    } else {
      await context.tracing.stop();
    }
    await browser.close();
  }
});
