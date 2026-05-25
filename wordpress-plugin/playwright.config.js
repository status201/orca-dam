// @ts-check
const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
    testDir: './tests/e2e',
    timeout: 60_000,
    retries: process.env.CI ? 2 : 0,
    // Serialize: the tests share a single WordPress backend (mock call log lives
    // in wp_options). Running in parallel causes one test's reset to wipe another
    // test's recorded calls mid-flight.
    workers: 1,
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
    use: {
        baseURL: process.env.WP_BASE_URL || 'http://localhost:8888',
        trace: 'retain-on-failure',
        video: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        {
            name: 'setup',
            testMatch: /global\.setup\.js/,
        },
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
                storageState: 'tests/e2e/.auth/admin.json',
            },
            dependencies: ['setup'],
        },
    ],
});
