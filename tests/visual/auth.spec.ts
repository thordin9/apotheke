import { test, expect } from './fixtures';

test.use({ appPassword: 'correct horse' });

test.describe('sign-in (APP_PASSWORD = account admin)', () => {
  test('login screen shows the logo', async ({ page }) => {
    await page.goto('/login');
    const logo = page.locator('.login .brand-lg img.brand-logo');
    await expect(logo).toBeVisible();
    expect(await logo.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth > 0)).toBe(true);
  });

  test('login, wrong password, logout @smoke', async ({ app, page }) => {
    await page.goto('/item/1');
    await expect(page).toHaveURL(/\/login/);
    await expect(page.locator('#login-user')).toBeFocused();
    await page.locator('#login-user').fill('admin');
    await page.locator('#login-pw').fill('nope');
    await page.locator('#login-go').click();
    await expect(page.locator('.err')).toHaveText('Wrong user name or password.');
    await expect(page.locator('#login-user')).toHaveValue('admin');
    await expect(page.locator('#login-pw')).toBeFocused();
    await page.locator('#login-pw').fill('correct horse');
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

