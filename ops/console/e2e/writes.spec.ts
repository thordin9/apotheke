import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

async function axe(page: Page): Promise<void> {
  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
    .analyze();
  expect(results.violations).toEqual([]);
}

test.beforeEach(async ({ context }) => {
  await context.addCookies([{ name: 'ops_preview_writes', value: '1', url: 'http://127.0.0.1:4181' }]);
});

test('a write sends X-CSRF: 1 and a cancel sends nothing', async ({ page }) => {
  const writes: { method: string; csrf: string | undefined; origin: string }[] = [];
  page.on('request', (request) => {
    if (request.method() === 'GET' || request.method() === 'HEAD') return;
    void request.allHeaders().then((headers) => {
      writes.push({ method: request.method(), csrf: headers['x-csrf'], origin: headers.origin ?? '' });
    });
  });
  await page.goto('/installations/yard');
  await page.getByRole('button', { name: 'Reset admin' }).click();
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  await dialog.getByRole('button', { name: 'Cancel' }).click();
  await expect(dialog).toBeHidden();
  await expect.poll(() => writes.length).toBe(0);
  await page.getByRole('button', { name: 'Reset admin' }).click();
  await page.getByRole('dialog').locator('input[name="confirm_host"]').fill('yard.example.test');
  await page.getByRole('dialog').getByRole('button', { name: 'Reset admin' }).click();
  await expect(page.locator('#saved')).toBeVisible();
  await expect.poll(() => writes.length).toBe(1);
  expect(writes[0].method).toBe('POST');
  expect(writes[0].csrf).toBe('1');
  expect(writes[0].origin).toBe('http://127.0.0.1:4181');
});

test('a wrong host is shown on the field', async ({ page }) => {
  await page.goto('/installations/north');
  await page.getByRole('button', { name: 'Claim link' }).click();
  const dialog = page.getByRole('dialog');
  await dialog.locator('input[name="confirm_host"]').fill('nope.example.test');
  await dialog.getByRole('button', { name: 'Create claim link' }).click();
  await expect(dialog.getByRole('alert')).toContainText('does not match');
  await expect(page.locator('#claim-link')).toHaveCount(0);
});

test('primary is refused in the screen and from a 403', async ({ page }) => {
  await page.goto('/installations/primary');
  await expect(page.locator('#primary-refused')).toHaveText("The primary installation can't be changed here.");
  await expect(page.getByRole('button', { name: 'Claim link' })).toHaveCount(0);
  await page.goto('/plans');
  await page.getByRole('heading', { name: 'Internal' }).locator('..').getByRole('button', { name: 'Edit limits' }).click();
  await page.getByRole('dialog').getByRole('button', { name: 'Save limits' }).click();
  await expect(page.getByRole('dialog').getByRole('alert')).toHaveText("The primary installation can't be changed here.");
});

test('a downgrade shows the keys and then confirms once', async ({ page }) => {
  const bodies: string[] = [];
  page.on('request', (request) => {
    if (request.method() === 'PUT' && request.url().includes('/subscription')) bodies.push(request.postData() ?? '');
  });
  await page.goto('/installations/north');
  await page.locator('select[name="plan_id"]').selectOption('loft');
  await page.getByRole('button', { name: 'Save subscription' }).click();
  await expect(page.locator('#downgrade-keys')).toContainText('storage_bytes');
  expect(bodies).toHaveLength(1);
  expect(bodies[0]).not.toContain('"confirm":true');
  await page.getByRole('button', { name: 'Confirm downgrade' }).click();
  await expect(page.locator('#saved')).toBeVisible();
  expect(bodies).toHaveLength(2);
  expect(bodies[1]).toContain('"confirm":true');
});

test('a job nonce is reused after a 503', async ({ page }) => {
  let failed = false;
  const nonces: string[] = [];
  await page.route('**/api/ops/v1/jobs', async (route) => {
    if (route.request().method() !== 'POST') {
      await route.continue();
      return;
    }
    const body = route.request().postData() ?? '';
    nonces.push(JSON.parse(body).nonce as string);
    if (!failed) {
      failed = true;
      await route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ ok: false, code: 'unavailable' }) });
      return;
    }
    await route.continue();
  });
  await page.goto('/installations/yard');
  await page.locator('select[name="kind"]').selectOption('export');
  await page.getByRole('button', { name: 'Create job' }).click();
  await expect(page.getByRole('alert')).toBeVisible();
  await page.getByRole('button', { name: 'Create job' }).click();
  await expect(page.locator('#saved')).toBeVisible();
  expect(nonces).toHaveLength(2);
  expect(nonces[0]).toBe(nonces[1]);
  expect(nonces[0].length).toBeGreaterThanOrEqual(8);
});

test('the claim link is shown once and is not stored', async ({ page }) => {
  await page.goto('/installations/north');
  await page.getByRole('button', { name: 'Claim link' }).click();
  await page.getByRole('dialog').locator('input[name="confirm_host"]').fill('north.example.test');
  await page.getByRole('dialog').getByRole('button', { name: 'Create claim link' }).click();
  const link = page.locator('#claim-link');
  await expect(link).toBeVisible();
  const value = await link.innerText();
  expect(value).toContain('/claim/');
  await page.getByRole('button', { name: 'Dismiss' }).click();
  await expect(page.locator('#claim-link')).toHaveCount(0);
  const stored = await page.evaluate((secret) => {
    const bag = [localStorage.getItem('claim') ?? '', sessionStorage.getItem('claim') ?? '', location.href, document.cookie, document.body.innerText];
    return bag.some((item) => item.includes(secret));
  }, value);
  expect(stored).toBe(false);
  await page.getByRole('button', { name: 'Claim link' }).click();
  await page.getByRole('dialog').locator('input[name="confirm_host"]').fill('north.example.test');
  await page.getByRole('dialog').getByRole('button', { name: 'Create claim link' }).click();
  await page.getByRole('link', { name: 'Plans and limits' }).click();
  await expect(page.locator('#claim-link')).toHaveCount(0);
});

test('provision is disabled and plan limits send every row', async ({ page }) => {
  await page.goto('/installations/north');
  const provision = page.locator('select[name="kind"] option[value="provision"]');
  await expect(provision).toBeDisabled();
  await expect(page.locator('#provision-off')).toBeVisible();
  let sent = '';
  page.on('request', (request) => {
    if (request.method() === 'PUT' && request.url().includes('/plans/shelf/limits')) sent = request.postData() ?? '';
  });
  await page.goto('/plans');
  await page.getByRole('heading', { name: 'Shelf' }).locator('..').getByRole('button', { name: 'Edit limits' }).click();
  await page.getByRole('dialog').getByRole('button', { name: 'Save limits' }).click();
  await expect(page.locator('#saved')).toBeVisible();
  const body = JSON.parse(sent) as { limits: Record<string, unknown> };
  expect(Object.keys(body.limits).sort()).toEqual([
    'autofill_calls_month', 'container_hours_month', 'import_file_bytes', 'items', 'photos',
    'photos_per_item', 'requests_month', 'sleep_after_ms', 'storage_bytes', 'users',
  ]);
});

test('a 422 detail is tied to its field', async ({ page }) => {
  await page.goto('/installations/north');
  await page.locator('select[name="limit_key"]').selectOption('photos_per_item');
  await page.locator('input[name="hard_value"]').fill('-1');
  await page.getByRole('button', { name: 'Grant limit override' }).click();
  const field = page.locator('input[name="hard_value"]');
  await expect(field).toHaveAttribute('aria-describedby', /error/);
  await expect(page.locator('#field-hard_value-error')).toBeVisible();
});

test('job polling stops once the job is done', async ({ page }) => {
  await page.goto('/jobs');
  const row = page.getByRole('row').filter({ hasText: 'yard' }).filter({ hasText: '17 Nov' });
  await row.getByRole('button', { name: 'Confirm' }).click();
  await page.getByRole('dialog').getByRole('button', { name: 'Confirm job' }).click();
  await expect(row).toContainText('Done', { timeout: 15_000 });
  let later = 0;
  page.on('request', (request) => {
    if (request.url().includes('/api/ops/v1/jobs')) later += 1;
  });
  await page.waitForTimeout(1500);
  expect(later).toBe(0);
});

test('dialogs trap focus and Esc cancels', async ({ page }) => {
  await page.goto('/jobs');
  const opener = page.getByRole('row', { name: /south/ }).getByRole('button', { name: 'Confirm' });
  await opener.click();
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  await page.keyboard.press('Tab');
  const inside = await page.evaluate(() => document.querySelector('dialog')?.contains(document.activeElement) ?? false);
  expect(inside).toBe(true);
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(opener).toBeFocused();
});

for (const scheme of ['light', 'dark'] as const) {
  for (const width of [1440, 320]) {
    test(`axe write forms at ${width} ${scheme}`, async ({ page }) => {
      await page.setViewportSize({ width, height: width === 320 ? 700 : 900 });
      await page.emulateMedia({ colorScheme: scheme });
      await page.goto('/installations/north');
      await axe(page);
      await page.getByRole('button', { name: 'Claim link' }).click();
      await axe(page);
      await page.keyboard.press('Escape');
      await page.goto('/plans');
      await page.getByRole('heading', { name: 'Shelf' }).locator('..').getByRole('button', { name: 'Edit limits' }).click();
      await axe(page);
    });
  }
}
