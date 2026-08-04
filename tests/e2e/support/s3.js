// MinIO availability probe. Specs that need real bytes in object storage are
// guarded by `requiresS3()`, so a developer without a container runtime can still
// run the rest of the suite (specs/features/e2e-testing.md REQ-8). CI always has
// MinIO, so nothing is silently skipped there.
import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { ROOT } from './db.js';

// Allowlists for the endpoint parsed out of .env.e2e. Everything the probe fetches is rebuilt from
// these literals rather than from the parsed text — see loopbackOrigin().
const PROBE_PROTOCOLS = ['http:', 'https:'];
const PROBE_HOSTS = ['127.0.0.1', 'localhost'];

/**
 * Validate a parsed AWS_ENDPOINT and return an origin built from this file's own constants, or null.
 *
 * CodeQL's `js/file-access-to-http` reported the previous version: the value went from readFileSync
 * straight into fetch(). `.env.e2e` is committed, so anyone who can edit it can edit this file next
 * to it — it was never a trust boundary. But the rule was pointing at something worth fixing anyway:
 * a probe that will fetch whatever host a config file happens to name, and where a typo produces a
 * confusing network error rather than "no endpoint answered".
 *
 * `.find()` returns the entry from the allowlist, so the protocol and host that flow onward are
 * these literals and not the parsed string; the port goes through Number(). A malformed or
 * non-loopback endpoint is treated exactly like a missing one, which means the storage specs skip —
 * and in CI playwright.config.js turns that into a hard error, so this cannot quietly drop coverage.
 *
 * E2E_S3_ENDPOINT is deliberately *not* restricted this way: it is the documented escape hatch for a
 * non-standard setup (a remote MinIO, a tunnel), and an environment variable is not file data.
 */
function loopbackOrigin(raw) {
    let url;
    try {
        url = new URL(raw);
    } catch {
        return null;
    }

    const protocol = PROBE_PROTOCOLS.find((p) => p === url.protocol);
    const host = PROBE_HOSTS.find((h) => h === url.hostname);
    if (!protocol || !host) return null;

    if (url.port === '') return `${protocol}//${host}`;

    const port = Number(url.port);
    if (!Number.isInteger(port) || port < 1 || port > 65535) return null;

    return `${protocol}//${host}:${port}`;
}

/** The endpoint from .env.e2e, without pulling in a dotenv dependency. */
export function endpoint() {
    if (process.env.E2E_S3_ENDPOINT) return process.env.E2E_S3_ENDPOINT;

    const envFile = path.join(ROOT, '.env.e2e');
    if (!existsSync(envFile)) return null;

    const match = readFileSync(envFile, 'utf8').match(/^\s*AWS_ENDPOINT\s*=\s*(.+)$/m);
    if (!match) return null;

    return loopbackOrigin(match[1].trim().replace(/^["']|["']$/g, ''));
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
