import { defineConfig, devices } from '@playwright/test';

/*
 * Visual end-to-end tests (dev only). Baselines are rendered in the official Playwright image
 * (see scripts/update-snapshots.sh and the CI "update-snapshots" job) so they match CI pixel for pixel.
 */
const CI = !!process.env.CI;
const phones = {
  'webkit-iphone13': devices['iPhone 13'],
  'webkit-iphone-se': devices['iPhone SE'],
  'chromium-pixel': devices['Pixel 7'],
  'desktop-chromium': { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 860 } },
};

export default defineConfig({
  testDir: 'tests/visual',
  outputDir: 'tests/visual/results',
  snapshotPathTemplate: '{testDir}/__screenshots__/{projectName}/{testFilePath}/{arg}{ext}',
  timeout: 45_000,
  expect: {
    timeout: 7_000,
    toHaveScreenshot: {
      animations: 'disabled', caret: 'hide', scale: 'css',
      // The flip gate's change rule (D66, D67), in the path a run actually uses: a file has changed
      // when more than 5,000 pixels differ, counted byte for byte (`threshold: 0` - any channel
      // difference counts, which is the unit D67's measurements are in). 5,000 is 7.5x the worst
      // noise measured across two unplanted renders of one commit (663 px) and 9.3x below the
      // smallest material change measured (46,411 px). A count, not a ratio: noise is a pixel count,
      // and 0.2% of a phone viewport (500 px) sits *below* that measured noise floor.
      maxDiffPixels: 5_000, threshold: 0,
    },
  },
  fullyParallel: false,          // tests in a file share one worker (and its server); files run in parallel
  forbidOnly: CI,
  retries: CI ? 1 : 0,
  workers: CI ? 2 : undefined,
  reporter: CI ? [['github'], ['list'], ['html', { outputFolder: 'tests/visual/report', open: 'never' }]] : [['list'], ['html', { outputFolder: 'tests/visual/report', open: 'never' }]],
  use: {
    trace: CI ? 'retain-on-failure' : 'on-first-retry',
    screenshot: 'only-on-failure',
    locale: 'en-GB',
    timezoneId: 'Europe/Athens',
    acceptDownloads: true,
    // Cookie consent (docs/deployment-and-operations.md): every context, browser.newContext() ones included, starts with the
    // banner accepted. tests/visual/consent.spec.ts starts without it. Cookies ignore the port.
    storageState: { cookies: [{ name: 'apotheke_consent', value: 'v1', domain: '127.0.0.1', path: '/', expires: -1, httpOnly: false, secure: false, sameSite: 'Lax' }], origins: [] },
  },
  projects: [
    // full functional suite + light visuals on every device
    ...Object.entries(phones).map(([name, d]) => ({ name, use: { ...d, colorScheme: 'light' as const } })),
    // dark colour scheme: visual baselines + theme checks
    ...Object.entries(phones).map(([name, d]) => ({ name: name + '-dark', use: { ...d, colorScheme: 'dark' as const }, testMatch: /(visual[\w-]*|theme)\.spec\.ts/ })),
    // same smoke subset against PostgreSQL
    // smoke subset against the Docker image (nginx + php-fpm): E2E_DOCKER_IMAGE=apotheke:ci npx playwright test --project=docker-smoke
    ...(process.env.E2E_DOCKER_IMAGE ? [{ name: 'docker-smoke', use: { ...phones['desktop-chromium'], colorScheme: 'light' as const }, grep: /@smoke/ }] : []),
    { name: 'pgsql-smoke', use: { ...phones['desktop-chromium'], colorScheme: 'light' as const, dbDriver: 'pgsql' }, grep: /@smoke/ },
  ],
});
