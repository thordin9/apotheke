import { test, expect } from './fixtures';
import type { Page } from '@playwright/test';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { AutofillStub } from './autofill-stub';

/* AI autofill 2.4: analysis on demand (an Analyze button per photo, one analysis at a time), auto-analyze off on new
   and upgraded installs, Model / Image size from the service's /v1/info, and the resize in the browser – against the
   PHP echo stub (tests/stubs/autofill-stub.php). */
const stub = new AutofillStub();
test.beforeAll(async () => { await stub.start(); });
test.afterAll(() => stub.stop());

/** A v1.2 service: two models (the first is the default; 4 s and 2.5 s per photo) and three image sizes. */
const v12 = () => stub.base + '/n/' + encodeURIComponent('qwen2.5vl-jetson:3b') + '*=4000,qwen3-vl-2b=2500/z/448,672*,1024';

async function configure(page: Page, o: { url?: string; timeout?: string; auto?: boolean } = {}) {
  await page.goto('/settings/autofill');
  if (!(await page.locator('#s-autofill_enabled').isChecked())) await page.locator('label[for="s-autofill_enabled"]').click();
  if ((await page.locator('#s-autofill_auto').isChecked()) !== !!o.auto) await page.locator('label[for="s-autofill_auto"]').click();
  await page.locator('#s-autofill_url').fill(o.url ?? stub.base);
  await page.locator('#s-autofill_timeout').fill(o.timeout ?? '10');
  await page.locator('#set-save').click();
  await expect(page.locator('.toast')).toHaveText('Settings saved');
}
const pick = (page: Page, file: string) => page.locator('#itemform .photo-lib input[type=file]').setInputFiles(file);
const counter = (page: Page) => {
  const c = { n: 0 };
  page.on('request', r => { if (r.url().includes('/autofill/suggest')) c.n++; });
  return c;
};
const dataJson = async (page: Page, attr: string) => JSON.parse((await page.locator('#itemform').getAttribute(attr)) || 'null');
const shot = async (page: Page, name: string) => {
  if (test.info().project.name.endsWith('-smoke')) return;
  await page.evaluate(() => document.fonts.ready);
  await page.evaluate(() => window.scrollTo(0, 0));
  await expect(page).toHaveScreenshot(name + '.png', { mask: [page.locator('#preview'), page.locator('#pstatus'), page.locator('#name'), page.locator('textarea[name=notes]'), page.locator('.af-photo img')] });
};
/** Make an image with PHP (GD) in the test files dir. */
function gd(app: any, name: string, code: string): string {
  const out = path.join(app.server.filesDir, name);
  if (!fs.existsSync(out)) app.server.php(['-r', code, app.server.at(out)]);
  return out;
}
/** Two extra stored photos on an existing item; returns [item id, photo ids in order]. */
async function itemWithPhotos(app: any, page: Page): Promise<[number, number[]]> {
  const id = app.sql("SELECT id FROM items ORDER BY id LIMIT 1")[0].id as number;
  await app.go('/item/' + id);
  const csrf = await page.locator('meta[name=csrf]').getAttribute('content');
  for (const [n, rgb] of [['af-stored-1.jpg', [200, 40, 40]], ['af-stored-2.jpg', [40, 40, 200]]] as const) {
    const f = app.image(n, rgb as [number, number, number]);
    const r = await page.request.post('/item/' + id + '/photos', { headers: { 'X-Requested-With': 'fetch', Accept: 'application/json' },
      multipart: { csrf: csrf!, photos: { name: n, mimeType: 'image/jpeg', buffer: fs.readFileSync(f) } } });
    expect(r.ok()).toBe(true);
  }
  const ids = app.sql(`SELECT id FROM item_photos WHERE item_id = ${id} ORDER BY position, id`).map((r: any) => r.id as number);
  return [id, ids];
}

test.describe('AI autofill on demand', () => {
  test('default: picking a photo sends nothing; Analyze sends it and fills the empty fields @smoke', async ({ app, page }) => {
    await configure(page);
    const calls = counter(page);
    stub.clear();
    await app.go('/new');
    await expect(page.locator('#itemform')).toHaveAttribute('data-autofill', '1');
    await expect(page.locator('#itemform')).not.toHaveAttribute('data-af-auto');
    await expect(page.locator('#af-analyze'), 'no photo yet: no button').toBeHidden();
    await pick(page, app.image('af-od.jpg', [200, 80, 40]));
    await expect(page.locator('#pstatus')).toContainText('KB');
    await expect(page.locator('#af-analyze')).toBeVisible();
    await expect(page.locator('#af-analyze')).toBeEnabled();
    await page.waitForTimeout(500);
    expect(calls.n, 'nothing is sent on upload').toBe(0);
    expect(stub.last()).toBeNull();
    await expect(page.locator('#af-feedback')).toBeHidden();
    await expect(page.locator('#name')).toHaveValue('');
    await app.tapTargets();
    await app.noOverflow();
    await shot(page, 'autofill-analyze-ready');
    await page.locator('#af-analyze').click();
    await expect(page.locator('#af-status')).toContainText('Suggestions ready: name');
    expect(calls.n).toBe(1);
    await expect(page.locator('#name')).toHaveValue(/^Echo item [0-9a-f]{8}$/);
    await expect(page.locator('#af-analyze')).toBeEnabled();
    await expect(page.locator('#af-analyze')).not.toHaveAttribute('aria-busy');
    // the item saves with the photo and the suggestions
    await page.locator('#save').click();
    await page.waitForURL(/saved=\d+/);
    expect(app.sql<{ image: string }>("SELECT image FROM items WHERE name LIKE 'Echo item %'")[0].image).toMatch(/\.jpg$/);
  });

  test('auto-analyze (admin option) analyzes on upload, as in 2.3', async ({ app, page }) => {
    await configure(page, { auto: true });
    await app.go('/new');
    await expect(page.locator('#itemform')).toHaveAttribute('data-af-auto', '1');
    await pick(page, app.image('af-auto.jpg', [80, 200, 40]));
    await expect(page.locator('#af-status')).toContainText('Suggestions ready: name');
    await expect(page.locator('#af-analyze'), 'the button stays for another try').toBeVisible();
  });

  test('upgraded install: migration 0025 turns auto-analyze off, nothing is analyzed on upload', async ({ app, page }) => {
    await configure(page, { auto: true });
    // back to a 2.3 database: autofill on, no 2.4 settings rows, 0025 not applied; then upgrade
    app.exec("DELETE FROM settings WHERE name IN ('autofill_auto', 'autofill_model', 'autofill_image_size')");
    app.exec("DELETE FROM schema_migrations WHERE version = '0025'");
    // the upgrade: the next db() call (in the app the first request) applies 0025. Called directly, not through
    // bin/migrate.php: the CLI tools run as the data folder's owner, and this job's data folder is root's.
    app.migrate();
    expect(app.sql<{ version: string }>("SELECT version FROM schema_migrations WHERE version = '0025'").length, '0025 is applied').toBe(1);
    expect(app.sql<{ value: string }>("SELECT value FROM settings WHERE name = 'autofill_auto'")[0].value).toBe('false');
    expect(app.sql<{ value: string }>("SELECT value FROM settings WHERE name = 'autofill_enabled'")[0].value, 'autofill itself stays on').toBe('true');
    await page.goto('/settings/autofill');
    await expect(page.locator('#s-autofill_auto')).not.toBeChecked();
    const calls = counter(page);
    stub.clear();
    await app.go('/new');
    await pick(page, app.image('af-upgraded.jpg', [120, 40, 160]));
    await expect(page.locator('#af-analyze')).toBeVisible();
    await page.waitForTimeout(700);
    expect(calls.n, 'an upgraded install does not analyze on upload').toBe(0);
    expect(stub.last()).toBeNull();
  });

  test('one analysis at a time: the other Analyze buttons are disabled while it runs', async ({ app, page }) => {
    await configure(page, { url: stub.base + '/d/2500' });
    const [id, pids] = await itemWithPhotos(app, page);
    await app.go(`/item/${id}/edit`);
    await expect(page.locator('#af-photos')).toBeVisible();
    await expect(page.locator('#af-photos .af-analyze')).toHaveCount(pids.length);
    await pick(page, app.image('af-edit-new.jpg', [10, 150, 150]));
    const picked = page.locator('#af-analyze'), first = page.locator(`#af-analyze-${pids[0]}`), last = page.locator(`#af-analyze-${pids[pids.length - 1]}`);
    stub.clear();
    await last.click();
    await expect(last).toHaveAttribute('aria-busy', 'true');
    await expect(last).toBeDisabled();
    await expect(first).toBeDisabled();
    await expect(picked).toBeDisabled();
    await expect(page.locator('#af-status')).toHaveText('Analyzing photo…', { timeout: 5_000 });
    await first.click({ force: true });                       // disabled: does nothing
    await pick(page, app.image('af-edit-new2.jpg', [150, 150, 10]));   // a new photo does not cancel a stored photo's analysis
    await expect(picked).toBeDisabled();
    await expect(page.locator('#af-status')).toContainText(/Suggestions ready|nothing to fill in/, { timeout: 8_000 });
    for (const b of [picked, first, last]) await expect(b).toBeEnabled();
    await expect(last).not.toHaveAttribute('aria-busy');
    expect(stub.uploads().length, 'exactly one analysis ran').toBe(1);
    expect(stub.uploads()[0].width, 'the stored photo was analyzed').toBe(640);
  });

  test('a second analysis only fills what is still empty or untouched', async ({ app, page }) => {
    await configure(page);
    await app.go('/new');
    // the first answer has a name and tags only; the second (the real stub) would suggest every field
    // times: 1 – later requests are not intercepted at all (WebKit can lose a multipart body on route.continue())
    await page.route('**/autofill/suggest', route => route.fulfill({ json: { ok: true, code: 'ok', message: 'Suggestions from the photo', avg_latency_ms: null,
      suggestions: { name: 'Red hammer', category: null, tags: ['red', 'tool'], description: null } } }), { times: 1 });
    await pick(page, app.image('af-second-1.jpg', [200, 30, 30]));
    await page.locator('#af-analyze').click();
    await expect(page.locator('#af-status')).toContainText('Suggestions ready: name, tags filled in');
    await expect(page.locator('#name')).toHaveValue('Red hammer');
    await expect(page.locator('#tags')).toHaveValue('red, tool');
    await expect(page.locator('textarea[name=notes]')).toHaveValue('');
    await pick(page, app.image('af-second-2.jpg', [30, 30, 200]));
    await page.locator('#af-analyze').click();
    await expect(page.locator('#af-status')).toContainText(/Suggestions ready: .*description/);
    await expect(page.locator('#af-status')).not.toContainText('name');
    await expect(page.locator('#name'), 'the first analysis\'s name is kept').toHaveValue('Red hammer');
    await expect(page.locator('#tags'), '… and its tags').toHaveValue('red, tool');
    await expect(page.locator('textarea[name=notes]'), 'the still-empty notes are filled').toHaveValue(/^JPEG image, \d+ bytes$/);
    // typed by the user: never replaced by a later analysis
    await page.locator('textarea[name=notes]').fill('Mine.');
    await page.locator('#af-analyze').click();
    await expect(page.locator('#af-status')).toContainText('nothing to fill in, your entries were kept');
    await expect(page.locator('textarea[name=notes]')).toHaveValue('Mine.');
  });

  test('Model and Image size: hidden for an older service, shown from /v1/info; Test with a photo uses them', async ({ app, page }) => {
    await configure(page);
    await page.goto('/settings/autofill');
    await expect(page.locator('select#s-autofill_model')).toHaveCount(0);
    await expect(page.locator('select#s-autofill_image_size')).toHaveCount(0);
    await expect(page.locator('#s-autofill_model')).toBeHidden();
    await configure(page, { url: v12() });
    await expect(page.locator('select#s-autofill_model')).toBeVisible();
    await expect(page.locator('#s-autofill_model option')).toHaveText(['Service default (qwen2.5vl-jetson:3b)', 'qwen2.5vl-jetson:3b · loaded · about 4.0 s', 'qwen3-vl-2b · about 2.5 s']);
    await expect(page.locator('#s-autofill_image_size option')).toHaveText(['Recommended (672 px)', '448 px', '672 px (recommended)', '1024 px']);
    await expect(page.locator('#s-autofill_image_size')).toHaveValue('0');
    await page.locator('#s-autofill_model').selectOption('qwen3-vl-2b');
    await page.locator('#s-autofill_image_size').selectOption('448');
    stub.clear();
    await page.locator('#af-test-photo').click();
    await expect(page.locator('#af-res')).toHaveAttribute('data-code', 'ok', { timeout: 15_000 });
    await expect(page.locator('#af-s-model')).toHaveText('qwen3-vl-2b');
    await expect(page.locator('#af-s-size')).toHaveText('448 px');
    await expect(page.locator('#af-models')).toHaveText('qwen2.5vl-jetson:3b (default), qwen3-vl-2b');
    expect(stub.last().context.model, 'Test with a photo sends the model as chosen (not saved yet)').toBe('qwen3-vl-2b');
    await expect(page.locator('#s-autofill_model'), 'the choice survives the test').toHaveValue('qwen3-vl-2b');
    await page.locator('#set-save').click();
    await expect(page.locator('.toast')).toHaveText('Settings saved');
    await expect(page.locator('#s-autofill_model')).toHaveValue('qwen3-vl-2b');
    await expect(page.locator('#s-autofill_image_size')).toHaveValue('448');
    await app.noOverflow();
    await app.tapTargets();
    // the service drops the lists again: the dropdowns hide, the stored choice is kept
    await configure(page, { url: stub.base });
    await expect(page.locator('select#s-autofill_model')).toHaveCount(0);
    expect(app.sql<{ value: string }>("SELECT value FROM settings WHERE name = 'autofill_model'")[0].value).toBe('"qwen3-vl-2b"');
  });

  test('Model dropdown: hidden without models, one model shown disabled with a hint, two or more enabled', async ({ app, page }) => {
    const hint = 'More models appear when ALLOWED_MODELS is set on the service.';
    // no models list (the Jetson with ALLOWED_MODELS unset and an older service): no dropdown, no hint
    await configure(page, { url: stub.base + '/z/448,560*,1024' });
    await expect(page.locator('select#s-autofill_model')).toHaveCount(0);
    await expect(page.getByText(hint)).toHaveCount(0);
    await expect(page.locator('#s-autofill_image_size option'), 'the Jetson sizes, 560 recommended').toHaveText(['Recommended (560 px)', '448 px', '560 px (recommended)', '1024 px']);
    // exactly one model: shown, disabled, with the hint; saving keeps the stored value
    await configure(page, { url: stub.base + '/n/' + encodeURIComponent('qwen2.5vl-jetson:3b') + '=4000/z/448,560*,1024' });
    const sel = page.locator('select#s-autofill_model');
    await expect(sel).toBeVisible();
    await expect(sel).toBeDisabled();
    await expect(sel.locator('option')).toHaveText(['qwen2.5vl-jetson:3b · loaded · about 4.0 s']);
    await expect(page.locator('#s-autofill_model-one')).toHaveText(hint);
    await expect(page.locator('#s-autofill_model-one')).toBeVisible();
    await page.locator('#set-save').click();
    await expect(page.locator('.toast')).toHaveText('Settings saved');
    expect(app.sql<{ value: string }>("SELECT value FROM settings WHERE name = 'autofill_model'")[0].value).toBe('""');
    stub.clear();
    await page.locator('#af-test-photo').click();
    await expect(page.locator('#af-res')).toHaveAttribute('data-code', 'ok', { timeout: 15_000 });
    await expect(page.locator('#af-s-model')).toHaveText('qwen2.5vl-jetson:3b');
    await expect(page.locator('#af-s-size')).toHaveText('560 px');
    await app.noOverflow();
    // two models: enabled, no hint
    await configure(page, { url: v12() });
    await expect(sel).toBeEnabled();
    await expect(sel.locator('option')).toHaveCount(3);
    await expect(page.getByText(hint)).toHaveCount(0);
  });

  test('the chosen model is sent, the photo resized to the chosen size, model_used shown in the hooks', async ({ app, page }) => {
    await configure(page, { url: v12() });
    await page.locator('#s-autofill_model').selectOption('qwen3-vl-2b');
    await page.locator('#s-autofill_image_size').selectOption('448');
    await page.locator('#set-save').click();
    await expect(page.locator('.toast')).toHaveText('Settings saved');
    stub.clear();
    await app.go('/new');
    await expect(page.locator('#itemform')).toHaveAttribute('data-af-side', '448');
    await expect(page.locator('#itemform'), 'the estimate uses the chosen model\'s average').toHaveAttribute('data-af-latency', '2500');
    const big = gd(app, 'af-big.png', '$im=imagecreatetruecolor(3000,2000);imagefill($im,0,0,imagecolorallocate($im,10,90,160));imagepng($im,$argv[1],1);');
    await pick(page, big);
    await page.locator('#af-analyze').click();
    await expect(page.locator('#af-status')).toContainText('Suggestions ready');
    const last = stub.last();
    expect(last.context.model).toBe('qwen3-vl-2b');
    expect([last.type, last.width, last.height]).toEqual(['JPEG', 448, 299]);
    expect(await dataJson(page, 'data-af-upload')).toMatchObject({ type: 'image/jpeg', width: 448, height: 299 });
    expect(await dataJson(page, 'data-af-sent')).toEqual({ type: 'image/jpeg', bytes: last.bytes, width: 448, height: 299 });
    await expect(page.locator('#itemform')).toHaveAttribute('data-af-model-used', 'qwen3-vl-2b');
  });

  test('Analyze keeps keyboard focus: it is not left on body when the run finishes', async ({ app, page }) => {
    await configure(page, { url: stub.base + '/d/1200' });
    await app.go('/new');
    await pick(page, app.image('af-focus.jpg', [40, 90, 140]));
    const btn = page.locator('#af-analyze');
    await expect(btn).toBeVisible();
    await expect(page.locator('#name')).toBeFocused();   // picking an empty form focuses the name; then we tab to Analyze
    await btn.focus();
    await page.keyboard.press('Enter');
    await expect(btn).toHaveAttribute('aria-disabled', 'true');
    await expect(btn).toHaveAttribute('aria-busy', 'true');
    expect(await page.evaluate(() => document.activeElement?.tagName), 'focus stays on a control while it runs').not.toBe('BODY');
    await expect(page.locator('#af-status')).toContainText('Suggestions ready', { timeout: 8_000 });
    expect(await page.evaluate(() => document.activeElement?.tagName), 'focus is not left on body').not.toBe('BODY');
    await expect(btn).toBeFocused();
    await expect(btn).toBeEnabled();
  });

  test('a model switch is a cold start: warming up, one retry, then the answer', async ({ app, page }) => {
    await configure(page, { url: stub.base + '/m/switch/r/1/n/a*,b' });
    await page.locator('#s-autofill_model').selectOption('b');
    await page.locator('#set-save').click();
    await expect(page.locator('.toast')).toHaveText('Settings saved');
    stub.clear();
    const calls = counter(page);
    await app.go('/new');
    await pick(page, app.image('af-switch.jpg', [60, 60, 60]));
    await page.locator('#af-analyze').click();
    await expect(page.locator('#af-status')).toContainText('Model is warming up… retrying in', { timeout: 5_000 });
    await expect(page.locator('#af-analyze'), 'still the running analysis').toBeDisabled();
    await expect(page.locator('#af-status')).toContainText('Suggestions ready', { timeout: 8_000 });
    expect(calls.n).toBe(2);
    await expect(page.locator('#itemform')).toHaveAttribute('data-af-model-used', 'b');
  });

  test('resize: orientation, no upscaling, PNG transparency, the size limit after resizing, HEIC', async ({ app, page }) => {
    test.setTimeout(90_000);
    await configure(page);
    await app.go('/new');
    // a phone photo stored sideways (2000×1000 pixels, EXIF orientation 6 = rotate 90°): sent upright, 512×1024
    const exif = gd(app, 'af-exif6.jpg', `$im=imagecreatetruecolor(2000,1000);imagefill($im,0,0,imagecolorallocate($im,200,200,40));imagefilledrectangle($im,0,0,400,1000,imagecolorallocate($im,200,0,0));
      ob_start();imagejpeg($im,null,85);$j=ob_get_clean();$t="MM\\x00\\x2A\\x00\\x00\\x00\\x08\\x00\\x01\\x01\\x12\\x00\\x03\\x00\\x00\\x00\\x01\\x00\\x06\\x00\\x00\\x00\\x00\\x00\\x00";
      $a="Exif\\x00\\x00".$t;file_put_contents($argv[1],"\\xFF\\xD8\\xFF\\xE1".pack('n',strlen($a)+2).$a.substr($j,2));`);
    stub.clear();
    await pick(page, exif);
    await page.locator('#af-analyze').click();
    await expect(page.locator('#af-status')).toContainText('Suggestions ready');
    expect([stub.last().width, stub.last().height], 'upright').toEqual([512, 1024]);
    expect(await dataJson(page, 'data-af-upload')).toMatchObject({ width: 512, height: 1024 });
    // a small photo is never enlarged
    stub.clear();
    await pick(page, gd(app, 'af-small.png', '$im=imagecreatetruecolor(300,200);imagefill($im,0,0,imagecolorallocate($im,30,160,90));imagepng($im,$argv[1]);'));
    await page.locator('#af-analyze').click();
    await expect(page.locator('#af-status')).toContainText(/Suggestions ready|nothing to fill in/);
    expect([stub.last().type, stub.last().width, stub.last().height]).toEqual(['JPEG', 300, 200]);
    // the server always sends a stripped JPEG, so a PNG with alpha is flattened
    stub.clear();
    await pick(page, gd(app, 'af-alpha.png', '$im=imagecreatetruecolor(1600,1200);imagesavealpha($im,true);imagealphablending($im,false);imagefill($im,0,0,imagecolorallocatealpha($im,0,0,0,127));imagefilledellipse($im,800,600,900,900,imagecolorallocatealpha($im,220,40,40,0));imagepng($im,$argv[1]);'));
    await page.locator('#af-analyze').click();
    await expect(page.locator('#af-status')).toContainText(/Suggestions ready|nothing to fill in/);
    expect([stub.last().type, stub.last().alpha, stub.last().width, stub.last().height]).toEqual(['JPEG', false, 1024, 768]);
    // larger than the service's 10 MiB limit as picked, well below it once resized
    const huge = gd(app, 'af-huge-noise.png', '$w=2300;$h=1800;$im=imagecreatetruecolor($w,$h);mt_srand(7);for($y=0;$y<$h;$y++)for($x=0;$x<$w;$x++)imagesetpixel($im,$x,$y,mt_rand(0,0xFFFFFF));imagepng($im,$argv[1],0);');
    expect(fs.statSync(huge).size).toBeGreaterThan(10 * 1024 * 1024);
    stub.clear();
    await pick(page, huge);
    await page.locator('#af-analyze').click();
    await expect(page.locator('#af-status')).toContainText(/Suggestions ready|nothing to fill in/, { timeout: 20_000 });
    expect(stub.last().bytes).toBeLessThan(10 * 1024 * 1024);
    expect(Math.max(stub.last().width, stub.last().height)).toBe(1024);
    // HEIC that neither the browser nor PHP can decode: nothing is sent
    const heic = path.join(app.server.filesDir, 'af-photo.heic');
    fs.writeFileSync(heic, Buffer.concat([Buffer.from([0, 0, 0, 0x18]), Buffer.from('ftypheic'), Buffer.alloc(600, 7)]));
    await pick(page, heic);
    await page.locator('#af-analyze').click();
    await expect(page.locator('#af-status')).toContainText('cannot be read as a photo');
    await expect(page.locator('#af-status')).toHaveClass(/af-fail/);
  });

  test('a stored photo on the edit form only fills empty fields', async ({ app, page }) => {
    await configure(page);
    const [id, pids] = await itemWithPhotos(app, page);
    app.exec(`UPDATE items SET notes = '' WHERE id = ${id}`);
    const name = app.sql<{ name: string }>(`SELECT name FROM items WHERE id = ${id}`)[0].name;
    await app.go(`/item/${id}/edit`);
    await page.locator(`#af-analyze-${pids[0]}`).click();
    await expect(page.locator('#af-status')).toContainText(/Suggestions ready: .*description/);
    await expect(page.locator('#name'), 'the stored name is the user\'s').toHaveValue(name);
    await expect(page.locator('textarea[name=notes]')).toHaveValue(/^JPEG image, \d+ bytes$/);
  });
});
