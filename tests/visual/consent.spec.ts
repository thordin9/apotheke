import { test, expect } from './fixtures';

/* Cookie consent (docs/security.md): no cookie at all until Accept; Accept sets apotheke_consent=v1 and reloads. */
test.describe('cookie consent', () => {
  test('no cookie before Accept, then the app as before @smoke', async ({ browser, server }) => {
    const ctx = await browser.newContext({ baseURL: server.base, storageState: { cookies: [], origins: [] } });
    const p = await ctx.newPage();
    await p.goto('/', { waitUntil: 'networkidle' });
    await expect(p.locator('#consent')).toBeVisible();
    await p.locator('#menu-btn').click();
    await p.locator('.seg-btn[value="dark"]').click();
    await expect(p.locator('html')).toHaveAttribute('data-theme', 'dark');
    expect(await ctx.cookies(), 'nothing set, by the server or the page').toEqual([]);
    await p.keyboard.press('Escape');
    await p.locator('#consent-accept').click();
    await expect(p.locator('#consent')).toHaveCount(0);
    const consent = (await ctx.cookies()).find(c => c.name === 'apotheke_consent');
    expect(consent?.value).toBe('v1');
    expect(consent?.sameSite).toBe('Lax');
    expect(consent?.httpOnly).toBe(false);
    expect((await ctx.cookies()).map(c => c.name)).toContain('apothekesid');
    await ctx.close();
  });
});
