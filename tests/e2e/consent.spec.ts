import { expect, test, type BrowserContext, type Page, type Route } from '@playwright/test';
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

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

function watchSession(page: Page) {
    const hits: string[] = [];
    page.on('request', (req) => {
        if (new URL(req.url()).pathname === '/api/v1/session') hits.push(req.method() + ' ' + req.url());
    });
    return hits;
}

test('stores nothing until Accept, then one consent cookie', async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    const hits = watchSession(page);
    for (const path of ['/', '/privacy/', '/app/', '/app/sign-in', '/app/does-not-exist']) {
        await page.goto(path);
        await expect(page.locator('#consent')).toBeVisible();
        await expectNothingStored(page, context);
        expect(hits, 'no session request before Accept on ' + path).toEqual([]);
    }

    await page.goto('/');
    await page.getByRole('button', { name: 'Decline' }).click();
    await expect(page.locator('#consent')).toBeHidden();
    await expect(page.locator('#main')).toBeFocused();
    await expectNothingStored(page, context);
    expect(hits).toEqual([]);
    await page.reload();
    await expect(page.locator('#consent')).toBeVisible();
    await expectNothingStored(page, context);

    await page.goto('/app/sign-in');
    await expect(page.locator('#sign-in-note')).toHaveText('Accept cookies to sign in.');
    await expect(page.locator('#sign-in-submit')).toBeDisabled();
    expect(hits).toEqual([]);

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
    await expect(page.locator('#main')).toBeFocused();
    await expect(page.locator('#sign-in-note')).toHaveText('You can sign in.');
    await expect(page.locator('#sign-in-home')).toBeHidden();
    await expect(page.locator('#sign-in-submit')).toBeEnabled();
    expect(hits).toHaveLength(1);

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

async function acceptOnApp(page: Page, fulfill: (route: Route) => Promise<void>) {
    const hits = watchSession(page);
    await page.goto('/app/sign-in');
    await expect(page.locator('#consent')).toBeVisible();
    await expect(page.locator('#sign-in-note')).toHaveText('Accept cookies to sign in.');
    await expect(page.locator('#sign-in-submit')).toBeDisabled();
    expect(hits, 'no session request before Accept').toEqual([]);
    await page.route('**/api/v1/session', fulfill);
    await page.getByRole('button', { name: 'Accept' }).click();
    await expect(page.locator('#consent')).toBeHidden();
    await expect(page.locator('#sign-in-note')).toHaveText('Sign-in is coming soon.');
    await expect(page.locator('#sign-in-home')).toBeVisible();
    await expect(page.locator('#sign-in-submit')).toBeDisabled();
    expect(hits).toHaveLength(1);
}

test('an empty csrf token leaves sign-in off', async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    await acceptOnApp(page, (route) => route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ csrf_token: '' }),
    }));
    await context.close();
});

test('a non-string csrf token leaves sign-in off', async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    await acceptOnApp(page, (route) => route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ csrf_token: 1 }),
    }));
    await context.close();
});

test('a non-2xx session response leaves sign-in off', async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    await acceptOnApp(page, (route) => route.fulfill({
        status: 503,
        contentType: 'application/problem+json',
        body: JSON.stringify({ code: 'unavailable' }),
    }));
    await context.close();
});

test('a failed session fetch leaves sign-in off', async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    await acceptOnApp(page, (route) => route.abort('failed'));
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
    const hits = watchSession(page);
    await page.goto('/app/sign-in');
    await expect(page.locator('#consent')).toBeVisible();
    await expect(page.locator('#sign-in-submit')).toBeDisabled();
    expect(hits).toEqual([]);
    await context.close();
});

test('without JavaScript the notice stays hidden and stores nothing', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();
    await page.goto('/');
    await expect(page.locator('#consent')).toBeHidden();
    await expect(page.locator('h1')).toBeVisible();
    expect(await context.cookies()).toEqual([]);
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

// Page-view counting (frontend/src/analytics.js). The tracker skips loopback hosts, so these
// tests open the site under a test name that Chromium resolves to the same 127.0.0.1 server.
// The analytics host is never contacted: page.route answers /cache and /collect, and
// only requests the CSP lets through reach page.route.
const ANALYTICS = 'https://analytics.services.webapp.gr';
const TEST_HOST = 'apotheke.test';

function trackerChunk() {
    const dir = join(import.meta.dirname, '../../frontend/dist/assets');
    const found = readdirSync(dir).filter((f) => f.endsWith('.js') && readFileSync(join(dir, f), 'utf8').includes('/cache'));
    expect(found, 'one bundled tracker chunk').toHaveLength(1);
    return '/assets/' + found[0];
}

type Hit = { path: string; params: URLSearchParams };

async function watchAnalytics(context: BrowserContext) {
    const hits: Hit[] = [];
    const chunk = trackerChunk();
    const chunkLoads: string[] = [];
    context.on('request', (req) => {
        if (new URL(req.url()).pathname === chunk) chunkLoads.push(req.url());
    });
    await context.route(ANALYTICS + '/**', (route) => {
        const url = new URL(route.request().url());
        hits.push({ path: url.pathname, params: url.searchParams });
        const headers = { 'Access-Control-Allow-Origin': '*', 'Cache-Control': 'no-cache' };
        if (url.pathname === '/cache') {
            return route.fulfill({ status: 200, headers, contentType: 'application/json', body: JSON.stringify({ ht: 1 }) });
        }
        return route.fulfill({ status: 200, headers, contentType: 'image/gif', body: Buffer.from('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', 'base64') });
    });
    return { hits, chunkLoads, collects: () => hits.filter((h) => h.path === '/collect') };
}

async function expectOnlyConsentStored(page: Page, context: BrowserContext) {
    const bag = await stored(page);
    expect(bag.cookie, 'document.cookie').toBe('apotheke_consent=v1');
    expect(bag.local, 'localStorage').toBe(0);
    expect(bag.session, 'sessionStorage').toBe(0);
    expect(bag.idb, 'IndexedDB').toBe(0);
    expect(bag.caches, 'Cache storage').toBe(0);
    const cookies = await context.cookies();
    expect(cookies.map((c) => c.name)).toEqual(['apotheke_consent']);
}

async function settle(page: Page) {
    // Long enough for a stray /cache and /collect pair (each XHR times out after 1 s).
    await page.waitForTimeout(500);
}

test.describe('page-view counting', () => {
    test.use({
        // playwright.config.ts maps this name to 127.0.0.1 (--host-resolver-rules).
        baseURL: 'http://' + TEST_HOST + ':4175',
    });

    test('nothing is loaded or sent before Accept or after Decline', async ({ browser }) => {
        const context = await browser.newContext();
        const page = await context.newPage();
        const watch = await watchAnalytics(context);
        for (const path of ['/', '/privacy/', '/app/', '/app/sign-in']) {
            await page.goto(path);
            await expect(page.locator('#consent')).toBeVisible();
            await expectNothingStored(page, context);
        }
        await page.goto('/');
        await page.getByRole('button', { name: 'Decline' }).click();
        await expect(page.locator('#consent')).toBeHidden();
        for (const path of ['/', '/privacy/', '/app/']) {
            await page.goto(path);
            await expect(page.locator('#consent')).toBeVisible();
        }
        await page.locator('#app').getByRole('link', { name: 'Sign in' }).click();
        await expect(page).toHaveURL(/\/app\/sign-in$/);
        await page.goBack();
        await expect(page).toHaveURL(/\/app\/$/);
        await settle(page);
        expect(watch.hits, 'no analytics request').toEqual([]);
        expect(watch.chunkLoads, 'tracker not loaded').toEqual([]);
        await expectNothingStored(page, context);
        await context.close();
    });

    test('after Accept: one /cache and /collect per page view, SPA routes included, nothing stored', async ({ browser }) => {
        const context = await browser.newContext();
        const page = await context.newPage();
        const watch = await watchAnalytics(context);
        const expectViews = async (paths: string[]) => {
            await expect.poll(() => watch.collects().map((h) => h.params.get('p'))).toEqual(paths);
            await settle(page);
            expect(watch.collects().map((h) => h.params.get('p'))).toEqual(paths);
            expect(watch.hits.filter((h) => h.path === '/cache')).toHaveLength(paths.length);
        };

        await page.goto('/');
        await settle(page);
        expect(watch.hits).toEqual([]);
        await page.getByRole('button', { name: 'Accept' }).click();
        await expect(page.locator('#consent')).toBeHidden();
        await expectViews(['/']);
        expect(watch.chunkLoads).toHaveLength(1);
        for (const hit of watch.hits) expect(hit.params.get('sid')).toBe('getapotheke.com');
        expect(watch.collects()[0].params.get('h')).toBe('http://' + TEST_HOST);

        await page.goto('/privacy/');
        await expectViews(['/', '/privacy/']);
        await page.goto('/app/');
        await expectViews(['/', '/privacy/', '/app/']);
        // SPA navigations: pushState, then back (popstate). The canonical link stays /app/.
        await page.locator('#app').getByRole('link', { name: 'Sign in' }).click();
        await expect(page).toHaveURL(/\/app\/sign-in$/);
        await expectViews(['/', '/privacy/', '/app/', '/app/sign-in']);
        await page.goBack();
        await expect(page).toHaveURL(/\/app\/$/);
        await expectViews(['/', '/privacy/', '/app/', '/app/sign-in', '/app/']);
        await page.goForward();
        await expect(page).toHaveURL(/\/app\/sign-in$/);
        await expectViews(['/', '/privacy/', '/app/', '/app/sign-in', '/app/', '/app/sign-in']);
        for (const hit of watch.hits) expect(hit.params.get('sid')).toBe('getapotheke.com');

        await expectOnlyConsentStored(page, context);
        await context.close();
    });

    test('Accept in the app counts the current route once, and a reload once more', async ({ browser }) => {
        const context = await browser.newContext();
        const page = await context.newPage();
        const watch = await watchAnalytics(context);
        await page.route('**/api/v1/session', (route) => route.fulfill({ status: 503, body: '' }));
        await page.goto('/app/sign-in');
        await page.getByRole('button', { name: 'Accept' }).click();
        await expect.poll(() => watch.collects().length).toBe(1);
        await page.reload();
        await expect.poll(() => watch.collects().length).toBe(2);
        await settle(page);
        expect(watch.collects().map((h) => h.params.get('p'))).toEqual(['/app/sign-in', '/app/sign-in']);
        await expectOnlyConsentStored(page, context);

        // Withdrawing consent (deleting the cookie) stops counting from the next view.
        await context.clearCookies();
        await page.goto('/app/');
        await page.goto('/');
        await settle(page);
        expect(watch.collects()).toHaveLength(2);
        await context.close();
    });

    async function acceptOnAppHome(page: Page, watch: Awaited<ReturnType<typeof watchAnalytics>>) {
        await page.route('**/api/v1/session', (route) => route.fulfill({ status: 503, body: '' }));
        await page.goto('/app/');
        await page.getByRole('button', { name: 'Accept' }).click();
        await expect.poll(() => watch.collects().map((h) => h.params.get('p'))).toEqual(['/app/']);
    }

    async function navigateInApp(page: Page) {
        await page.locator('#app').getByRole('link', { name: 'Sign in' }).click();
        await expect(page).toHaveURL(/\/app\/sign-in$/);
        await page.goBack();
        await expect(page).toHaveURL(/\/app\/$/);
        await settle(page);
    }

    test('each SPA view re-checks consent: a cleared cookie counts nothing more', async ({ browser }) => {
        const context = await browser.newContext();
        const page = await context.newPage();
        const watch = await watchAnalytics(context);
        await acceptOnAppHome(page, watch);
        await context.clearCookies();
        await navigateInApp(page);
        expect(watch.collects()).toHaveLength(1);
        await context.close();
    });

    test('each SPA view re-checks consent: a v0 cookie counts nothing more', async ({ browser }) => {
        const context = await browser.newContext();
        const page = await context.newPage();
        const watch = await watchAnalytics(context);
        await acceptOnAppHome(page, watch);
        await context.clearCookies();
        await context.addCookies([{ name: 'apotheke_consent', value: 'v0', url: 'http://' + TEST_HOST + ':4175/' }]);
        await navigateInApp(page);
        expect(watch.collects()).toHaveLength(1);
        await context.close();
    });

    test('Cookie settings reopens the notice, and Decline there withdraws consent', async ({ browser }) => {
        const context = await browser.newContext();
        const page = await context.newPage();
        const watch = await watchAnalytics(context);
        const settings = page.getByRole('button', { name: 'Cookie settings' });

        await page.goto('/');
        await expect(settings).toBeVisible();
        await settings.click();
        await expect(page.locator('#consent-choice')).toHaveText(/not accepted/);
        await page.getByRole('button', { name: 'Accept' }).click();
        await expect(page.locator('#consent')).toBeHidden();
        await expect.poll(() => watch.collects().length).toBe(1);

        // Reopening with consent shows the choice, focuses the first button, and Accept again counts nothing extra.
        await settings.click();
        await expect(page.locator('#consent')).toBeVisible();
        await expect(page.locator('#consent-choice')).toHaveText(/accepted\. Decline deletes/);
        await expect(page.getByRole('button', { name: 'Decline' })).toBeFocused();
        await page.getByRole('button', { name: 'Accept' }).click();
        await expect(page.locator('#consent')).toBeHidden();
        await expect(page.locator('#consent-choice')).toBeHidden();
        await settle(page);
        expect(watch.collects()).toHaveLength(1);

        // Withdraw inside the app: no reload, then SPA and full navigations count nothing.
        await page.route('**/api/v1/session', (route) => route.fulfill({ status: 503, body: '' }));
        await page.goto('/app/');
        await expect.poll(() => watch.collects().length).toBe(2);
        await settings.click();
        await page.getByRole('button', { name: 'Decline' }).click();
        await expect(page.locator('#consent')).toBeHidden();
        expect(await context.cookies()).toEqual([]);
        await expectNothingStored(page, context);
        await navigateInApp(page);
        await page.goto('/privacy/');
        await expect(page.locator('#consent')).toBeVisible();
        await page.goto('/');
        await settle(page);
        expect(watch.collects()).toHaveLength(2);
        await expectNothingStored(page, context);
        await context.close();
    });
});

test('withdrawing on the sign-in page turns sign-in back off', async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    const posts: string[] = [];
    await page.route('**/api/v1/session', (route) => {
        if (route.request().method() === 'POST') {
            posts.push(route.request().headers()['x-csrf-token'] || '');
            return route.fulfill({ status: 403, contentType: 'application/problem+json', body: '{"code":"consent_required"}' });
        }
        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ csrf_token: 'fresh-token' }) });
    });
    await page.goto('/app/sign-in');
    await page.getByRole('button', { name: 'Accept' }).click();
    await expect(page.locator('#sign-in-note')).toHaveText('You can sign in.');
    await expect(page.locator('#sign-in-submit')).toBeEnabled();

    await page.getByRole('button', { name: 'Cookie settings' }).click();
    await page.getByRole('button', { name: 'Decline' }).click();
    expect(await context.cookies()).toEqual([]);
    await expect(page.locator('#sign-in-note')).toHaveText('Accept cookies to sign in.');
    await expect(page.locator('#sign-in-submit')).toBeDisabled();
    await expect(page.locator('#sign-in-user')).toBeDisabled();
    await expect(page.locator('#sign-in-pass')).toBeDisabled();
    await expect(page.locator('#sign-in-home')).toBeHidden();
    // Even a forced submit sends nothing: the CSRF token is gone.
    await page.locator('#sign-in').evaluate((form: HTMLFormElement) => form.requestSubmit());
    await page.waitForTimeout(300);
    expect(posts).toEqual([]);
    await context.close();
});

test('Cookie settings is a 44px footer control on a phone, hidden without JavaScript', async ({ browser }) => {
    const phone = await browser.newContext({ viewport: { width: 375, height: 812 }, colorScheme: 'dark' });
    const page = await phone.newPage();
    for (const path of ['/', '/privacy/', '/404.html', '/app/']) {
        await page.goto(path);
        const button = page.locator('footer #cookie-settings');
        await expect(button).toBeVisible();
        const box = await button.boundingBox();
        expect(box!.height).toBeGreaterThanOrEqual(44);
        expect(box!.x + box!.width).toBeLessThanOrEqual(376);
    }
    await phone.close();
    const noJs = await browser.newContext({ javaScriptEnabled: false });
    const plain = await noJs.newPage();
    await plain.goto('/');
    await expect(plain.locator('footer #cookie-settings')).toBeHidden();
    await noJs.close();
});
