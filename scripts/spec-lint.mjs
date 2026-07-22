#!/usr/bin/env node
// Spec-structure lint (see specs/README.md → "Enforcement & exemptions").
//
// The SDD guard (scripts/sdd-guard.mjs) checks that a spec *changed*; this checks
// that each spec is structurally *well-formed* and that the hand-maintained
// indexes stay complete. Zero-dep. Runs three ways:
//   • `npm run spec:lint` / `node scripts/spec-lint.mjs` — lint all, exit 1 on error
//   • CI (.github/workflows/sdd.yml)                     — same
//   • local Stop hook (`spec-lint.mjs hook`) — lints unconditionally (fast, and a
//     changed-this-turn gate misses turns that end after `git commit`);
//     exit 2 to block finishing, so a malformed spec is caught at edit time too
// It complements — never replaces — human review of spec quality.
//
// Per spec under specs/ (skipping README.md and any _*.md template):
//   • a leading ```yaml metadata block
//   • `id` present and equal to the filename (without .md)
//   • a valid `status` — ADR lifecycle under decisions/, else feature lifecycle
//   • every root-anchored `# pinned by:` path resolves (literal exists; glob ≥1)
// Plus repo-structure checks (drift prevention):
//   • every spec / ADR / template file is listed in the specs/README.md folder map
//   • every ADR is listed in the specs/decisions/README.md index
// Plus documented-fact checks (see "Version drift" below):
//   • dependency versions named in specs/**.md + CLAUDE.md match composer.json /
//     package.json *constraints*
//   • the hand-counted Alpine-module and spec/ADR totals in CLAUDE.md are right
// `version` not being a positive integer is a warning, not a failure.

import { execFileSync } from 'node:child_process';
import { readFileSync, readdirSync, existsSync } from 'node:fs';
import path from 'node:path';

function git(args, cwd) {
  try {
    return execFileSync('git', args, { cwd, encoding: 'utf8' }).trim();
  } catch {
    return '';
  }
}

const ROOT = git(['rev-parse', '--show-toplevel'], process.cwd()) || process.cwd();
const SPECS = path.join(ROOT, 'specs');

const FEATURE_STATUS = /^(draft|active|implemented)$/;
const ADR_STATUS = /^(proposed|accepted|deprecated|superseded by adr-\d{3,})$/;
const ADR_FILE = /^adr-\d{3,}-.+\.md$/;
const PRUNE = new Set([
  'node_modules', '.git', 'vendor', 'storage', 'bootstrap',
  'public', '.idea', '.phpunit.cache', 'coverage',
]);

const rel = (f) => path.relative(ROOT, f).replace(/\\/g, '/');

/** Recursively collect file paths under `dir` (absolute), pruning build dirs. */
function walk(dir, out = []) {
  for (const ent of readdirSync(dir, { withFileTypes: true })) {
    if (ent.isDirectory()) {
      if (PRUNE.has(ent.name) || ent.name.startsWith('dist-v')) continue;
      walk(path.join(dir, ent.name), out);
    } else {
      out.push(path.join(dir, ent.name));
    }
  }
  return out;
}

/** Lazily-built set of every repo file as a root-relative POSIX path (for globs). */
let _repoFiles = null;
function repoFiles() {
  if (_repoFiles) return _repoFiles;
  _repoFiles = walk(ROOT).map(rel);
  return _repoFiles;
}

function globToRegExp(glob) {
  let re = '';
  for (let i = 0; i < glob.length; i++) {
    const c = glob[i];
    if (c === '*') {
      if (glob[i + 1] === '*') { re += '.*'; i++; } else { re += '[^/]*'; }
    } else if (c === '?') {
      re += '[^/]';
    } else if ('.+^${}()|[]\\'.includes(c)) {
      re += '\\' + c;
    } else {
      re += c;
    }
  }
  return new RegExp('^' + re + '$');
}

/** First ```yaml fenced block's body, or null. */
function metadataBlock(text) {
  const m = text.match(/```ya?ml\s*\n([\s\S]*?)\n```/);
  return m ? m[1] : null;
}

/** The fenced code block under "## Folder map" in the specs README, or ''. */
function folderMapBlock(readme) {
  const after = readme.split('## Folder map')[1];
  if (!after) return '';
  const m = after.match(/```[a-z]*\r?\n([\s\S]*?)\r?\n```/);
  return m ? m[1] : '';
}

/** Top-level (column-0) scalar key from a flat YAML block. */
function topKey(block, key) {
  const re = new RegExp('^' + key + ':\\s*(.*)$', 'm');
  const m = block.match(re);
  if (!m) return null;
  return m[1].split('#')[0].trim().replace(/^["']|["']$/g, '');
}

/**
 * Path/glob tokens referenced by every `# pinned by:` line. The convention is
 * free-form prose (mixed `,`/`;` separators, bare symbols, spec cross-refs), so
 * we extract only **root-anchored** path substrings (app/ tests/ routes/ database/
 * resources/ config/ lang/ scripts/) and ignore everything else. Placeholders
 * (`<name>`, `...`) are skipped.
 */
function pinnedPaths(text) {
  const tokens = [];
  for (const m of text.matchAll(/#\s*pinned by:\s*(.+)/gi)) {
    for (const pm of m[1].matchAll(/\b(?:app|tests|routes|database|resources|config|lang|scripts)\/[\w./*[\]<>-]+/g)) {
      const tok = pm[0].replace(/[).,;]+$/, '');
      if (tok.includes('<') || tok.includes('...')) continue; // placeholders
      tokens.push(tok);
    }
  }
  return tokens;
}

// ── Version drift ────────────────────────────────────────────────────────────
// The docs state a *supported range*, so we compare against the constraints in
// composer.json / package.json — deliberately never composer.lock. A routine
// `composer update` inside an existing range must not trip this; only a real
// constraint change (an architectural fact) should.

/** Display names used in prose → the manifest package they refer to. */
const ALIASES = [
  ['PHP', 'php'],
  ['Laravel', 'laravel/framework'],
  ['Alpine', 'alpinejs'],
  ['Tailwind', 'tailwindcss'],
  ['Font Awesome', '@fortawesome/fontawesome-free'],
  ['Vite', 'vite'],
  ['PHPUnit', 'phpunit/phpunit'],
  ['Pest', 'pestphp/pest'],
  ['Intervention Image', 'intervention/image'],
];

function readJson(file) {
  try {
    return JSON.parse(readFileSync(file, 'utf8'));
  } catch {
    return null;
  }
}

/** package name → { constraint, manifest }, from composer.json + package.json. */
function manifestConstraints() {
  const out = new Map();
  const add = (obj, manifest) => {
    for (const [name, constraint] of Object.entries(obj || {})) {
      if (typeof constraint === 'string') out.set(name, { constraint, manifest });
    }
  };
  const composer = readJson(path.join(ROOT, 'composer.json'));
  add(composer?.require, 'composer.json');
  add(composer?.['require-dev'], 'composer.json');
  const pkg = readJson(path.join(ROOT, 'package.json'));
  add(pkg?.dependencies, 'package.json');
  add(pkg?.devDependencies, 'package.json');
  return out;
}

/** `^8.3` `~0.2.1` `3.x` `8.2+` `v13` `>=1.0` → `8.3` `0.2.1` `3` `8.2` `13` `1.0`. */
function normalizeVersion(v) {
  return v.trim().replace(/^(>=|[\^~v])/, '').replace(/(\+|\.x)$/, '').trim();
}

/**
 * The docs are deliberately coarser than the manifest (`^13` for `^13.0`), so a
 * documented version passes when it is the constraint, or a dot-boundary prefix
 * of it. `3` vs `4.2` and `8.2` vs `8.3` therefore still fail.
 */
function versionMatches(documented, constraint) {
  const d = normalizeVersion(documented);
  const a = normalizeVersion(constraint);
  return d !== '' && (d === a || a.startsWith(d + '.'));
}

const escapeRe = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/**
 * Pre-compiled matchers for every package we could verify. A claim is a package
 * name (or a prose alias) followed by a version token, optionally separated by
 * backticks / spaces / a YAML `#` comment marker. Only names that actually exist
 * in a manifest get a matcher, so unrelated versions already in the docs
 * (`api.cloudflare.com/client/v4`, `tikzjax.com/v1`, TeX Live) can never produce
 * a false positive. Matching is case-sensitive: `PHP` is the alias, while the
 * bare `php` requirement must not match prose like `phpunit.xml`.
 *
 * The boundaries are stricter than `\b`, which would let the bare `php`
 * requirement match the tail of `aws/aws-sdk-php` (a `-` is a word boundary).
 * The version token is digit-groups only, so a trailing sentence period in
 * "…via `laravel/passkeys` ~0.2.1." is not swallowed into the version.
 */
function versionMatchers(constraints) {
  const targets = [...[...constraints.keys()].map((n) => [n, n]), ...ALIASES];
  return targets
    .filter(([, pkg]) => constraints.has(pkg))
    .map(([label, pkg]) => ({
      label,
      pkg,
      re: new RegExp(
        `(?<![\\w/@.-])${escapeRe(label)}(?![\\w/-])`
        + '[\\s`]*#?[\\s`]*'
        + '([~^]?\\d+(?:\\.\\d+)*(?:\\+|\\.x)?)'
      ),
    }));
}

/** Every dependency version named in the docs must match the manifests. */
function checkVersions(errors, docFiles) {
  const constraints = manifestConstraints();
  if (!constraints.size) return;
  const matchers = versionMatchers(constraints);

  for (const file of docFiles) {
    if (!existsSync(file)) continue;
    const r = rel(file);
    readFileSync(file, 'utf8').split(/\r?\n/).forEach((line, i) => {
      for (const { label, pkg, re } of matchers) {
        const m = line.match(re);
        if (!m) continue;
        const { constraint, manifest } = constraints.get(pkg);
        if (!versionMatches(m[1], constraint)) {
          errors.push(`${r}:${i + 1}: ${label} documented as ${m[1]}, ${manifest} says ${constraint}`);
        }
      }
    });
  }
}

/** Hand-counted totals in CLAUDE.md that are cheap to verify against the tree. */
function checkCounts(errors) {
  const file = path.join(ROOT, 'CLAUDE.md');
  if (!existsSync(file)) return;

  const appJs = path.join(ROOT, 'resources', 'js', 'app.js');
  const modules = existsSync(appJs)
    ? (readFileSync(appJs, 'utf8').match(/^\s*import\s+['"]\.\/alpine\//gm) || []).length
    : null;

  const countMd = (dir, keep) => (existsSync(dir)
    ? readdirSync(dir).filter((f) => f.endsWith('.md') && keep(f)).length
    : null);
  const features = countMd(path.join(SPECS, 'features'), (f) => !f.startsWith('_') && f !== 'README.md');
  const adrs = countMd(path.join(SPECS, 'decisions'), (f) => ADR_FILE.test(f));

  readFileSync(file, 'utf8').split(/\r?\n/).forEach((line, i) => {
    const at = `CLAUDE.md:${i + 1}`;

    const mod = line.match(/(\d+)\s+(?:Alpine\s+)?modules in `resources\/js\/alpine\/`/);
    if (mod && modules !== null && Number(mod[1]) !== modules) {
      errors.push(`${at}: ${mod[1]} Alpine modules documented, resources/js/app.js imports ${modules}`);
    }

    const spec = line.match(/(\d+)\s+feature specs?,\s*(\d+)\s+ADRs?/);
    if (spec) {
      if (features !== null && Number(spec[1]) !== features) {
        errors.push(`${at}: ${spec[1]} feature specs documented, specs/features/ has ${features}`);
      }
      if (adrs !== null && Number(spec[2]) !== adrs) {
        errors.push(`${at}: ${spec[2]} ADRs documented, specs/decisions/ has ${adrs}`);
      }
    }
  });
}

function lint(failExit) {
  const errors = [];
  const warnings = [];
  const allMd = walk(SPECS).filter((f) => f.endsWith('.md'));
  const specs = allMd.filter((f) => {
    const base = path.basename(f);
    return base !== 'README.md' && !base.startsWith('_');
  });

  for (const file of specs) {
    const r = rel(file);
    const err = (msg) => errors.push(`${r}: ${msg}`);
    const warn = (msg) => warnings.push(`${r}: ${msg}`);
    const text = readFileSync(file, 'utf8');

    const block = metadataBlock(text);
    if (!block) {
      err('no leading ```yaml metadata block');
      continue;
    }

    const id = topKey(block, 'id');
    const expectedId = path.basename(file, '.md');
    if (!id) err('metadata is missing `id`');
    else if (id !== expectedId) err(`\`id: ${id}\` does not match filename (\`${expectedId}\`)`);

    const isAdr = r.includes('/decisions/');
    const status = topKey(block, 'status');
    const allowed = isAdr ? ADR_STATUS : FEATURE_STATUS;
    if (!status) err('metadata is missing `status`');
    else if (!allowed.test(status)) {
      err(`invalid \`status: ${status}\` (expected ${isAdr
        ? 'proposed | accepted | deprecated | superseded by adr-NNN'
        : 'draft | active | implemented'})`);
    }

    const version = topKey(block, 'version');
    if (version !== null && !/^[1-9]\d*$/.test(version)) {
      warn(`\`version: ${version}\` is not a positive integer`);
    }

    for (const tok of pinnedPaths(text)) {
      if (/[*?[]/.test(tok)) {
        const re = globToRegExp(tok);
        if (!repoFiles().some((f) => re.test(f))) err(`\`# pinned by:\` glob matches no file: ${tok}`);
      } else if (!existsSync(path.join(ROOT, tok))) {
        err(`\`# pinned by:\` path does not exist: ${tok}`);
      }
    }
  }

  // Structure: the hand-maintained enumerations must stay complete — this catches
  // the exact drift the lint exists to prevent (a new spec/ADR never indexed).
  const map = folderMapBlock(existsSync(path.join(SPECS, 'README.md'))
    ? readFileSync(path.join(SPECS, 'README.md'), 'utf8') : '');
  if (!map) {
    errors.push('specs/README.md: could not find the "## Folder map" code block');
  } else {
    for (const f of allMd) {
      if (path.basename(f) === 'README.md') continue; // the index files themselves
      if (!map.includes(path.basename(f))) errors.push(`${rel(f)}: not listed in the specs/README.md folder map`);
    }
  }

  const adrIndexFile = path.join(SPECS, 'decisions', 'README.md');
  const adrIndex = existsSync(adrIndexFile) ? readFileSync(adrIndexFile, 'utf8') : '';
  if (!adrIndex) {
    errors.push('specs/decisions/README.md: ADR index is missing');
  } else {
    for (const f of allMd) {
      if (ADR_FILE.test(path.basename(f)) && !adrIndex.includes(path.basename(f))) {
        errors.push(`${rel(f)}: not listed in the specs/decisions/README.md index`);
      }
    }
  }

  // Documented facts: versions named in the docs must match the manifests, and
  // the hand-counted totals must match the tree. Without this a spec can satisfy
  // every structural rule above while stating things that are simply untrue.
  checkVersions(errors, [...allMd, path.join(ROOT, 'CLAUDE.md')]);
  checkCounts(errors);

  if (warnings.length) {
    process.stdout.write(`spec-lint: ${warnings.length} warning(s):\n` +
      warnings.map((w) => `  ⚠ ${w}`).join('\n') + '\n');
  }
  if (errors.length) {
    process.stderr.write(`spec-lint FAILED: ${errors.length} error(s):\n` +
      errors.map((e) => `  ✗ ${e}`).join('\n') +
      `\nFix the spec(s) above, or update the README folder map / decisions index.\n`);
    process.exit(failExit);
  }
  process.stdout.write(`spec-lint: ${specs.length} specs OK.\n`);
  process.exit(0);
}

/** Did the working tree touch anything under specs/ this turn? */
async function readStdin() {
  const chunks = [];
  for await (const c of process.stdin) chunks.push(c);
  return Buffer.concat(chunks).toString('utf8');
}

function parseJson(json) {
  try {
    return JSON.parse(json);
  } catch {
    return {};
  }
}

// Stop-hook mode: lint unconditionally (a changed-this-turn gate misses turns
// that end after `git commit` — the tree is clean by then), but never loop on
// ourselves; block (exit 2) on failure so the agent fixes the spec before finishing.
async function runHook() {
  const input = parseJson(await readStdin());
  if (input?.stop_hook_active) process.exit(0);
  lint(2);
}

if ((process.argv[2] || '') === 'hook') {
  runHook();
} else {
  lint(1);
}
