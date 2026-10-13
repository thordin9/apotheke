import { test, expect } from './fixtures';

test.describe('merge duplicates', () => {
  test('pick a target, confirm, everything moves over @smoke', async ({ app, page }) => {
    const csrf = async () => page.locator('meta[name=csrf]').getAttribute('content');
    await app.go('/new');
    const tok = await csrf();
    const save = async (name: string, quantity: number, extra: Record<string, string> = {}) =>
      Number(/saved=(\d+)/.exec((await (await page.request.post('/save', { headers: { Accept: 'application/json' }, form: { csrf: tok!, name, quantity: String(quantity), ...extra } })).json()).redirect)![1]);
    const keep = await save('Head torch', 2);
    const dupe = await save('head torch', 3, { serial: 'HT-9', tags: 'camping' });
    await app.go(`/item/${dupe}`);
    await page.locator('#merge-link').click();
    await expect(page).toHaveURL(new RegExp(`/item/${dupe}/merge$`));
    await expect(page.locator('.merge-pick').first()).toHaveAttribute('data-id', String(keep));
    await app.noOverflow();
    await app.tapTargets();
    await page.locator('.merge-pick').first().click();
    await expect(page.locator('#merge-changes')).toContainText('2 + 3 = 5');
    await expect(page.locator('#merge-changes')).toContainText('HT-9');
    await app.noOverflow();
    await app.tapTargets();
    await page.locator('#merge-confirm').click();
    await expect(page).toHaveURL(new RegExp(`/item/${keep}\\?merged=`));
    await expect(page.locator('.toast')).toContainText('Merged “head torch” into this item');
    await expect(page.locator('.qty-lg .qval')).toHaveText('5');
    await expect(page.locator('#history')).toContainText('Merged “head torch” into this item');
    expect(app.sql(`SELECT id FROM items WHERE id = ${dupe}`)).toEqual([]);
  });

  test('cancel leaves both items', async ({ app, page }) => {
    await app.go('/item/2/merge?into=1');
    await page.locator('.actionbar a', { hasText: 'Cancel' }).click();
    await expect(page).toHaveURL(/\/item\/2$/);
    expect(app.sql('SELECT COUNT(*) AS n FROM items WHERE id IN (1, 2)')[0]).toEqual({ n: 2 });
  });
});
