import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdir, readFile } from 'node:fs/promises';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

const baseUrl = new URL(process.env.E2E_BASE_URL || 'http://127.0.0.1:18767');
assert.equal(baseUrl.hostname, '127.0.0.1', 'Browser CI must target isolated loopback only.');
assert.equal(baseUrl.protocol, 'http:', 'Browser CI must target local HTTP only.');

test('seeded administrator can open Assets and Brands in Chromium', { timeout: 90_000 }, async () => {
  const email = process.env.E2E_ADMIN_EMAIL;
  const password = process.env.E2E_ADMIN_PASSWORD;
  assert.ok(email && password, 'The isolated CI fixture credentials are required.');

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await context.tracing.start({ screenshots: true, snapshots: true, sources: true });

  let passed = false;
  try {
    const loginResponse = await page.goto(new URL('/admin/login', baseUrl).href, {
      waitUntil: 'domcontentloaded',
      timeout: 20_000,
    });
    assert.equal(loginResponse?.status(), 200, 'The local login page should load.');

    await page.locator('input[type="email"]').fill(email);
    await page.locator('input[type="password"]').fill(password);
    await page.locator('button[type="submit"]').first().click();
    await page.waitForURL(
      (url) => url.pathname.startsWith('/admin') && url.pathname !== '/admin/login',
      { timeout: 20_000 },
    );

    for (const path of ['/admin/media-assets', '/admin/brands']) {
      const response = await page.goto(new URL(path, baseUrl).href, {
        waitUntil: 'domcontentloaded',
        timeout: 20_000,
      });
      assert.equal(response?.status(), 200, `Expected successful response at ${path}.`);
      assert.equal(new URL(page.url()).pathname, path, `Expected authorized page at ${path}.`);
      await page.locator('h1').first().waitFor({ state: 'visible', timeout: 15_000 });
      assert.ok((await page.locator('h1').first().innerText()).trim().length > 0);
    }

    assert.deepEqual(errors, [], 'No uncaught browser JavaScript errors.');
    passed = true;
  } catch (error) {
    // CI-only root-cause evidence: distinguish PHP-server death/OOM from a UI defect.
    try {
      const processes = execFileSync('ps', ['-eo', 'pid,ppid,rss,stat,comm,args'], { encoding: 'utf8' });
      console.error('LOCAL_SERVER_DIAGNOSTIC_PROCESSES', processes.split('\n').filter(line => /artisan serve|php -S|PID|node --test/.test(line)).join('\n'));
      console.error('LOCAL_SERVER_DIAGNOSTIC_FREE', execFileSync('free', ['-m'], { encoding: 'utf8' }));
      console.error('LOCAL_SERVER_DIAGNOSTIC_MEMORY_EVENTS', await readFile('/sys/fs/cgroup/memory.events', 'utf8').catch(() => 'unavailable'));
      console.error('LOCAL_SERVER_DIAGNOSTIC_PHP_LOG', (await readFile('/tmp/babypark-browser-smoke-server.log', 'utf8')).slice(-2500));
    } catch (diagnosticError) {
      console.error('LOCAL_SERVER_DIAGNOSTIC_ERROR', diagnosticError.message);
    }
    throw error;
  } finally {
    const artifactDir = 'test-results/browser-smoke';
    if (!passed) {
      await mkdir(artifactDir, { recursive: true });
      await page.screenshot({ path: `${artifactDir}/failure.png`, fullPage: true }).catch(() => {});
      await context.tracing.stop({ path: `${artifactDir}/failure-trace.zip` }).catch(() => {});
    } else {
      await context.tracing.stop();
    }
    await browser.close();
  }
});
