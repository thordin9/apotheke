import { expect, test, type BrowserContext, type Page } from '@playwright/test';

const MAX_AGE = 15552000;

async function stored(page: Page) {
    return page.evaluate(async () => {
        const idb = typeof indexedDB.databases === 'function' ? (await indexedDB.databases()).length : 0;
        const cacheNames = typeof caches === 'undefined' ? 0 : (await caches.keys()).length;
        return {
            cookie: document.cookie,
            local: localStorage.length,
            session: sessionStorage.length,
            idb,
            caches: cacheNames,
        };
    });
}

async function expectNothingStored(page: Page, context: BrowserContext) {
    const bag = await stored(page);
    expect(bag.cookie, 'document.cookie').toBe('');
    expect(bag.local, 'localStorage').toBe(0);
    expect(bag.session, 'sessionStorage').toBe(0);
    expect(bag.idb, 'IndexedDB').toBe(0);
    expect(bag.caches, 'Cache storage').toBe(0);
    expect(await context.cookies()).toEqual([]);
}

test('stores nothing until Accept, then one consent cookie', async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    for (const path of ['/', '/privacy/', '/app/']) {
        await page.goto(path);
        await expect(page.locator('#consent')).toBeVisible();
        await expectNothingStored(page, context);
    }

    await page.goto('/');
    await page.getByRole('button', { name: 'Decline' }).click();
    await expect(page.locator('#consent')).toBeHidden();
    await expectNothingStored(page, context);
    await page.reload();
    await expect(page.locator('#consent')).toBeVisible();
    await expectNothingStored(page, context);

    await page.goto('/app/');
    await expect(page.locator('#sign-in-submit')).toBeDisabled();
    let sessionHits = 0;
    page.on('request', (req) => {
        if (new URL(req.url()).pathname === '/api/v1/session') sessionHits += 1;
    });
    expect(sessionHits).toBe(0);

    await page.route('**/api/v1/session', (route) => {
        if (route.request().method() === 'GET') {
            return route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ csrf_token: 'fresh-token', signed_in: false }),
            });
        }
        return route.fulfill({
            status: 403,
            contentType: 'application/problem+json',
            body: JSON.stringify({ code: 'consent_required', error: 'consent_required' }),
        });
    });

    await page.getByRole('button', { name: 'Accept' }).click();
    await expect(page.locator('#consent')).toBeHidden();
    await expect(page.locator('#sign-in-submit')).toBeEnabled();
    expect(sessionHits).toBe(1);

    const cookies = await context.cookies();
    expect(cookies).toHaveLength(1);
    const cookie = cookies[0];
    expect(cookie.name).toBe('apotheke_consent');
    expect(cookie.value).toBe('v1');
    expect(cookie.path).toBe('/');
    expect(cookie.sameSite).toBe('Lax');
    expect(cookie.httpOnly).toBe(false);
    expect(cookie.secure).toBe(true);
    expect(cookie.domain).toBe('127.0.0.1');
    const now = Date.now() / 1000;
    expect(cookie.expires).toBeGreaterThan(now + MAX_AGE - 120);
    expect(cookie.expires).toBeLessThan(now + MAX_AGE + 120);

    const after = await stored(page);
    expect(after.local).toBe(0);
    expect(after.session).toBe(0);
    expect(after.idb).toBe(0);
    expect(after.caches).toBe(0);

    await page.goto('/privacy/');
    await expect(page.locator('#consent')).toBeHidden();
    expect(await context.cookies()).toHaveLength(1);
    await context.close();
});

test('a value other than v1 is not consent', async ({ browser }) => {
    const context = await browser.newContext();
    await context.addCookies([{
        name: 'apotheke_consent',
        value: 'v0',
        url: 'http://127.0.0.1:4175/',
    }]);
    const page = await context.newPage();
    await page.goto('/');
    await expect(page.locator('#consent')).toBeVisible();
    await context.close();
});

test('the notice fits a phone in dark mode', async ({ browser }) => {
    const context = await browser.newContext({
        viewport: { width: 375, height: 812 },
        colorScheme: 'dark',
    });
    const page = await context.newPage();
    await page.goto('/');
    const banner = page.locator('#consent');
    await expect(banner).toBeVisible();
    await expect(page.getByRole('button', { name: 'Accept' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Decline' })).toBeVisible();
    const box = await banner.boundingBox();
    expect(box).not.toBeNull();
    expect(box!.x).toBeGreaterThanOrEqual(-1);
    expect(box!.x + box!.width).toBeLessThanOrEqual(376);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1);
    expect(overflow).toBe(true);
    await page.getByRole('button', { name: 'Decline' }).focus();
    await page.keyboard.press('Tab');
    await expect(page.getByRole('button', { name: 'Accept' })).toBeFocused();
    await page.keyboard.press('Tab');
    const stillInside = await page.evaluate(() => {
        const region = document.getElementById('consent');
        return region ? region.contains(document.activeElement) : false;
    });
    expect(stillInside).toBe(false);
    await context.close();
});
