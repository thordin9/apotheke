import { test, expect } from './fixtures';

test.use({ serviceWorkers: 'allow' });

test.describe('PWA', () => {
  test('installable manifest + service worker; offline shows saved pages, then the offline page @smoke', async ({ app, page, context, errors }) => {
    const man = await (await page.request.get('/manifest.webmanifest')).json();
    expect(man.display).toBe('standalone');
    expect(man.shortcuts.map((s: { url: string }) => s.url)).toEqual(['/new', '/lent']);

    await app.go('/');
    await page.evaluate(() => navigator.serviceWorker.ready.then(() => true));
    await page.reload();   // now controlled by the worker
    await expect.poll(() => page.evaluate(() => !!navigator.serviceWorker.controller)).toBe(true);
    const shell = await page.evaluate(async () => (await caches.keys()).filter(k => k.startsWith('apotheke-shell-')).length);
    expect(shell, 'one versioned shell cache').toBe(1);
    await app.go('/item/1');
    await expect(page.locator('.item-name')).toHaveText('Cordless drill');
    await expect.poll(() => page.evaluate(async () => (await (await caches.open('apotheke-pages')).keys()).map(r => new URL(r.url).pathname).sort()))
      .toEqual(['/', '/item/1']);

    await context.setOffline(true);
    await page.goto('/item/1');
    await expect(page.locator('.item-name'), 'last saved copy of the item').toHaveText('Cordless drill');
    await expect(page.locator('#offline-banner')).toHaveText('Offline – showing the last saved copy');
    await page.goto('/');
    await expect(page.locator('#grid .card')).toHaveCount(8);
    await page.goto('/item/2');   // never opened → offline page
    await expect(page.locator('.item-name')).toHaveText('You’re offline');
    await expect(page.locator('#offline-pages a')).toHaveCount(2);
    await expect(page.locator('#offline-pages a').first()).toContainText('Cordless drill');
    await app.noOverflow();
    await app.tapTargets();
    await page.goto('/backup');   // never cached
    await expect(page.locator('.item-name')).toHaveText('You’re offline');
    await page.locator('#offline-pages a', { hasText: 'All items' }).click();
    await expect(page.locator('#grid .card')).toHaveCount(8);

    await context.setOffline(false);
    // photos that were never loaded can't be shown offline; that's expected, anything else is not
    const rest = errors.filter(e => !/ERR_INTERNET_DISCONNECTED|WebKit encountered an internal error/.test(e));
    errors.length = 0;
    errors.push(...rest);
    await page.goto('/item/2');
    await expect(page.locator('.item-name')).toHaveText('HDMI cables');
    await expect(page.locator('#offline-banner')).toHaveCount(0);
  });
});
