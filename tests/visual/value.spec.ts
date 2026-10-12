import { test, expect } from './fixtures';

const money = (cents: number) => (cents / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' EUR';

test.describe('purchase price and value totals', () => {
  test('overview totals, sort by value, editing a price @smoke', async ({ app, page }) => {
    const total = () => Number(app.sql('SELECT COALESCE(SUM(value_cents), 0) AS t FROM items')[0].t);
    const before = total();
    expect(before).toBeGreaterThan(0);
    await app.go('/');
    await expect(page.locator('#value-total')).toHaveText(money(before));
    await expect(page.locator('#st-value .hbars').first().locator('li')).not.toHaveCount(0);
    await app.noOverflow();
    await app.tapTargets();

    // sort by value through the filter sheet: the most expensive item comes first
    const top = app.sql<{ id: number }>('SELECT id FROM items WHERE value_cents IS NOT NULL ORDER BY value_cents DESC, id LIMIT 1')[0];
    await page.locator('#filter-open').click();
    await page.locator('#sort').selectOption('value');
    await page.locator('#filter-apply').click();
    await expect(page).toHaveURL(/sort=value/);
    await expect(page.locator('#grid .card').first()).toHaveAttribute('data-id', String(top.id));
    await expect(page.locator('#achips')).toContainText('Value (highest first)');

    // give an unpriced item a price (comma decimal) and see the total follow
    const unpriced = app.sql<{ id: number }>('SELECT id FROM items WHERE value_cents IS NULL ORDER BY id LIMIT 1')[0];
    await app.go(`/item/${unpriced.id}/edit`);
    await page.locator('.more-fields').evaluate(d => { (d as HTMLDetailsElement).open = true; });
    await expect(page.locator('label[for], .field').filter({ hasText: 'Purchase price' }).first()).toContainText('EUR');
    await page.locator('#value').fill('1000,50');
    await page.locator('#purchase_date').fill('2025-12-24');
    await page.locator('#save').click();
    await expect(page.locator('#facts')).toContainText('Price1,000.50 EUR');
    await expect(page.locator('#facts')).toContainText('Purchased2025-12-24');
    await app.go('/');
    await expect(page.locator('#value-total')).toHaveText(money(before + 100050));
    await app.go('/?sort=value');
    await expect(page.locator('#grid .card').first()).toHaveAttribute('data-id', String(unpriced.id));
  });
});
