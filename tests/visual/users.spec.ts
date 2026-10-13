import { test, expect } from './fixtures';

test.describe('users & roles', () => {
  test('first admin, add a viewer, the viewer can only look @smoke', async ({ app, page, browser }) => {
    await app.go('/settings/security');
    await expect(page.locator('#pw-state')).toContainText('Accounts are off');
    await page.locator('#pw-user').fill('Stathis');
    await page.locator('#pw-new').fill('admin-secret');
    await page.locator('#pw-confirm').fill('admin-secret');
    await page.locator('#pw-save').click();
    await expect(page.locator('.toast')).toHaveText('Password saved');
    await expect(page.locator('#me-name')).toHaveText('Stathis');
    // add a viewer
    await page.locator('#nu-name').fill('guest');
    await page.locator('#nu-role').selectOption('viewer');
    await page.locator('#nu-pw').fill('guest-password');
    await page.locator('#nu-pw-confirm').fill('guest-password');
    await page.locator('#nu-save').click();
    await expect(page.locator('.toast')).toHaveText('User added');
    await expect(page.locator('#userlist .user')).toHaveCount(2);
    await expect(page.locator('#userlist')).toContainText('Viewer');
    await app.noOverflow();
    await app.tapTargets();
    // the admin changes something: the history names them
    await app.go('/item/1');
    await page.locator('.qty-lg .qbtn[data-delta="1"]').click();
    await expect(page.locator('.qty-lg .qval')).toHaveText('3');
    // the viewer
    const ctx = await browser.newContext({ baseURL: app.base });
    const v = await ctx.newPage();
    await v.goto('/');
    await expect(v).toHaveURL(/\/login/);
    await v.locator('#login-user').fill('GUEST');
    await v.locator('#login-pw').fill('guest-password');
    await v.locator('#login-go').click();
    await expect(v.locator('#grid .card')).toHaveCount(8);
    await expect(v.locator('body')).toHaveClass(/role-viewer/);
    await expect(v.locator('#fab')).toBeHidden();
    await v.locator('#menu-btn').click();
    await expect(v.locator('#menu-settings')).toHaveCount(0);
    await expect(v.locator('#menu-account')).toContainText('guest');
    await v.goto('/item/1');
    await expect(v.locator('.actionbar')).toBeHidden();
    await expect(v.locator('#history')).toContainText('· Stathis');
    expect((await v.request.get('/settings')).status()).toBe(403);
    expect((await v.request.get('/new')).status()).toBe(403);
    // own password
    await v.goto('/account');
    await expect(v.locator('#acct-role')).toHaveText('Viewer');
    await v.locator('#acct-current').fill('guest-password');
    await v.locator('#acct-new').fill('guest-password-2');
    await v.locator('#acct-confirm').fill('guest-password-2');
    await v.locator('#acct-save').click();
    await expect(v.locator('.toast')).toHaveText('Password changed');
    await ctx.close();
    // the admin promotes the viewer, then deletes the account
    await app.go('/settings/security');
    const id = (app.sql("SELECT id FROM users WHERE username = 'guest'")[0] as { id: number }).id;
    await page.locator(`#user-${id} summary`).click();
    await page.locator(`#role-${id}`).selectOption('editor');
    await page.locator(`#role-save-${id}`).click();
    await expect(page.locator('.toast')).toHaveText('Role changed');
    await page.locator(`#user-${id} summary`).click();
    page.once('dialog', d => d.accept());
    await page.locator(`#udel-${id}`).click();
    await expect(page.locator('.toast')).toHaveText('User deleted');
    await expect(page.locator('#userlist .user')).toHaveCount(1);
  });
});
