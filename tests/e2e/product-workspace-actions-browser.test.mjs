import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { chromium } from 'playwright';

const baseUrl = new URL(process.env.E2E_BASE_URL ?? 'http://127.0.0.1:18767');
assert.equal(process.env.CI, 'true', 'Product workspace browser verification is isolated CI only.');
assert.equal(baseUrl.protocol, 'http:');
assert.equal(baseUrl.hostname, '127.0.0.1');
assert.equal(baseUrl.port, '18767');

const root = 'test-results/browser-smoke';
const fixture = JSON.parse(await readFile(root + '/fixture.json', 'utf8'));

async function signIn(page) {
  const login = await page.goto(new URL('/admin/login', baseUrl).href);
  assert.equal(login?.status(), 200);
  await page.locator('input[type="email"]').fill(process.env.E2E_ADMIN_EMAIL);
  await page.locator('input[type="password"]').fill(process.env.E2E_ADMIN_PASSWORD);
  await page.locator('button[type="submit"]').first().click();
  await page.waitForURL(url => url.pathname.startsWith('/admin') && url.pathname !== '/admin/login');
}

test('Master Product contextual actions and close behavior work on desktop and mobile', { timeout: 100_000 }, async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 960 } });
  const list = await context.newPage();
  const nativeDialogs = [];
  try {
    await signIn(list);

    const listUrl = new URL('/admin/products', baseUrl);
    listUrl.searchParams.set('channel', 'campaign-b-context');
    listUrl.searchParams.set('tableSearch', 'E2E');
    await list.goto(listUrl.href, { waitUntil: 'domcontentloaded' });
    await list.waitForFunction(() => typeof window.__babyparkProductsRefreshHandler === 'function');

    const editor = await context.newPage();
    editor.on('dialog', async dialog => {
      nativeDialogs.push(dialog.type);
      await dialog.accept();
    });

    const editUrl = new URL('/admin/products/' + fixture.mediaProductId + '/edit', baseUrl);
    editUrl.searchParams.set('channel', 'campaign-b-context');
    editUrl.searchParams.set('tableSearch', 'E2E');
    const response = await editor.goto(editUrl.href, { waitUntil: 'domcontentloaded' });
    assert.equal(response?.status(), 200);
    const name = editor.locator('input[id="form.name"]');
    await name.waitFor({ state: 'visible', timeout: 20_000 });
    const header = editor.locator('.fi-header-actions-ctn');
    await header.waitFor({ state: 'visible', timeout: 20_000 });
    const viewLink = header.getByRole('link', { name: 'Перегляд', exact: true });
    const closeButton = header.getByRole('button', { name: 'Закрити', exact: true });
    assert.equal(await viewLink.count(), 1, 'Edit header must expose the View action as one link.');
    assert.equal(await closeButton.count(), 1, 'Edit header must expose one Close button.');
    assert.equal(
      (await header.getByRole('link').count()) + (await header.getByRole('button').count()),
      2,
      'Edit header must contain only View and Close.',
    );
    await viewLink.waitFor({ state: 'visible' });
    await closeButton.waitFor({ state: 'visible' });
    assert.equal(await editor.getByRole('button', { name: 'Медіа', exact: true }).count(), 0,
      'The duplicate top-level Media menu must be removed.');

    const reorder = editor.getByRole('button', { name: 'Впорядкувати', exact: true });
    const remove = editor.getByRole('button', { name: 'Видалити з товару', exact: true });
    await reorder.waitFor({ state: 'visible', timeout: 20_000 });
    await remove.waitFor({ state: 'visible', timeout: 20_000 });

    for (const width of [1440, 390]) {
      await editor.setViewportSize({ width, height: width < 768 ? 844 : 960 });
      await closeButton.waitFor({ state: 'visible' });
      await reorder.waitFor({ state: 'visible' });
      await remove.waitFor({ state: 'visible' });
    }

    await name.fill('E2E quiet save product');
    await editor.getByRole('button', { name: 'Зберегти', exact: true }).last().click();
    await editor.getByRole('heading', { name: 'E2E quiet save product', exact: true })
      .waitFor({ state: 'visible', timeout: 20_000 });
    assert.equal(await name.inputValue(), 'E2E quiet save product');
    assert.equal(await editor.getByText('Товар збережено', { exact: true }).count(), 0);

    await name.fill('E2E unsaved product name');
    await closeButton.click();
    const closeDialog = editor.locator('.fi-modal.fi-modal-open').filter({
      hasText: 'Закрити без збереження?',
    });
    await closeDialog.getByRole('heading', { name: 'Закрити без збереження?', exact: true })
      .waitFor({ state: 'visible', timeout: 10_000 });
    await closeDialog.getByRole('button', { name: 'Скасувати', exact: true }).click();
    await closeDialog.waitFor({ state: 'hidden', timeout: 10_000 });
    assert.equal(await name.inputValue(), 'E2E unsaved product name');

    const listReload = list.waitForEvent('load', { timeout: 20_000 }).then(() => true).catch(error => {
      console.log('PRODUCT LIST RELOAD DEBUG', JSON.stringify({
        message: error.message,
        editorClosed: editor.isClosed(),
        editorUrl: editor.url(),
        listUrl: list.url(),
      }));
      return false;
    });
    await closeButton.click();
    const confirmation = editor.locator('.fi-modal.fi-modal-open').filter({
      hasText: 'Закрити без збереження?',
    });
    await confirmation.getByRole('button', { name: 'Закрити', exact: true }).click();
    const listReloaded = await listReload;
    assert.equal(listReloaded, true, 'The open Products list must reload after confirmed Close.');

    const returnedListUrl = new URL(list.url());
    assert.equal(returnedListUrl.pathname, '/admin/products');
    assert.equal(returnedListUrl.searchParams.get('channel'), 'campaign-b-context');
    assert.equal(returnedListUrl.searchParams.get('tableSearch'), 'E2E');
    assert.equal(nativeDialogs.length, 0,
      'Confirmed Close must reset the browser-exit guard before closing or falling back.');

    const exitEditor = await context.newPage();
    const exitResponse = await exitEditor.goto(editUrl.href, { waitUntil: 'domcontentloaded' });
    assert.equal(exitResponse?.status(), 200);
    await exitEditor.locator('input[id="form.name"]').fill('E2E browser exit draft');
    let exitDialogType = null;
    exitEditor.once('dialog', async dialog => {
      exitDialogType = dialog.type;
      await dialog.accept();
    });
    await exitEditor.close({ runBeforeUnload: true });
    assert.equal(exitDialogType, 'beforeunload',
      'Ordinary browser exit must retain Filament’s unsaved-changes guard.');

  } finally {
    await context.close().catch(() => {});
    await browser.close();
  }
});
