// MinIO availability probe. Specs that need real bytes in object storage are
// guarded by `requiresS3()`, so a developer without a container runtime can still
// run the rest of the suite (specs/features/e2e-testing.md REQ-8). CI always has
// MinIO, so nothing is silently skipped there.
import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { ROOT } from './db.js';

/** The endpoint from .env.e2e, without pulling in a dotenv dependency. */
export function endpoint() {
    if (process.env.E2E_S3_ENDPOINT) return process.env.E2E_S3_ENDPOINT;

    const envFile = path.join(ROOT, '.env.e2e');
    if (!existsSync(envFile)) return null;

    const match = readFileSync(envFile, 'utf8').match(/^\s*AWS_ENDPOINT\s*=\s*(.+)$/m);

    return match ? match[1].trim().replace(/^["']|["']$/g, '') : null;
}

/**
 * Called once from playwright.config.js (before test collection) and recorded in
 * `E2E_S3`, which worker processes inherit — the check itself is async, but
 * `requiresS3()` has to be synchronous at collection time.
 */
export async function probeS3() {
    if (process.env.E2E_S3 === '0' || process.env.E2E_S3 === '1') {
        return process.env.E2E_S3 === '1';
    }

    const base = endpoint();
    if (!base) return false;

    try {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 2000);
        const response = await fetch(`${base}/minio/health/live`, { signal: controller.signal });
        clearTimeout(timer);

        return response.ok;
    } catch {
        return false;
    }
}

export function hasS3() {
    return process.env.E2E_S3 === '1';
}
