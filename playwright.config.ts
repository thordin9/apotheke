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
    toHaveScreenshot: { animations: 'disabled', caret: 'hide', scale: 'css', maxDiffPixelRatio: 0.002, threshold: 0.2 },
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
  },
  projects: [
    // full functional suite + light visuals on every device
    ...Object.entries(phones).map(([name, d]) => ({ name, use: { ...d, colorScheme: 'light' as const } })),
    // dark colour scheme: visual baselines + theme checks
    ...Object.entries(phones).map(([name, d]) => ({ name: name + '-dark', use: { ...d, colorScheme: 'dark' as const }, testMatch: /(visual[\w-]*|theme)\.spec\.ts/ })),
    // same smoke subset against PostgreSQL
    { name: 'pgsql-smoke', use: { ...phones['desktop-chromium'], colorScheme: 'light' as const, dbDriver: 'pgsql' }, grep: /@smoke/ },
  ],
});
