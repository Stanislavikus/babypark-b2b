import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile, mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { chromium } from 'playwright';

const baseUrl = new URL(process.env.E2E_BASE_URL ?? 'http://127.0.0.1:18767');
assert.equal(process.env.CI, 'true', 'Only isolated GitHub CI may run this Chromium test.');
assert.equal(baseUrl.href, 'http://127.0.0.1:18767/');
const root = 'test-results/browser-smoke';
const fixture = JSON.parse(await readFile(`${root}/fixture.json`, 'utf8'));
const brandPath = `/admin/brands/${fixture.pickerBrandId}/edit`;

test('Brand Media accepts real Chromium file drop near the dashed edge and native browse click', { timeout: 90_000 }, async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1280, height: 800 } });
  const page = await context.newPage();
  await context.tracing.start({ screenshots: true, snapshots: true });
  let succeeded = false;
  try {
    const login = await page.goto(new URL('/admin/login', baseUrl).href);
    assert.equal(login?.status(), 200);
    await page.locator('input[type="email"]').fill(process.env.E2E_ADMIN_EMAIL);
    await page.locator('input[type="password"]').fill(process.env.E2E_ADMIN_PASSWORD);
    await page.locator('button[type="submit"]').first().click();
    await page.waitForURL(url => url.pathname.startsWith('/admin') && url.pathname !== '/admin/login');
    await page.goto(new URL(brandPath, baseUrl).href);
    const area = page.locator('.bp-brand-media-dropzone');
    await area.waitFor({ state: 'visible' });
    await mkdir(root, { recursive: true });
    // Seed fixture is a valid actual PNG, unlike an unvalidated tiny base64 stub.
    const filePath = resolve(root, 'other.png');
    const bounds = await area.boundingBox();
    assert.ok(bounds && bounds.width > 200 && bounds.height > 180);
    const x = Math.round(bounds.x + 12);
    const y = Math.round(bounds.y + 12);
    await area.evaluate(node => {
      window.__babyparkDropEvents = [];
      for (const name of ['dragenter', 'dragover', 'drop']) {
        node.addEventListener(name, event => {
          window.__babyparkDropEvents.push({
            name,
            targetClass: event.target?.className,
            fileCount: event.dataTransfer?.files.length,
            types: Array.from(event.dataTransfer?.types ?? []),
          });
        }, true);
      }
    });
    const hit = await page.evaluate(([px, py]) => ({
      target: document.elementFromPoint(px, py)?.className,
      root: !!document.elementFromPoint(px, py)?.closest('.filepond--root'),
    }), [x, y]);
    assert.ok(hit.root, 'True dashed drop target must be the native FilePond surface: ' + JSON.stringify(hit));
    const cdp = await context.newCDPSession(page);
    const data = {
      items: [{ mimeType: 'image/png', data: '', title: 'synthetic-drop-image.png', baseURL: '' }],
      files: [filePath],
      dragOperationsMask: 1,
    };
    for (const type of ['dragEnter', 'dragOver', 'drop']) {
      await cdp.send('Input.dispatchDragEvent', { type, x, y, data });
    }
    try {
      await area.locator('.filepond--item').first().waitFor({ state: 'visible', timeout: 15_000 });
    await area.locator('.filepond--item[data-filepond-item-state="processing-complete"]').first()
      .waitFor({ state: 'visible', timeout: 20_000 });
    } catch (error) {
      const events = await page.evaluate(() => window.__babyparkDropEvents);
      console.log('NATIVE_FILEPOND_DROP_EVIDENCE', JSON.stringify({ hit, events }));
      throw error;
    }
    const processed = await area.evaluate(element => {
      const item = element.querySelector('.filepond--item[data-filepond-item-state="processing-complete"]');
      const heading = element.querySelector('.bp-brand-media-heading');
      const root = element.querySelector('.filepond--root');
      const pickButton = element.querySelector('.bp-brand-media-picker button');
      const uploadLabel = element.querySelector('.filepond--drop-label label');
      const uploadParent = uploadLabel?.closest('.filepond--drop-label');
      const uploadRect = uploadLabel?.getBoundingClientRect();
      const pickRect = pickButton?.getBoundingClientRect();
      const itemRect = item?.getBoundingClientRect();
      const rootRect = root?.getBoundingClientRect();
      const zone = element.getBoundingClientRect();
      return {
        headingDisplay: heading ? getComputedStyle(heading).display : null,
        picker: pickRect ? { x: pickRect.x, y: pickRect.y, width: pickRect.width, height: pickRect.height } : null,
        upload: uploadRect ? { x: uploadRect.x, y: uploadRect.y, width: uploadRect.width, height: uploadRect.height } : null,
        uploadOpacity: uploadParent ? getComputedStyle(uploadParent).opacity : null,
        uploadVisibility: uploadParent ? getComputedStyle(uploadParent).visibility : null,
        item: itemRect ? { x: itemRect.x, y: itemRect.y, width: itemRect.width, height: itemRect.height } : null,
        root: rootRect ? { x: rootRect.x, y: rootRect.y, width: rootRect.width, height: rootRect.height } : null,
        zone: { x: zone.x, y: zone.y, width: zone.width, height: zone.height },
      };
    });
    assert.ok(processed.item && processed.item.width >= processed.zone.width * 0.85
      && processed.item.width <= processed.zone.width && processed.item.height > 5
      && processed.headingDisplay === 'none'
      && processed.root && Math.abs(processed.root.width - processed.zone.width) <= 8
      && processed.picker && processed.upload
      && processed.uploadOpacity === '1' && processed.uploadVisibility === 'visible'
      && Math.abs(processed.upload.y - processed.picker.y) <= 15
      && processed.upload.y >= processed.item.y + processed.item.height + 5
      && (
        processed.item.x + processed.item.width + 5 <= processed.picker.x
        || processed.picker.x + processed.picker.width + 5 <= processed.item.x
        || processed.item.y + processed.item.height + 5 <= processed.picker.y
        || processed.picker.y + processed.picker.height + 5 <= processed.item.y
      ),
      'Completed FilePond item must remain visible in native full-area drop target: ' + JSON.stringify(processed));
    assert.ok(await area.getByRole('button', { name: 'Обрати з медіатеки' }).isVisible()
      && await area.getByRole('button', { name: 'Додати за URL' }).isVisible(),
      'Selecting a native file must not disable the other Media source actions.');
    const realPreview = await area.evaluate(element => {
      const completed = element.querySelector('.filepond--item[data-filepond-item-state="processing-complete"]');
      const image = completed?.querySelector('.filepond--image-preview');
      const panel = completed?.querySelector('.filepond--item-panel');
      const status = completed?.querySelector('.filepond--file-status');
      const remove = completed?.querySelector('.filepond--action-remove-item');
      const imgBox = image?.getBoundingClientRect();
      return {
        image: image ? { width: imgBox.width, height: imgBox.height, display: getComputedStyle(image).display } : null,
        panelOpacity: panel ? getComputedStyle(panel).opacity : null,
        statusOpacity: status ? getComputedStyle(status).opacity : null,
        removePresent: !!remove,
      };
    });
    assert.ok(realPreview.image && realPreview.image.width > 20 && realPreview.image.height > 20
      && realPreview.image.display !== 'none'
      && realPreview.panelOpacity === '0'
      && (!realPreview.statusOpacity || realPreview.statusOpacity === '0')
      && realPreview.removePresent,
      'Completed upload must show native image preview, not a green FilePond success slab: ' + JSON.stringify(realPreview));
    const inset = await area.evaluate(node => {
      const item = node.querySelector('.filepond--item[data-filepond-item-state="processing-complete"]');
      const a = node.getBoundingClientRect(), b = item?.getBoundingClientRect();
      return b ? { top: b.top-a.top, left: b.left-a.left, right: a.right-b.right } : null;
    });
    assert.ok(inset && inset.top >= 16 && inset.left >= 16 && inset.right >= 16
      && Math.abs(inset.left-inset.right) <= 5,
      'Selected preview requires balanced 24px insets: ' + JSON.stringify(inset));
    await area.screenshot({ path: root + '/brand-media-real-drop.png' });
    // Upload must remain a working native chooser after the file was dropped.
    const replacementChooser = page.waitForEvent('filechooser', { timeout: 10_000 });
    await area.locator('.filepond--drop-label label').click();
    await (await replacementChooser).setFiles(resolve(root, 'replacement.png'));
    assert.equal(await area.locator('.filepond--item').count(), 1,
      'A second native local file must replace, not append.');
    await area.locator('.filepond--item[data-filepond-item-state="processing-complete"]').first()
      .waitFor({ state: 'visible', timeout: 20_000 });
    await page.reload();
    const fileChooser = page.waitForEvent('filechooser', { timeout: 10_000 });
    await page.locator('.bp-brand-media-dropzone .filepond--drop-label label').click();
    const chooser = await fileChooser;
    await chooser.setFiles(filePath);
    await page.locator('.bp-brand-media-dropzone .filepond--item').first()
      .waitFor({ state: 'visible', timeout: 15_000 });
    succeeded = true;
  } finally {
    if (!succeeded) {
      await mkdir(root, { recursive: true });
      await page.screenshot({ path: root + '/brand-media-drop-failure.png', fullPage: true }).catch(() => {});
    }
    await context.tracing.stop({ path: root + '/brand-media-real-drop-trace.zip' }).catch(() => {});
    await browser.close();
  }
});

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
    const mediaSection = page.locator('.fi-section').filter({
      has: page.getByRole('heading', { name: 'Медіа', exact: true }),
    }).last();
    const unifiedDropzone = mediaSection.locator('.bp-brand-media-dropzone');
    await unifiedDropzone.getByText('Перетягніть зображення сюди', { exact: true })
      .waitFor({ state: 'visible', timeout: 20_000 });
    await unifiedDropzone.getByText('або скористайтеся одним зі способів додавання', { exact: true })
      .waitFor({ state: 'visible', timeout: 20_000 });
    await unifiedDropzone.getByText('Завантажити', { exact: true }).first()
      .waitFor({ state: 'visible', timeout: 20_000 });
    await unifiedDropzone.locator('input[type="file"]').first()
      .waitFor({ state: 'attached', timeout: 20_000 });
    assert.ok(await unifiedDropzone.locator('.filepond--root').count() > 0,
      'The canonical FilePond uploader must initialize inside the dashed Media area.');
    const selectButton = unifiedDropzone.getByRole('button', { name: 'Обрати з медіатеки' });
    await selectButton.waitFor({ state: 'visible', timeout: 20_000 });
    const geometry = await unifiedDropzone.evaluate(element => {
      const rect = node => {
        const box = node.getBoundingClientRect();
        return { top: box.top, bottom: box.bottom, left: box.left, right: box.right };
      };
      const picker = element.querySelector('.bp-brand-media-picker button');
      const upload = element.querySelector('.filepond--root');
      const panel = element.querySelector('.filepond--panel-root');
      const actions = element.querySelector('.bp-brand-media-actions');
      return {
        area: rect(element),
        picker: picker ? rect(picker) : null,
        upload: upload ? rect(upload) : null,
        actions: actions ? rect(actions) : null,
        borderStyle: getComputedStyle(element).borderTopStyle,
        innerPanelShadow: panel ? getComputedStyle(panel).boxShadow : null,
        innerPanelColor: panel ? getComputedStyle(panel).backgroundColor : null,
      };
    });
    assert.equal(geometry.borderStyle, 'dashed', 'One approved dashed Media area must contain both controls.');
    for (const name of ['picker', 'upload']) {
      assert.ok(geometry[name], `Expected ${name} in Media area.`);
      assert.ok(geometry[name].left >= geometry.area.left && geometry[name].right <= geometry.area.right,
        `${name} must be horizontally inside the dashed area: ${JSON.stringify(geometry)}`);
      assert.ok(geometry[name].top >= geometry.area.top && geometry[name].bottom <= geometry.area.bottom,
        `${name} must be vertically inside the dashed area: ${JSON.stringify(geometry)}`);
    }
    assert.ok(geometry.actions, 'The three native media actions must share a dedicated row.');
    await unifiedDropzone.getByRole('button', { name: 'Додати за URL' }).waitFor({ state: 'visible' });
    assert.ok(geometry.picker.top >= geometry.actions.top && geometry.picker.bottom <= geometry.actions.bottom,
      `Library button must remain inside the three-source actions region: ${JSON.stringify(geometry)}`);
    assert.ok(Math.abs((geometry.upload.right - geometry.upload.left) - (geometry.area.right - geometry.area.left)) < 8
      && Math.abs((geometry.upload.bottom - geometry.upload.top) - (geometry.area.bottom - geometry.area.top)) < 8,
      `Native FilePond must cover the ENTIRE advertised dashed dropzone: ${JSON.stringify(geometry)}`);
    assert.equal(geometry.innerPanelShadow, 'none', 'FilePond must not render a second framed surface.');
    // Frozen owner-approved 1440px desktop composition, not merely "buttons exist".
    const desktop = await unifiedDropzone.evaluate(element => {
      const rect = node => {
        if (!node) return null;
        const { x, y, width, height } = node.getBoundingClientRect();
        return { x, y, width, height };
      };
      return {
        area: rect(element),
        heading: rect(element.querySelector('.bp-brand-media-heading')),
        guidance: rect(element.querySelector('.bp-brand-media-subheading')),
        upload: rect(element.querySelector('.filepond--drop-label label')),
        library: rect(element.querySelector('.bp-brand-media-picker button')),
        url: rect(element.querySelector('.bp-brand-media-url-action button')),
        hint: rect(element.querySelector('.bp-brand-media-format')),
        section: rect(element.closest('.bp-brand-media-section')),
      };
    });
    assert.ok(desktop.area.width >= 680 && desktop.section.width >= 710,
      `Brand form on desktop must span the native Filament form grid, not a narrow half-column: ${JSON.stringify(desktop)}`);
    const { heading, guidance: desktopGuidance, upload: desktopUpload, library, url: desktopUrl, hint } = desktop;
    assert.ok(heading && desktopGuidance && desktopUpload && library && desktopUrl && hint,
      `Approved Media heading, guidance and three source controls must all exist: ${JSON.stringify(desktop)}`);
    assert.ok(heading.y + heading.height <= desktopGuidance.y + 2
      && desktopGuidance.y + desktopGuidance.height <= Math.min(desktopUpload.y, library.y, desktopUrl.y) + 2,
      `Approved desktop hierarchy: icon, heading, guidance, actions: ${JSON.stringify(desktop)}`);
    assert.ok(Math.abs(desktopUpload.y - library.y) <= 12 && Math.abs(library.y - desktopUrl.y) <= 12
      && desktopUpload.x + desktopUpload.width <= library.x + 4
      && library.x + library.width <= desktopUrl.x + 4
      && desktopUrl.y + desktopUrl.height <= hint.y + 2,
      `Desktop Media source actions must form ONE ordered horizontal row: ${JSON.stringify(desktop)}`);
    for (const action of [desktopUpload, library, desktopUrl]) {
      assert.ok(action.height >= 36 && action.height <= 58 && action.width >= 110,
        `Approved Media controls need consistent button-sized bounds: ${JSON.stringify(desktop)}`);
    }
    await mkdir(root, { recursive: true });
    await page.screenshot({ path: `${root}/brand-media-desktop-page.png`, fullPage: true });
    await mediaSection.screenshot({ path: `${root}/brand-media-layout.png` });
    const nativeMetrics = await unifiedDropzone.evaluate(node => {
      const upload=node.querySelector('.filepond--drop-label label');
      const uploadText=upload?.querySelector('.filepond--label-action') || upload;
      const library=node.querySelector('.bp-brand-media-picker button');
      const url=node.querySelector('.bp-brand-media-url-action button');
      const style=element => { const s=getComputedStyle(element), r=element.getBoundingClientRect();
        return { color:s.color, opacity:s.opacity, weight:s.fontWeight,
          radius:s.borderTopLeftRadius, font:s.fontSize, top:r.top }; };
      return { upload:style(upload), uploadText:style(uploadText),
        library:style(library), url:style(url) };
    });
    assert.equal(nativeMetrics.upload.opacity, '1', 'Native upload label must not fade.');
    assert.equal(nativeMetrics.uploadText.opacity, '1', 'Native upload text must not fade.');
    assert.equal(nativeMetrics.upload.radius, nativeMetrics.library.radius,
      'All Media buttons must have identical corner radius.');
    assert.equal(nativeMetrics.upload.color, nativeMetrics.library.color,
      'All Media buttons must have the same text color.');
    assert.ok(Math.abs(nativeMetrics.upload.top-nativeMetrics.library.top) <= 3
      && Math.abs(nativeMetrics.library.top-nativeMetrics.url.top) <= 3,
      'All Media buttons must align vertically: ' + JSON.stringify(nativeMetrics));
    // The URL action must be a real Filament modal, not a decorative third button.
    await unifiedDropzone.getByRole('button', { name: 'Додати за URL' }).click();
    const urlDialog = page.locator('.fi-modal.fi-modal-open').filter({
      has: page.getByRole('heading', { name: 'Додати за URL', exact: true }),
    });
    await urlDialog.getByLabel('Посилання на зображення').waitFor({ state: 'visible' });
    await page.keyboard.press('Escape');
    await page.setViewportSize({ width: 390, height: 844 });
    const mobileRects = await unifiedDropzone.evaluate(element => {
      const bounds = selector => {
        const node = element.querySelector(selector);
        if (!node) return null;
        const { x, y, width, height } = node.getBoundingClientRect();
        return { x, y, width, height };
      };
      return {
        guidance: bounds('.bp-brand-media-subheading'),
        upload: bounds('.filepond--drop-label label'),
        choose: bounds('.bp-brand-media-picker button'),
        url: bounds('.bp-brand-media-url-action button'),
        zone: bounds('.bp-brand-media-dropzone') ?? (() => {
          const { x, y, width, height } = element.getBoundingClientRect();
          return { x, y, width, height };
        })(),
      };
    });
    const { guidance, upload, choose, url, zone } = mobileRects;
    assert.ok(guidance && upload && choose && url && zone,
      `Missing real responsive actions: ${JSON.stringify(mobileRects)}`);
    assert.ok(guidance.y + guidance.height + 6 <= upload.y,
      `Mobile upload must not cover media guidance: ${JSON.stringify(mobileRects)}`);
    assert.ok(upload.y + upload.height + 6 <= choose.y
      && choose.y + choose.height + 6 <= url.y,
      `Three Media buttons must not overlap vertically: ${JSON.stringify(mobileRects)}`);
    for (const action of [upload, choose, url]) {
      assert.ok(Math.abs(action.x + action.width / 2 - zone.x - zone.width / 2) <= 20,
        `Responsive Media actions must be centered: ${JSON.stringify(mobileRects)}`);
    }
    assert.ok(Math.max(upload.width, choose.width, url.width) - Math.min(upload.width, choose.width, url.width) <= 3
      && Math.max(upload.height, choose.height, url.height) - Math.min(upload.height, choose.height, url.height) <= 3,
      `Mobile Media buttons must share exactly one width and height: ${JSON.stringify(mobileRects)}`);
    await page.evaluate(() => document.activeElement?.blur());
    await mediaSection.screenshot({ path: `${root}/brand-media-mobile.png` });
    const mobileGeometry = await unifiedDropzone.evaluate(element => ({
      scrollWidth: element.scrollWidth,
      clientWidth: element.clientWidth,
      viewport: document.documentElement.clientWidth,
      documentWidth: document.documentElement.scrollWidth,
    }));
    assert.ok(mobileGeometry.scrollWidth <= mobileGeometry.clientWidth + 2,
      `Responsive Media block must not overflow its own box: ${JSON.stringify(mobileGeometry)}`);
    await page.setViewportSize({ width: 1440, height: 900 });
    await selectButton.click();
    const modal = page.locator('.fi-modal.fi-modal-open').filter({ hasText: 'Медіатека' });
    await modal.getByRole('heading', { name: 'Медіатека', exact: true }).waitFor({ state: 'visible', timeout: 20_000 });
    const cardGrid = modal.locator('.fi-ta-content-grid');
    await cardGrid.waitFor({ state: 'visible', timeout: 20_000 });
    const modalWindow = modal.locator('.fi-modal-window').first();
    await modalWindow.evaluate(element => Promise.all(
      element.getAnimations({ subtree: true })
        .filter(animation => animation.playState === 'running'
          && animation.effect?.getTiming().iterations !== Infinity)
        .map(animation => animation.finished.catch(() => {})),
    ));
    assert.ok(await cardGrid.locator('.fi-ta-record').count() >= 2,
      'Picker must reuse canonical Media library card grid, not an ad hoc row table.');
    await modalWindow.screenshot({ path: `${root}/brand-media-picker-layout.png` });
    const searchInput = modalWindow.locator('.fi-ta-search-field input[type="search"]');
    await searchInput.waitFor({ state: 'visible', timeout: 20_000 });
    const filtersButton = modalWindow.getByRole('button', { name: /^Фільтри/ }).first();
    await filtersButton.waitFor({ state: 'visible', timeout: 20_000 });
    const searchBox = await searchInput.boundingBox();
    const buttonBox = await filtersButton.boundingBox();
    assert.ok(searchBox && buttonBox && buttonBox.x >= searchBox.x + searchBox.width - 2
      && Math.abs(buttonBox.y - searchBox.y) < 45,
      `Filter button must stay next to search in the same toolbar: ${JSON.stringify({ searchBox, buttonBox })}`);
    const unfilteredMainWidth = await modalWindow.locator('.fi-ta-main').first()
      .evaluate(element => element.getBoundingClientRect().width);
    await filtersButton.click();
    const filtersPanel = modalWindow.locator('.fi-ta-filters-after-content-ctn');
    await filtersPanel.getByText('Зберігання', { exact: true }).waitFor({ state: 'visible', timeout: 20_000 });
    await filtersPanel.getByText('Використання', { exact: true }).waitFor({ state: 'visible' });
    await filtersPanel.getByText('Потребує уваги', { exact: true }).waitFor({ state: 'visible' });
    await filtersPanel.getByLabel('Відображення').waitFor({ state: 'visible' });
    const panelScroll = await filtersPanel.evaluate(element => ({
      client: element.clientHeight,
      content: element.scrollHeight,
    }));
    assert.ok(panelScroll.content <= panelScroll.client + 3,
      'Desktop picker filters should display their full form instead of clipping controls: ' + JSON.stringify(panelScroll));
    assert.equal(await page.locator('.fi-modal.fi-modal-open').count(), 1,
      'Opening picker filters must not create a second modal over the workspace.');
    const modalBox = await modalWindow.boundingBox();
    const filtersBox = await filtersPanel.boundingBox();
    const mainBox = await modalWindow.locator('.fi-ta-main').first().boundingBox();
    assert.ok(modalBox && filtersBox && mainBox
      && Math.abs(mainBox.width - unfilteredMainWidth) <= 2
      && filtersBox.x > mainBox.x + mainBox.width * 0.45
      && filtersBox.x < mainBox.x + mainBox.width - 20
      && filtersBox.x >= modalBox.x - 2 && filtersBox.x + filtersBox.width <= modalBox.x + modalBox.width + 2,
      `Native filters must overlay the right of Media grid WITHOUT shrinking cards: ${JSON.stringify({ modalBox, filtersBox, mainBox, unfilteredMainWidth })}`);
    assert.ok(filtersBox.width <= 280,
      `The sidebar must stay compact instead of consuming half of the Media picker: ${JSON.stringify(filtersBox)}`);
    const visibleCards = await cardGrid.locator('.fi-ta-record').evaluateAll(nodes =>
      nodes.map(node => {
        const bounds = node.getBoundingClientRect();
        return { x: bounds.x, right: bounds.right, width: bounds.width };
      }));
    for (const cardBounds of visibleCards) {
      assert.ok(cardBounds.width >= 275 && cardBounds.right <= mainBox.x + mainBox.width + 2,
        `Media card must remain readable with the right filter panel open: ${JSON.stringify({ mainBox, cardBounds })}`);
    }
    await modalWindow.screenshot({ path: `${root}/brand-media-picker-filters.png` });
    await filtersPanel.getByLabel('Відображення').selectOption('list');
    await cardGrid.waitFor({ state: 'hidden', timeout: 20_000 });
    await modalWindow.screenshot({ path: `${root}/brand-media-picker-list-layout.png` });
    await filtersPanel.getByLabel('Відображення').selectOption('');
    await cardGrid.waitFor({ state: 'visible', timeout: 20_000 });
    await filtersButton.click();
    await filtersPanel.waitFor({ state: 'hidden', timeout: 20_000 });
    const cardBounds = await cardGrid.locator('.fi-ta-record').evaluateAll(nodes =>
      nodes.slice(0, 2).map(node => {
        const { left, right, top, bottom } = node.getBoundingClientRect();
        return { left, right, top, bottom };
      }));
    const pickerBounds = await modalWindow.evaluate(node => {
      const { left, right } = node.getBoundingClientRect();
      return { left, right };
    });
    assert.ok(Math.abs(cardBounds[0].top - cardBounds[1].top) < 15,
      `At desktop size picker must show two cards per row: ${JSON.stringify(cardBounds)}`);
    for (const cardBox of cardBounds) {
      assert.ok(cardBox.left >= pickerBounds.left && cardBox.right <= pickerBounds.right,
        `Card overflow causes the horizontal scrollbar rejected by owner: ${JSON.stringify({ pickerBounds, cardBounds })}`);
    }
    // Official Filament single-select semantics: selecting a second asset replaces
    // the first and never permits two checked records or a late validation error.
    const firstCard = cardGrid.locator('.fi-ta-record').filter({ hasText: 'shared.png' }).first();
    const firstChoice = firstCard.locator('input[type="checkbox"]').first();
    await firstChoice.check();
    assert.equal(await firstChoice.isChecked(), true);
    const secondCard = cardGrid.locator('.fi-ta-record').filter({ hasText: 'other.png' }).first();
    await secondCard.locator('input[type="checkbox"]').first().check();
    assert.equal(await firstChoice.isChecked(), false,
      'Brand single image mode must automatically replace an earlier selection.');
    assert.equal(await cardGrid.locator('input[type="checkbox"]:checked').count(), 1,
      'At most one Brand image may be selected in the native picker.');
    assert.ok(await secondCard.evaluate(element => element.classList.contains('fi-selected')),
      'Selected image card should have the native highlighted background.');
    await modalWindow.screenshot({ path: `${root}/brand-media-picker-selected.png` });
    const card = cardGrid.locator('.fi-ta-record').filter({ hasText: 'other.png' }).first();
    await card.waitFor({ state: 'visible', timeout: 20_000 });
    assert.ok(await card.locator('img').count(), 'Shared Media card must contain original preview.');
    await card.locator('input[type="checkbox"]').first().check();
    await modal.getByRole('button', { name: 'Підтвердити вибір', exact: true }).click();
    await modal.getByRole('heading', { name: 'Медіатека', exact: true }).waitFor({ state: 'hidden', timeout: 20_000 });
    await page.getByText('other.png', { exact: false }).first().waitFor({ state: 'visible' });
    const pending = unifiedDropzone.locator('.bp-brand-media-candidate');
    await pending.locator('img').waitFor({ state: 'visible', timeout: 20_000 });
    const placement = await unifiedDropzone.evaluate(node => {
      const zone=node.getBoundingClientRect();
      const image=node.querySelector('.bp-brand-media-candidate img')?.getBoundingClientRect();
      const upload=node.querySelector('.filepond--drop-label label')?.getBoundingClientRect();
      const library=node.querySelector('.bp-brand-media-picker button')?.getBoundingClientRect();
      return { zone: zone.width, image: image?.width, uploadY: upload?.y, libraryY: library?.y };
    });
    assert.ok(placement.image >= placement.zone*.75
      && Math.abs(placement.uploadY-placement.libraryY) <= 12,
      'Library preview and native upload must coexist: ' + JSON.stringify(placement));
    await mediaSection.screenshot({ path: `${root}/brand-media-library-preview.png` });
    await unifiedDropzone.getByRole('button', { name: 'Додати за URL' }).click();
    await page.locator('.fi-modal.fi-modal-open').filter({
      has: page.getByRole('heading', { name: 'Додати за URL', exact: true }),
    }).getByLabel('Посилання на зображення').waitFor({ state: 'visible' });
    await page.keyboard.press('Escape');
    await pending.locator('img').waitFor({ state: 'visible' });
    // Replacing a pending library Original with a local upload must not require X.
    const localChooser = page.waitForEvent('filechooser', { timeout: 10_000 });
    await unifiedDropzone.locator('.filepond--drop-label label').click();
    await (await localChooser).setFiles(resolve(root, 'replacement.png'));
    await unifiedDropzone.locator('.filepond--item[data-filepond-item-state="processing-complete"]')
      .first().waitFor({ state: 'visible', timeout: 20_000 });
    await pending.waitFor({ state: 'hidden', timeout: 15_000 });
    // Replacing the pending local file with a library Original must also work.
    await selectButton.click();
    const secondPicker = page.locator('.fi-modal.fi-modal-open').filter({ hasText: 'Медіатека' });
    await secondPicker.getByRole('heading', { name: 'Медіатека', exact: true }).waitFor({ state: 'visible' });
    await secondPicker.locator('.fi-ta-record').filter({ hasText: 'other.png' })
      .first().locator('input[type="checkbox"]').first().check();
    await secondPicker.getByRole('button', { name: 'Підтвердити вибір', exact: true }).click();
    await secondPicker.getByRole('heading', { name: 'Медіатека', exact: true })
      .waitFor({ state: 'hidden', timeout: 20_000 });
    await pending.locator('img').waitFor({ state: 'visible', timeout: 20_000 });
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
    // A pending local upload removed with X is NOT an explicit Brand logo deletion.
    const discardChooser = page.waitForEvent('filechooser', { timeout: 10_000 });
    await page.locator('.bp-brand-media-dropzone .filepond--drop-label label').click();
    await (await discardChooser).setFiles(resolve(root, 'replacement.png'));
    const uploadedItem = page.locator('.bp-brand-media-dropzone .filepond--item');
    await uploadedItem.first().waitFor({ state: 'visible', timeout: 20_000 });
    await uploadedItem.first().locator('.filepond--action-remove-item').click();
    await uploadedItem.first().waitFor({ state: 'hidden', timeout: 15_000 });
    await page.getByRole('button', { name: 'Зберегти', exact: true }).first().click();
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
