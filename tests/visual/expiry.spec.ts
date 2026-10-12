import { test, expect } from './fixtures';

// The app clock is frozen at 2026-06-15 (Europe/Athens); "Expiring soon" is the default 30 days.
test.describe('expiry / warranty', () => {
  test('set a date, badges, tiles, filters, history @smoke', async ({ app, page }) => {
    await app.go('/item/3/edit');
    // the section may already be open (item 3 has a value): open it without toggling
    await page.locator('.more-fields').evaluate(d => { (d as HTMLDetailsElement).open = true; });
    await page.locator('#expires_on').fill('2026-06-20');
    await app.tapTargets();
    await page.locator('#save').click();
    await expect(page).toHaveURL(/\/item\/3$/);
    await expect(page.locator('#facts')).toContainText('Expires2026-06-20');
    await expect(page.locator('#facts .expbadge')).toHaveText('Expires in 5 days');
    await expect(page.locator('#history')).toContainText('Expires');
    await app.noOverflow();

    app.sql("UPDATE items SET expires_on = '2026-06-01' WHERE id = 7");
    app.sql("UPDATE items SET expires_on = '2027-06-01' WHERE id = 1");   // far away: no badge
    await app.go('/');
    await expect(page.locator('.card[data-id="3"] .expbadge')).toHaveText('Expires in 5 days');
    await expect(page.locator('.card[data-id="7"] .expbadge')).toHaveText('Expired');
    await expect(page.locator('.card[data-id="1"] .expbadge')).toHaveCount(0);
    await expect(page.locator('#tile-expiring b')).toHaveText('1');
    await expect(page.locator('#tile-expired b')).toHaveText('1');
    await expect(page.locator('#tile-expired')).toHaveClass(/has-expired/);
    await app.noOverflow();
    await app.tapTargets();

    await page.locator('#tile-expired').click();
    await expect(page.locator('#grid .card')).toHaveCount(1);
    await expect(page.locator('#grid .card')).toHaveAttribute('data-id', '7');
    await expect(page.locator('#achips a[data-clear="exp"]')).toContainText('Expired');
    await expect(page).toHaveURL(/exp=expired/);
    await page.locator('#achips a[data-clear="exp"]').click();
    await expect(page.locator('#grid .card')).toHaveCount(8);

    await page.locator('#filter-open').click();
    await page.locator('#exp').selectOption('soon');
    await page.locator('#filter-apply').click();
    await expect(page.locator('#grid .card')).toHaveCount(1);
    await expect(page.locator('#grid .card')).toHaveAttribute('data-id', '3');
  });

  test('without JavaScript the filter links still work', async ({ app, page }) => {
    app.sql("UPDATE items SET expires_on = '2026-06-01' WHERE id IN (2, 4)");
    await app.go('/?exp=expired');
    await expect(page.locator('#grid .card')).toHaveCount(2);
    await expect(page.locator('#exp')).toHaveValue('expired');
  });
});
