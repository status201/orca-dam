#!/usr/bin/env node
// Stamps bootstrap/cache/orca-version.json for the System → Overview "ORCA version" card.
// Runs before `vite build` (package.json). Never fails the build: without git or a v* tag
// it warns, removes any stale stamp, and the card reads "Unknown".
// Spec: specs/features/system-admin.md REQ-orca-version-shown.
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync, mkdirSync, rmSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const out = join(root, 'bootstrap', 'cache', 'orca-version.json');

const git = (...args) =>
    execFileSync('git', args, { cwd: root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();

try {
    // --match keeps the WordPress plugin's wp-v* tags out.
    const described = git('describe', '--tags', '--long', '--match', 'v[0-9]*');
    const match = described.match(/^(.+)-(\d+)-g([0-9a-f]+)$/);
    if (!match) throw new Error(`unexpected git describe output: ${described}`);
    const [, tag, ahead, sha] = match;

    let branch = null;
    try {
        const b = git('rev-parse', '--abbrev-ref', 'HEAD');
        branch = b === 'main' || b === 'HEAD' ? null : b;
    } catch {
        // detached or unreadable — leave the branch out
    }

    let codename = null;
    try {
        const changelog = readFileSync(join(root, 'CHANGELOG.md'), 'utf8');
        const escaped = tag.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const heading = changelog.match(new RegExp(`^## \\[${escaped}\\][^\\n]*?—\\s*([^\\n—]+?)\\s*$`, 'm'));
        codename = heading ? heading[1] : null;
    } catch {
        // no CHANGELOG — show the tag alone
    }

    const stamp = { tag, codename, ahead: Number(ahead), sha, branch, built_at: new Date().toISOString() };
    mkdirSync(dirname(out), { recursive: true });
    writeFileSync(out, JSON.stringify(stamp, null, 4) + '\n');
    console.log(`ORCA version: ${tag}${codename ? ` "${codename}"` : ''}${stamp.ahead ? ` +${stamp.ahead} unreleased · ${sha}` : ''}${branch ? ` (${branch})` : ''}`);
} catch (error) {
    // A stale stamp from an earlier build would show the wrong version — remove it.
    rmSync(out, { force: true });
    // git's own reason, e.g. "detected dubious ownership" → git config --global --add safe.directory <path>
    const reason = (error.stderr?.toString().trim() || error.message).split('\n')[0];
    console.warn(`ORCA version not stamped (${reason}); the System page will show "Unknown".`);
}
