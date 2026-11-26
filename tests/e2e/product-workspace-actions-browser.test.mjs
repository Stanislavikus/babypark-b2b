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
    listUrl.searchParams.set('search', 'E2E');
    listUrl.searchParams.set('returnUrl', 'https://untrusted.example.test/never');
    await list.goto(listUrl.href, { waitUntil: 'domcontentloaded' });
    await list.waitForFunction(() => typeof window.__babyparkProductsRefreshHandler === 'function');

    const assertListContext = currentUrl => {
      const url = new URL(currentUrl);
      assert.equal(url.searchParams.get('channel'), 'campaign-b-context');
      assert.equal(url.searchParams.get('search'), 'E2E');
      assert.equal(url.searchParams.has('returnUrl'), false,
        'Only allowlisted list state may cross product page transitions.');
    };

    const productRow = list.getByRole('row').filter({ hasText: 'E2E Master Media Product' });
    await productRow.waitFor({ state: 'visible', timeout: 20_000 });
    await productRow.getByText('E2E Master Media Product', { exact: true }).click();

    const fullCardLink = list.getByRole('link', { name: 'Відкрити повну картку', exact: true });
    try {
      await fullCardLink.waitFor({ state: 'visible', timeout: 20_000 });
    } catch (error) {
      const linkState = await fullCardLink.evaluateAll(links => links.map(link => {
        const dialog = link.closest('[role="dialog"]');
        const rect = link.getBoundingClientRect();
        const dialogRect = dialog?.getBoundingClientRect();
        const style = window.getComputedStyle(link);

        return {
          text: link.innerText,
          rect: { x: rect.x, y: rect.y, width: rect.width, height: rect.height },
          visibility: style.visibility,
          display: style.display,
          dialogRect: dialogRect && {
            x: dialogRect.x,
            y: dialogRect.y,
            width: dialogRect.width,
            height: dialogRect.height,
          },
          dialogClasses: dialog?.className ?? null,
        };
      }));
      console.log('PRODUCT_ROW_VIEW_DEBUG', JSON.stringify({ url: list.url(), linkState }));
      throw error;
    }
    assert.equal(await fullCardLink.count(), 1, 'View drawer must expose one full-card link.');
    const viewDialog = fullCardLink.locator('xpath=ancestor::*[@role="dialog"][1]');
    assert.ok((await viewDialog.getAttribute('class'))?.includes('fi-modal-open'),
      'The full-card link must belong to the open View drawer.');
    const viewPagePromise = context.waitForEvent('page', { timeout: 20_000 });
    await fullCardLink.click();
    const editor = await viewPagePromise;
    await editor.waitForLoadState('domcontentloaded');
    editor.on('dialog', async dialog => {
      nativeDialogs.push(dialog.type);
      await dialog.accept();
    });
    assertListContext(editor.url());

    const viewHeader = editor.locator('.fi-header-actions-ctn');
    const viewEditLink = viewHeader.getByRole('link').first();
    assert.equal(await viewHeader.getByRole('link').count(), 1,
      'View header must expose the standard Edit action.');
    await viewEditLink.click();
    await editor.waitForURL(url => url.pathname.endsWith('/edit'), { timeout: 20_000 });
    assertListContext(editor.url());
    const editUrl = new URL(editor.url());
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
    await viewLink.click();
    await editor.waitForURL(url => !url.pathname.endsWith('/edit'), { timeout: 20_000 });
    assertListContext(editor.url());
    const editLinkFromView = editor.locator('.fi-header-actions-ctn').getByRole('link').first();
    await editLinkFromView.click();
    await editor.waitForURL(url => url.pathname.endsWith('/edit'), { timeout: 20_000 });
    assertListContext(editor.url());
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
    await confirmation.locator('button[type="submit"]').click();
    const listReloaded = await listReload;
    assert.equal(listReloaded, true, 'The open Products list must reload after confirmed Close.');

    const returnedListUrl = new URL(list.url());
    assert.equal(returnedListUrl.pathname, '/admin/products');
    assert.equal(returnedListUrl.searchParams.get('channel'), 'campaign-b-context');
    assert.equal(returnedListUrl.searchParams.get('search'), 'E2E');
    assert.equal(nativeDialogs.length, 0,
      'Confirmed Close must reset the browser-exit guard before closing or falling back.');

    const exitEditor = await context.newPage();
    const exitResponse = await exitEditor.goto(editUrl.href, { waitUntil: 'domcontentloaded' });
    assert.equal(exitResponse?.status(), 200);
    await exitEditor.locator('input[id="form.name"]').fill('E2E browser exit draft');
    const navigation = exitEditor.goto(new URL('/admin/products', baseUrl).href, {
      waitUntil: 'domcontentloaded',
    }).catch(() => null);
    const exitDialog = await exitEditor.waitForEvent('dialog', { timeout: 10_000 });
    const exitDialogType = exitDialog.type();
    await exitDialog.dismiss();
    await navigation;
    assert.equal(exitDialogType, 'beforeunload',
      'Ordinary browser navigation must retain Filament’s unsaved-changes guard.');
    assert.equal(new URL(exitEditor.url()).pathname, editUrl.pathname,
      'Dismissing the browser-exit warning must leave the user on the edited product.');

    const fallbackEditor = await context.newPage();
    const fallbackUrl = new URL('/admin/products/' + fixture.mediaProductId + '/edit', baseUrl);
    fallbackUrl.searchParams.set('channel', 'campaign-b-context');
    fallbackUrl.searchParams.set('search', 'E2E');
    fallbackUrl.searchParams.set('returnUrl', 'https://untrusted.example.test/never');
    const fallbackResponse = await fallbackEditor.goto(fallbackUrl.href, { waitUntil: 'domcontentloaded' });
    assert.equal(fallbackResponse?.status(), 200);
    const fallbackCloseButton = fallbackEditor.locator('.fi-header-actions-ctn')
      .getByRole('button', { name: 'Закрити', exact: true });
    assert.equal(await fallbackCloseButton.count(), 1,
      'A clean direct Edit page must expose the existing Close Action.');
    await fallbackCloseButton.click();
    await fallbackEditor.waitForURL(url => url.pathname === '/admin/products', { timeout: 20_000 });
    assertListContext(fallbackEditor.url());

  } finally {
    await context.close().catch(() => {});
    await browser.close();
  }
});

test('Clicking a variant row photo opens its existing media drawer with the clicked VariantID selected', { timeout: 60_000 }, async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 960 } });
  const page = await context.newPage();
  try {
    await signIn(page);

    const editUrl = new URL('/admin/products/' + fixture.variantMediaProductId + '/edit', baseUrl);
    const response = await page.goto(editUrl.href, { waitUntil: 'domcontentloaded' });
    assert.equal(response?.status(), 200);

    const existingActionButton = page.getByRole('button', { name: 'Призначити варіантам', exact: true });
    assert.equal(await existingActionButton.count(), 1,
      'The existing media assignment Action must be visible in its schema component.');
    await existingActionButton.waitFor({ state: 'visible', timeout: 10_000 });
    const existingActionHandler = await existingActionButton.getAttribute('wire:click');
    assert.ok(existingActionHandler?.includes('form.product_media_manage_actions'),
      'The native Action trigger must use its registered schema context.');

    const targetRow = page.locator('tr').filter({ hasText: 'Сірий' });
    await targetRow.waitFor({ state: 'visible', timeout: 20_000 });
    const photoButton = targetRow.locator('button[title="Редагувати власні фото варіанта"]');
    assert.equal(await photoButton.count(), 1, 'The target Variant row must expose its photo action.');
    const handler = await photoButton.getAttribute('wire:click');
    assert.ok(handler?.includes('form.product_media_manage_actions'),
      'The photo click must pass the existing media action schema context.');
    const clientErrors = [];
    const livewireCalls = [];
    page.on('pageerror', error => clientErrors.push(error.message));
    page.on('console', message => {
      if (message.type() === 'error') clientErrors.push(message.text());
    });
    page.on('request', request => {
      if (request.method() !== 'POST' || !request.url().includes('/livewire-') || !request.url().endsWith('/update')) {
        return;
      }

      try {
        const payload = JSON.parse(request.postData() ?? '{}');
        for (const component of payload.components ?? []) {
          livewireCalls.push(...(component.calls ?? []));
        }
      } catch (error) {
        livewireCalls.push({ parseError: error.message });
      }
    });
    await photoButton.click();
    const livewireResponse = await page.waitForResponse(response =>
      response.request().method() === 'POST'
      && response.url().includes('/livewire-')
      && response.url().endsWith('/update'),
    { timeout: 10_000 });
    const livewireResponseBody = await livewireResponse.text();
    if (livewireResponse.status() !== 200) {
      console.log('VARIANT_ACTION_HTTP_DEBUG', JSON.stringify({
        status: livewireResponse.status(),
        body: livewireResponseBody.slice(0, 6_000),
      }));
    }
    assert.equal(livewireResponse.status(), 200, livewireResponseBody.slice(0, 2_000));

    assert.ok(livewireCalls.some(call =>
      call.method === 'mountAction'
      && call.params?.[0] === 'assign_variant_media'
      && call.params?.[2]?.schemaComponent === 'form.product_media_manage_actions'
    ), 'The Livewire request must send the existing Action schema context.');
    const drawerHeading = page.getByRole('heading', { name: 'Медіа варіантів', exact: true });
    try {
      await drawerHeading.waitFor({ state: 'visible', timeout: 20_000 });
    } catch (error) {
      const componentStates = await page.locator('[wire\\:id][wire\\:snapshot]').evaluateAll(elements =>
        elements.map(element => {
          const snapshot = JSON.parse(element.getAttribute('wire:snapshot') ?? '{}');
          return {
            id: element.getAttribute('wire:id'),
            mountedActions: snapshot.data?.mountedActions ?? null,
          };
        }),
      );
      console.log('VARIANT_MEDIA_MOUNT_DEBUG', JSON.stringify({
        handler,
        existingActionHandler,
        livewireCalls,
        livewireStatus: livewireResponse.status(),
        livewireResponseBody: livewireResponseBody.slice(0, 6_000),
        clientErrors,
        componentStates,
      }));
      throw error;
    }
    const drawer = drawerHeading.locator('xpath=ancestor::*[@role="dialog"][1]');
    assert.ok((await drawer.getAttribute('class'))?.includes('fi-modal-open'),
      'The media heading must belong to the open variant media drawer.');
    const selectedVariant = drawer.locator(
      '.fi-select-input-btn .fi-badge[data-value="' + fixture.variantMediaTargetId + '"]',
    );
    await selectedVariant.waitFor({ state: 'visible', timeout: 10_000 });
    assert.equal(await selectedVariant.count(), 1,
      'The existing media drawer must preselect the exact clicked VariantID.');
    const selectedVariantIds = await drawer.locator('.fi-select-input-btn .fi-badge[data-value]')
      .evaluateAll(badges => badges.map(badge => badge.getAttribute('data-value')));
    assert.deepEqual(selectedVariantIds, [String(fixture.variantMediaTargetId)]);
  } finally {
    await context.close().catch(() => {});
    await browser.close();
  }
});
