import { test, expect } from './fixtures';
import type { Page } from '@playwright/test';
import * as path from 'node:path';
import { AutofillStub } from './autofill-stub';
import { ROOT } from './fixtures';

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
  if (test.info().project.name.endsWith('-smoke')) return;   // smoke projects (Docker, PostgreSQL) have no baselines
  await page.evaluate(() => document.fonts.ready);
  await page.evaluate(() => window.scrollTo(0, 0));   // the name field takes focus after a photo; screenshot from the top
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
    await expect(page.locator('#af-progress')).toHaveCount(0);
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
    await expect(page.locator('#af-status')).toContainText('Suggestions ready: name');
    await expect(page.locator('#af-progress')).toBeHidden();
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
    await expect(page.locator('#af-status')).toContainText(/Suggestions ready: .*description/);
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

  test('progress: uploading, then an estimate from avg_latency_ms, held below 100 % until the answer', async ({ app, page }) => {
    // the service advertises 4 s per photo; this one takes 6 s. Test connection caches /v1/info (and so the 4 s).
    await configure(page, { on: true, url: stub.base + '/l/4000/d/6000', timeout: '20' });
    await page.locator('#af-test').click();
    await expect(page.locator('#af-res')).toHaveAttribute('data-code', 'ok');
    await page.clock.install({ time: new Date('2026-06-15T09:00:00Z') });
    await app.go('/new');
    await expect(page.locator('#itemform')).toHaveAttribute('data-af-latency', '4000');
    await page.clock.pauseAt(new Date('2026-06-15T12:00:00Z'));
    await pick(page, app.image('af-progress.jpg', [60, 130, 210]));
    const bar = page.locator('#af-progress');
    await expect(bar, 'feedback within a second').toBeVisible({ timeout: 1_000 });
    await expect(page.locator('#af-status')).toHaveText('Analyzing photo…', { timeout: 5_000 });
    await expect(bar).toHaveAttribute('role', 'progressbar');
    await expect(bar).toHaveAttribute('aria-valuenow', '0');
    await expect(page.locator('#af-pct')).toHaveText('0 % · about 4 s left');
    await page.clock.runFor(2_000);                     // half the expected time: half way, and the seconds agree
    await expect(bar).toHaveAttribute('aria-valuenow', '50');
    await expect(page.locator('#af-pct')).toHaveText('50 % · about 2 s left');
    await expect(bar).toHaveAttribute('aria-valuetext', '50 % · about 2 s left');
    await expect(page.locator('#name'), 'the form stays usable').toBeEditable();
    await app.noOverflow();
    await shot(page, 'autofill-progress');
    await page.clock.runFor(1_000);
    await expect(page.locator('#af-pct')).toHaveText('75 % · almost done');
    await page.clock.runFor(2_000);                     // over the estimate: held at 95 %
    await expect(bar).toHaveAttribute('aria-valuenow', '95');
    await expect(page.locator('#af-pct')).toHaveText('95 % · still working…');
    await expect(page.locator('#af-status')).toContainText('Suggestions ready', { timeout: 10_000 });
    await expect(bar).toHaveAttribute('aria-valuenow', '100');
    await page.clock.runFor(1_000);
    await expect(bar).toBeHidden();
    await expect(page.locator('#name')).toHaveValue(/^Echo item/);
  });

  test('progress: a spinner when the service gives no average; typing still wins', async ({ app, page }) => {
    await configure(page, { on: true, url: stub.base + '/d/2500' });
    await app.go('/new');
    await expect(page.locator('#itemform')).not.toHaveAttribute('data-af-latency');
    await pick(page, app.image('af-spin.jpg', [210, 130, 60]));
    await expect(page.locator('#af-status')).toHaveText('Analyzing photo…', { timeout: 5_000 });
    const bar = page.locator('#af-progress');
    await expect(bar).toHaveClass(/af-indet/);
    await expect(bar).not.toHaveAttribute('aria-valuenow');
    await page.locator('#name').fill('Typed while waiting');
    await expect(page.locator('#af-status')).toContainText('Suggestions ready', { timeout: 8_000 });
    await expect(bar).toBeHidden();
    await expect(page.locator('#name')).toHaveValue('Typed while waiting');
    // the answer taught the page the service's average for the next photo? (none advertised: still unknown)
    await expect(page.locator('#itemform')).not.toHaveAttribute('data-af-latency');
  });

  test('model warming up: waits for Retry-After, then retries once', async ({ app, page }) => {
    await configure(page, { on: true, url: stub.base + '/m/cold/r/2' });
    stub.clear();
    await app.go('/new');
    await pick(page, app.image('af-cold.jpg', [30, 160, 120]));
    await expect(page.locator('#af-status')).toContainText('Model is warming up… retrying in', { timeout: 5_000 });
    await expect(page.locator('#af-status')).not.toContainText('busy');
    await expect(page.locator('#af-status')).toContainText('Suggestions ready', { timeout: 8_000 });
    await expect(page.locator('#name')).toHaveValue(/^Echo item/);
  });

  test('busy: a retry that fails again, or a wait longer than the timeout, ends with a clear message', async ({ app, page }) => {
    await configure(page, { on: true, url: stub.base + '/m/busy/r/1' });
    let calls = 0;
    page.on('request', r => { if (r.url().includes('/autofill/suggest')) calls++; });
    await app.go('/new');
    await pick(page, app.image('af-busy.jpg', [160, 40, 120]));
    await expect(page.locator('#af-status')).toContainText('Service is busy… retrying in 1 s', { timeout: 5_000 });
    await expect(page.locator('#af-status')).toContainText('the service is busy. Try again in 1 s', { timeout: 8_000 });
    await expect(page.locator('#af-status')).toHaveClass(/af-fail/);
    expect(calls, 'exactly one retry').toBe(2);
    await configure(page, { on: true, url: stub.base + '/m/busy/r/30', timeout: '5' });
    calls = 0;
    await app.go('/new');
    await pick(page, app.image('af-busy2.jpg', [40, 160, 120]));
    await expect(page.locator('#af-status')).toContainText('Try again in 30 s', { timeout: 5_000 });
    await expect(page.locator('#af-progress')).toBeHidden();
    expect(calls, 'no retry past the timeout').toBe(1);
  });

  test('a preselected default category is replaced; one the user picked (even back to the default) is kept', async ({ app, page }) => {
    await configure(page, { on: true, url: stub.base + '/s/Tools' });
    app.server.php(['-r', `require '${app.server.at(ROOT)}/src/bootstrap.php'; require '${app.server.at(ROOT)}/src/items.php'; tax_set_default('category', tax_create('category', 'General')['id'], true);`]);
    const cat = page.locator('#catsel');
    const label = () => cat.evaluate((s: HTMLSelectElement) => s.selectedOptions[0].textContent!.trim());
    await app.go('/new');
    await expect(page.locator('#itemform')).toHaveAttribute('data-af-defaults', /category/);
    expect(await label()).toBe('General ★');
    await pick(page, app.image('af-default-cat.jpg', [70, 70, 160]));
    await expect(page.locator('#af-status')).toContainText(/Suggestions ready: .*category/);
    expect(await label(), 'the untouched default is replaced').toBe('Tools');
    await expect(cat).toHaveClass(/af-filled/);
    // changed to Kitchen and back to General: the user's choice
    await app.go('/new');
    // with the keyboard: real (trusted) change events, like a person; selectOption() would dispatch synthetic ones
    await cat.focus();
    await page.keyboard.press('ArrowDown');
    expect(await label()).toBe('Kitchen');
    await page.keyboard.press('ArrowUp');
    await pick(page, app.image('af-picked-cat.jpg', [160, 70, 70]));
    await expect(page.locator('#af-status')).toContainText('Suggestions ready');
    await expect(page.locator('#af-status')).not.toContainText('category');
    expect(await label(), 'a picked category is kept').toBe('General ★');
    // a category chosen by the link (/new?cat=…) is not a default either
    const kitchen = app.sql<{ id: number }>("SELECT id FROM categories WHERE name = 'Kitchen'")[0].id;
    await app.go('/new?cat=' + kitchen);
    await expect(page.locator('#itemform')).not.toHaveAttribute('data-af-defaults', /category/);
    await pick(page, app.image('af-link-cat.jpg', [70, 160, 70]));
    await expect(page.locator('#af-status')).toContainText('Suggestions ready');
    expect(await label()).toBe('Kitchen');
  });

  test('an untouched default only gives way to an existing category; an empty picker still offers a new one', async ({ app, page }) => {
    // QA's C1: "Gadgets" is not in the list. With General ★ preselected and untouched, the default stays (saving must
    // not create "Gadgets"); with nothing selected, "＋ New category…" is pre-filled with the name, as before.
    await configure(page, { on: true, url: stub.base + '/s/Gadgets' });
    const cat = page.locator('#catsel');
    const label = () => cat.evaluate((s: HTMLSelectElement) => s.selectedOptions[0].textContent!.trim());
    app.server.php(['-r', `require '${app.server.at(ROOT)}/src/bootstrap.php'; db()->exec('UPDATE categories SET is_default = 0');`]);
    await app.go('/new');
    await expect(page.locator('#itemform'), 'no default category').not.toHaveAttribute('data-af-defaults', /category/);
    expect(await cat.inputValue()).toBe('');
    await pick(page, app.image('af-empty-newcat.jpg', [70, 120, 160]));
    await expect(page.locator('#af-status')).toContainText(/Suggestions ready: .*category/);
    expect(await cat.inputValue()).toBe('__new');
    await expect(page.locator('#newcat')).toBeVisible();
    await expect(page.locator('#newcatname')).toHaveValue('Gadgets');
    app.server.php(['-r', `require '${app.server.at(ROOT)}/src/bootstrap.php'; require '${app.server.at(ROOT)}/src/items.php'; tax_set_default('category', tax_create('category', 'General')['id'], true);`]);
    await app.go('/new');
    await expect(page.locator('#itemform')).toHaveAttribute('data-af-defaults', /category/);
    expect(await label()).toBe('General ★');
    await pick(page, app.image('af-default-newcat.jpg', [160, 120, 70]));
    await expect(page.locator('#af-status')).toContainText('Suggestions ready: name');
    await expect(page.locator('#af-status')).not.toContainText('category');
    expect(await label(), 'the untouched default is kept').toBe('General ★');
    await expect(cat).not.toHaveClass(/af-filled/);
    await expect(page.locator('#newcat')).toBeHidden();
    await expect(page.locator('#newcatname')).toHaveValue('');
    await page.locator('#save').click();
    await page.waitForURL(/saved=\d+/);
    expect(app.sql<{ n: number }>("SELECT COUNT(*) AS n FROM categories WHERE name = 'Gadgets'")[0].n, 'no category created').toBe(0);
    expect(app.sql<{ name: string }>('SELECT c.name FROM items i JOIN categories c ON c.id = i.category_id ORDER BY i.id DESC LIMIT 1')[0].name).toBe('General');
  });

  test('the edit form treats the stored category as the user\'s choice', async ({ app, page }) => {
    await configure(page, { on: true, url: stub.base + '/s/Tools' });
    const id = app.sql<{ id: number }>("SELECT i.id FROM items i JOIN categories c ON c.id = i.category_id WHERE c.name <> 'Tools' ORDER BY i.id LIMIT 1")[0].id;
    const before = app.sql<{ name: string }>(`SELECT c.name FROM items i JOIN categories c ON c.id = i.category_id WHERE i.id = ${id}`)[0].name;
    await app.go(`/item/${id}/edit`);
    await expect(page.locator('#itemform')).not.toHaveAttribute('data-af-defaults');
    await pick(page, app.image('af-edit-cat.jpg', [90, 90, 90]));
    await expect(page.locator('#af-status')).toContainText(/Suggestions ready|nothing to fill in/);
    expect(await page.locator('#catsel').evaluate((s: HTMLSelectElement) => s.selectedOptions[0].textContent!.replace(/ ★$/, '').trim())).toBe(before);
  });

  test('default tags and notes are replaced while untouched', async ({ app, page }) => {
    await configure(page, { on: true });
    await page.goto('/settings/items');
    await page.locator('#s-default_tags').fill('home, new');
    await page.locator('#s-default_notes').fill('Bought new.');
    await page.locator('#set-save').click();
    await expect(page.locator('.toast')).toHaveText('Settings saved');
    await app.go('/new');
    await expect(page.locator('#itemform')).toHaveAttribute('data-af-defaults', 'category,tags,notes');
    await pick(page, app.image('af-default-tags.jpg', [200, 200, 60]));
    await expect(page.locator('#af-status')).toContainText(/Suggestions ready: .*tags.*description/);
    await expect(page.locator('#tags')).not.toHaveValue('home, new');
    await expect(page.locator('textarea[name=notes]')).toHaveValue(/^JPEG image/);
    // typed into the notes first: kept, the default tags are still replaced
    await app.go('/new');
    await page.locator('textarea[name=notes]').fill('Bought new. Mine.');
    await pick(page, app.image('af-typed-notes.jpg', [60, 200, 200]));
    await expect(page.locator('#af-status')).toContainText('Suggestions ready');
    await expect(page.locator('textarea[name=notes]')).toHaveValue('Bought new. Mine.');
  });

  test('saving the token of a token-protected service caches its info: the first photo gets the bar', async ({ app, page }) => {
    await configure(page, { on: true, url: stub.base + '/l/6000/d/1500/t/bar-token' });
    await app.go('/new');
    await expect(page.locator('#itemform'), 'no token yet: /v1/info refused').not.toHaveAttribute('data-af-latency');
    await page.goto('/settings/autofill');
    await page.locator('#af-token-input').fill('bar-token');
    await page.locator('#af-token-save').click();
    await expect(page.locator('.toast')).toHaveText('Token saved');
    await app.go('/new');
    await expect(page.locator('#itemform')).toHaveAttribute('data-af-latency', '6000');
    await pick(page, app.image('af-token-bar.jpg', [120, 60, 200]));
    await expect(page.locator('#af-progress')).toHaveAttribute('aria-valuenow', /\d+/, { timeout: 5_000 });
    await expect(page.locator('#af-status')).toContainText('Suggestions ready');
  });

  for (const c of [
    { name: 'service down', url: 'http://127.0.0.1:1', msg: 'not reachable' },
    { name: 'backend unavailable (503)', url: () => stub.base + '/m/error', msg: 'busy or not ready' },
    { name: 'backend error (503)', url: () => stub.base + '/m/fault', msg: 'had a problem with this photo. Try again, or fill in the fields yourself' },
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
