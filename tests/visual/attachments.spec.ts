import { test, expect } from './fixtures';
import * as fs from 'node:fs';
import * as path from 'node:path';

test.describe('notes and attachments', () => {
  test('Markdown notes render safely @smoke', async ({ app, page }) => {
    await app.go('/item/1/edit');
    await page.locator('textarea[name=notes]').fill('# Manual\n**Charge** before use\n\n- step one\n- see [docs](https://example.org)\n\n<script>alert(1)</script>');
    await page.locator('#save').click();
    await expect(page).toHaveURL(/\/item\/1$/);
    const notes = page.locator('#notes');
    await expect(notes.locator('h3')).toHaveText('Manual');
    await expect(notes.locator('strong')).toHaveText('Charge');
    await expect(notes.locator('li')).toHaveCount(2);
    await expect(notes.locator('a')).toHaveAttribute('rel', /noopener/);
    await expect(notes).toContainText('<script>alert(1)</script>');
    await expect(notes.locator('script')).toHaveCount(0);
  });

  test('attach a PDF, download it, delete it', async ({ app, page }) => {
    const pdf = path.join(app.server.filesDir, 'receipt.pdf');
    fs.writeFileSync(pdf, '%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n');
    await app.go('/item/1');
    await page.locator('#attachments > summary').click();
    await page.locator('#att-file').setInputFiles(pdf);
    await page.locator('#att-upload').click();
    await expect(page).toHaveURL(/\/item\/1#attachments/);
    const row = page.locator('#attachments .att');
    await expect(row).toHaveCount(1);
    await expect(row).toContainText('receipt.pdf');
    const dl = page.waitForEvent('download');
    await row.locator('.att-dl').click();
    expect((await dl).suggestedFilename()).toBe('receipt.pdf');
    await expect(page.locator('#history')).toContainText('Attached “receipt.pdf”');
    await app.tapTargets();
    page.once('dialog', d => d.accept());
    await row.locator('.pbtn-del').click();
    await expect(page.locator('#attachments .att')).toHaveCount(0);
  });

  test('wrong file type shows an error', async ({ app, page }) => {
    const bad = path.join(app.server.filesDir, 'notes.pdf');
    fs.writeFileSync(bad, '<html>not a pdf</html>');
    await app.go('/item/1');
    await page.locator('#attachments > summary').click();
    await page.locator('#att-file').setInputFiles(bad);
    await page.locator('#att-upload').click();
    await expect(page.locator('main')).toContainText('can be attached');
  });
});
