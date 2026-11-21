import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

const pages = ['/', '/privacy/', '/404.html'] as const;
const axePages = [...pages, '/app/', '/app/sign-in'] as const;

async function axe(page: Page): Promise<void> {
    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
        .analyze();
    expect(results.violations).toEqual([]);
}

async function decline(page: Page): Promise<void> {
    const button = page.getByRole('button', { name: 'Decline' });
    if (await button.isVisible()) await button.click();
}

async function names(page: Page, steps: number): Promise<string[]> {
    const seen: string[] = [];
    for (let i = 0; i < steps; i += 1) {
        await page.keyboard.press('Tab');
        const name = await page.evaluate(() => (document.activeElement?.textContent || '').replace(/\s+/g, ' ').trim());
        seen.push(name);
    }
    return seen;
}

function assertNoInline(html: string): void {
    expect(html).not.toMatch(/\sstyle="/i);
    expect(html).not.toMatch(/<style[\s>]/i);
    const scripts = [...html.matchAll(/<script\b([^>]*)>/gi)];
    const inline = scripts.filter((match) => !/\bsrc=/i.test(match[1]) && !/type=["']application\/ld\+json["']/i.test(match[1]));
    expect(inline).toEqual([]);
}

for (const scheme of ['light', 'dark'] as const) {
    for (const path of axePages) {
        test(`axe ${path} in ${scheme}`, async ({ browser }) => {
            const context = await browser.newContext({ colorScheme: scheme });
            const page = await context.newPage();
            await page.goto(path);
            await decline(page);
            await axe(page);
            await context.close();
        });
    }

    test(`tab order on the three pages in ${scheme}`, async ({ browser }) => {
        const context = await browser.newContext({ colorScheme: scheme });
        const page = await context.newPage();
        const header = ['Skip to content', 'Privacy', 'Decline', 'Accept', 'Apotheke', 'Open the app', 'Sign in'];
        for (const path of pages) {
            await page.goto(path);
            expect(await names(page, header.length), path).toEqual(header);
        }
        await page.goto('/');
        await decline(page);
        await expect(page.locator('#main')).toBeFocused();
        await page.keyboard.press('Tab');
        await expect(page.locator('.hero .btn-primary')).toBeFocused();
        await page.goto('/404.html');
        await decline(page);
        await page.keyboard.press('Tab');
        await expect(page.getByRole('link', { name: 'Back to the home page' })).toBeFocused();
        await context.close();
    });
}

test('the consent bar leaves the hero call to action clear at 390 by 844', async ({ browser }) => {
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await context.newPage();
    await page.goto('/');
    await expect(page.locator('#consent')).toBeVisible();
    const seen = await page.evaluate(() => {
        const bar = document.querySelector('#consent')?.getBoundingClientRect().top ?? 0;
        return ['.hero h1', '.hero .lead', '.hero .btn-primary'].every((selector) => {
            const node = document.querySelector(selector);
            if (!(node instanceof HTMLElement)) return false;
            const box = node.getBoundingClientRect();
            return box.top >= 0 && box.bottom <= bar + 1 && box.height > 0;
        });
    });
    expect(seen).toBe(true);
    await context.close();
});

const widths = [320, 375, 768, 1024, 1440];

for (const width of widths) {
    test(`the consent bar sits below the footer at ${width}`, async ({ browser }) => {
        const context = await browser.newContext({ viewport: { width, height: 800 } });
        const page = await context.newPage();
        await page.goto('/');
        await expect(page.locator('#consent')).toBeVisible();
        await page.locator('.scroller').evaluate((node) => { node.scrollTop = node.scrollHeight; });
        const fit = await page.evaluate(() => {
            const bar = document.querySelector('#consent');
            const footer = document.querySelector('footer');
            const last = footer?.lastElementChild;
            if (!(bar instanceof HTMLElement) || !(last instanceof HTMLElement)) return { ok: false, gap: -1 };
            return { ok: true, gap: bar.getBoundingClientRect().top - last.getBoundingClientRect().bottom };
        });
        expect(fit.ok).toBe(true);
        expect(fit.gap).toBeGreaterThanOrEqual(-1);
        await context.close();
    });
}

test('the hero fits 1280 by 800 and stays within the caps', async ({ browser }) => {
    const wide = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const widePage = await wide.newPage();
    await widePage.goto('/');
    const wideHero = await widePage.locator('.hero').evaluate((node) => node.getBoundingClientRect().height);
    expect(wideHero).toBeLessThanOrEqual(720);
    await wide.close();

    const mid = await browser.newContext({ viewport: { width: 768, height: 900 } });
    const midPage = await mid.newPage();
    await midPage.goto('/');
    const midHero = await midPage.locator('.hero').evaluate((node) => node.getBoundingClientRect().height);
    expect(midHero).toBeLessThanOrEqual(900);
    await mid.close();

    const laptop = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    const page = await laptop.newPage();
    await page.goto('/');
    await expect(page.locator('#consent')).toBeVisible();
    const seen = await page.evaluate(() => {
        const bar = document.querySelector('#consent')?.getBoundingClientRect().top ?? 0;
        const boxes = ['.hero h1', '.hero .lead', '.hero .btn-primary'].map((selector) => {
            const node = document.querySelector(selector);
            if (!(node instanceof HTMLElement)) return false;
            const box = node.getBoundingClientRect();
            return box.top >= 0 && box.bottom <= bar + 1 && box.height > 0;
        });
        return boxes;
    });
    expect(seen).toEqual([true, true, true]);
    await laptop.close();
});

for (const scheme of ['light', 'dark'] as const) {
    test(`product shots follow ${scheme}`, async ({ browser }) => {
        const context = await browser.newContext({ colorScheme: scheme, viewport: { width: 1440, height: 900 } });
        const page = await context.newPage();
        await page.goto('/');
        await page.locator('.scroller').evaluate((node) => { node.scrollTop = node.scrollHeight; });
        await page.locator('picture img').evaluateAll((imgs) => Promise.all(imgs.map((img) => (img as HTMLImageElement).decode().catch(() => undefined))));
        const srcs = await page.locator('picture img').evaluateAll((imgs) => imgs.map((img) => (img as HTMLImageElement).currentSrc));
        const needle = scheme === 'dark' ? '-dark.' : '-light.';
        expect(srcs.length).toBeGreaterThan(0);
        expect(srcs.every((src) => src.includes(needle))).toBe(true);
        await context.close();
    });
}

for (const scheme of ['light', 'dark'] as const) {
    for (const width of [320, 768, 1440]) {
        for (const path of pages) {
            test(`axe ${path} at ${width} in ${scheme}`, async ({ browser }) => {
                const context = await browser.newContext({ colorScheme: scheme, viewport: { width, height: width === 320 ? 640 : 900 } });
                const page = await context.newPage();
                await page.goto(path);
                await decline(page);
                await axe(page);
                await context.close();
            });
        }
    }
}

test('the three pages have no inline script or style', async ({ page }) => {
    for (const path of pages) {
        const response = await page.goto(path);
        expect(response?.ok()).toBe(true);
        assertNoInline(await response!.text());
    }
});
