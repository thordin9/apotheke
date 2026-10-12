import { test, expect } from './fixtures';

test.describe('theme switch', () => {
  test('Auto / Light / Dark is applied instantly and persists @smoke', async ({ app, page }, info) => {
    await app.go('/');
    const scheme = info.project.use.colorScheme;
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'auto');
    const bgAuto = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
    expect(bgAuto === 'rgb(17, 17, 19)').toBe(scheme === 'dark');   // auto follows the OS scheme

    for (const [theme, bg, meta] of [['dark', 'rgb(17, 17, 19)', '#111113'], ['light', 'rgb(245, 245, 247)', null], ['auto', null, null]] as const) {
      await app.menu();
      await page.locator(`.seg-btn[value="${theme}"]`).click();
      await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
      await expect(page.locator(`.seg-btn[value="${theme}"]`)).toHaveAttribute('aria-checked', 'true');
      if (bg) expect(await page.evaluate(() => getComputedStyle(document.body).backgroundColor)).toBe(bg);
      await page.keyboard.press('Escape');
      await page.reload({ waitUntil: 'networkidle' });
      await expect(page.locator('html'), `${theme} survives a reload (server-rendered)`).toHaveAttribute('data-theme', theme);
      if (meta) await expect(page.locator('meta[name=theme-color]').first()).toHaveAttribute('content', meta);
      await app.go('/locations');
      await expect(page.locator('html'), `${theme} on other pages`).toHaveAttribute('data-theme', theme);
      await app.go('/');
    }
    const cookies = await page.context().cookies();
    expect(cookies.find(c => c.name === 'theme')?.value).toBe('auto');
  });

  test('works without JavaScript (form POST)', async ({ browser, server }) => {
    const ctx = await browser.newContext({ javaScriptEnabled: false, baseURL: server.base });
    const p = await ctx.newPage();
    await p.goto('/');
    await p.locator('#menu-btn').click();
    await p.locator('.seg-btn[value="dark"]').click();
    await expect(p.locator('html')).toHaveAttribute('data-theme', 'dark');
    await ctx.close();
  });
});
