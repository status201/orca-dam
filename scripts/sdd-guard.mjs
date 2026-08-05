#!/usr/bin/env node
// SDD guard — enforces spec-driven development (see specs/README.md).
//
// One zero-dep script backing several Claude Code hooks + CI. Two rules:
//
//   1. Spec-before-code — a change that touches *production code* must also touch a
//      spec under specs/, unless it is exempt. Exempt = an allowlisted path, or an
//      explicit marker (a gitignored `.sdd-skip` sentinel locally, or `[skip-sdd]` /
//      a `skip-sdd` label in CI).
//   2. Contract-changes-bump-the-version — a feature spec whose `## Requirements` or
//      `## Technical design` changed must also increment its `version:`. Both rules
//      share the same marker as their escape hatch.
//
// Modes (argv[2]):
//   pretool  PreToolUse hook  — deny an Edit/Write to gated code with no spec change
//   stop     Stop hook        — block finishing if either rule is broken
//   remind   SessionStart     — print the procedure into context
//   ci       GitHub Action    — fail the PR if either rule is broken
//   version  standalone       — rule 2 only (`npm run spec:version`)
//
// Rule 2 is deliberately absent from `pretool`: that hook fires *before* the edit
// lands, so the bump cannot exist yet and gating there would deny the first edit to
// every spec. Stop and CI judge the finished change instead.
//
// Blocking is via exit code 2 (stderr is shown back to the agent) for the hook
// modes, and exit code 1 for ci/version. Anything unexpected fails open (exit 0) so
// the guard can never wedge a session on its own bug.

import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';

const mode = process.argv[2] ?? '';

function git(args, cwd) {
  try {
    // stderr is discarded, not inherited: every caller here treats a failure as "no"
    // (a ref that does not resolve, a path absent from a commit), and execFileSync
    // forwards the child's stderr to ours by default — which printed git's `fatal:`
    // lines for probes that are *expected* to fail, e.g. `show <base>:<new-spec>`.
    return execFileSync('git', args, {
      cwd,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'ignore'],
    }).trim();
  } catch {
    return '';
  }
}

const ROOT = git(['rev-parse', '--show-toplevel'], process.cwd()) || process.cwd();

/**
 * The base ref to diff a branch against (CI sets BASE_REF; else origin/main).
 *
 * GITHUB_BASE_REF is a bare branch name, so `origin/` has to be prepended — but
 * prepending it *unconditionally* meant a value that was already a full ref (a tag,
 * a local branch) turned into a ref that does not exist. Every caller then diffed
 * against nothing and reported OK, so a misconfigured base silently passed the gate.
 * Resolve as given first, fall back to the remote-tracking form, and let callers see
 * null rather than a string that only looks like a ref.
 */
function baseRef() {
  if (baseRef.cached !== undefined) return baseRef.cached;
  const env = (process.env.BASE_REF || process.env.GITHUB_BASE_REF || '').trim();
  const candidates = env
    ? [env, `origin/${env.replace(/^origin\//, '')}`]
    : ['origin/main', 'main'];
  baseRef.cached = null;
  for (const ref of candidates) {
    if (git(['rev-parse', '--verify', '--quiet', `${ref}^{commit}`], ROOT)) {
      baseRef.cached = ref;
      break;
    }
  }
  return baseRef.cached;
}

/**
 * Both branch-scoped rules need a base to diff against. When there is none (a shallow
 * clone, no remote, a typo'd BASE_REF) they cannot run — the guard still fails open,
 * per the contract at the top of this file, but it must never do so *silently*, or a
 * misconfigured CI job reads as a clean pass.
 */
function announceMissingBase(label) {
  const env = process.env.BASE_REF || process.env.GITHUB_BASE_REF || '(unset)';
  process.stdout.write(
    `${label}: SKIPPED — no base ref to diff against (BASE_REF=${env}, origin/main absent).\n`,
  );
}

const FRAMEWORK_CONFIG = new Set([
  'app', 'auth', 'cache', 'database', 'filesystems',
  'logging', 'mail', 'queue', 'services', 'session',
]); // published by Laravel — a framework upgrade must not demand a spec

/** A path is "production code" (needs a spec) unless it is on the allowlist. */
function isProductionPath(rel) {
  const p = rel.replace(/\\/g, '/').replace(/^\.\//, '');
  const gated =
    p.startsWith('app/') ||
    p.startsWith('routes/') ||
    p.startsWith('database/migrations/') ||
    p.startsWith('config/') ||
    p.startsWith('resources/js/');
  if (!gated) return false;
  if (p.endsWith('.md')) return false;
  if (p.startsWith('resources/js/vendor/')) return false;
  const cfg = p.match(/^config\/([\w-]+)\.php$/);
  if (cfg && FRAMEWORK_CONFIG.has(cfg[1])) return false;
  return true;
}

/** Convert an absolute or relative path to a repo-root-relative POSIX path. */
function toRel(file) {
  if (!file) return '';
  const abs = path.isAbsolute(file) ? file : path.resolve(ROOT, file);
  return path.relative(ROOT, abs).replace(/\\/g, '/');
}

/** Did this change touch any spec? (uncommitted, or committed on this branch.) */
function specChanged() {
  if (git(['status', '--porcelain', '--', 'specs'], ROOT)) return true;
  const base = baseRef();
  if (base && git(['diff', '--name-only', `${base}...HEAD`, '--', 'specs'], ROOT)) return true;
  return false;
}

/** Explicit trivial-change marker. */
function markerPresent({ ci = false } = {}) {
  if (process.env.SDD_SKIP === '1') return true;
  if (existsSync(path.join(ROOT, '.sdd-skip'))) return true;
  if (ci) {
    if (process.env.SDD_SKIP_LABEL === 'true') return true;
    const base = baseRef();
    const log = base ? git(['log', '--format=%B', `${base}..HEAD`], ROOT) : '';
    if (/\[skip-sdd\]/i.test(log)) return true;
  }
  return false;
}

/** Production files changed in the working tree (staged + unstaged + untracked). */
function changedProductionWorkingTree() {
  const out = git(['status', '--porcelain'], ROOT);
  if (!out) return [];
  const files = [];
  for (const line of out.split('\n')) {
    if (!line.trim()) continue;
    let p = line.slice(3); // strip the XY status + space
    const arrow = p.indexOf(' -> '); // renames: "old -> new"
    if (arrow >= 0) p = p.slice(arrow + 4);
    p = p.replace(/^"|"$/g, '');
    if (isProductionPath(p)) files.push(p);
  }
  return files;
}

/** Production files changed on this branch vs base (for CI). */
function changedProductionBranch() {
  const base = baseRef();
  if (!base) return [];
  const out = git(['diff', '--name-only', `${base}...HEAD`], ROOT);
  if (!out) return [];
  return out.split('\n').map((s) => s.trim()).filter(isProductionPath);
}

// ── rule 2: a contract change bumps the version ──────────────────────────────

// The two sections that *are* the contract. Everything else in a feature spec may
// change freely: Background/Why is context, Tests & verification is bookkeeping,
// Open questions records what does not exist yet, and Scenarios must stay free —
// the bug-fix procedure prescribes adding a regression scenario, so counting that
// as a contract change would force a bump on nearly every fix.
const CONTRACT_SECTIONS = new Set(['Requirements', 'Technical design']);

// Only feature specs. recipes/ carry a `version:` too but follow the leaner playbook
// shape (Background → Steps → Gotchas → Scenarios → Tests) with no contract section
// for this rule to read; ADRs carry no `version:` at all.
const SPEC_DIR = 'specs/features/';

function isVersionedSpec(rel) {
  const p = rel.replace(/\\/g, '/').replace(/^\.\//, '');
  if (!p.startsWith(SPEC_DIR) || !p.endsWith('.md')) return false;
  const base = path.posix.basename(p);
  return base !== 'README.md' && !base.startsWith('_'); // templates are not specs
}

/** The contract half of a spec, normalized so trailing whitespace cannot flag it. */
function contractOf(text) {
  const out = [];
  let section = '';
  for (const line of text.split('\n')) {
    const heading = line.match(/^## +(.+?) *$/);
    if (heading) section = heading[1];
    if (CONTRACT_SECTIONS.has(section)) out.push(line.replace(/\s+$/, ''));
  }
  return out.join('\n');
}

/** `version:` as a positive integer, or null. Strips a trailing `# comment`. */
function versionOf(text) {
  const m = text.match(/^version: *(.*)$/m);
  if (!m) return null;
  const raw = m[1].split('#')[0].trim();
  return /^[1-9]\d*$/.test(raw) ? Number(raw) : null;
}

function readWorkingTree(rel) {
  try {
    return readFileSync(path.join(ROOT, rel), 'utf8');
  } catch {
    return null; // deleted in this change — nothing to version
  }
}

/**
 * Feature specs whose contract changed against the base without a version bump.
 *
 * Judges committed and uncommitted work together, the same way specChanged() does:
 * base ref → what is on disk now. That is also what makes one implementation serve
 * both Stop (a dirty working tree) and CI (a clean checkout of the PR head).
 */
function unbumpedSpecs() {
  const base = baseRef();
  if (!base) return []; // no base to diff — callers announce the skip

  const candidates = new Set();
  for (const line of git(['status', '--porcelain', '--', SPEC_DIR], ROOT).split('\n')) {
    if (!line.trim()) continue;
    let p = line.slice(3);
    const arrow = p.indexOf(' -> ');
    if (arrow >= 0) p = p.slice(arrow + 4);
    candidates.add(p.replace(/^"|"$/g, ''));
  }
  for (const p of git(['diff', '--name-only', `${base}...HEAD`, '--', SPEC_DIR], ROOT).split('\n')) {
    if (p.trim()) candidates.add(p.trim());
  }

  const offenders = [];
  for (const rel of candidates) {
    if (!isVersionedSpec(rel)) continue;

    // An empty blob means the file is new on this branch (or was renamed into
    // place): there is no previous contract to have changed.
    const before = git(['show', `${base}:${rel}`], ROOT);
    if (!before) continue;

    const after = readWorkingTree(rel);
    if (after === null) continue;
    if (contractOf(before) === contractOf(after)) continue;

    const from = versionOf(before);
    const to = versionOf(after);
    if (from !== null && to !== null && to > from) continue;

    offenders.push({ rel, from, to });
  }

  return offenders.sort((a, b) => a.rel.localeCompare(b.rel));
}

function describeUnbumped(offenders) {
  return offenders
    .map(({ rel, from, to }) => {
      const seen = from === null ? '?' : from;
      const now = to === null ? 'missing/invalid' : to;
      return `  - ${rel}  (version ${seen} → ${now}, expected ${seen === '?' ? 'an integer' : seen + 1}+)`;
    })
    .join('\n');
}

const VERSION_ADVICE =
  `A spec's \`version:\` records contract revisions, so bump it when \`## Requirements\` or\n` +
  `\`## Technical design\` changes. Editing Background/Why, Scenarios (a regression scenario\n` +
  `for a fix included), Tests & verification or Open questions does not need a bump.\n` +
  `See specs/README.md → "Procedure by change type".`;

async function readStdin() {
  const chunks = [];
  for await (const c of process.stdin) chunks.push(c);
  return Buffer.concat(chunks).toString('utf8');
}

function parse(json) {
  try {
    return JSON.parse(json);
  } catch {
    return {};
  }
}

const PROCEDURE = `SDD is enforced in this repo (see specs/README.md → "Procedure by change type").
Spec-before-code: a change that edits production code (app/**, routes/**,
database/migrations/**, config/**, resources/js/**) must also create/update a spec
under specs/ in the same change.
  • Feature/behaviour change → write specs/features/<name>.md first (/feature, /spec)
  • Bug fix → add/correct the governing spec's scenario (a regression scenario)
  • Refactor (no behaviour change) → keep the spec's contract + scenarios green
  • Trivial (typo/comment/dep-bump/test/style/docs) → exempt; for a rare trivial
    production tweak run:  touch .sdd-skip
Version: a feature spec whose ## Requirements or ## Technical design changes must also
increment its \`version:\`. Scenarios, tests, background and open questions do not.`;

function denyPretool(rel) {
  process.stderr.write(
    `SDD gate: editing production code (${rel}) requires a spec change first.\n` +
      `Write or update the relevant spec under specs/ (spec-before-code) — e.g. /feature or /spec —\n` +
      `then retry this edit. If this change is genuinely trivial, run:  touch .sdd-skip\n` +
      `See specs/README.md → "Procedure by change type".\n`,
  );
  process.exit(2);
}

async function runPretool() {
  const input = parse(await readStdin());
  const file = input?.tool_input?.file_path;
  const rel = toRel(file);
  if (!rel || !isProductionPath(rel)) process.exit(0);
  if (markerPresent() || specChanged()) process.exit(0);
  denyPretool(rel);
}

async function runStop() {
  const input = parse(await readStdin());
  if (input?.stop_hook_active) process.exit(0); // never loop on ourselves
  if (markerPresent()) process.exit(0);

  const problems = [];

  // Rule 1 — spec-before-code.
  const changed = changedProductionWorkingTree();
  if (changed.length > 0 && !specChanged()) {
    problems.push(
      `SDD backstop: production code changed but no spec under specs/ was updated:\n` +
        changed.map((f) => `  - ${f}`).join('\n') +
        `\nReconcile the spec (add/update the governing spec, or a regression scenario for a fix),\n` +
        `or run  touch .sdd-skip  if this change is genuinely trivial.`,
    );
  }

  // Rule 2 — a changed contract bumps the version. Runs whether or not production
  // code changed: a spec-only change still owes the bump.
  const unbumped = unbumpedSpecs();
  if (unbumped.length > 0) {
    problems.push(
      `SDD backstop: a spec's contract changed without a version bump:\n` +
        describeUnbumped(unbumped) +
        `\n${VERSION_ADVICE}\n` +
        `If the edit was cosmetic (a typo inside a REQ line), run  touch .sdd-skip`,
    );
  }

  if (problems.length === 0) process.exit(0);
  process.stderr.write(problems.join('\n\n') + '\n');
  process.exit(2);
}

function runRemind() {
  let out = PROCEDURE;
  if (!markerPresent()) {
    const changed = changedProductionWorkingTree();
    if (changed.length > 0 && !specChanged()) {
      out += `\n\n⚠ This working tree already changes production code without a spec:\n` +
        changed.map((f) => `  - ${f}`).join('\n');
    }
    const unbumped = unbumpedSpecs();
    if (unbumped.length > 0) {
      out += `\n\n⚠ A spec contract already changed here without a version bump:\n` +
        describeUnbumped(unbumped);
    }
  }
  process.stdout.write(out + '\n');
  process.exit(0);
}

function runCi() {
  if (markerPresent({ ci: true })) {
    process.stdout.write('SDD check skipped (marker present).\n');
    process.exit(0);
  }

  const failures = [];

  if (!baseRef()) {
    announceMissingBase('SDD check');
    process.exit(0);
  }

  const changed = changedProductionBranch();
  if (changed.length === 0) {
    process.stdout.write('SDD check: no production-code changes — OK.\n');
  } else if (specChanged()) {
    process.stdout.write('SDD check: production code changed and a spec was updated — OK.\n');
  } else {
    failures.push(
      `SDD check FAILED: production code changed without any spec under specs/:\n` +
        changed.map((f) => `  - ${f}`).join('\n') +
        `\nAdd/update the governing spec, or add [skip-sdd] to a commit message / the` +
        ` 'skip-sdd' PR label if this change is genuinely trivial.`,
    );
  }

  const unbumped = unbumpedSpecs();
  if (unbumped.length === 0) {
    process.stdout.write('SDD check: no spec contract changed without a version bump — OK.\n');
  } else {
    failures.push(
      `SDD check FAILED: a spec's contract changed without a version bump:\n` +
        describeUnbumped(unbumped) +
        `\n${VERSION_ADVICE}\n` +
        `If the edit was cosmetic, add [skip-sdd] to a commit message / the 'skip-sdd' PR label.`,
    );
  }

  if (failures.length === 0) process.exit(0);
  process.stderr.write(failures.join('\n\n') + '\n');
  process.exit(1);
}

/** Rule 2 on its own — `npm run spec:version`, and handy in a pre-push hook. */
function runVersion() {
  if (markerPresent({ ci: true })) {
    process.stdout.write('Spec version check skipped (marker present).\n');
    process.exit(0);
  }
  if (!baseRef()) {
    announceMissingBase('Spec version check');
    process.exit(0);
  }
  const unbumped = unbumpedSpecs();
  if (unbumped.length === 0) {
    process.stdout.write(`Spec version check: no contract changed without a bump (base ${baseRef()}) — OK.\n`);
    process.exit(0);
  }
  process.stderr.write(
    `Spec version check FAILED: a spec's contract changed without a version bump:\n` +
      describeUnbumped(unbumped) +
      `\n${VERSION_ADVICE}\n`,
  );
  process.exit(1);
}

switch (mode) {
  case 'pretool':
    await runPretool();
    break;
  case 'stop':
    await runStop();
    break;
  case 'remind':
    runRemind();
    break;
  case 'ci':
    runCi();
    break;
  case 'version':
    runVersion();
    break;
  default:
    process.stderr.write(
      `sdd-guard: unknown mode "${mode}" (use pretool|stop|remind|ci|version)\n`,
    );
    process.exit(0); // fail open
}
