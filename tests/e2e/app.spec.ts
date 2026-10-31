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
            body: JSON.stringify({ code: 'consent_required', error: 'consent_required' }),
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
    expect(hits).toEqual([]);
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
