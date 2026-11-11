#!/usr/bin/env node
// Stamps bootstrap/cache/orca-version.json for the System → Overview "ORCA version" card.
// Runs before `vite build` (package.json). Never fails the build: without git it warns,
// removes any stale stamp, and the card reads "Unknown".
// Spec: specs/features/system-admin.md REQ-orca-version-shown.
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync, mkdirSync, rmSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const out = join(root, 'bootstrap', 'cache', 'orca-version.json');

const git = (...args) =>
    execFileSync('git', args, { cwd: root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
const tryGit = (...args) => {
    try {
        return git(...args);
    } catch {
        return null;
    }
};

// "v1.9.0" → [1, 9, 0]; compares release tags numerically (v1.10.0 > v1.9.0).
const parts = (tag) => tag.replace(/^v/, '').split('.').map(Number);
const isNewer = (a, b) => {
    const [x, y] = [parts(a), parts(b)];
    for (let i = 0; i < Math.max(x.length, y.length); i++) {
        if ((x[i] ?? 0) !== (y[i] ?? 0)) return (x[i] ?? 0) > (y[i] ?? 0);
    }
    return false;
};

const isMain = (name) => name === 'main' || name === 'master';

try {
    // Fails without git (or with git refusing the repo) — that is the "Unknown" case.
    const sha = git('rev-parse', '--short=7', 'HEAD');

    let changelog = '';
    try {
        changelog = readFileSync(join(root, 'CHANGELOG.md'), 'utf8');
    } catch {
        // no CHANGELOG — no codename, no stale-tag check
    }
    const codenameOf = (tag) => {
        const escaped = tag.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const heading = changelog.match(new RegExp(`^## \\[${escaped}\\][^\\n]*?—\\s*([^\\n—]+?)\\s*$`, 'm'));
        return heading ? heading[1] : null;
    };
    // The newest release the checked-out CHANGELOG records (the first `## [vX.Y.Z]` heading).
    const changelogRelease = changelog.match(/^## \[(v\d+(?:\.\d+)*)\]/m)?.[1] ?? null;

    // --match keeps the WordPress plugin's wp-v* tags out.
    const described = tryGit('describe', '--tags', '--long', '--match', 'v[0-9]*');
    const match = described?.match(/^(.+)-(\d+)-g[0-9a-f]+$/);
    let tag = match?.[1] ?? null;
    let ahead = match ? Number(match[2]) : null;

    // The CHANGELOG names a newer release than the local tags know: they were never fetched.
    // Show that release, and leave the commit count out rather than count from an old tag.
    if (changelogRelease && (!tag || isNewer(changelogRelease, tag))) {
        console.warn(
            `ORCA version: local tags are stale (newest ${tag ?? 'none'}, CHANGELOG ${changelogRelease}). ` +
                'Run `git fetch --tags` and rebuild for the unreleased-commit count.',
        );
        tag = changelogRelease;
        ahead = null;
    }
    if (!tag) throw new Error('no v* tag and no released version in CHANGELOG.md');

    // Hidden on main, and on a local `master` (the default branch name of an older clone of main).
    // Not by upstream: a feature branch made with `git switch -c x origin/main` tracks main too.
    let branch = tryGit('rev-parse', '--abbrev-ref', 'HEAD');
    if (!branch || branch === 'HEAD' || isMain(branch)) {
        branch = null;
    }

    const codename = codenameOf(tag);
    const stamp = { tag, codename, ahead, sha, branch, built_at: new Date().toISOString() };
    mkdirSync(dirname(out), { recursive: true });
    writeFileSync(out, JSON.stringify(stamp, null, 4) + '\n');
    console.log(
        `ORCA version: ${tag}${codename ? ` "${codename}"` : ''}` +
            (ahead ? ` +${ahead} unreleased` : '') +
            (ahead !== 0 ? ` · ${sha}` : '') +
            (branch ? ` (${branch})` : ''),
    );
} catch (error) {
    // A stale stamp from an earlier build would show the wrong version — remove it.
    rmSync(out, { force: true });
    // git's own reason, e.g. "detected dubious ownership" → git config --global --add safe.directory <path>
    const reason = (error.stderr?.toString().trim() || error.message).split('\n')[0];
    console.warn(`ORCA version not stamped (${reason}); the System page will show "Unknown".`);
}
