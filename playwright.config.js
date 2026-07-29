// Playwright config for the ORCA DAM browser suite.
// Contract: specs/features/e2e-testing.md · Decision: specs/decisions/adr-014-playwright-e2e-real-stack.md
//
// The suite boots the app itself (`artisan serve --env=e2e`) against
// database/e2e.sqlite + the MinIO bucket from docker-compose.e2e.yml. It never
// reads .env — see .env.e2e.
import { existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig, devices } from '@playwright/test';
import { probeS3 } from './tests/e2e/support/s3.js';
import { ensureRuntimeDirs } from './tests/e2e/support/db.js';

const ROOT = path.dirname(fileURLToPath(import.meta.url));

// Fail loudly rather than silently booting the app against the developer's .env:
// the suite runs `migrate:fresh`, which would drop the dev database.
if (!existsSync(path.join(ROOT, '.env.e2e'))) {
    throw new Error('.env.e2e is missing — the E2E suite refuses to run against .env (see specs/features/e2e-testing.md).');
}

// The web server's own readiness probe creates a session, so the (gitignored)
// storage/framework/* directories have to exist before it boots.
ensureRuntimeDirs();

const PORT = Number(process.env.E2E_PORT || 8100);
const BASE_URL = process.env.E2E_BASE_URL || `http://127.0.0.1:${PORT}`;

// Probe MinIO once, here, because `requiresS3()` has to be synchronous at test
// collection time. Worker processes inherit this env var. Force it with
// E2E_S3=0|1 to skip the probe (REQ-8).
process.env.E2E_S3 = (await probeS3()) ? '1' : '0';

// In CI the bucket is mandatory: otherwise a MinIO that failed to start would
// silently skip the upload/storage specs and the job would still go green.
if (process.env.CI && process.env.E2E_S3 !== '1') {
    throw new Error('No S3 endpoint answered — the storage specs would silently skip. Check the MinIO step.');
}

export default defineConfig({
    testDir: './tests/e2e',
    timeout: 60_000,
    expect: { timeout: 10_000 },
    retries: process.env.CI ? 2 : 0,
    // Serialize: the whole suite shares one SQLite file and one bucket, and
    // mutating specs reseed in beforeAll (e2e-testing.md REQ-9).
    workers: 1,
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    reporter: process.env.CI
        ? [['list'], ['html', { open: 'never' }]]
        : [['list']],
    use: {
        baseURL: BASE_URL,
        trace: 'retain-on-failure',
        video: 'retain-on-failure',
        screenshot: 'only-on-failure',
        // Thumbnails/originals load from MinIO over plain HTTP on another port.
        ignoreHTTPSErrors: true,
    },
    projects: [
        {
            // Reseeds the DB, probes MinIO, then logs in as each role.
            name: 'setup',
            testMatch: /global\.setup\.js/,
        },
        {
            name: 'chromium',
            dependencies: ['setup'],
            use: {
                ...devices['Desktop Chrome'],
                // Admin by default; role specs override with test.use().
                storageState: 'tests/e2e/.auth/admin.json',
            },
        },
    ],
    webServer: {
        // config:clear first: a cached bootstrap/cache/config.php from `dev`
        // outranks .env.e2e, which would silently point the suite (and its
        // migrate:fresh) at the development database. Same footgun as the
        // mandatory clear before `php artisan test`.
        command: `php artisan config:clear && php artisan serve --env=e2e --host=127.0.0.1 --port=${PORT}`,
        url: `${BASE_URL}/login`,
        reuseExistingServer: !process.env.CI,
        timeout: 60_000,
        stdout: 'pipe',
        stderr: 'pipe',
        // Spread process.env explicitly so PATH (and therefore `php`) is kept.
        // PHP_CLI_SERVER_WORKERS is a no-op on Windows, hence CI-only.
        env: process.env.CI ? { ...process.env, PHP_CLI_SERVER_WORKERS: '4' } : { ...process.env },
    },
});
