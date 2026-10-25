import { test, expect } from './fixtures';

test.describe('empty catalog', () => {
  test.use({ seed: 'empty' });
  test('shows the empty state with a call to action', async ({ app, page }) => {
    await app.go('/');
    await expect(page.locator('#empty')).toBeVisible();
    await expect(page.locator('#empty-title')).toHaveText('Your catalog is empty.');
    await expect(page.locator('#stats')).toHaveCount(0);
    await expect(page.locator('#count')).toHaveText('0 items');
    await page.locator('#empty-add').click();
    await expect(page).toHaveURL(/\/new$/);
    await app.go('/activity');
    await expect(page.locator('.empty-title')).toHaveText('No activity yet.');
  });

  test('the labels page has an empty state', async ({ app, page }) => {
    await app.go('/labels');
    await expect(page.locator('.empty-title')).toHaveText('Nothing to print yet.');
  });
});

test.describe('list, overview and search', () => {
  test('grid of cards with photos, chips and quantities @smoke', async ({ app, page }) => {
    await app.go('/');
    await expect(page.locator('#grid .card')).toHaveCount(8);
    await expect(page.locator('#count')).toHaveText('8 items');
    await expect(page.locator('.stats')).toContainText('8 items · 19 pcs');
    await expect(page.locator('#grid .card').first().locator('.card-title')).toHaveText('HDMI cables');   // most recently changed
    await expect(page.locator('#grid .card img')).toHaveCount(6);
    await expect(page.locator('.card[data-id="5"] .chip-muted')).toHaveCount(0);
    await expect(page.locator('.card[data-id="5"] .chip-category')).toHaveText('Kitchen');
    await app.noOverflow();
    await app.tapTargets();
  });

  test('overview: tiles, range toggle, collapse persistence, tap to filter', async ({ app, page }) => {
    await app.go('/');
    const stats = page.locator('#stats');
    if ((page.viewportSize()?.width ?? 1280) <= 640) {
      // phones start collapsed so the items show above the fold
      await expect(stats).not.toHaveAttribute('open', '');
      await stats.locator('summary').click();
    }
    await expect(stats).toHaveAttribute('open', '');
    const tiles = page.locator('#stats .tile');
    await expect(tiles).toHaveCount(9);
    await expect(tiles.nth(0)).toContainText('8');
    await expect(tiles.nth(1)).toContainText('19');
    await expect(page.locator('.chart-d')).toBeVisible();
    await expect(page.locator('.chart-d .st-sub')).toContainText('6 in the last 30 days');
    // the axis text keeps its size however wide the chart is (QA: ~25px at 1280px)
    const fs = await page.locator('.chart-d .chart-axis .ax').first().evaluate(e => parseFloat(getComputedStyle(e).fontSize));
    expect(fs).toBe(12);
    const ch = await page.locator('.chart-d svg.chart').evaluate(e => e.getBoundingClientRect().height);
    expect(ch).toBeLessThanOrEqual(90);
    await page.locator('label[for=rg-m]').click();
    await expect(page.locator('.chart-m')).toBeVisible();
    await expect(page.locator('.chart-d')).toBeHidden();
    await expect(page.locator('.chart-m .st-sub')).toContainText('8 in the last 12 months');
    await page.reload({ waitUntil: 'networkidle' });
    await expect(page.locator('.chart-m'), 'range choice persists').toBeVisible();
    // collapse persists (cookie, server-rendered closed)
    await page.locator('#stats > summary').click();
    await expect(stats).not.toHaveAttribute('open', '');
    await expect.poll(async () => (await page.context().cookies()).find(c => c.name === 'stats_open')?.value).toBe('0');
    await page.reload({ waitUntil: 'networkidle' });
    await expect(stats).not.toHaveAttribute('open', '');
    await page.locator('#stats > summary').click();
    await expect(page.locator("#stats .tile")).toHaveCount(9);   // lazily loaded again
    // tap a bar in "By location" to filter in place
    await page.locator('#stats a[data-loc="3"]').first().click();
    await expect(page.locator('#grid .card')).toHaveCount(2);
    await expect(page).toHaveURL(/[?&]loc=3/);
    await expect(page.locator('#achips')).toContainText('Attic');
    // tap a category chip on a card
    await page.locator('#achips a[data-clear="all"]').click();
    await expect(page.locator('#grid .card')).toHaveCount(8);
    await expect(page).not.toHaveURL(/loc=/);
    const chip = page.locator('.card[data-id="3"] .chip-category');
    // instant scroll: html{scroll-behavior:smooth} makes WebKit tap while still scrolling
    await chip.evaluate(e => e.scrollIntoView({ block: 'center', behavior: 'instant' }));
    await chip.click();
    await expect(page.locator('#grid .card')).toHaveCount(2);
    await expect(page.locator('#cat')).toHaveValue('3');
  });

  test('instant search, including Greek without accents @smoke', async ({ app, page }) => {
    await app.go('/');
    await page.locator('#q').fill('drill');
    await expect(page.locator('#grid .card')).toHaveCount(1);
    await expect(page.locator('#count')).toHaveText('1 item found');
    await page.locator('#q').fill('ΠΡΙΖΑ');
    await expect(page.locator('#grid .card .card-title')).toHaveText(['Πρίζα πολύπριζο']);
    await page.locator('#q').fill('σημειωσεις');
    await expect(page.locator('#grid .card')).toHaveCount(1);
    await page.locator('#q').fill('attic');   // matches the location
    await expect(page.locator('#grid .card')).toHaveCount(2);
    await page.locator('#q').fill('mkt-dhp');  // serial number
    await expect(page.locator('#grid .card')).toHaveCount(1);
    await page.locator('#q').fill('power tools');  // tag
    await expect(page.locator('#grid .card')).toHaveCount(1);
    await page.locator('#q').fill('zzz nothing');
    await expect(page.locator('#empty-title')).toHaveText('Nothing matches.');
    await page.locator('#empty-clear').click();
    await expect(page.locator('#grid .card')).toHaveCount(8);
    await expect(page.locator('#q')).toHaveValue('');
    await page.locator('#q').fill('cable');
    await expect(page).toHaveURL(/\?q=cable$/);
    await page.reload({ waitUntil: 'networkidle' });
    await expect(page.locator('#q'), 'search kept in the URL').toHaveValue('cable');
  });

  test('filter sheet: location, category, tag, sort, chips and clear all', async ({ app, page }) => {
    await app.go('/');
    await page.locator('#filter-open').click();
    await expect(page.locator('#fsheet .fpanel')).toBeVisible();
    await app.tapTargets();
    await page.locator('#loc').selectOption('1');
    await expect(page.locator('#grid .card')).toHaveCount(2);
    await page.locator('#cat').selectOption('1');
    await page.locator('#tag').selectOption({ label: 'Power tools (1)' });
    await page.locator('#sort').selectOption('name');
    await page.locator('#filter-apply').click();
    await expect(page.locator('#fsheet .fpanel')).toBeHidden();
    await expect(page.locator('#grid .card .card-title')).toHaveText(['Cordless drill']);
    await expect(page.locator('#fcount')).toHaveText('4');
    await expect(page.locator('#achips a[data-clear]')).toHaveCount(5);   // 4 filters + "Clear all"
    await page.locator('#achips a[data-clear="tag"]').click();
    await expect(page.locator('#grid .card .card-title')).toHaveText(['Cordless drill', 'Screwdriver set']);
    await page.locator('#achips a[data-clear="cat"]').click();
    await expect(page.locator('#fcount')).toHaveText('2');
    await page.locator('#achips a[data-clear="sort"]').click();
    await expect(page.locator('#fcount')).toHaveText('1');
    await page.reload({ waitUntil: 'networkidle' });
    await expect(page.locator('#loc'), 'filters live in the URL').toHaveValue('1');
    await page.locator('#achips a[data-clear="all"]').click();
    await expect(page.locator('#grid .card')).toHaveCount(8);
    await expect(page.locator('#fcount')).toBeHidden();
    // "unassigned" filter + clear all from the sheet
    await page.locator('#filter-open').click();
    await page.locator('#loc').selectOption('none');
    await expect(page.locator('#grid .card .card-title')).toHaveText(['Cast iron pan']);
    await page.locator('#filter-clear').click();
    await expect(page.locator('#grid .card')).toHaveCount(8);
    // sort by quantity
    await page.locator('#filter-open').click();
    await page.locator('#sort').selectOption('qty');
    await page.locator('#filter-close').click();
    await expect(page.locator('#grid .card .card-title').nth(1)).toHaveText('Headlamp');   // 5 each, then by name
    await expect(page.locator('#grid .card .card-title').nth(0)).toHaveText('HDMI cables');
  });

  test('quantity +/- on a card', async ({ app, page }) => {
    await app.go('/');
    const card = page.locator('.card[data-id="8"]');
    const tap = async (d: string) => Promise.all([page.waitForResponse(r => r.url().endsWith('/item/8/qty')), card.locator(`[data-delta="${d}"]`).click()]);
    await tap('1');
    await expect(card.locator('.qval')).toHaveText('6');
    await tap('-1');
    await tap('-1');
    await expect(card.locator('.qval')).toHaveText('4');
    await page.reload({ waitUntil: 'networkidle' });
    await expect(page.locator('.card[data-id="8"] .qval')).toHaveText('4');
  });
});
