#!/usr/bin/env node
//
// Brings the S3 stand-in the Playwright suite stores into up and down:
//   node scripts/e2e-storage.mjs up | down | bucket
// Wired as `npm run e2e:up` / `e2e:down`.
//
// Two ways to run it, picked automatically:
//
//   • Docker — `docker-compose.e2e.yml`, which is what CI uses.
//   • A downloaded RustFS binary under storage/e2e/ — the fallback for a machine
//     with no container runtime. MinIO, which this replaces, stopped publishing
//     binaries before it was archived, so that option did not exist before and
//     the storage specs simply skipped. See ADR-017.
//
// Either way the bucket is provisioned by tests/e2e/support/bucket.js, so local
// and CI provision it identically — the MinIO setup used `mc` in one and the
// runner's `aws` CLI in the other, and only one of those was ever exercised
// locally.
//
// Contract: specs/features/e2e-testing.md REQ-2 · Decision: ADR-017.
import { spawn, spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, openSync, readFileSync, rmSync, unlinkSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { ROOT } from '../tests/e2e/support/db.js';
import { endpoint } from '../tests/e2e/support/s3.js';
import { assertAnonymousRead, bucketConfig, createBucket, putPublicReadPolicy } from '../tests/e2e/support/bucket.js';

// Pinned, like every other third-party artefact in this repo (the CI actions are
// pinned to commit SHAs for the same reason): a storage backend that changes
// under you turns an unrelated PR red. Nothing bumps this automatically — it
// moves when someone edits the constants below together with the image tag in
// docker-compose.e2e.yml, re-recording the digests from the release's SHA256SUMS.
const VERSION = '1.0.0-rc.6';
const RELEASE = `https://github.com/rustfs/rustfs/releases/download/${VERSION}`;

// asset name → sha256, from https://github.com/rustfs/rustfs/releases/download/1.0.0-rc.6/SHA256SUMS
const BINARIES = {
    'win32-x64': ['rustfs-windows-x86_64-v1.0.0-rc.6.zip', 'e9f4ad57ea8596a41d0e5879c565784021663ecca32c40e69527cf575f107f97'],
    'linux-x64': ['rustfs-linux-x86_64-gnu-v1.0.0-rc.6.zip', '68d0df70b4c7b377e1bb9a2681b7325ffd00d6a78e3acb61e65d30d257459ae9'],
    'linux-arm64': ['rustfs-linux-aarch64-gnu-v1.0.0-rc.6.zip', 'ceaf1496f057d829e95e3c0e0ee11d4b7b3a590326e4b24b4f06f8a2791e3f3b'],
    'darwin-arm64': ['rustfs-macos-aarch64-v1.0.0-rc.6.zip', 'eb3c2b8a6f4bbe2734f9545413922604ba8bc00e50a6463cef3a321c4ca988e7'],
};

const COMPOSE_FILE = path.join(ROOT, 'docker-compose.e2e.yml');
const STATE_DIR = path.join(ROOT, 'storage', 'e2e');
const BIN_DIR = path.join(STATE_DIR, 'bin');
const DATA_DIR = path.join(STATE_DIR, 'rustfs-data');
const PID_FILE = path.join(STATE_DIR, 'rustfs.pid');
const LOG_FILE = path.join(STATE_DIR, 'rustfs.log');

const READY_ATTEMPTS = 60;
const READY_INTERVAL_MS = 500;

const log = (message) => process.stdout.write(`${message}\n`);
const sleep = (ms) => new Promise((resolve) => { setTimeout(resolve, ms); });

/** Is there a usable Docker with Compose v2? */
function hasDocker() {
    const probe = spawnSync('docker', ['compose', 'version'], { stdio: 'ignore', shell: false });

    return probe.status === 0;
}

function docker(args) {
    const result = spawnSync('docker', args, { cwd: ROOT, stdio: 'inherit', shell: false });

    if (result.status !== 0) throw new Error(`docker ${args.join(' ')} failed (exit ${result.status}).`);
}

/**
 * The RustFS build for this machine. Only the four platforms the project publishes
 * binaries for; anything else (an Intel Mac, a 32-bit runner) has to use Docker.
 */
function binaryForThisPlatform() {
    const key = `${process.platform}-${process.arch}`;
    const entry = BINARIES[key];

    if (!entry) {
        throw new Error(`RustFS publishes no ${VERSION} binary for ${key} — install Docker and re-run, or set E2E_S3=0 to skip the storage specs.`);
    }

    return { asset: entry[0], sha256: entry[1] };
}

/** Unpack a .zip with the tar every supported platform already ships (bsdtar reads zip). */
function extract(archive, into) {
    // On Windows `tar` may resolve to Git's GNU tar, which cannot read a zip; the
    // system one can. Everywhere else the PATH tar is bsdtar or GNU tar 1.35+.
    const tar = process.platform === 'win32'
        ? path.join(process.env.SystemRoot || 'C:\\Windows', 'System32', 'tar.exe')
        : 'tar';

    const result = spawnSync(tar, ['-xf', archive, '-C', into], { stdio: 'inherit', shell: false });

    if (result.status !== 0) throw new Error(`Could not unpack ${path.basename(archive)} (exit ${result.status}).`);
}

/**
 * Path to the RustFS executable, downloading it on first use.
 *
 * The digest is verified before anything is unpacked, let alone run: this fetches
 * an executable over the network, and a pinned version with an unpinned payload
 * would only be pretending to be pinned.
 */
async function ensureBinary() {
    const binary = path.join(BIN_DIR, process.platform === 'win32' ? 'rustfs.exe' : 'rustfs');
    if (existsSync(binary)) return binary;

    const { asset, sha256 } = binaryForThisPlatform();
    mkdirSync(BIN_DIR, { recursive: true });

    log(`Downloading RustFS ${VERSION} (${asset}) — once; it is cached in storage/e2e/bin/.`);
    const response = await fetch(`${RELEASE}/${asset}`);
    if (!response.ok) throw new Error(`Download failed: HTTP ${response.status} for ${RELEASE}/${asset}`);

    const bytes = Buffer.from(await response.arrayBuffer());
    const digest = createHash('sha256').update(bytes).digest('hex');

    if (digest !== sha256) {
        throw new Error(`Checksum mismatch for ${asset}.\n  expected ${sha256}\n  got      ${digest}`);
    }

    const archive = path.join(BIN_DIR, asset);
    writeFileSync(archive, bytes);
    extract(archive, BIN_DIR);
    unlinkSync(archive);

    if (!existsSync(binary)) throw new Error(`${asset} did not contain ${path.basename(binary)}.`);

    return binary;
}

/** Credentials, bucket and origin from `.env.e2e`, or a legible failure. */
function requireBucketConfig() {
    const config = bucketConfig();

    if (!config) {
        throw new Error('.env.e2e does not describe a usable loopback endpoint and bucket — see specs/features/e2e-testing.md REQ-2.');
    }

    return config;
}

/** The port `.env.e2e` names, so the server and the app cannot disagree about it. */
function addressFromEnv() {
    const origin = endpoint();
    if (!origin) throw new Error('AWS_ENDPOINT in .env.e2e is missing or not a loopback URL.');

    const url = new URL(origin);

    return `127.0.0.1:${url.port || '80'}`;
}

async function waitForHealth() {
    const origin = endpoint();

    for (let attempt = 0; attempt < READY_ATTEMPTS; attempt += 1) {
        try {
            // /health/ready, not /health: the latter answers as soon as the HTTP
            // listener is up, while the storage layer is still assembling quorum
            // and every S3 call comes back 503. Readiness is what we need here.
            const response = await fetch(`${origin}/health/ready`, { signal: AbortSignal.timeout(1000) });
            if (response.ok) return;
        } catch {
            // Not up yet.
        }

        await sleep(READY_INTERVAL_MS);
    }

    throw new Error(`RustFS was not ready at ${origin}/health/ready within ${(READY_ATTEMPTS * READY_INTERVAL_MS) / 1000}s. See storage/e2e/rustfs.log.`);
}

function runningPid() {
    if (!existsSync(PID_FILE)) return null;

    const pid = Number(readFileSync(PID_FILE, 'utf8').trim());
    if (!Number.isInteger(pid) || pid <= 0) return null;

    try {
        process.kill(pid, 0);

        return pid;
    } catch {
        return null;
    }
}

async function startBinary() {
    if (runningPid()) {
        log('RustFS is already running (storage/e2e/rustfs.pid).');

        return;
    }

    const binary = await ensureBinary();
    mkdirSync(DATA_DIR, { recursive: true });

    const config = requireBucketConfig();
    const out = openSync(LOG_FILE, 'a');

    // Detached: `npm run e2e:up` returns and the server outlives it, the same
    // lifecycle `docker compose up -d` gives. `e2e:down` kills it by pid.
    const child = spawn(binary, [
        'server',
        '--address', addressFromEnv(),
        '--access-key', config.accessKey,
        '--secret-key', config.secretKey,
        '--region', config.region,
        DATA_DIR,
    ], { cwd: ROOT, detached: true, stdio: ['ignore', out, out], windowsHide: true });

    child.unref();
    writeFileSync(PID_FILE, String(child.pid));
    log(`RustFS ${VERSION} started (pid ${child.pid}), logging to storage/e2e/rustfs.log.`);
}

function stopBinary() {
    const pid = runningPid();

    if (pid === null) {
        log('No RustFS process to stop.');
    } else if (process.platform === 'win32') {
        // /T so the whole tree goes; a bare kill can leave workers behind.
        spawnSync('taskkill', ['/PID', String(pid), '/T', '/F'], { stdio: 'ignore', shell: false });
        log(`Stopped RustFS (pid ${pid}).`);
    } else {
        process.kill(pid, 'SIGTERM');
        log(`Stopped RustFS (pid ${pid}).`);
    }

    rmSync(PID_FILE, { force: true });
    rmSync(DATA_DIR, { recursive: true, force: true });
}

/** Create the bucket and open it to anonymous reads. Safe to re-run. */
async function provisionBucket() {
    const config = requireBucketConfig();

    const outcome = await createBucket(config);
    await putPublicReadPolicy(config);
    await assertAnonymousRead(config);

    log(`Bucket ${config.bucket} ${outcome === 'exists' ? 'already present' : 'created'}, anonymously readable at ${config.origin}/${config.bucket}.`);
}

async function up() {
    if (hasDocker()) {
        log('Starting RustFS with Docker Compose.');
        docker(['compose', '-f', COMPOSE_FILE, 'up', '-d', 'rustfs']);
    } else {
        log('No Docker found — starting the RustFS binary instead.');
        await startBinary();
    }

    await waitForHealth();
    await provisionBucket();
}

function down() {
    if (hasDocker()) {
        docker(['compose', '-f', COMPOSE_FILE, 'down', '-v']);
    }

    // Always: a machine can gain Docker between `up` and `down`, and a stale
    // detached process holding the port is the worst thing to leave behind.
    stopBinary();
}

const COMMANDS = {
    up,
    down: async () => down(),
    bucket: provisionBucket,
};

const command = process.argv[2];

if (!Object.hasOwn(COMMANDS, command ?? '')) {
    process.stderr.write(`Usage: node scripts/e2e-storage.mjs ${Object.keys(COMMANDS).join('|')}\n`);
    process.exit(2);
}

try {
    await COMMANDS[command]();
} catch (error) {
    process.stderr.write(`${error.message}\n`);
    process.exit(1);
}
