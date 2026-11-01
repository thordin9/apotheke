import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: '.',
    testMatch: '*.spec.ts',
    timeout: 30_000,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    use: {
        baseURL: 'http://127.0.0.1:4175',
        // apotheke.test is the same local server under a non-loopback name, for the
        // page-view tests in consent.spec.ts (the tracker skips localhost and 127.x).
        launchOptions: { args: ['--host-resolver-rules=MAP apotheke.test 127.0.0.1'] },
    },
    webServer: {
        command: 'node consent-server.mjs',
        cwd: import.meta.dirname,
        url: 'http://127.0.0.1:4175/',
        reuseExistingServer: !process.env.CI,
        timeout: 30_000,
    },
});
