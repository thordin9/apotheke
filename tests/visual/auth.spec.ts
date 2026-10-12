import { test, expect } from './fixtures';

test.use({ appPassword: 'correct horse' });

test.describe('password mode', () => {
  test('login screen shows the logo', async ({ page }) => {
    await page.goto('/login');
    const logo = page.locator('.login .brand-lg img.brand-logo');
    await expect(logo).toBeVisible();
    expect(await logo.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth > 0)).toBe(true);
  });

  test('login, wrong password, logout @smoke', async ({ app, page }) => {
    await page.goto('/item/1');
    await expect(page).toHaveURL(/\/login/);
    await expect(page.locator('input[name=password]')).toBeFocused();
    await page.locator('input[name=password]').fill('nope');
    await page.locator('button.btn-primary').click();
    await expect(page.locator('.err')).toBeVisible();
    await page.locator('input[name=password]').fill('correct horse');
    await page.locator('button.btn-primary').click();
    await expect(page.locator('#grid .card')).toHaveCount(8);
    await app.tapTargets();
    await app.menu();
    await page.locator('#menu button', { hasText: 'Sign out' }).click();
    await expect(page).toHaveURL(/\/login/);
    await page.goto('/');
    await expect(page).toHaveURL(/\/login/);
    expect((await page.request.get('/export.csv')).url()).toMatch(/\/login/);
  });
});

