import { expect, test, type Page, type Route } from '@playwright/test';

test('header Sign in stays header ink and underlines on hover', async ({ browser }) => {
    const context = await browser.newContext({ colorScheme: 'light' });
    const page = await context.newPage();
    await page.goto('/');
    const decline = page.getByRole('button', { name: 'Decline' });
    if (await decline.isVisible()) await decline.click();
    const link = page.getByRole('banner').getByRole('link', { name: 'Sign in' });
    await link.hover();
    const paint = await link.evaluate((el) => {
        const probe = document.createElement('span');
        probe.style.color = 'var(--color-header-ink)';
        document.body.appendChild(probe);
        const ink = getComputedStyle(probe).color;
        probe.remove();
        const style = getComputedStyle(el);
        return { color: style.color, ink, line: style.textDecorationLine };
    });
    expect(paint.color).toBe(paint.ink);
    expect(paint.line).toContain('underline');
    await context.close();
});

test('the privacy page describes the sign-in session cookie', async ({ page }) => {
    await page.goto('/privacy/');
    await page.getByRole('button', { name: 'Decline' }).click();
    const main = page.locator('#main');
    await expect(main).toContainText('__Host-apothekesid');
    await expect(main).toContainText('when you visit that host or start signing in');
    await expect(main).toContainText('SameSite=Lax');
    await expect(main).toContainText('host-only on api.services.getapotheke.com');
    await expect(main).toContainText('up to 30 days');
    await expect(main).toContainText('See Sign-in for the API session cookie.');
    await expect(main).toContainText('Declining removes the consent cookie at once, and sign-out follows when the site can reach the API.');
    await expect(main).toContainText('Accepting again needs a fresh sign-in.');
    await expect(main).toContainText('Signing out removes the session cookie.');
    await expect(main).toContainText('removes the consent cookie, apotheke_consent');
});

async function hasConsentCookie(page: Page) {
    const cookies = await page.context().cookies();
    return cookies.some((cookie) => cookie.name === 'apotheke_consent' && cookie.value === 'v1');
}

test('Decline after consent sends one credentialed session read, and none before consent', async ({ page }) => {
    await allowApiCookie(page);
    const reads: string[] = [];
    await page.route(API_SESSION, async (route) => {
        const req = route.request();
        if (req.method() === 'DELETE') {
            return route.fulfill({
                status: 403,
                contentType: 'application/problem+json',
                body: JSON.stringify(problem(403, 'Forbidden', 'consent_required', 'Accept cookies, then try again.')),
            });
        }
        const consented = await hasConsentCookie(page);
        reads.push((consented ? 'with-cookie' : 'no-cookie') + ' ' + (req.headers().cookie || ''));
        return route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify(sessionOk('kept-token', true, 'ada')),
        });
    });
    await page.goto('/app/');
    await page.getByRole('button', { name: 'Decline' }).click();
    await page.waitForTimeout(200);
    expect(reads).toEqual([]);
    await page.goto('/app/');
    await page.getByRole('button', { name: 'Accept' }).click();
    await expect(page.locator('#signed-in')).toHaveText('Signed in as ada.');
    const before = reads.length;
    await page.getByRole('button', { name: 'Cookie settings' }).click();
    await page.getByRole('button', { name: 'Decline' }).click();
    await expect(page.locator('#consent')).toBeHidden();
    await expect(page.locator('#signed-in')).toHaveCount(0);
    await expect.poll(() => reads.slice(before)).toEqual(['no-cookie probe=sent']);
    await expect.poll(async () => hasConsentCookie(page)).toBe(false);
    await page.getByRole('button', { name: 'Cookie settings' }).click();
    await expect(page.locator('#consent-choice')).toHaveText(/not accepted/);
});

test('Decline from the landing pages sends one credentialed session read after consent', async ({ page }) => {
    await allowApiCookie(page);
    for (const path of ['/', '/privacy/', '/404.html']) {
        const reads: string[] = [];
        await page.unroute(API_SESSION).catch(() => {});
        await page.context().clearCookies();
        await allowApiCookie(page);
        await page.route(API_SESSION, async (route) => {
            const req = route.request();
            if (req.method() === 'GET') {
                const consented = await hasConsentCookie(page);
                reads.push((consented ? 'with' : 'without') + ' ' + (req.headers().cookie || ''));
                return route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    headers: { 'set-cookie': 'apothekesid=; Max-Age=0; Path=/' },
                    body: JSON.stringify(sessionOk('', false)),
                });
            }
            if (req.method() === 'DELETE' && !(await hasConsentCookie(page))) {
                return route.fulfill({
                    status: 403,
                    contentType: 'application/problem+json',
                    body: JSON.stringify(problem(403, 'Forbidden', 'consent_required', 'Accept cookies, then try again.')),
                });
            }
            return route.fulfill({ status: 204, body: '' });
        });
        await page.goto(path);
        await page.getByRole('button', { name: 'Decline' }).click();
        await page.waitForTimeout(200);
        expect(reads, path + ' before consent').toEqual([]);
        await page.goto(path);
        await page.getByRole('button', { name: 'Accept' }).click();
        await page.getByRole('button', { name: 'Cookie settings' }).click();
        await page.getByRole('button', { name: 'Decline' }).click();
        await expect(page.locator('#consent')).toBeHidden();
        await expect.poll(() => reads, path).toEqual(['without probe=sent']);
    }
});

test('a slow config read started with consent still sends the session read after navigation', async ({ page }) => {
    await allowApiCookie(page);
    let release = () => {};
    const held = new Promise<void>((resolve) => {
        release = resolve;
    });
    let configStarted = false;
    const sessions: string[] = [];
    await page.route('**/config.json', async (route) => {
        configStarted = true;
        await held;
        return route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: '{"apiBase":"https://api.services.getapotheke.com/api/v1"}',
        });
    });
    await page.route(API_SESSION, (route) => {
        sessions.push(route.request().headers().cookie || '');
        return route.fulfill({
            status: 200,
            contentType: 'application/json',
            headers: { 'set-cookie': 'apothekesid=; Max-Age=0; Path=/' },
            body: JSON.stringify(sessionOk('', false)),
        });
    });
    await page.goto('/');
    await page.evaluate(() => {
        document.cookie = 'apotheke_consent=v1; Path=/; Max-Age=15552000; Secure; SameSite=Lax';
    });
    await page.reload();
    await expect.poll(() => configStarted).toBe(true);
    const configDone = page.waitForResponse((response) => response.url().includes('/config.json'));
    release();
    await configDone;
    await page.getByRole('button', { name: 'Cookie settings' }).click();
    await page.getByRole('button', { name: 'Decline' }).click();
    await page.goto('/privacy/');
    await expect.poll(() => sessions).toEqual(['probe=sent']);
    expect(await hasConsentCookie(page)).toBe(false);
});

test('Decline ends the session, and accepting again shows signed out', async ({ page }) => {
    let live = true;
    const calls: string[] = [];
    await page.route(API_SESSION, async (route) => {
        const req = route.request();
        const consented = await hasConsentCookie(page);
        if (req.method() === 'DELETE') {
            if (!consented) {
                return route.fulfill({
                    status: 403,
                    contentType: 'application/problem+json',
                    body: JSON.stringify(problem(403, 'Forbidden', 'consent_required', 'Accept cookies, then try again.')),
                });
            }
            return route.fulfill({ status: 204, body: '' });
        }
        if (!consented) {
            live = false;
            calls.push('end');
            return route.fulfill({
                status: 200,
                contentType: 'application/json',
                headers: { 'set-cookie': 'apothekesid=; Max-Age=0; Path=/' },
                body: JSON.stringify(sessionOk('', false)),
            });
        }
        calls.push(live ? 'in' : 'out');
        return route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify(sessionOk('kept-token', live, 'ada')),
        });
    });
    await page.goto('/app/');
    await page.getByRole('button', { name: 'Accept' }).click();
    await expect(page.locator('#signed-in')).toHaveText('Signed in as ada.');
    await page.getByRole('button', { name: 'Cookie settings' }).click();
    await page.getByRole('button', { name: 'Decline' }).click();
    await expect(page.locator('#signed-in')).toHaveCount(0);
    await expect.poll(() => calls.includes('end')).toBe(true);
    await page.goto('/privacy/');
    await expect(page.locator('#consent')).toBeVisible();
    expect(await hasConsentCookie(page)).toBe(false);
    await page.goto('/app/');
    await page.getByRole('button', { name: 'Accept' }).click();
    await expect(page.locator('#app').getByRole('link', { name: 'Sign in' })).toBeVisible();
    await expect(page.locator('#signed-in')).toHaveCount(0);
    expect(calls.filter((hit) => hit === 'end')).toHaveLength(1);
});

test('a failed or hanging session read still leaves consent withdrawn', async ({ page }) => {
    let release = () => {};
    const held = new Promise<void>((resolve) => {
        release = resolve;
    });
    await page.route(API_SESSION, async (route) => {
        if (route.request().method() !== 'GET' || (await hasConsentCookie(page))) {
            return route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify(sessionOk('kept-token', true, 'ada')),
            });
        }
        await held;
        return route.fulfill({ status: 500, body: '' });
    });
    await page.goto('/app/');
    await page.getByRole('button', { name: 'Accept' }).click();
    await expect(page.locator('#signed-in')).toHaveText('Signed in as ada.');
    await page.getByRole('button', { name: 'Cookie settings' }).click();
    await page.getByRole('button', { name: 'Decline' }).click();
    await expect(page.locator('#consent')).toBeHidden();
    await expect(page.locator('#signed-in')).toHaveCount(0);
    expect(await hasConsentCookie(page)).toBe(false);
    release();
    await page.waitForTimeout(200);
    expect(await hasConsentCookie(page)).toBe(false);
    await expect(page.locator('#signed-in')).toHaveCount(0);
});

test('a session response that arrives after Decline does not show signed in', async ({ page }) => {
    let release = () => {};
    const held = new Promise<void>((resolve) => {
        release = resolve;
    });
    let gets = 0;
    await page.route(API_SESSION, async (route) => {
        gets += 1;
        if (gets === 1) {
            await held;
            return route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify(sessionOk('kept-token', true, 'ada')),
            });
        }
        return route.fulfill({ status: 204, body: '' });
    });
    await page.goto('/app/');
    await page.getByRole('button', { name: 'Accept' }).click();
    await page.getByRole('button', { name: 'Cookie settings' }).click();
    await page.getByRole('button', { name: 'Decline' }).click();
    await expect(page.locator('#consent')).toBeHidden();
    release();
    await page.waitForTimeout(200);
    await expect(page.locator('#signed-in')).toHaveCount(0);
    await expect(page.locator('#app').getByRole('link', { name: 'Sign in' })).toBeVisible();
});

test('the FAQ says hosted sign-up is invitation-only', async ({ page }) => {
    await page.goto('/');
    await page.getByRole('button', { name: 'Decline' }).click();
    await expect(page.locator('#faq')).toContainText('Sign-up is invitation-only.');
});

test('header and hero CTAs point at the app', async ({ page }) => {
    for (const path of ['/', '/privacy/']) {
        await page.goto(path);
        const bar = page.getByRole('banner');
        await expect(bar.getByRole('link', { name: 'Open the app' })).toHaveAttribute('href', '/app/');
        await expect(bar.getByRole('link', { name: 'Sign in' })).toHaveAttribute('href', '/app/sign-in');
    }
    await page.goto('/');
    await expect(page.locator('.hero').getByRole('link', { name: 'Open the app' })).toHaveAttribute('href', '/app/');
    await page.goto('/app/');
    const bar = page.getByRole('banner');
    await expect(bar.getByRole('link', { name: 'Home' })).toHaveAttribute('href', '/');
    await expect(bar.getByRole('link', { name: 'Privacy' })).toHaveCount(0);
    await expect(bar.getByRole('link', { name: 'Open the app' })).toHaveCount(0);
    await expect(page.getByRole('contentinfo').getByRole('link', { name: 'Privacy' })).toHaveAttribute('href', '/privacy/');
});

test('Privacy stays in the footer, and narrow phones do not scroll sideways', async ({ browser }) => {
    for (const width of [360, 390]) {
        const context = await browser.newContext({ viewport: { width, height: 800 }, colorScheme: 'light' });
        const page = await context.newPage();
        for (const path of ['/', '/privacy/', '/404.html', '/app/', '/app/sign-in']) {
            await page.goto(path);
            const decline = page.getByRole('button', { name: 'Decline' });
            if (await decline.isVisible()) await decline.click();
            await expect(page.getByRole('banner').getByRole('link', { name: 'Privacy' })).toHaveCount(0);
            await expect(page.getByRole('contentinfo').getByRole('link', { name: 'Privacy' })).toHaveAttribute('href', '/privacy/');
            const fits = await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1);
            expect(fits, path + ' at ' + width).toBe(true);
        }
        await context.close();
    }
});

test('the header CTAs fit a phone in dark mode and show focus', async ({ browser }) => {
    const context = await browser.newContext({
        viewport: { width: 375, height: 812 },
        colorScheme: 'dark',
    });
    const page = await context.newPage();
    await page.goto('/');
    await page.getByRole('button', { name: 'Decline' }).click();
    await expect(page.locator('#main')).toBeFocused();
    await page.keyboard.press('Shift+Tab');
    await page.keyboard.press('Shift+Tab');
    const open = page.getByRole('banner').getByRole('link', { name: 'Open the app' });
    await expect(open).toBeFocused();
    const outline = await page.evaluate(() => {
        const style = getComputedStyle(document.activeElement as Element);
        return style.outlineStyle + ' ' + style.outlineWidth;
    });
    expect(outline).not.toContain('none');
    expect(outline).not.toContain('0px');
    for (const path of ['/', '/privacy/', '/app/', '/app/sign-in']) {
        await page.goto(path);
        const name = path.startsWith('/app') ? 'Home' : 'Open the app';
        const link = page.getByRole('banner').getByRole('link', { name });
        await expect(link).toBeVisible();
        const box = await link.boundingBox();
        expect(box).not.toBeNull();
        expect(box!.height).toBeGreaterThanOrEqual(44);
        expect(box!.x).toBeGreaterThanOrEqual(-1);
        expect(box!.x + box!.width).toBeLessThanOrEqual(376);
        const fits = await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1);
        expect(fits, path).toBe(true);
    }
    await context.close();
});

test('refreshing an app route serves the shell', async ({ page }) => {
    const signIn = await page.goto('/app/sign-in');
    expect(signIn?.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible();
    await expect(page.locator('#sign-in-note')).toHaveText('Accept cookies to sign in.');

    const missing = await page.goto('/app/does-not-exist');
    expect(missing?.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1, name: 'Nothing on this shelf' })).toBeVisible();
    await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', 'noindex');
    await expect(page.locator('#app').getByRole('link', { name: 'Back to the app' })).toHaveAttribute('href', '/app/');
    await expect(page.locator('#app').getByRole('link', { name: 'Home' })).toHaveAttribute('href', '/');

    const site = await page.goto('/no-such-page');
    expect(site?.status()).toBe(404);
    await expect(page.getByRole('banner').getByRole('link', { name: 'Open the app' })).toHaveAttribute('href', '/app/');
});

test('in-app links do not reload, and back and forward restore the view', async ({ page }) => {
    await page.goto('/app/');
    await page.evaluate(() => {
        (window as unknown as { __kept?: number }).__kept = 1;
    });
    await page.locator('#app').getByRole('link', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/app\/sign-in$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible();
    expect(await page.evaluate(() => (window as unknown as { __kept?: number }).__kept)).toBe(1);

    await page.goBack();
    await expect(page).toHaveURL(/\/app\/$/);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Where did you put the drill?');
    expect(await page.evaluate(() => (window as unknown as { __kept?: number }).__kept)).toBe(1);

    await page.goForward();
    await expect(page).toHaveURL(/\/app\/sign-in$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible();
    expect(await page.evaluate(() => (window as unknown as { __kept?: number }).__kept)).toBe(1);

    await page.goto('/app/nope');
    await page.evaluate(() => {
        (window as unknown as { __kept?: number }).__kept = 2;
    });
    await page.locator('#app').getByRole('link', { name: 'Back to the app' }).click();
    await expect(page).toHaveURL(/\/app\/$/);
    expect(await page.evaluate(() => (window as unknown as { __kept?: number }).__kept)).toBe(2);
    await page.goBack();
    await expect(page).toHaveURL(/\/app\/nope$/);
    await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', 'noindex');
    expect(await page.evaluate(() => (window as unknown as { __kept?: number }).__kept)).toBe(2);
});

async function comingSoon(page: Page, fulfill: (route: Route) => Promise<void>) {
    const pageErrors: string[] = [];
    page.on('pageerror', (error) => pageErrors.push(String(error)));
    await page.goto('/app/sign-in');
    const status = page.locator('#sign-in-error');
    await expect(status).toHaveCount(1);
    await expect(status).toHaveText('');
    expect(await status.evaluate((el) => getComputedStyle(el).display)).not.toBe('none');
    await page.route('**/api/v1/session', fulfill);
    await page.getByRole('button', { name: 'Accept' }).click();
    await expect(page.locator('#sign-in-note')).toHaveText('Sign-in is coming soon.');
    await expect(page.locator('#sign-in-note')).toHaveClass(/answer/);
    await expect(page.locator('#sign-in-submit')).toBeDisabled();
    await expect(page.locator('#sign-in-user')).toBeDisabled();
    await expect(page.locator('#sign-in-home')).toBeVisible();
    await expect(page.locator('#sign-in-home').getByRole('link', { name: 'Home' })).toHaveAttribute('href', '/');
    await expect(page.locator('#sign-in-error')).toHaveCount(1);
    await expect(page.locator('#sign-in-error')).toHaveText('');
    expect(pageErrors).toEqual([]);
}

test('an unreachable session shows that sign-in is coming soon', async ({ page }) => {
    await comingSoon(page, (route) => route.abort('failed'));
});

test('a 404 session shows that sign-in is coming soon', async ({ page }) => {
    await comingSoon(page, (route) => route.fulfill({ status: 404, body: 'missing' }));
});

test('a 500 session shows that sign-in is coming soon', async ({ page }) => {
    await comingSoon(page, (route) => route.fulfill({ status: 500, contentType: 'text/plain', body: 'down' }));
});

test('a non-JSON session shows that sign-in is coming soon', async ({ page }) => {
    await comingSoon(page, (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '<p>no</p>' }));
});

test('a refused sign-in says to accept cookies', async ({ page }) => {
    await page.goto('/app/sign-in');
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
            body: JSON.stringify(problem(403, 'Forbidden', 'consent_required', 'Accept cookies, then try again.')),
        });
    });
    await page.getByRole('button', { name: 'Accept' }).click();
    await expect(page.locator('#sign-in-note')).toHaveText('You can sign in.');
    await expect(page.locator('#sign-in-submit')).toBeEnabled();
    await page.locator('#sign-in-user').fill('ada');
    await page.locator('#sign-in-pass').fill('secret');
    await page.locator('#sign-in-submit').click();
    const error = page.locator('#sign-in-error');
    await expect(error).toBeVisible();
    await expect(error).toHaveText('Accept cookies, then try again.');
});

test('Accept on the landing page is the same single cookie in the app', async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    const hits: string[] = [];
    page.on('request', (req) => {
        if (new URL(req.url()).pathname === '/api/v1/session') hits.push(req.method());
    });
    for (const path of ['/', '/privacy/', '/app/', '/app/sign-in', '/app/does-not-exist']) {
        await page.goto(path);
        await expect(page.locator('#consent')).toBeVisible();
        expect(await context.cookies(), path).toEqual([]);
        const web = await page.evaluate(() => localStorage.length + sessionStorage.length);
        expect(web, path).toBe(0);
        expect(hits, path).toEqual([]);
    }
    await page.goto('/');
    await page.getByRole('button', { name: 'Accept' }).click();
    await expect(page.locator('#consent')).toBeHidden();
    expect(hits).toEqual([]);
    const cookies = await context.cookies();
    expect(cookies).toHaveLength(1);
    expect(cookies[0].name).toBe('apotheke_consent');
    expect(cookies[0].value).toBe('v1');
    expect(cookies[0].path).toBe('/');
    await page.goto('/app/');
    await expect(page.locator('#consent')).toBeHidden();
    expect(await context.cookies()).toHaveLength(1);
    expect(hits).toEqual(['GET']);
    await page.goto('/app/sign-in');
    await expect(page.locator('#consent')).toBeHidden();
    expect(await context.cookies()).toHaveLength(1);
    await context.close();
});

test('header and footer controls are at least 44px', async ({ page }) => {
    for (const width of [390, 1280]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto('/');
        const decline = page.getByRole('button', { name: 'Decline' });
        if (await decline.isVisible()) await decline.click();
        const boxes = await page.locator('header a, header button, footer a, footer button').evaluateAll((els) => {
            return els.map((el) => {
                const box = el.getBoundingClientRect();
                return { name: (el.textContent || '').trim(), width: box.width, height: box.height };
            });
        });
        expect(boxes.length).toBeGreaterThan(0);
        for (const box of boxes) {
            expect(box.width, box.name + ' width at ' + width).toBeGreaterThanOrEqual(44);
            expect(box.height, box.name + ' height at ' + width).toBeGreaterThanOrEqual(44);
        }
    }
});

test('keyboard focus draws an outline on the hero and the footer', async ({ page }) => {
    await page.goto('/');
    await page.getByRole('button', { name: 'Decline' }).click();
    await expect(page.locator('#main')).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(page.locator('.hero .btn-primary')).toBeFocused();
    const hero = await page.evaluate(() => {
        const style = getComputedStyle(document.activeElement as Element);
        return { style: style.outlineStyle, width: style.outlineWidth };
    });
    expect(hero.style).not.toBe('none');
    expect(parseFloat(hero.width)).toBeGreaterThan(0);

    await page.locator('footer').getByRole('link', { name: 'GitHub' }).focus();
    await page.keyboard.press('Tab');
    await expect(page.locator('footer').getByRole('link', { name: 'Docs' })).toBeFocused();
    const footer = await page.evaluate(() => {
        const style = getComputedStyle(document.activeElement as Element);
        return { style: style.outlineStyle, width: style.outlineWidth };
    });
    expect(footer.style).not.toBe('none');
    expect(parseFloat(footer.width)).toBeGreaterThan(0);
});

const API_SESSION = 'https://api.services.getapotheke.com/api/v1/session';

function problem(status: number, title: string, code: string, detail: string, extra: Record<string, unknown> = {}) {
    return { type: 'about:blank', title, status, detail, code, ...extra };
}

function sessionOk(token: string, signedIn = false, username = '') {
    return {
        csrf_token: token,
        signed_in: signedIn,
        user: signedIn ? { username } : null,
    };
}

async function allowApiCookie(page: Page) {
    await page.context().addCookies([{
        name: 'probe',
        value: 'sent',
        domain: 'api.services.getapotheke.com',
        path: '/',
        secure: true,
        sameSite: 'None',
    }]);
}

function expectApiCall(req: { url: () => string; headers: () => Record<string, string> }) {
    expect(req.url()).toBe(API_SESSION);
    expect(req.headers().cookie || '').toContain('probe=sent');
}

async function acceptAndSignIn(page: Page) {
    await page.goto('/app/sign-in');
    await page.getByRole('button', { name: 'Accept' }).click();
    await expect(page.locator('#sign-in-note')).toHaveText('You can sign in.');
    await page.locator('#sign-in-user').fill('ada');
    await page.locator('#sign-in-pass').fill('secret');
    await page.locator('#sign-in-submit').click();
}

test('a wrong password shows the API detail', async ({ page }) => {
    await allowApiCookie(page);
    await page.route(API_SESSION, (route) => {
        expectApiCall(route.request());
        if (route.request().method() === 'GET') {
            return route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ csrf_token: 'fresh-token', signed_in: false }),
            });
        }
        return route.fulfill({
            status: 401,
            contentType: 'application/problem+json',
            body: JSON.stringify(problem(401, 'Unauthorized', 'auth.invalid_credentials', 'Wrong user name or password.')),
        });
    });
    await acceptAndSignIn(page);
    await expect(page.locator('#sign-in-error')).toHaveText('Wrong user name or password.');
    await expect(page).toHaveURL(/\/app\/sign-in$/);
});

test('invalid fields show the API detail next to each input', async ({ page }) => {
    await allowApiCookie(page);
    await page.route(API_SESSION, (route) => {
        expectApiCall(route.request());
        if (route.request().method() === 'GET') {
            return route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ csrf_token: 'fresh-token', signed_in: false }),
            });
        }
        return route.fulfill({
            status: 422,
            contentType: 'application/problem+json',
            body: JSON.stringify(problem(422, 'Unprocessable Content', 'validation.failed', 'Enter a user name and password.', {
                errors: { username: 'Required.', password: 'Required.' },
            })),
        });
    });
    await acceptAndSignIn(page);
    await expect(page.locator('#sign-in-error')).toHaveText('Enter a user name and password.');
    await expect(page.locator('#sign-in-user-error')).toHaveText('Required.');
    await expect(page.locator('#sign-in-pass-error')).toHaveText('Required.');
});

test('an invalid CSRF token is refreshed and the sign-in is retried once', async ({ page }) => {
    await allowApiCookie(page);
    let posts = 0;
    await page.route(API_SESSION, (route) => {
        const req = route.request();
        expectApiCall(req);
        if (req.method() === 'GET') {
            const token = posts === 0 ? 'stale-token' : 'fresh-token';
            return route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ csrf_token: token, signed_in: false }),
            });
        }
        posts += 1;
        if (posts === 1) {
            expect(req.headers()['x-csrf-token']).toBe('stale-token');
            return route.fulfill({
                status: 403,
                contentType: 'application/problem+json',
                body: JSON.stringify(problem(403, 'Forbidden', 'csrf.invalid', 'The request was refused: reload and try again.')),
            });
        }
        expect(req.headers()['x-csrf-token']).toBe('fresh-token');
        return route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({ csrf_token: 'fresh-token', signed_in: true }),
        });
    });
    await acceptAndSignIn(page);
    await expect(page).toHaveURL(/\/app\/$/);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Where did you put the drill?');
    expect(posts).toBe(2);
});

test('a rate limit shows how long to wait', async ({ page }) => {
    await allowApiCookie(page);
    await page.route(API_SESSION, (route) => {
        expectApiCall(route.request());
        if (route.request().method() === 'GET') {
            return route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ csrf_token: 'fresh-token', signed_in: false }),
            });
        }
        return route.fulfill({
            status: 429,
            contentType: 'application/problem+json',
            headers: { 'Retry-After': '60', 'Access-Control-Expose-Headers': 'Retry-After' },
            body: JSON.stringify(problem(429, 'Too Many Requests', 'rate_limited', 'Too many attempts for this account from this address – please wait 1 minutes and try again.')),
        });
    });
    await acceptAndSignIn(page);
    await expect(page.locator('#sign-in-error')).toHaveText('Too many attempts for this account from this address – please wait 1 minutes and try again.');
});

test('a second invalid CSRF token is shown and not retried again', async ({ page }) => {
    await allowApiCookie(page);
    let posts = 0;
    await page.route(API_SESSION, (route) => {
        expectApiCall(route.request());
        if (route.request().method() === 'GET') {
            return route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify(sessionOk(posts === 0 ? 'stale-token' : 'fresh-token')),
            });
        }
        posts += 1;
        return route.fulfill({
            status: 403,
            contentType: 'application/problem+json',
            body: JSON.stringify(problem(403, 'Forbidden', 'csrf.invalid', 'The request was refused: reload and try again.')),
        });
    });
    await acceptAndSignIn(page);
    await expect(page.locator('#sign-in-error')).toHaveText('The request was refused: reload and try again.');
    expect(posts).toBe(2);
});

test('a forbidden response that is not an invalid token is not retried', async ({ page }) => {
    await allowApiCookie(page);
    let posts = 0;
    await page.route(API_SESSION, (route) => {
        expectApiCall(route.request());
        if (route.request().method() === 'GET') {
            return route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify(sessionOk('fresh-token')),
            });
        }
        posts += 1;
        return route.fulfill({
            status: 403,
            contentType: 'application/problem+json',
            body: JSON.stringify(problem(403, 'Forbidden', 'permission.denied', 'You cannot do that.')),
        });
    });
    await acceptAndSignIn(page);
    await expect(page.locator('#sign-in-error')).toHaveText('You cannot do that.');
    expect(posts).toBe(1);
});

test('an API detail is shown as text', async ({ page }) => {
    await allowApiCookie(page);
    const detail = '<img src=x onerror=alert(1)>';
    await page.route(API_SESSION, (route) => {
        if (route.request().method() === 'GET') {
            return route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify(sessionOk('fresh-token')),
            });
        }
        return route.fulfill({
            status: 401,
            contentType: 'application/problem+json',
            body: JSON.stringify(problem(401, 'Unauthorized', 'auth.invalid_credentials', detail)),
        });
    });
    await acceptAndSignIn(page);
    const error = page.locator('#sign-in-error');
    await expect(error).toHaveText(detail);
    expect(await error.evaluate((el) => el.querySelector('img'))).toBeNull();
});

test('a double click sends one sign-in', async ({ page }) => {
    await allowApiCookie(page);
    let posts = 0;
    let release = () => {};
    const held = new Promise<void>((resolve) => {
        release = resolve;
    });
    await page.route(API_SESSION, async (route) => {
        if (route.request().method() === 'GET') {
            return route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify(sessionOk('fresh-token')),
            });
        }
        posts += 1;
        await held;
        return route.fulfill({
            status: 401,
            contentType: 'application/problem+json',
            body: JSON.stringify(problem(401, 'Unauthorized', 'auth.invalid_credentials', 'Wrong user name or password.')),
        });
    });
    await page.goto('/app/sign-in');
    await page.getByRole('button', { name: 'Accept' }).click();
    await expect(page.locator('#sign-in-note')).toHaveText('You can sign in.');
    await page.locator('#sign-in-user').fill('ada');
    await page.locator('#sign-in-pass').fill('secret');
    const button = page.locator('#sign-in-submit');
    await button.evaluate((el: HTMLButtonElement) => {
        el.click();
        el.click();
    });
    await expect(button).toHaveAttribute('aria-busy', 'true');
    release();
    await expect(page.locator('#sign-in-error')).toHaveText('Wrong user name or password.');
    await expect(button).not.toHaveAttribute('aria-busy');
    expect(posts).toBe(1);
});

test('a signed-in session replaces the sign-in offer', async ({ page }) => {
    let gets = 0;
    await page.route(API_SESSION, (route) => {
        gets += 1;
        return route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify(sessionOk('kept-token', true, 'ada')),
        });
    });
    await page.goto('/app/');
    await expect(page.locator('#app').getByRole('link', { name: 'Sign in' })).toBeVisible();
    expect(gets).toBe(0);
    await page.getByRole('button', { name: 'Accept' }).click();
    await expect(page.locator('#signed-in')).toHaveText('Signed in as ada.');
    await expect(page.locator('#app').getByRole('link', { name: 'Sign in' })).toHaveCount(0);
    expect(gets).toBe(1);
});

test('a rate limit without a detail uses Retry-After in minutes', async ({ page }) => {
    await allowApiCookie(page);
    await page.route(API_SESSION, (route) => {
        if (route.request().method() === 'GET') {
            return route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify(sessionOk('fresh-token')),
            });
        }
        return route.fulfill({
            status: 429,
            contentType: 'application/problem+json',
            headers: { 'Retry-After': '61', 'Access-Control-Expose-Headers': 'Retry-After' },
            body: JSON.stringify(problem(429, 'Too Many Requests', 'rate_limited', '')),
        });
    });
    await acceptAndSignIn(page);
    await expect(page.locator('#sign-in-error')).toHaveText('Too many sign-in attempts. Try again in 2 minutes.');
});
