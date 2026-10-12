import { test, expect } from './fixtures';

test.describe('lending', () => {
  test('lend, badges, filters, Lent page, return @smoke', async ({ app, page }) => {
    await app.go('/item/3');
    await page.locator('#lending > summary').click();
    await page.locator('#lent_to').fill('Maria');
    await page.locator('#lent_due').fill('2000-01-01');   // in the past → overdue (but not before the lent date)
    await page.locator('#lent_at').fill('1999-12-20');
    await app.tapTargets();
    await page.locator('#lend-save').click();
    await expect(page.locator('#lending')).toHaveClass(/is-overdue/);
    await expect(page.locator('#lending h2')).toHaveText('Overdue');
    await expect(page.locator('.lendinfo')).toContainText('With Maria since 1999-12-20 · due back 2000-01-01');
    await expect(page.locator('#history')).toContainText('Lent to Maria');
    await app.noOverflow();

    // a second item, out without a due date
    await app.go('/item/7');
    await page.locator('#lending > summary').click();
    await page.locator('#lent_to').fill('Nikos');
    await page.locator('#lend-save').click();
    await expect(page.locator('#lending h2')).toHaveText('Lent out');

    await app.go('/');
    await expect(page.locator('.card[data-id="3"] .lendbadge')).toHaveText('Overdue · Maria');
    await expect(page.locator('.card[data-id="7"] .lendbadge')).toHaveText('Lent · Nikos');
    await page.locator('#filter-open').click();
    await page.locator('#lend').selectOption('overdue');
    await page.locator('#filter-apply').click();
    await expect(page.locator('#grid .card')).toHaveCount(1);
    await page.locator('#achips a[data-clear="lend"]').click();
    await expect(page.locator('#grid .card')).toHaveCount(8);
    await app.go('/?lend=out');
    await expect(page.locator('#grid .card')).toHaveCount(2);

    await app.menu();
    await page.locator('#menu a[href="/lent"]').click();
    await expect(page).toHaveURL(/\/lent$/);
    await expect(page.locator('#lent-summary')).toHaveText('2 items out · 1 overdue');
    await expect(page.locator('#lentlist .lent b')).toHaveText(['Camping stove', 'Screwdriver set']);
    await app.noOverflow();
    await app.tapTargets();
    await page.getByRole('button', { name: 'Mark Camping stove as returned' }).click();
    await expect(page).toHaveURL(/\/lent$/);
    await expect(page.locator('#lentlist .lent')).toHaveCount(1);
    expect(app.sql<{ lent_to: string | null }>('SELECT lent_to FROM items WHERE id = 3')[0].lent_to).toBeNull();

    await app.go('/item/7');
    await page.locator('#lend-return').click();
    await expect(page.locator('#lending > summary')).toHaveText('Lend this item');
    await expect(page.locator('#history')).toContainText('Returned by Nikos');
  });
});
