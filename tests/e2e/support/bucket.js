// Provisions the bucket the E2E suite stores into: create it, then make it
// anonymously readable. Two requests, both SigV4-signed by hand.
//
// Signing 150 lines of AWS SigV4 rather than shelling out to a client is the
// same trade this harness already makes elsewhere — `files.js` hand-rolls a PNG
// writer with its own CRC32, `s3.js` parses `.env.e2e` rather than pulling in
// dotenv. The alternative here was a second container image purely to run two
// HTTP calls (`minio/mc`, which is archived, or `amazon/aws-cli`), and that
// image is unavailable on exactly the Docker-less machine the binary fallback
// in `scripts/e2e-storage.mjs` exists to serve. One signer covers both paths and
// CI, so the bucket is provisioned the same way everywhere.
//
// Contract: specs/features/e2e-testing.md REQ-2 · Decision: ADR-017.
import { createHash, createHmac } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { ROOT } from './db.js';
import { endpoint } from './s3.js';

const ALGORITHM = 'AWS4-HMAC-SHA256';
const SERVICE = 's3';

/** Public read on every object — the browser loads asset and thumbnail URLs straight from the bucket. */
const PUBLIC_READ_POLICY = (bucket) => JSON.stringify({
    Version: '2012-10-17',
    Statement: [{
        Effect: 'Allow',
        Principal: '*',
        Action: ['s3:GetObject'],
        Resource: [`arn:aws:s3:::${bucket}/*`],
    }],
});

const sha256 = (data) => createHash('sha256').update(data, 'utf8').digest('hex');
const hmac = (key, data) => createHmac('sha256', key).update(data, 'utf8').digest();

/** `.env.e2e` value for `key`, unquoted, or null. */
function envValue(key) {
    const file = path.join(ROOT, '.env.e2e');
    if (!existsSync(file)) return null;

    const match = readFileSync(file, 'utf8').match(new RegExp(`^\\s*${key}\\s*=\\s*(.+)$`, 'm'));

    return match ? match[1].trim().replace(/^["']|["']$/g, '') : null;
}

/**
 * Credentials + bucket for the local stand-in, from `.env.e2e` — the same file the
 * app itself reads, so the two can never drift apart.
 *
 * The origin comes from `s3.js`'s `endpoint()`, which rebuilds it from allowlisted
 * literals rather than from the parsed text (CodeQL `js/file-access-to-http`; see the
 * note on `loopbackOrigin()`). The bucket name lands in the request *path*, so it gets
 * its own S3 naming check for the same reason: a typo should read as "that is not a
 * bucket name", not as a confusing request to somewhere unintended.
 */
export function bucketConfig() {
    const origin = endpoint();
    const bucket = envValue('AWS_BUCKET');

    if (!origin) return null;
    if (!bucket || !/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/.test(bucket)) return null;

    return {
        origin,
        bucket,
        region: envValue('AWS_DEFAULT_REGION') || 'us-east-1',
        accessKey: envValue('AWS_ACCESS_KEY_ID') || '',
        secretKey: envValue('AWS_SECRET_ACCESS_KEY') || '',
    };
}

/** SigV4 headers for one request. Host is left to fetch(), which sets it from the URL. */
function sign({ method, url, body, accessKey, secretKey, region }) {
    const target = new URL(url);
    const amzDate = new Date().toISOString().replace(/[:-]|\.\d{3}/g, '');
    const dateStamp = amzDate.slice(0, 8);
    const payloadHash = sha256(body ?? '');

    // Canonical query: RFC-3986 encoded, sorted by key. `?policy` carries an empty value.
    const canonicalQuery = [...target.searchParams.entries()]
        .map(([k, v]) => [encodeURIComponent(k), encodeURIComponent(v)])
        .sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))
        .map(([k, v]) => `${k}=${v}`)
        .join('&');

    const signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
    const canonicalHeaders = `host:${target.host}\nx-amz-content-sha256:${payloadHash}\nx-amz-date:${amzDate}\n`;
    const canonicalRequest = [
        method,
        target.pathname,
        canonicalQuery,
        canonicalHeaders,
        signedHeaders,
        payloadHash,
    ].join('\n');

    const scope = `${dateStamp}/${region}/${SERVICE}/aws4_request`;
    const stringToSign = [ALGORITHM, amzDate, scope, sha256(canonicalRequest)].join('\n');

    const signingKey = [dateStamp, region, SERVICE, 'aws4_request']
        .reduce((key, part) => hmac(key, part), `AWS4${secretKey}`);
    const signature = createHmac('sha256', signingKey).update(stringToSign, 'utf8').digest('hex');

    return {
        'x-amz-date': amzDate,
        'x-amz-content-sha256': payloadHash,
        authorization: `${ALGORITHM} Credential=${accessKey}/${scope}, SignedHeaders=${signedHeaders}, Signature=${signature}`,
    };
}

async function send(config, { method, pathname, query = '', body = '' }) {
    const url = `${config.origin}${pathname}${query}`;
    const headers = sign({ method, url, body, ...config });

    const response = await fetch(url, { method, headers, body: body === '' ? undefined : body });

    return { status: response.status, text: await response.text() };
}

/**
 * Create the bucket. Idempotent: a bucket this account already owns is success,
 * so `e2e:up` can run against a storage backend that is already provisioned.
 */
export async function createBucket(config) {
    const { status, text } = await send(config, { method: 'PUT', pathname: `/${config.bucket}` });

    if (status < 300) return 'created';
    if (/BucketAlreadyOwnedByYou|BucketAlreadyExists/.test(text)) return 'exists';

    throw new Error(`Could not create bucket ${config.bucket} (HTTP ${status}): ${text.slice(0, 300)}`);
}

/** Grant anonymous `s3:GetObject` on the bucket. Replaces any policy already there. */
export async function putPublicReadPolicy(config) {
    const { status, text } = await send(config, {
        method: 'PUT',
        pathname: `/${config.bucket}`,
        query: '?policy',
        body: PUBLIC_READ_POLICY(config.bucket),
    });

    if (status >= 300) {
        throw new Error(`Could not set the public-read policy on ${config.bucket} (HTTP ${status}): ${text.slice(0, 300)}`);
    }
}

/**
 * Verify the policy actually took: fetch a key that does not exist, without
 * credentials. A bucket open to anonymous reads answers 404 (no such key); one
 * that is not answers 403. Getting this wrong is silent until a browser test
 * fails on a missing thumbnail, so it is checked at provisioning time.
 */
export async function assertAnonymousRead(config) {
    const response = await fetch(`${config.origin}/${config.bucket}/e2e-anonymous-read-probe`);

    if (response.status === 403) {
        throw new Error(`${config.bucket} is not anonymously readable — the browser could not load thumbnails.`);
    }
}
