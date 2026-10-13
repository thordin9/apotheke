import { test, expect } from './fixtures';
import type { Page, Browser } from '@playwright/test';

/** Turn accounts on with the admin "root". */
async function accountsOn(page: Page, app: any) {
  await app.go('/settings/security');
  await page.locator('#pw-user').fill('root');
  await page.locator('#pw-new').fill('root-password');
  await page.locator('#pw-confirm').fill('root-password');
  await page.locator('#pw-save').click();
  await expect(page.locator('.toast')).toHaveText('Password saved');
}
async function setSwitch(page: Page, id: string, on: boolean) {
  const sw = page.locator(`#s-${id}`);
  if ((await sw.isChecked()) !== on) await page.locator(`label[for="s-${id}"]`).click();
}
async function saveOptions(page: Page, o: { guestOff: boolean; regOff: boolean; approval?: boolean; role?: string }) {
  await page.goto('/settings/security');
  await setSwitch(page, 'auth_guest_disabled', o.guestOff);
  await setSwitch(page, 'auth_register_disabled', o.regOff);
  await setSwitch(page, 'auth_register_approval', !!o.approval);
  if (o.role) await page.locator('#s-auth_register_role').selectOption(o.role);
  await page.locator('#set-save').click();
  await expect(page.locator('.toast')).toHaveText('Settings saved');
}
const fresh = async (browser: Browser, app: any) => (await browser.newContext({ baseURL: app.base })).newPage();

test.describe('roles & sign-in options', () => {
  test('roles editor: custom role with granular permissions @smoke', async ({ app, page, browser }) => {
    await accountsOn(page, app);
    await expect(page.locator('#rolelist .rolecard')).toHaveCount(4);
    await expect(page.locator('#perm-admin-users')).toBeDisabled();
    await page.locator('#nr-name').fill('Lender');
    await page.locator('#perm-new-lend').check();
    await page.locator('#perm-new-labels').check();
    await app.tapTargets();
    await app.noOverflow();
    await page.locator('#nr-save').click();
    await expect(page.locator('.toast')).toHaveText('Role added');
    await expect(page.locator('#rc-lender')).toContainText('view, lend, labels');
    await page.locator('#nu-name').fill('lea');
    await page.locator('#nu-role').selectOption('lender');
    await page.locator('#nu-pw').fill('lea-password');
    await page.locator('#nu-pw-confirm').fill('lea-password');
    await page.locator('#nu-save').click();
    await expect(page.locator('.toast')).toHaveText('User added');
    const p = await fresh(browser, app);
    await p.goto('/login');
    await p.locator('#login-user').fill('lea');
    await p.locator('#login-pw').fill('lea-password');
    await p.locator('#login-go').click();
    await expect(p.locator('body')).toHaveClass(/no-edit/);
    await expect(p.locator('#fab')).toBeHidden();
    await p.locator('#menu-btn').click();
    await expect(p.locator('#menu-labels')).toBeVisible();
    await expect(p.locator('#menu-export')).toHaveCount(0);
    await expect(p.locator('#menu-settings')).toHaveCount(0);
    await p.goto('/item/1');
    await expect(p.locator('#lending')).toBeVisible();
    await expect(p.locator('.actionbar .btn-primary')).toBeHidden();
    const r = await p.goto('/item/1/edit');
    expect(r!.status()).toBe(403);
    await expect(p.locator('main')).toContainText('“Edit” permission');
    // the admin adds "edit": the lender may now edit
    await page.goto('/settings/security#rc-lender');
    await page.locator('#rc-lender summary').click();
    await page.locator('#perm-lender-edit').check();
    await page.locator('#role-save-perm-lender').click();
    await expect(page.locator('.toast')).toHaveText('Role saved');
    expect((await p.goto('/item/1/edit'))!.status()).toBe(200);
    // delete the role: lea becomes a viewer
    await page.locator('#rc-lender summary').click();
    page.once('dialog', d => d.accept());
    await page.locator('#role-del-lender').click();
    await expect(page.locator('.toast')).toHaveText('Role deleted');
    await expect(page.locator('#rc-lender')).toHaveCount(0);
    expect((await p.goto('/item/1/edit'))!.status()).toBe(403);
  });

  test('guest browsing: off by default, then look-only guests', async ({ app, page, browser }) => {
    await accountsOn(page, app);
    await expect(page.locator('#s-auth_guest_disabled')).toBeChecked();
    await expect(page.locator('#s-auth_register_disabled')).toBeChecked();
    const g = await fresh(browser, app);
    await g.goto('/');
    await expect(g).toHaveURL(/\/login$/);
    await expect(g.locator('#login-guest')).toHaveCount(0);
    await saveOptions(page, { guestOff: false, regOff: true });
    await g.goto('/login');
    await g.locator('#login-guest').click();
    await expect(g.locator('#grid .card')).toHaveCount(8);
    await expect(g.locator('body')).toHaveClass(/is-guest/);
    await expect(g.locator('#fab')).toBeHidden();
    await g.locator('#menu-btn').click();
    await expect(g.locator('#menu-login')).toBeVisible();
    await g.goto('/item/1/edit');
    await expect(g).toHaveURL(/\/login$/);
  });

  test('self-registration with admin approval', async ({ app, page, browser }) => {
    await accountsOn(page, app);
    await saveOptions(page, { guestOff: true, regOff: false, approval: true, role: 'editor' });
    const n = await fresh(browser, app);
    await n.goto('/login');
    await n.locator('#login-register').click();
    await expect(n).toHaveURL(/\/register$/);
    await expect(n.locator('#reg-hint')).toContainText('Editor');
    await n.locator('#reg-user').fill('newbie');
    await n.locator('#reg-pw').fill('newbie-password');
    await n.locator('#reg-confirm').fill('newbie-password');
    await n.locator('#reg-go').click();
    await expect(n.locator('#login-pending')).toBeVisible();
    await n.locator('#login-user').fill('newbie');
    await n.locator('#login-pw').fill('newbie-password');
    await n.locator('#login-go').click();
    await expect(n.locator('.err')).toContainText('approval');
    await page.goto('/settings/security');
    const id = app.sql<{ id: number }>("SELECT id FROM users WHERE username = 'newbie'")[0].id;
    await expect(page.locator(`#pending-${id}`)).toBeVisible();
    await page.locator(`#user-${id} summary`).click();
    await page.locator(`#approve-${id}`).click();
    await expect(page.locator('.toast')).toHaveText('Account approved');
    await n.locator('#login-pw').fill('newbie-password');
    await n.locator('#login-go').click();
    await expect(n.locator('#fab')).toBeVisible();
  });

  test('accounts off: everyone is an anonymous admin', async ({ app, page }) => {
    await app.go('/settings/security');
    await expect(page.locator('#pw-state')).toContainText('anonymous guest with admin rights');
    await app.go('/backup');
    await expect(page.locator('main')).toBeVisible();
    expect((await page.goto('/register'))!.status()).toBe(404);
  });
});
