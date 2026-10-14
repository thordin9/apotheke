import { test, expect } from './fixtures';
import type { Page } from '@playwright/test';
import * as path from 'node:path';
import { AutofillStub } from './autofill-stub';

/* AI autofill: suggestions after picking a photo on the item form, against the PHP echo stub (QA's checklist). */
const stub = new AutofillStub();
test.beforeAll(async () => { await stub.start(); });
test.afterAll(() => stub.stop());

async function configure(page: Page, o: { on: boolean; url?: string; timeout?: string; token?: string }) {
  await page.goto('/settings/autofill');
  const sw = page.locator('#s-autofill_enabled');
  if ((await sw.isChecked()) !== o.on) await page.locator('label[for="s-autofill_enabled"]').click();
  await page.locator('#s-autofill_url').fill(o.url ?? stub.base);
  await page.locator('#s-autofill_timeout').fill(o.timeout ?? '5');
  await page.locator('#set-save').click();
  await expect(page.locator('.toast')).toHaveText('Settings saved');
  if (o.token !== undefined) {
    await page.locator('#af-token-input').fill(o.token);
    await page.locator('#af-token-save').click();
    await expect(page.locator('.toast')).toHaveText('Token saved');
  }
}
const pick = (page: Page, file: string) => page.locator('#itemform .photo-lib input[type=file]').setInputFiles(file);
const shot = async (page: Page, name: string) => {
  await page.evaluate(() => document.fonts.ready);
  await expect(page).toHaveScreenshot(name + '.png', { mask: [page.locator('#preview'), page.locator('#pstatus'), page.locator('#name'), page.locator('textarea[name=notes]')] });
};

test.describe('AI autofill suggestions', () => {
  test('off: no request, no status line', async ({ app, page }) => {
    await configure(page, { on: false });
    let calls = 0;
    page.on('request', r => { if (r.url().includes('/autofill/suggest')) calls++; });
    stub.clear();
    await app.go('/new');
    await expect(page.locator('#itemform')).not.toHaveAttribute('data-autofill', '1');
    await expect(page.locator('#af-status')).toHaveCount(0);
    await pick(page, app.image('af-off.jpg', [40, 120, 200]));
    await expect(page.locator('#pstatus')).toContainText('KB');
    await page.waitForTimeout(300);
    expect(calls).toBe(0);
    expect(stub.last()).toBeNull();
    await expect(page.locator('#name')).toHaveValue('');
  });

  test('happy path: empty fields are filled, context is sent @smoke', async ({ app, page }) => {
    await configure(page, { on: true });
    stub.clear();
    await app.go('/new');
    await pick(page, app.image('af-ok.jpg', [200, 80, 40]));
    await expect(page.locator('#af-status')).toContainText('Suggested from the photo: name');
    await expect(page.locator('#name')).toHaveValue(/^Echo item [0-9a-f]{8}$/);
    await expect(page.locator('#name')).toHaveClass(/af-filled/);
    await expect(page.locator('textarea[name=notes]')).toHaveValue(/^JPEG image, \d+ bytes$/);
    await expect(page.locator('#tags')).not.toHaveValue('');
    await expect(page.locator('#tagbox .tagchip').first()).toBeVisible();
    await expect(page.locator('#tagbox')).toHaveClass(/af-filled/);
    const last = stub.last();
    expect(last.type).toBe('JPEG');
    expect(Math.max(last.width, last.height)).toBeLessThanOrEqual(1024);
    expect(last.context.locale).toBe('en');
    expect(Array.isArray(last.context.categories) && Array.isArray(last.context.tags)).toBe(true);
    const cats = app.sql<{ name: string }>('SELECT name FROM categories ORDER BY name').map(c => c.name);
    expect(last.context.categories).toEqual(cats);
    await app.noOverflow();
    await shot(page, 'autofill-suggested');
    // editing a suggestion clears the highlight; the item saves with the suggested values
    await page.locator('#name').fill('My drill');
    await expect(page.locator('#name')).not.toHaveClass(/af-filled/);
    await page.locator('#save').click();
    await page.waitForURL(/saved=\d+/);
    expect(app.sql<{ notes: string }>("SELECT notes FROM items WHERE name = 'My drill'")[0].notes).toMatch(/^JPEG image/);
  });

  test('what the user already typed is never overwritten', async ({ app, page }) => {
    await configure(page, { on: true });
    await app.go('/new');
    await page.locator('#name').fill('Grandpa’s hammer');
    await page.locator('#tag-entry').fill('heirloom');
    await page.locator('#tag-entry').press('Enter');
    await page.locator('#tagbox .tagchip-x[data-tag="heirloom"]').click();   // typed and removed: still the user's choice
    await pick(page, app.image('af-typed.jpg', [90, 160, 60]));
    await expect(page.locator('#af-status')).toContainText(/Suggested from the photo: .*description/);
    await expect(page.locator('#af-status')).not.toContainText('name');
    await expect(page.locator('#af-status')).not.toContainText('tags');
    await expect(page.locator('#name')).toHaveValue('Grandpa’s hammer');
    await expect(page.locator('#tags')).toHaveValue('');
    await expect(page.locator('textarea[name=notes]')).toHaveValue(/^JPEG image/);
  });

  test('a huge photo is sent as a ~1024 px JPEG', async ({ app, page }) => {
    await configure(page, { on: true });
    const big = path.join(app.server.filesDir, 'af-huge.png');
    app.server.php(['-r', '$im=imagecreatetruecolor(4000,3000);imagefill($im,0,0,imagecolorallocate($im,10,90,160));imagepng($im,$argv[1],1);', app.server.at(big)]);
    stub.clear();
    await app.go('/new');
    await pick(page, big);
    await expect(page.locator('#name')).toHaveValue(/^Echo item/);
    const last = stub.last();
    expect([last.type, last.width, last.height]).toEqual(['JPEG', 1024, 768]);
  });

  for (const c of [
    { name: 'service down', url: 'http://127.0.0.1:1', msg: 'not reachable' },
    { name: 'backend error (503)', url: () => stub.base + '/m/error', msg: 'busy or not ready' },
    { name: 'malformed response', url: () => stub.base + '/m/malformed', msg: 'unexpected answer' },
    { name: 'photo too large (413)', url: () => stub.base + '/m/toolarge', msg: 'larger than the service accepts' },
    { name: 'unsupported image (415)', url: () => stub.base + '/m/unsupported', msg: 'cannot read this kind of image' },
    { name: 'bad token (401)', url: () => stub.base + '/t/right-token', token: 'wrong-token', msg: 'refused the access token' },
    { name: 'timeout', url: () => stub.base + '/m/slow', timeout: '3', msg: 'did not answer within 3 seconds' },
  ]) {
    test(`fails soft: ${c.name}`, async ({ app, page }) => {
      await configure(page, { on: true, url: typeof c.url === 'function' ? c.url() : c.url, timeout: c.timeout, token: c.token });
      await app.go('/new');
      await pick(page, app.image('af-fail.jpg', [120, 120, 120]));
      await expect(page.locator('#af-status')).toContainText(c.msg, { timeout: 12_000 });
      await expect(page.locator('#af-status')).toHaveClass(/af-fail/);
      await expect(page.locator('#name')).toHaveValue('');
      if (c.name === 'service down') await shot(page, 'autofill-down');
      // the item still saves, photo included
      await page.locator('#name').fill('Saved anyway ' + c.name);
      await page.locator('#save').click();
      await page.waitForURL(/saved=\d+/);
      expect(app.sql<{ image: string }>(`SELECT image FROM items WHERE name = 'Saved anyway ${c.name}'`)[0].image).toMatch(/\.jpg$/);
    });
  }
});
