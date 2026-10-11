import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile, mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';

const baseUrl = new URL(process.env.E2E_BASE_URL ?? 'http://127.0.0.1:18767');
assert.equal(process.env.CI, 'true', 'Only isolated GitHub CI may run this Chromium test.');
assert.equal(baseUrl.href, 'http://127.0.0.1:18767/');
const root = 'test-results/browser-smoke';
const fixture = JSON.parse(await readFile(`${root}/fixture.json`, 'utf8'));
const brandPath = `/admin/brands/${fixture.pickerBrandId}/edit`;

test('Brand picks an existing Original image from the native Assets visual library', { timeout: 100_000 }, async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  await context.tracing.start({ screenshots: true, snapshots: true, sources: true });
  const jsErrors = [];
  page.on('pageerror', error => jsErrors.push(error.message));
  let succeeded = false;
  try {
    const login = await page.goto(new URL('/admin/login', baseUrl).href);
    assert.equal(login?.status(), 200);
    await page.locator('input[type="email"]').fill(process.env.E2E_ADMIN_EMAIL);
    await page.locator('input[type="password"]').fill(process.env.E2E_ADMIN_PASSWORD);
    await page.locator('button[type="submit"]').first().click();
    await page.waitForURL(url => url.pathname.startsWith('/admin') && url.pathname !== '/admin/login');

    const result = await page.goto(new URL(brandPath, baseUrl).href);
    assert.equal(result?.status(), 200);
    const mediaSection = page.locator('.fi-section').filter({ has: page.getByText('Медіа', { exact: true }) }).first();
    await mediaSection.getByText('Перетягніть зображення сюди або виберіть файл', { exact: true }).waitFor({ state: 'visible', timeout: 20_000 });
    assert.ok(await mediaSection.locator('input[type="file"]').count() > 0, 'The native dropzone is inside the same Media section.');
    await mkdir(root, { recursive: true });
    await mediaSection.screenshot({ path: `${root}/brand-media-layout.png` });
    await mediaSection.getByRole('button', { name: 'Обрати з медіатеки' }).click();
    const modal = page.locator('.fi-modal.fi-modal-open').filter({ hasText: 'Медіатека' });
    await modal.locator('h2.fi-modal-heading').waitFor({ state: 'visible', timeout: 20_000 });
    const row = modal.locator('tr').filter({ hasText: 'other.png' }).first();
    await row.waitFor({ state: 'visible', timeout: 20_000 });
    assert.ok(await row.locator('img').count(), 'Native Filament ImageColumn renders a preview in picker.');
    await row.locator('input[type="checkbox"]').first().check();
    await modal.getByRole('button', { name: 'Підтвердити вибір', exact: true }).click();
    await modal.locator('h2.fi-modal-heading').waitFor({ state: 'hidden', timeout: 20_000 });
    await page.getByText('other.png', { exact: false }).first().waitFor({ state: 'visible' });
    const saved = page.waitForResponse(response =>
      response.request().method() === 'POST'
      && response.url().includes('/livewire-')
      && response.request().postData()?.includes('"method":"save"'),
      { timeout: 20_000 },
    );
    await page.getByRole('button', { name: 'Зберегти', exact: true }).first().click();
    const saveResponse = await saved;
    assert.equal(saveResponse.status(), 200, 'Save must complete before navigating away.');
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.getByText('other.png', { exact: false }).first().waitFor({ state: 'visible', timeout: 20_000 });
    assert.deepEqual(jsErrors, []);
    succeeded = true;
  } finally {
    if (!succeeded) {
      await mkdir(root, { recursive: true });
      await page.screenshot({ path: `${root}/visual-assets-picker-failure.png`, fullPage: true }).catch(() => {});
      await context.tracing.stop({ path: `${root}/visual-assets-picker-trace.zip` }).catch(() => {});
    } else {
      await context.tracing.stop();
    }
    await browser.close();
  }
});
