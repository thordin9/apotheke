import { test, expect } from './fixtures';

test('item history and recent activity @smoke', async ({ app, page }) => {
  await app.go('/item/4/edit');
  await page.locator('#name').fill('Πρίζα 6x');
  await page.locator('#locsel').selectOption('3');
  await page.locator('.stepper [data-step="1"]').click();
  await page.locator('#save').click();
  await expect(page).toHaveURL(/\/item\/4$/);
  const hist = page.locator('#history .act');
  await expect(hist).toHaveCount(4);
  await expect(hist.nth(0)).toHaveClass(/act-quantity/);
  await expect(hist.nth(0)).toContainText('Quantity 3 → 4');
  await expect(hist.nth(1)).toContainText('Moved from Κουτί 5 to Attic');
  await expect(hist.nth(2)).toContainText('“Πρίζα πολύπριζο” → “Πρίζα 6x”');
  await expect(hist.nth(3)).toContainText('Created in Κουτί 5');
  await expect(hist.nth(3).locator('time')).toHaveText('15 Jun 2026, 12:00');
  await app.noOverflow();
  // recent activity, newest first; deleted items stay listed
  await app.go('/item/5');
  page.once('dialog', d => d.accept());
  await page.getByRole('button', { name: 'Delete item' }).click();
  await app.menu();
  await page.locator('#menu a[href="/activity"]').click();
  const acts = page.locator('#activity .act');
  await expect(acts.first()).toHaveClass(/act-deleted/);
  await expect(acts.first().locator('.act-gone')).toHaveText('Cast iron pan');
  await expect(acts.nth(1).locator('a.act-item')).toHaveText('Πρίζα 6x');
  await acts.nth(1).locator('a.act-item').click();
  await expect(page).toHaveURL(/\/item\/4$/);
  await app.go('/activity?item=5');
  await expect(page.locator('#activity .act')).toHaveCount(2);
  await app.tapTargets();
});
