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

test('the consent bar leaves the hero image clear at 390 by 844', async ({ browser }) => {
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await context.newPage();
    await page.goto('/');
    await expect(page.locator('#consent')).toBeVisible();
    const gap = await page.evaluate(() => {
        const img = document.querySelector('.hero-shot img:not(.hero-mascot)');
        const bar = document.querySelector('#consent');
        if (!(img instanceof HTMLElement) || !(bar instanceof HTMLElement)) {
            return { clear: -1, reserved: 0, bar: 0 };
        }
        const image = img.getBoundingClientRect();
        const notice = bar.getBoundingClientRect();
        const reserved = parseFloat(getComputedStyle(document.body).paddingBlockEnd);
        return { clear: notice.top - image.bottom, reserved, bar: notice.height };
    });
    expect(gap.clear).toBeGreaterThanOrEqual(0);
    expect(gap.reserved).toBeGreaterThanOrEqual(gap.bar - 1);
    await context.close();
});

test('the three pages have no inline script or style', async ({ page }) => {
    for (const path of pages) {
        const response = await page.goto(path);
        expect(response?.ok()).toBe(true);
        assertNoInline(await response!.text());
    }
});
