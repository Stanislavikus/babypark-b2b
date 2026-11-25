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

    const assetSource = page.locator('.bp-asset-replacement-source.bp-brand-media-dropzone');
    await assetSource.getByText('Перетягніть зображення сюди', { exact: true })
      .waitFor({ state: 'visible', timeout: 20_000 });
    await assetSource.locator('.filepond--root').waitFor({ state: 'attached', timeout: 20_000 });
    const assetArea = await assetSource.boundingBox();
    assert.ok(assetArea && assetArea.width >= 700,
      'Asset editor source area must span its desktop Filament form, not one half-width column.');
    assert.equal(await assetSource.getByRole('button', { name: 'Завантажити' }).count(), 1,
      'Original replacement must retain the native FilePond action.');
    assert.equal(await assetSource.getByRole('button', { name: 'Додати за URL' }).count(), 1,
      'Asset replacement must share the real native URL source button with Brand and Product.');
    assert.equal(await assetSource.getByRole('button', { name: 'Обрати з медіатеки' }).count(), 0,
      'Replacing canonical Asset bytes must never masquerade as rebinding another Asset.');

    // The URL button stages a URL only; an explicit Save is the sole mutation.
    await assetSource.getByRole('button', { name: 'Додати за URL' }).click();
    const urlDialog = page.locator('.fi-modal.fi-modal-open').filter({
      has: page.getByRole('heading', { name: 'Обрати URL нового зображення', exact: true }),
    });
    await urlDialog.getByLabel('Посилання на зображення').fill('https://cdn.example.net/replacement.png');
    await urlDialog.getByRole('button', { name: 'Обрати URL', exact: true }).click();
    const stagedUrl = assetSource.getByLabel('URL нового зображення (після «Зберегти»)');
    await stagedUrl.waitFor({ state: 'visible', timeout: 20_000 });
    assert.equal(await stagedUrl.inputValue(), 'https://cdn.example.net/replacement.png');
    await stagedUrl.fill('');
    await stagedUrl.waitFor({ state: 'hidden', timeout: 20_000 });
    // Compare the two real source buttons, not just presence of a common CSS class.
    const desktopActions = await assetSource.evaluate(element => {
      const rect = selector => {
        const node = element.querySelector(selector);
        if (!node) return null;
        const { left, right, top, bottom, height } = node.getBoundingClientRect();
        return { left, right, top, bottom, height };
      };
      return {
        upload: rect('.bp-brand-media-browse-button'),
        url: rect('.bp-brand-media-url-action button'),
      };
    });
    assert.ok(desktopActions.upload && desktopActions.url
      && desktopActions.upload.right <= desktopActions.url.left + 4
      && Math.abs(desktopActions.upload.top - desktopActions.url.top) <= 5
      && Math.abs(desktopActions.upload.height - desktopActions.url.height) <= 3,
    'Asset desktop Upload/URL must share the approved Brand source-action row: '
      + JSON.stringify(desktopActions));
    await mkdir(root, { recursive: true });
    await assetSource.screenshot({ path: `${root}/asset-replacement-desktop.png` });
    await page.setViewportSize({ width: 390, height: 844 });
    const mobileActions = await assetSource.evaluate(element => {
      const bounds = selector => {
        const { left, right, top, bottom } = element.querySelector(selector).getBoundingClientRect();
        return { left, right, top, bottom };
      };
      return {
        upload: bounds('.bp-brand-media-browse-button'),
        url: bounds('.bp-brand-media-url-action button'),
      };
    });
    assert.ok(mobileActions.upload.bottom + 2 <= mobileActions.url.top,
      'Asset mobile Upload/URL must stack without overlapping: ' + JSON.stringify(mobileActions));
    await assetSource.screenshot({ path: `${root}/asset-replacement-mobile.png` });

    const currentLayout = page.locator('.bp-asset-media-current-layout');
    await currentLayout.waitFor({ state: 'visible', timeout: 20_000 });
    for (const width of [360, 390, 430, 1440]) {
      await page.setViewportSize({ width, height: width < 768 ? 844 : 960 });
      const currentGeometry = await currentLayout.evaluate(element => {
        const bounds = node => {
          const rect = node.getBoundingClientRect();
          return { left: rect.left, right: rect.right, top: rect.top, width: rect.width, height: rect.height };
        };
        const imageWrap = element.querySelector('.bp-asset-media-current-image');
        const imageFrame = imageWrap?.querySelector('.bp-media-preview-frame');
        const metadata = element.querySelector('.bp-brand-media-current-metadata');
        const rows = [...(metadata?.querySelectorAll('.fi-in-entry') ?? [])].map(row => ({
          display: getComputedStyle(row).display,
          alignment: getComputedStyle(row.querySelector('.fi-in-entry-content')).textAlign,
          label: bounds(row.querySelector('.fi-in-entry-label-col')),
          value: bounds(row.querySelector('.fi-in-entry-content-col')),
        }));
        const parent = element.parentElement;
        const parentRect = parent.getBoundingClientRect();
        const layoutRect = element.getBoundingClientRect();
        const imageRect = imageWrap.getBoundingClientRect();
        const frameRect = imageFrame.getBoundingClientRect();

        return {
          width: element.clientWidth,
          scrollWidth: element.scrollWidth,
          parentCenterDelta: Math.abs((layoutRect.left + layoutRect.width / 2) - (parentRect.left + parentRect.width / 2)),
          imageCenterDelta: Math.abs((imageRect.left + imageRect.width / 2) - (frameRect.left + frameRect.width / 2)),
          metadata: bounds(metadata),
          metadataRowGap: getComputedStyle(metadata.querySelector('.fi-grid')).rowGap,
          rows,
        };
      });

      assert.ok(currentGeometry.scrollWidth <= currentGeometry.width + 1,
        `Media Asset current-image block must not overflow at ${width}px: ${JSON.stringify(currentGeometry)}`);
      assert.ok(currentGeometry.parentCenterDelta <= 1,
        `Media Asset current-image container must be centered at ${width}px: ${JSON.stringify(currentGeometry)}`);
      assert.ok(currentGeometry.imageCenterDelta <= 1,
        `Media Asset image must be centered in the Brand preview frame at ${width}px.`);
      if (width <= 430) {
        assert.equal(currentGeometry.metadataRowGap, '12px');
        assert.ok(currentGeometry.rows.length >= 4);
        assert.ok(currentGeometry.rows.every(row => row.display === 'grid' && row.alignment === 'right'
          && row.label.left < row.value.left && row.value.right <= currentGeometry.metadata.right + 1),
        `Media Asset metadata must use the compact Brand label/value rows at ${width}px: ${JSON.stringify(currentGeometry.rows)}`);
      }
      await currentLayout.screenshot({ path: `${root}/asset-current-${width}.png` });
    }
    await page.setViewportSize({ width: 1440, height: 960 });

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

    // A different existing Asset must fail closed, with concise merchant-facing wording.
    await page.reload({ waitUntil: 'domcontentloaded' });
    await uploadViaInitializedFilePond(page, 'other.png');
    await page.getByRole('button', { name: 'Зберегти', exact: true }).first().click();
    await modalHeading.waitFor({ state: 'visible', timeout: 20_000 });
    await dialog.getByRole('button', { name: 'Замінити', exact: true }).click();
    await page.getByText('Це зображення вже є в медіатеці. Оберіть інший файл.', { exact: true })
      .waitFor({ state: 'visible', timeout: 20_000 });
    await page.getByText('replacement.png', { exact: false }).first().waitFor({ state: 'visible' });

    const brandResponse = await page.goto(brandUrl, { waitUntil: 'domcontentloaded' });
    assert.equal(brandResponse?.status(), 200, 'Brand edit screen must open.');
    await page.getByText('other.png', { exact: false }).first().waitFor({ state: 'visible' });

    await uploadViaInitializedFilePond(page, 'replacement.png');
    await page.getByRole('button', { name: 'Зберегти', exact: true }).first().click();
    await page.getByText('Зображення вже є в медіатеці. Використано файл з медіатеки.', { exact: true })
      .waitFor({ state: 'visible', timeout: 20_000 });
    await page.getByText('replacement.png', { exact: false }).first()
      .waitFor({ state: 'visible', timeout: 20_000 });

    // A separate upload after navigation must not inherit FilePond's previously processed item.
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.getByText('replacement.png', { exact: false }).first().waitFor({ state: 'visible' });
    const brandCurrent = page.locator('.bp-brand-media-current-layout');
    await brandCurrent.waitFor({ state: 'visible', timeout: 20_000 });
    for (const width of [360, 390, 430, 1440]) {
      await page.setViewportSize({ width, height: width < 768 ? 844 : 960 });
      const brandGeometry = await brandCurrent.evaluate(element => {
        const metadata = element.querySelector('.bp-brand-media-current-metadata');
        const rows = [...metadata.querySelectorAll('.fi-in-entry')].map(row => ({
          display: getComputedStyle(row).display,
          alignment: getComputedStyle(row.querySelector('.fi-in-entry-content')).textAlign,
        }));
        return {
          width: element.clientWidth,
          scrollWidth: element.scrollWidth,
          rows,
        };
      });
      assert.ok(brandGeometry.scrollWidth <= brandGeometry.width + 1,
        `The approved Brand media block must remain overflow-free at ${width}px.`);
      if (width <= 430) {
        assert.ok(brandGeometry.rows.every(row => row.display === 'grid' && row.alignment === 'right'),
          `The approved Brand compact metadata layout must remain intact at ${width}px.`);
      }
      await brandCurrent.screenshot({ path: `${root}/brand-current-${width}.png` });
    }
    await page.setViewportSize({ width: 1440, height: 960 });
    await uploadViaInitializedFilePond(page, 'replacement.png');
    await page.getByRole('button', { name: 'Зберегти', exact: true }).first().click();
    await page.getByText('Зображення вже актуальне. Інших змін немає.', { exact: true })
      .waitFor({ state: 'visible', timeout: 20_000 });

    // Saved Brand must close without a second duplicate notice or an unsaved-data prompt.
    await page.getByRole('button', { name: 'Закрити', exact: true }).first().click();
    await page.waitForURL((url) => url.pathname === '/admin/brands', { timeout: 20_000 });
    assert.equal(await page.getByText('Закрити без збереження?', { exact: true }).isVisible(), false,
      'Brand Close after Save must not request unsaved-change confirmation.');
    assert.equal(await page.getByText('Це зображення вже є в медіатеці. Оберіть інший файл.', { exact: true }).isVisible(), false,
      'Brand Close must not show an unrelated Asset replace warning.');

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
