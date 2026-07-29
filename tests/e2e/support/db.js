// Database + fixture-token plumbing for the E2E suite. Node-only (no Playwright
// import) so it can also be driven from the CLI — see reseed.mjs.
// Contract: specs/features/e2e-testing.md.
import { execFile } from 'node:child_process';
import { closeSync, existsSync, mkdirSync, openSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';

const execFileAsync = promisify(execFile);

export const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');

const DB_FILE = path.join(ROOT, 'database', 'e2e.sqlite');
const TOKENS_FILE = path.join(ROOT, 'storage', 'e2e', 'tokens.json');

async function artisan(args) {
    // Never inherit a shell: the seeder class name contains backslashes.
    const { stdout, stderr } = await execFileAsync('php', ['artisan', ...args, '--env=e2e'], {
        cwd: ROOT,
        maxBuffer: 10 * 1024 * 1024,
    });

    return (stdout || '') + (stderr || '');
}

/**
 * Rebuild the e2e database from scratch and reseed the fixtures.
 *
 * `config:clear` first for the same reason the Pest suite demands it: a cached
 * bootstrap/cache/config.php outranks .env.e2e, and `migrate:fresh` would then
 * drop the development database instead of database/e2e.sqlite.
 */
/**
 * Gitignored runtime directories the served app needs. `storage/framework/sessions`
 * in particular is absent on a fresh checkout (dev uses the database session
 * driver) and .env.e2e deliberately uses the file driver — see e2e-testing.md REQ-5.
 * Called from playwright.config.js *before* the web server starts, because the
 * readiness probe is itself a session-creating request.
 */
export function ensureRuntimeDirs() {
    for (const dir of [
        'bootstrap/cache',
        'storage/logs',
        'storage/e2e',
        'storage/framework/cache',
        'storage/framework/sessions',
        'storage/framework/views',
    ]) {
        mkdirSync(path.join(ROOT, dir), { recursive: true });
    }
}

export async function reseed() {
    ensureRuntimeDirs();

    if (!existsSync(DB_FILE)) {
        // Laravel's SQLite connector resolves the path with realpath(), which
        // fails on a file that doesn't exist yet.
        mkdirSync(path.dirname(DB_FILE), { recursive: true });
        closeSync(openSync(DB_FILE, 'w'));
    }

    await artisan(['config:clear']);
    await artisan(['migrate:fresh', '--seed', '--seeder=Database\\Seeders\\E2eSeeder', '--force']);
}

/** Plaintext Sanctum tokens written by E2eSeeder: `{admin, editor, api}`. */
export function tokens() {
    if (!existsSync(TOKENS_FILE)) {
        throw new Error(`${TOKENS_FILE} is missing — run reseed() (npm run e2e:reset) first.`);
    }

    return JSON.parse(readFileSync(TOKENS_FILE, 'utf8'));
}

/** Read one value out of the settings table (assert persistence without a UI round-trip). */
export async function settingValue(key) {
    const out = await artisan(['tinker', '--execute', `echo App\\Models\\Setting::where('key', '${key}')->value('value');`]);

    return out.trim();
}

/**
 * Run a PHP snippet against the e2e app and return its output.
 *
 * The escape hatch for states no HTTP route can reach. Discovery needs an S3
 * object with no database row, and every delete path in the app removes the
 * object along with the row — so the only way to orphan one is to drop the row
 * behind the controller's back.
 *
 * Prefer the UI or a seeder fixture. Reach for this only when the state is
 * genuinely unreachable otherwise, and keep the snippet a single statement.
 */
export async function tinker(php) {
    return (await artisan(['tinker', '--execute', php])).trim();
}
