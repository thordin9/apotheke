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
    // FEATURE-CHECKS
    ok(errors.length === 0, 'no JS errors' + (errors.length ? ': ' + errors.join('; ') : ''));
    await ctx.close();
  }
} finally { await browser.close(); }
console.log(fails ? `${fails} failed` : 'all e2e checks passed');
process.exit(fails ? 1 : 0);
