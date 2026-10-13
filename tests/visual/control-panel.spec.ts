import { test, expect } from './fixtures';
import type { Page } from '@playwright/test';
import * as fs from 'node:fs';
import * as path from 'node:path';

/* Control Panel (/settings): every section works from the UI and actually changes the app. */

const open = async (page: Page, section: string) => {
  await page.goto('/settings', { waitUntil: 'networkidle' });
  await page.locator(`#sl-${section}`).click();
  await expect(page).toHaveURL(new RegExp(`/settings/${section}$`));
};
const save = async (page: Page) => {
  await page.locator('#set-save').click();
  await expect(page.locator('.toast')).toHaveText('Settings saved');
};

test.describe('control panel', () => {
  test('reached from the menu, every section opens @smoke', async ({ app, page }) => {
    await app.go('/');
    await app.menu();
    await page.locator('#menu-settings').click();
    await expect(page).toHaveURL(/\/settings$/);
    for (const s of ['general', 'appearance', 'locale', 'list', 'items', 'limits', 'uploads', 'security', 'data', 'system']) {
      await page.goto(`/settings/${s}`, { waitUntil: 'networkidle' });
      await expect(page.locator('main')).toBeVisible();
      await app.noOverflow();
      await app.tapTargets();
    }
  });

  test('general: name and tagline', async ({ app, page }) => {
    await open(page, 'general');
    await page.locator('#s-app_name').fill('Shed Stock');
    await page.locator('#s-app_tagline').fill('tools & bits');
    await page.locator('#s-public_url').fill('https://inv.example.org');
    await save(page);
    await app.go('/');
    await expect(page).toHaveTitle(/Shed Stock/);
    await expect(page.locator('header h1.brand')).toContainText('Shed Stock');
    await app.go('/labels');
    await expect(page.locator('main')).toContainText('https://inv.example.org');
  });

  test('general: invalid values show an error and keep the input', async ({ page }) => {
    await open(page, 'general');
    await page.locator('#s-app_name').fill('');
    await page.locator('#set-save').click();
    await expect(page.locator('#s-app_name')).toHaveAttribute('aria-invalid', 'true');
    await expect(page.locator('.field-err').first()).toBeVisible();
  });

  test('appearance: accent preset, live preview, contrast warning', async ({ app, page }) => {
    await open(page, 'appearance');
    await page.locator('.preset[data-accent="#C8502D"]').click();
    await expect(page.locator('#s-accent')).toHaveValue('#C8502D');
    await expect(page.locator('#accent-warn')).toContainText('4.16:1');
    // the preview uses the adjusted (readable) shade
    const bg = await page.locator('#pv-light .pv-btn').evaluate(e => getComputedStyle(e).backgroundColor);
    expect(bg).not.toBe('rgb(79, 70, 229)');
    await page.locator('#s-theme_default').selectOption('dark');
    await save(page);
    await expect(page.locator('#accent-css')).toHaveCount(1);
    await app.go('/');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    const accent = await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--accent').trim());
    expect(accent).not.toBe('#8b93ff');
    const fab = await page.locator('.fab').evaluate(e => getComputedStyle(e).backgroundColor);
    expect(fab).not.toBe('rgb(139, 147, 255)');
  });

  test('appearance: colour picker and typed value stay in sync', async ({ page }) => {
    await open(page, 'appearance');
    await page.locator('#s-accent').fill('#0f766e');
    await page.locator('#s-accent').dispatchEvent('input');
    await expect(page.locator('#s-accent-pick')).toHaveValue('#0f766e');
    await page.locator('#s-accent').fill('nope');
    await page.locator('#set-save').click();
    await expect(page.locator('#s-accent')).toHaveAttribute('aria-invalid', 'true');
  });

  test('appearance: upload a PNG logo, then reset', async ({ app, page }) => {
    await open(page, 'appearance');
    await page.locator('#logo-file').setInputFiles(app.image('logo.png', [200, 80, 45], true));
    await page.locator('#logo-upload').click();
    await expect(page.locator('.toast')).toHaveText('Logo updated');
    await app.go('/');
    const logo = page.locator('header h1.brand img.brand-logo');
    await expect(logo).toHaveAttribute('src', /\/brand\/logo\.png\?v=/);
    expect(await logo.evaluate((i: HTMLImageElement) => i.complete && i.naturalWidth > 0)).toBe(true);
    await expect(page.locator('link[rel=apple-touch-icon]')).toHaveAttribute('href', /\/brand\/icon-180\.png/);
    const man = await (await page.request.get('/manifest.webmanifest')).json();
    expect(JSON.stringify(man.icons)).toContain('/brand/icon-512.png');
    await open(page, 'appearance');
    page.once('dialog', d => d.accept());
    await page.locator('#logo-reset').click();
    await expect(page.locator('.toast')).toHaveText('Default logo restored');
    await app.go('/');
    await expect(page.locator('header h1.brand img.brand-logo')).toHaveAttribute('src', /\/assets\//);
  });

  test('appearance: SVG logo is cleaned', async ({ app, page }) => {
    const svg = path.join(app.server.filesDir, 'logo.svg');
    fs.writeFileSync(svg, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><script>alert(1)</script><circle cx="5" cy="5" r="5" fill="#0f766e"/></svg>');
    await open(page, 'appearance');
    await page.locator('#logo-file').setInputFiles(svg);
    await page.locator('#logo-upload').click();
    await expect(page.locator('.toast')).toHaveText('Logo updated');
    const body = await (await page.request.get('/brand/logo.svg')).text();
    expect(body).not.toContain('script');
    expect(body).toContain('circle');
  });

  test('locale: currency, number and date formats', async ({ app, page }) => {
    await open(page, 'locale');
    await page.locator('#s-currency').fill('USD');
    await page.locator('#s-money_format').selectOption('de');
    await page.locator('#s-currency_position').selectOption('before');
    await page.locator('#s-date_format').selectOption('d.m.Y');
    await page.locator('#s-timezone').selectOption('America/New_York');
    await save(page);
    const id = app.sql<{ id: number }>("SELECT id FROM items WHERE value_cents IS NOT NULL AND purchase_date IS NOT NULL ORDER BY id LIMIT 1")[0].id;
    await app.go(`/item/${id}`);
    await expect(page.locator('main')).toContainText(/USD\s?\d{1,3}(\.\d{3})*,\d{2}/);
    await expect(page.locator('main')).toContainText(/\d{2}\.\d{2}\.\d{4}/);
  });

  test('list: page size, sort and compact view', async ({ app, page }) => {
    await open(page, 'list');
    await page.locator('#s-page_size').fill('3');
    await page.locator('#s-default_sort').selectOption('name');
    await page.locator('#s-list_view').selectOption('compact');
    await page.locator('label[for=s-overview_open]').click();
    await expect(page.locator('#s-overview_open')).not.toBeChecked();
    await save(page);
    await app.go('/');
    await expect(page.locator('#grid .card')).toHaveCount(3);
    await expect(page.locator('#grid')).toHaveClass(/is-compact/);
    await expect(page.locator('#stats')).not.toHaveAttribute('open', '');
    const names = await page.locator('#grid .card h2, #grid .card .card-title').allTextContents();
    expect([...names].sort((a, b) => a.localeCompare(b))).toEqual(names);
    await app.noOverflow();
  });

  test('items: defaults pre-fill the add form', async ({ app, page }) => {
    await open(page, 'items');
    await page.locator('#s-default_quantity').fill('3');
    await page.locator('#s-default_tags').fill('garage, new');
    await page.locator('label[for=s-default_purchase_today]').click();
    await expect(page.locator('#s-default_purchase_today')).toBeChecked();
    await page.locator('#s-default_location').selectOption({ label: 'Attic' });
    await save(page);
    await app.go('/new');
    await expect(page.locator('input[name=quantity]')).toHaveValue('3');
    await expect(page.locator('#purchase_date')).toHaveValue('2026-06-15');
    await expect(page.locator('select[name=location_id] option:checked')).toContainText('Attic');
    await expect(page.locator('form')).toContainText('garage');
  });

  test('limits: expiry window and loan period', async ({ app, page }) => {
    await open(page, 'limits');
    await page.locator('#s-lend_due_days').fill('14');
    await page.locator('#s-expiry_soon_days').fill('7');
    await save(page);
    const id = app.sql<{ id: number }>('SELECT id FROM items WHERE lent_to IS NULL ORDER BY id LIMIT 1')[0].id;
    await app.go(`/item/${id}`);
    await page.locator('#lending > summary').click();
    await expect(page.locator('#lent_due')).toHaveValue('2026-06-29');
  });

  test('uploads: values are validated', async ({ page }) => {
    await open(page, 'uploads');
    await page.locator('#s-image_thumb_max').fill('900');
    await page.locator('#s-jpeg_quality').fill('70');
    await save(page);
    await expect(page.locator('#s-image_thumb_max')).toHaveValue('900');
    await page.locator('#s-jpeg_quality').fill('5');
    await page.locator('#set-save').click();
    await expect(page.locator('#s-jpeg_quality')).toHaveAttribute('aria-invalid', 'true');
    page.once('dialog', d => d.accept());
    await page.locator('#set-reset').click();
    await expect(page.locator('.toast')).toHaveText('Section reset to defaults');
    await expect(page.locator('#s-image_thumb_max')).toHaveValue('480');
  });

  test('security: turn the password on, sign in, turn it off', async ({ page, browser }) => {
    await open(page, 'security');
    await page.locator('#pw-new').fill('garage-secret');
    await page.locator('#pw-confirm').fill('garage-secret');
    await page.locator('#pw-save').click();
    await expect(page.locator('.toast')).toHaveText('Password saved');
    const other = await browser.newPage({ baseURL: page.url().replace(/\/settings.*$/, '') });
    await other.goto('/settings');
    await expect(other).toHaveURL(/\/login/);
    await other.locator('input[name=password]').fill('garage-secret');
    await other.locator('button.btn-primary').click();
    await other.goto('/settings');
    await expect(other).toHaveURL(/\/settings$/);
    await other.close();
    await page.locator('#pw-current-off').fill('garage-secret');
    page.once('dialog', d => d.accept());
    await page.locator('#pw-off').click();
    await expect(page.locator('.toast')).toHaveText('Password turned off');
  });

  test('data: backup, CSV and settings export / import', async ({ app, page }) => {
    await open(page, 'data');
    await expect(page.locator('#d-backup')).toHaveAttribute('href', /backup/);
    await expect(page.locator('#d-csv')).toHaveAttribute('href', /export\.csv/);
    const dl = page.waitForEvent('download');
    await page.locator('#d-export').click();
    const file = await (await dl).path();
    const json = JSON.parse(fs.readFileSync(file!, 'utf8'));
    expect(json.format).toBe('apotheke-settings');
    json.settings.app_name = 'Imported';
    const imp = path.join(app.server.filesDir, 'settings.json');
    fs.writeFileSync(imp, JSON.stringify(json));
    await page.locator('#import-file').setInputFiles(imp);
    await page.locator('#import-form button').click();
    await expect(page.locator('.toast')).toContainText('Imported');
    await app.go('/');
    await expect(page.locator('header h1.brand')).toContainText('Imported');
  });

  test('system: bootstrap values are read-only', async ({ page }) => {
    await open(page, 'system');
    await expect(page.locator('#systable')).toContainText('Data directory');
    await expect(page.locator('#systable input')).toHaveCount(0);
    await expect(page.locator('#set-save')).toHaveCount(0);
  });
});
