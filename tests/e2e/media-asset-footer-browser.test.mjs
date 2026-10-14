import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile, mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';

assert.equal(process.env.CI, 'true', 'Assets visual verification is limited to isolated GitHub CI.');
const baseUrl = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:18767';
assert.equal(new URL(baseUrl).origin, 'http://127.0.0.1:18767');
const root = 'test-results/browser-smoke';
const fixture = JSON.parse(await readFile(`${root}/fixture.json`, 'utf8'));

test('Asset View and Edit keep compact bottom metadata on mobile and 3-column desktop', { timeout: 100_000 }, async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
    const login = await page.goto(new URL('/admin/login', baseUrl).href);
    assert.equal(login?.status(), 200);
    await page.locator('input[type="email"]').fill(process.env.E2E_ADMIN_EMAIL);
    await page.locator('input[type="password"]').fill(process.env.E2E_ADMIN_PASSWORD);
    await page.locator('button[type="submit"]').first().click();
    await page.waitForURL(url => url.pathname.startsWith('/admin') && url.pathname !== '/admin/login');
    await mkdir(root, { recursive: true });

    const collect = async () => page.locator('.bp-media-asset-footer-metadata').first().evaluate(container => {
      const rect = container.getBoundingClientRect();
      const styles = getComputedStyle(container);
      const rows = [...container.querySelectorAll('.fi-in-entry')].map(el => {
        const l = el.querySelector('.fi-in-entry-label-col')?.getBoundingClientRect();
        const v = el.querySelector('.fi-in-entry-content-col')?.getBoundingClientRect();
        return l && v ? {
          label: {x:l.x,y:l.y,right:l.right,bottom:l.bottom},
          value: {x:v.x,y:v.y,right:v.right,bottom:v.bottom},
          text: el.textContent?.trim().slice(0,100),
        } : null;
      });
      return {rect:{x:rect.x,right:rect.right,width:rect.width},
        inset:{left:parseFloat(styles.paddingLeft),right:parseFloat(styles.paddingRight)},
        rows};
    });

    for (const mode of ['view', 'edit']) {
      const url = new URL(`/admin/media-assets/${fixture.sharedAssetId}${mode==='edit'?'/edit':''}`, baseUrl);
      const response = await page.goto(url.href);
      assert.equal(response?.status(), 200, `Asset ${mode} must respond 200`);
      await page.locator('.bp-media-asset-footer-metadata').first().waitFor({state:'visible'});
      const mobile = await collect();
      assert.ok(mobile.rows.length === 3
        && mobile.rows.every((r,i) => r && r.label.x >= mobile.rect.x + 7
          && r.value.right <= mobile.rect.right - 7
          && Math.abs(r.label.y-r.value.y) <= 8
          && r.label.right < r.value.x
          && (i === 0 || r.label.y-mobile.rows[i-1].label.y < 65))
        && mobile.inset.left >= 7 && mobile.inset.right >= 7,
        `Asset ${mode} must have three compact mobile left/right metadata rows: ${JSON.stringify(mobile)}`);
      await page.screenshot({path:`${root}/asset-footer-${mode}-mobile.png`,fullPage:true});

      await page.setViewportSize({width:1440,height:900});
      await page.reload();
      await page.locator('.bp-media-asset-footer-metadata').first().waitFor({state:'visible'});
      const desktop = await collect();
      assert.ok(desktop.rows.length === 3
        && desktop.rows.every(r=>r)
        && desktop.rows[0].label.x < desktop.rows[1].label.x
        && desktop.rows[1].label.x < desktop.rows[2].label.x
        && Math.abs(desktop.rows[0].label.y-desktop.rows[1].label.y) <= 12
        && Math.abs(desktop.rows[1].label.y-desktop.rows[2].label.y) <= 12,
        `Asset ${mode} must retain native 3-column desktop metadata: ${JSON.stringify(desktop)}`);
      await page.screenshot({path:`${root}/asset-footer-${mode}-desktop.png`,fullPage:true});
      await page.setViewportSize({width:390,height:844});
    }
  } finally {
    await browser.close();
  }
});
