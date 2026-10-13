// Headless mobile checks for the filter sheet and theme switch (optional; needs puppeteer-core + Chrome).
// Usage: node tests/e2e/mobile.mjs http://127.0.0.1:8080   (CHROME=/path/to/chrome to override)
import puppeteer from 'puppeteer-core';
const base = process.argv[2] || 'http://127.0.0.1:8080';
const exe = process.env.CHROME || '/usr/bin/google-chrome';
let fails = 0;
const ok = (c, m) => { console.log((c ? '  ✔ ' : '  ✘ ') + m); if (!c) fails++; };
const browser = await puppeteer.launch({ executablePath: exe, headless: 'new', args: ['--no-sandbox'] });
try {
  for (const vp of [{ width: 375, height: 667 }, { width: 390, height: 844 }]) {
    console.log(`== ${vp.width}×${vp.height}`);
    const ctx = await browser.createBrowserContext();
    const p = await ctx.newPage();
    await p.setViewport({ ...vp, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
    // Taps scroll their target to the middle of the screen first, so the sticky header and action bar never cover it.
    const rawTap = p.tap.bind(p);
    p.tap = async sel => { await p.$eval(sel, e => e.scrollIntoView({ block: 'center', inline: 'nearest' })); return rawTap(sel); };
    const errors = []; p.on('pageerror', e => errors.push(e.message));
    await p.goto(base + '/', { waitUntil: 'networkidle0' });
    const overflow = async () => p.evaluate(() => document.documentElement.scrollWidth > innerWidth);
    ok(!(await overflow()), 'no horizontal overflow on list');
    // filter sheet
    const pause = ms => new Promise(r => setTimeout(r, ms));
    await p.tap('#filter-open'); await pause(400);
    ok(await p.$eval('#fsheet', d => d.open), 'filter sheet opens');
    const rect = await p.$eval('.fpanel', e => { const r = e.getBoundingClientRect(); return { b: r.bottom, w: r.width }; });
    ok(Math.abs(rect.b - vp.height) < 2 && rect.w >= vp.width - 1, 'sheet is a full-width bottom sheet');
    const locVal = await p.$eval('#loc', s => [...s.options].find(o => /^\d+$/.test(o.value))?.value || '');
    ok(locVal !== '', 'location options present');
    await p.select('#loc', locVal);
    await p.waitForFunction(() => document.querySelector('#achips a[data-clear="loc"]'), { timeout: 5000 });
    ok(true, 'selecting a location adds an active chip');
    ok(await p.$eval('#fcount', e => !e.hidden && e.textContent === '1'), 'badge shows 1 active filter');
    await p.tap('#filter-apply'); await pause(700);
    ok(!(await p.$eval('#fsheet', d => d.open)), '"Show results" closes the sheet');
    ok(new URL(p.url()).searchParams.get('loc') === locVal, 'URL reflects filter');
    await p.tap('#achips a[data-clear="loc"]');
    await p.waitForFunction(() => !document.querySelector('#achips a'), { timeout: 5000 });
    ok(await p.$eval('#loc', s => s.value === ''), 'tapping the chip × clears the filter');
    // theme switch
    await p.tap('#menu-btn'); await pause(300);
    ok(await p.$eval('#menu', d => d.open), 'menu opens');
    await p.tap('.seg-btn[value="dark"]'); await pause(300);
    ok(await p.evaluate(() => document.documentElement.dataset.theme === 'dark'), 'dark applied instantly');
    ok(await p.evaluate(() => localStorage.getItem('theme') === 'dark'), 'stored in localStorage');
    const bg = await p.evaluate(() => getComputedStyle(document.body).backgroundColor);
    ok(bg === 'rgb(17, 17, 19)', 'dark palette in effect (' + bg + ')');
    await p.reload({ waitUntil: 'networkidle0' });
    ok(await p.evaluate(() => document.documentElement.dataset.theme === 'dark' && document.querySelector('meta[name=theme-color]').content === '#111113'), 'persists across reload (server-rendered, no flash)');
    await p.keyboard.press('Escape');
    await p.tap('#menu-btn'); await pause(300); await p.tap('.seg-btn[value="auto"]'); await pause(100);
    ok(await p.evaluate(() => document.documentElement.dataset.theme === 'auto'), 'back to auto');
    for (const u of ['/new', '/locations', '/item/1']) {
      await p.goto(base + u, { waitUntil: 'networkidle0' });
      ok(!(await overflow()), `no horizontal overflow on ${u}`);
    }
    await p.goto(base + '/new', { waitUntil: 'networkidle0' });
    const bar = await p.$eval('.actionbar', e => e.getBoundingClientRect().bottom);
    ok(Math.abs(bar - vp.height) < 2, 'form action bar sticks to the bottom');
    // QR labels
    await p.goto(base + '/labels', { waitUntil: 'networkidle0' });
    ok(await p.$$eval('figure.label svg.qr', l => l.length) > 0, 'labels sheet shows QR codes');
    ok(!(await overflow()), 'no horizontal overflow on /labels');
    ok(await p.$eval('#print', b => b.getBoundingClientRect().height >= 44), 'print button is a 44px target');
    await p.emulateMediaType('print');
    ok(await p.$eval('header.top', h => getComputedStyle(h).display === 'none'), 'print stylesheet hides the header');
    await p.emulateMediaType(null);
    // photos: add two from the library on an item without photos, then use the arrows
    {
      const fs = await import('node:fs'), os = await import('node:os'), path = await import('node:path');
      const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAAEklEQVR4nGP4z8CAFWEXHbQSACj/P8Hu5wAAAAAASUVORK5CYII=', 'base64');
      const f1 = path.join(os.tmpdir(), 'e2e-a.png'), f2 = path.join(os.tmpdir(), 'e2e-b.png');
      fs.writeFileSync(f1, png); fs.writeFileSync(f2, png);
      await p.goto(base + '/new', { waitUntil: 'networkidle0' });
      await p.type('input[name=name]', 'E2E gallery');
      await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle0' }), p.click('#save')]);
      const link = await p.evaluate(() => [...document.querySelectorAll('a[href^="/item/"]')].find(a => a.textContent.includes('E2E gallery'))?.getAttribute('href'));
      ok(!!link, 'new item listed');
      await p.goto(base + link, { waitUntil: 'networkidle0' });
      ok(!(await p.$('#gallery')) && !!(await p.$('#photoadd')), 'item without photos offers the add form');
      await p.tap('#photos > summary'); await pause(200);
      const inp = await p.$('#photoadd-lib');
      await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle0', timeout: 15000 }), inp.uploadFile(f1, f2)]);
      ok(await p.$eval('#gal-count', e => e.textContent.trim() === '2 / 2'), 'two photos uploaded, gallery opens on the last');
      await p.tap('#gal-next'); await pause(200);
      ok(await p.$eval('#gal-count', e => e.textContent.trim() === '1 / 2') && /\?p=0$/.test(p.url()), 'next arrow wraps to the first photo (no reload)');
      ok(await p.$eval('#gal-prev', b => b.getBoundingClientRect().width >= 44), 'gallery arrows are 44px targets');
      await p.tap('.thumbs a[data-i="1"]'); await pause(200);
      ok(await p.$eval('#gal-count', e => e.textContent.trim() === '2 / 2'), 'thumbnail strip selects a photo');
      ok(await p.$$eval('.pm', l => l.length) === 2 && await p.$eval('.pm.is-cover .pm-badge', e => !!e), 'photo manager lists photos with the cover badge');
      ok(!(await overflow()), 'no horizontal overflow on the gallery page');
    }
    // tags: chip entry with suggestions, custom fields, tag filter
    {
      await p.goto(base + '/new', { waitUntil: 'networkidle0' });
      ok(await p.$eval('#tags', i => i.type === 'hidden') && !!(await p.$('#tagbox #tag-entry')), 'tag field is enhanced into a chip box');
      await p.type('input[name=name]', 'E2E tagged');
      await p.tap('#tag-entry'); await p.type('#tag-entry', 'Garage'); await p.keyboard.press('Enter');
      await p.type('#tag-entry', 'warranty,');
      ok(await p.$$eval('#tagbox .tagchip', l => l.map(c => c.dataset.tag).join('|')) === 'Garage|warranty', 'Enter and comma turn text into chips');
      await p.type('#tag-entry', 'GARAGE'); await p.keyboard.press('Enter');
      ok(await p.$$eval('#tagbox .tagchip', l => l.length) === 2, 'duplicate tag ignored');
      await p.tap('#tagbox .tagchip[data-tag="warranty"]');
      ok(await p.$eval('#tags', i => i.value) === 'Garage', 'tapping a chip removes it');
      ok(await p.$eval('.tagchip-x', b => b.getBoundingClientRect().height >= 36), 'tag chips are comfortable tap targets');
      await p.tap('.more-fields > summary'); await pause(150);
      await p.type('#value', '19,90'); await p.type('#serial', 'E2E-SN');
      await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle0' }), p.click('#save')]);
      const link = await p.evaluate(() => [...document.querySelectorAll('a[href^="/item/"]')].find(a => a.textContent.includes('E2E tagged'))?.getAttribute('href'));
      await p.goto(base + link, { waitUntil: 'networkidle0' });
      ok(await p.$eval('.tags', e => e.textContent.includes('#Garage')) && await p.$eval('#facts', e => /19\.90/.test(e.textContent) && e.textContent.includes('E2E-SN')), 'item page shows tag chip and custom fields');
      ok(!(await overflow()), 'no horizontal overflow on a tagged item');
      await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle0' }), p.tap('.tags a.tagchip')]);
      ok(/[?&]tag=\d+/.test(p.url()) && await p.$eval('#achips', e => e.textContent.includes('Garage')), 'tag chip opens the list filtered by that tag');
      ok(await p.$$eval('#grid .card', l => l.length) >= 1, 'filtered list shows the tagged item');
    }
    // backup & restore: menu → page, download, upload for a dry run, confirm
    {
      const fs = await import('node:fs'), os = await import('node:os'), path = await import('node:path');
      await p.goto(base + '/', { waitUntil: 'networkidle0' });
      await p.tap('#menu-btn'); await pause(250);
      await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle0' }), p.tap('#menu a[href="/backup"]')]);
      ok(!!(await p.$('#backup-dl')) && !(await overflow()), 'backup page from the menu, no overflow');
      ok(await p.$eval('#backup-dl', b => b.getBoundingClientRect().height >= 44), 'download button is a 44px target');
      const zip = Buffer.from(await (await fetch(base + '/backup.zip')).arrayBuffer());
      ok(zip.subarray(0, 2).toString() === 'PK', 'backup download is a zip (' + zip.length + ' bytes)');
      const zf = path.join(os.tmpdir(), 'e2e-backup.zip'); fs.writeFileSync(zf, zip);
      await (await p.$('#restore-file')).uploadFile(zf);
      ok(await p.$eval('#restore-name', e => e.textContent === 'e2e-backup.zip'), 'chosen file name shown');
      await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle0' }), p.tap('#restore-check')]);
      ok(!!(await p.$('#restore-preview')) && !(await overflow()), 'dry-run summary shown, no overflow');
      await p.tap('#restore-ok');
      await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle0' }), p.tap('#restore-go')]);
      ok(/\/backup\?restored=1$/.test(p.url()) && await p.$eval('.toast', t => t.textContent.includes('restored')), 'restore confirmed, toast shown');
    }
    // history: change an item, see it on the item page and in recent activity
    {
      await p.goto(base + '/', { waitUntil: 'networkidle0' });
      const link = await p.$eval('#grid .card a.card-img', a => a.getAttribute('href'));
      await p.goto(base + link, { waitUntil: 'networkidle0' });
      await p.tap('.qty-lg .qbtn[data-delta="1"]'); await pause(400);
      await p.reload({ waitUntil: 'networkidle0' });
      ok(await p.$eval('#history .act-quantity', e => /Quantity \d+ → \d+/.test(e.textContent)), 'quantity change shows in the item history');
      ok(!(await overflow()), 'no horizontal overflow with history');
      await p.goto(base + '/', { waitUntil: 'networkidle0' });
      await p.tap('#menu-btn'); await pause(250);
      await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle0' }), p.tap('#menu a[href="/activity"]')]);
      ok(await p.$$eval('#activity .act', l => l.length) > 0 && await p.$eval('#activity .act', e => e.classList.contains('act-quantity')), 'recent activity lists the newest change first');
      ok(!(await overflow()), 'no horizontal overflow on /activity');
    }
    // FEATURE-CHECKS
    ok(errors.length === 0, 'no JS errors' + (errors.length ? ': ' + errors.join('; ') : ''));
    await ctx.close();
  }
} finally { await browser.close(); }
console.log(fails ? `${fails} failed` : 'all e2e checks passed');
process.exit(fails ? 1 : 0);
