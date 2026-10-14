import { test, expect } from './fixtures';
import type { Page } from '@playwright/test';
import { AutofillStub } from './autofill-stub';

/* AI autofill: Control Panel section, write-only token and "Test connection" against the PHP echo stub. */
const stub = new AutofillStub();
test.beforeAll(async () => { await stub.start(); });
test.afterAll(() => stub.stop());

const shot = async (page: Page, name: string) => {
  if (test.info().project.name.endsWith('-smoke')) return;   // smoke projects (Docker, PostgreSQL) have no baselines
  await page.evaluate(() => document.fonts.ready);
  await expect(page).toHaveScreenshot(name + '.png', { fullPage: true, mask: [page.locator('.app-version'), page.locator('#s-autofill_url')] });
};
async function configure(page: Page, url: string, timeout = '5') {
  await page.goto('/settings/autofill');
  const sw = page.locator('#s-autofill_enabled');
  if (!(await sw.isChecked())) await page.locator('label[for="s-autofill_enabled"]').click();
  await page.locator('#s-autofill_url').fill(url);
  await page.locator('#s-autofill_timeout').fill(timeout);
}

test.describe('AI autofill settings', () => {
  test('off by default; section in the Control Panel @smoke', async ({ app, page }) => {
    await app.go('/settings');
    await expect(page.locator('a[href="/settings/autofill"]')).toBeVisible();
    await app.go('/settings/autofill');
    await expect(page.locator('#s-autofill_enabled')).not.toBeChecked();
    await expect(page.locator('#s-autofill_url')).toHaveValue('');
    await expect(page.locator('#af-token-state')).toHaveText('No token set.');
    await app.tapTargets();
    await app.noOverflow();
    await shot(page, 'autofill-settings-default');
  });

  test('save, test connection ok, and the result is shown', async ({ app, page }) => {
    await configure(page, stub.base);
    await page.locator('#set-save').click();
    await expect(page.locator('.toast')).toHaveText('Settings saved');
    await expect(page.locator('#s-autofill_url')).toHaveValue(stub.base);
    await page.locator('#af-test').click();
    await expect(page.locator('#af-res')).toHaveAttribute('data-code', 'ok');
    await expect(page.locator('#af-name')).toHaveText('echo');
    await expect(page.locator('#af-caps')).toHaveText('name, category, tags, description');
    await expect(page.locator('#af-res .af-info')).toContainText('10 MiB');
    await expect(page.locator('#s-autofill_url')).toHaveAttribute('placeholder', 'http://192.168.1.10:8080');
    const saveBox = await page.locator('#set-save').boundingBox();
    const resBox = await page.locator('#af-res').boundingBox();
    expect(saveBox!.y, 'Save sits above the test result').toBeLessThan(resBox!.y);
    await app.noOverflow();
    await page.locator('.toast').evaluateAll(t => t.forEach(e => e.remove()));
    await shot(page, 'autofill-test-ok');
  });

  test('token is write-only; a wrong or missing token is reported', async ({ app, page }) => {
    await configure(page, stub.base + '/t/s3cret-token');
    await page.locator('#set-save').click();
    await expect(page.locator('.toast')).toHaveText('Settings saved');
    await page.locator('#af-test').click();
    await expect(page.locator('#af-res')).toHaveAttribute('data-code', 'unauthorized');
    await expect(page.locator('#af-res')).toContainText('requires a token');
    await page.locator('#af-token-input').fill('wrong-token');
    await page.locator('#af-token-save').click();
    await expect(page.locator('.toast')).toHaveText('Token saved');
    await page.locator('#af-test').click();
    await expect(page.locator('#af-res')).toHaveAttribute('data-code', 'unauthorized');
    await expect(page.locator('#af-res')).toContainText('refused the token');
    await page.locator('.toast').evaluateAll(t => t.forEach(e => e.remove()));
    await shot(page, 'autofill-test-unauthorized');
    await page.locator('#af-token-input').fill('s3cret-token');
    await page.locator('#af-token-save').click();
    await expect(page.locator('#af-token-state')).toContainText('A token is set.');
    await expect(page.locator('#af-token-input')).toHaveValue('');
    // (the stub reads the token it expects from its own address, so drop that before looking for the token)
    const strip = (h: string) => h.split(stub.base + '/t/s3cret-token').join('');
    expect(strip(await page.content()), 'token never rendered').not.toContain('s3cret-token');
    expect(strip(await (await page.request.get('/settings/autofill')).text())).not.toContain('s3cret-token');
    expect(await (await page.request.get('/settings/export.json')).text()).not.toContain('autofill_token');
    await page.locator('#af-test').click();
    await expect(page.locator('#af-res')).toHaveAttribute('data-code', 'ok');
    page.once('dialog', d => d.accept());
    await page.locator('#af-token-clear').click();
    await expect(page.locator('.toast')).toHaveText('Token cleared');
    await expect(page.locator('#af-token-state')).toHaveText('No token set.');
  });

  test('changing the saved address clears the token and says so', async ({ page }) => {
    await configure(page, stub.base);
    await page.locator('#set-save').click();
    await expect(page.locator('.toast')).toHaveText('Settings saved');
    await page.locator('#af-token-input').fill('moving-token');
    await page.locator('#af-token-save').click();
    await expect(page.locator('#af-token-state')).toContainText('A token is set.');
    await configure(page, stub.base.replace('127.0.0.1', 'localhost'));
    await page.locator('#set-save').click();
    await expect(page.locator('.toast')).toHaveText('Settings saved. The token was cleared because the service address changed: enter it again.');
    await expect(page.locator('#af-token-state')).toHaveText('No token set.');
    await page.goto('/settings/autofill');
    await expect(page.locator('#af-token-state')).toHaveText('No token set.');
  });

  test('service down, timeout and not an autofill service', async ({ page }) => {
    await configure(page, 'http://127.0.0.1:1');
    await page.locator('#af-test').click();
    await expect(page.locator('#af-res')).toHaveAttribute('data-code', 'unreachable');
    await shot(page, 'autofill-test-down');
    await configure(page, 'http://10.255.255.1:8080', '10');
    await page.locator('#af-test').click();
    await expect(page.locator('#af-res')).toHaveAttribute('data-code', 'unreachable', { timeout: 8_000 });
    await configure(page, 'gopher://127.0.0.1:70/x');
    await page.locator('#af-test').click();
    await expect(page.locator('#af-res')).toHaveAttribute('data-code', 'invalid_url');
    await expect(page.locator('#af-res')).toContainText('Only http:// and https://');
    await configure(page, stub.base + '/m/slow', '3');
    await page.locator('#af-test').click();
    await expect(page.locator('#af-res')).toHaveAttribute('data-code', 'timeout', { timeout: 10_000 });
    await configure(page, stub.base + '/m/badinfo');
    await page.locator('#af-test').click();
    await expect(page.locator('#af-res')).toHaveAttribute('data-code', 'bad_response');
    // testing never saves
    await page.goto('/settings/autofill');
    await expect(page.locator('#s-autofill_url')).toHaveValue('');
  });
});
