import { test, expect } from './fixtures';

const loaded = (img: HTMLImageElement) => img.complete && img.naturalWidth > 0;

test.describe('branding', () => {
  test('header logo, favicon links and manifest @smoke', async ({ app, page }) => {
    await app.go('/');
    const logo = page.locator('header h1.brand img.brand-logo');
    await expect(logo).toBeVisible();
    expect(await logo.evaluate(loaded)).toBe(true);
    const box = await logo.boundingBox();
    expect(box!.width).toBeGreaterThanOrEqual(24);
    await expect(page.locator('header h1.brand')).toContainText('Apotheke');
    await expect(page.locator('link[rel=icon][type="image/svg+xml"]')).toHaveCount(1);
    await expect(page.locator('link[rel=icon][href="/favicon.ico"]')).toHaveCount(1);
    const man = await (await page.request.get('/manifest.webmanifest')).json();
    expect(man.theme_color).toBe('#C8502D');
    expect(man.icons.some((i: { purpose?: string }) => i.purpose === 'maskable')).toBe(true);
  });
});

