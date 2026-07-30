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
//   • every `tests/…` path named ANYWHERE in the spec resolves too — the `- E2E:` /
//     `- Feature:` bullets in "## Tests & verification" used to be unchecked, so a
//     deleted test file could leave a spec claiming coverage it no longer had
//   • features/ and recipes/ specs have a `## Tests & verification` section at all
// Plus repo-structure checks (drift prevention):
//   • every spec / ADR / template file is listed in the specs/README.md folder map
//   • every ADR is listed in the specs/decisions/README.md index
// Plus documented-fact checks (see "Version drift" below), over specs/**.md AND the
// root docs — the same stale count used to sit in three files at once:
//   • dependency versions named there match composer.json / package.json *constraints*
//   • hand-counted totals are right: Alpine modules and spec/ADR counts (CLAUDE.md),
//     Pest test files (specs/architecture.md), E2E tests + spec files (e2e-testing.md)
//   • the single file tree (QUICK_REFERENCE.md) names every app/Services/,
//     app/Console/Commands/ and top-level directory entry, and nothing that is gone
//   • USER_MANUAL.md and GEBRUIKERSHANDLEIDING.md share one heading-level sequence,
//     so the Dutch manual cannot silently fall behind the English one
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
const PATH_TOKEN = /\b(?:app|tests|routes|database|resources|config|lang|scripts)\/[\w./*[\]<>-]+/g;

/** Strip trailing prose punctuation; null for a placeholder token. */
function cleanToken(raw) {
  const tok = raw.replace(/[).,;]+$/, '');
  if (tok.includes('<') || tok.includes('...')) return null; // placeholders
  return tok;
}

function pinnedPaths(text) {
  const tokens = [];
  for (const m of text.matchAll(/#\s*pinned by:\s*(.+)/gi)) {
    for (const pm of m[1].matchAll(PATH_TOKEN)) {
      const tok = cleanToken(pm[0]);
      if (tok) tokens.push(tok);
    }
  }
  return tokens;
}

/**
 * `tests/…` paths named in **backticks** anywhere in the spec — the
 * "## Tests & verification" bullets, mostly. Those are the spec's own claim about what
 * covers it, so a path that stopped existing is exactly as wrong as a broken pin; only
 * `# pinned by:` lines were checked before. Restricted to backticked spans so prose
 * mentioning a directory (`tests/e2e/` in a sentence) and fenced command examples don't
 * produce noise.
 */
function proseTestPaths(text) {
  // "Open questions / future" is where the method says to record *missing* coverage
  // (see specs/README.md — never fabricate a pin). Paths named there are deliberately
  // aspirational, so stop before it.
  const cut = text.search(/^## Open questions/m);
  const body = cut < 0 ? text : text.slice(0, cut);

  const tokens = [];
  for (const span of body.matchAll(/`([^`\n]+)`/g)) {
    for (const pm of span[1].matchAll(/\btests\/[\w./*[\]<>-]+/g)) {
      const tok = cleanToken(pm[0]);
      if (tok && !tok.endsWith('/')) tokens.push(tok);
    }
  }
  return tokens;
}

/** Resolve one path/glob token against the repo; returns an error string or null. */
function unresolved(tok, label) {
  if (/[*?[]/.test(tok)) {
    const re = globToRegExp(tok);
    if (!repoFiles().some((f) => re.test(f))) return `${label} glob matches no file: ${tok}`;
  } else if (!existsSync(path.join(ROOT, tok))) {
    return `${label} path does not exist: ${tok}`;
  }
  return null;
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
    checkableText(file).split(/\r?\n/).forEach((line, i) => {
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

/**
 * A doc's checkable text. For CHANGELOG.md that is only the `[Unreleased]` section:
 * released entries are a historical record of what shipped *then* ("all 643 tests
 * passing", "Laravel 12"), and rewriting them to match today would be a lie.
 */
function checkableText(file) {
  const text = readFileSync(file, 'utf8');
  if (path.basename(file) !== 'CHANGELOG.md') return text;
  const start = text.search(/^## \[Unreleased\]/m);
  if (start < 0) return '';
  const rest = text.slice(start + 1);
  const end = rest.search(/^## \[/m);
  const body = end < 0 ? rest : rest.slice(0, end);
  // Keep line numbers aligned with the file so error messages stay clickable.
  return '\n'.repeat(text.slice(0, start + 1).split('\n').length - 1) + body;
}

/** Every *.md at the repo root, plus .claude/agents/*.md — the prose-doc surface. */
function rootDocs() {
  const out = readdirSync(ROOT)
    .filter((f) => f.endsWith('.md'))
    .map((f) => path.join(ROOT, f));
  const agents = path.join(ROOT, '.claude', 'agents');
  if (existsSync(agents)) {
    for (const f of readdirSync(agents)) {
      if (f.endsWith('.md')) out.push(path.join(agents, f));
    }
  }
  return out;
}

/** Count files under `dir` matching `keep`, or null when the dir is absent. */
function countFiles(dir, keep) {
  return existsSync(dir) ? readdirSync(dir).filter(keep).length : null;
}

/** Recursively count *Test.php files under a directory. */
function countTestFiles(dir) {
  if (!existsSync(dir)) return null;
  return walk(dir).filter((f) => f.endsWith('Test.php')).length;
}

/**
 * Playwright tests: literal `test(` calls plus the ones a loop generates. Counting only
 * literals gets it wrong (that is exactly the drift this replaces), so loop-generated
 * cases are counted by resolving the array the loop iterates. `global.setup.js` is
 * excluded — it is not a spec file.
 */
function countE2e() {
  const dir = path.join(ROOT, 'tests', 'e2e');
  if (!existsSync(dir)) return null;
  const specs = readdirSync(dir).filter((f) => f.endsWith('.spec.js'));
  let total = 0;
  for (const f of specs) {
    const text = readFileSync(path.join(dir, f), 'utf8');
    const lines = text.split(/\r?\n/);
    // Track `for (const x of ARR)` blocks so a test( inside one counts ARR.length times.
    // ARR is either an inline literal or a top-level `const ARR = [ … ]`.
    let loopFactor = 1;
    let loopIndent = -1;
    for (const line of lines) {
      const indent = line.search(/\S/);
      if (loopIndent >= 0 && indent >= 0 && indent <= loopIndent) { loopFactor = 1; loopIndent = -1; }
      const loop = line.match(/for\s*\(\s*const\s+\w+\s+of\s+(.+?)\)\s*\{/);
      if (loop) {
        const src = loop[1].trim();
        let n = 0;
        const inline = src.match(/^\[(.*)\]$/);
        if (inline) {
          n = inline[1].split(',').filter((s) => s.trim() !== '').length;
        } else {
          const decl = text.match(new RegExp('const\\s+' + escapeRe(src) + '\\s*=\\s*\\[([\\s\\S]*?)\\]\\s*;', 'm'));
          if (decl) n = (decl[1].match(/\{/g) || decl[1].split(',')).length;
        }
        if (n > 1) { loopFactor = n; loopIndent = indent; }
        continue;
      }
      if (/^\s*test\(/.test(line)) total += loopFactor;
    }
  }
  return { tests: total, files: specs.length };
}

/**
 * Hand-counted totals stated in the docs. Every number here is one a human typed and
 * nothing else verifies, which is why each one had gone stale at least once.
 */
function checkCounts(errors, docFiles) {
  const appJs = path.join(ROOT, 'resources', 'js', 'app.js');
  const modules = existsSync(appJs)
    ? (readFileSync(appJs, 'utf8').match(/^\s*import\s+['"]\.\/alpine\//gm) || []).length
    : null;

  const features = countFiles(path.join(SPECS, 'features'), (f) => f.endsWith('.md') && !f.startsWith('_') && f !== 'README.md');
  const adrs = countFiles(path.join(SPECS, 'decisions'), (f) => ADR_FILE.test(f));
  const services = countFiles(path.join(ROOT, 'app', 'Services'), (f) => f.endsWith('.php'));
  const commands = countFiles(path.join(ROOT, 'app', 'Console', 'Commands'), (f) => f.endsWith('.php'));
  const testFiles = countTestFiles(path.join(ROOT, 'tests'));
  const e2e = countE2e();

  const rule = (re, actual, what) => ({ re, actual, what });
  const RULES = [
    rule(/(\d+)\s+(?:Alpine\s+)?modules(?: registered)? in `resources\/js\/(?:alpine\/|app\.js)`/, modules, 'Alpine modules'),
    rule(/(\d+)\s+feature specs?/, features, 'feature specs in specs/features/'),
    rule(/(\d+)\s+ADRs?\b/, adrs, 'ADRs in specs/decisions/'),
    rule(/(\d+)\s+services/, services, 'files in app/Services/'),
    // One rule for every phrasing the docs use, because the narrow pair this replaced
    // ("N artisan commands" / "N console commands") missed two: QUICK_REFERENCE.md's
    // "all 16 commands" sat one section above its own tree comment saying 17, and
    // maintenance-commands.md writes "17 `artisan` commands" — backticked, so the old
    // literal did not match. The qualifier is optional and its backticks are too.
    rule(/(\d+)\s+(?:`?artisan`?\s+|`?console`?\s+)?commands\b/, commands, 'files in app/Console/Commands/'),
    rule(/(\d+)\s+files:\s*`tests\/Feature\//, testFiles, '*Test.php files under tests/'),
    rule(/(\d+)\s+tests,\s*(?:in-memory SQLite|\d+ files)/, null, 'Pest tests (not auto-countable)'),
    rule(/(\d+)\s+tests across (\d+) spec files/, e2e && e2e.tests, 'Playwright tests'),
  ];

  for (const file of docFiles) {
    if (!existsSync(file)) continue;
    const r = rel(file);
    checkableText(file).split(/\r?\n/).forEach((line, i) => {
      const at = `${r}:${i + 1}`;
      for (const { re, actual, what } of RULES) {
        if (actual === null || actual === undefined) continue;
        const m = line.match(re);
        if (m && Number(m[1]) !== actual) {
          errors.push(`${at}: ${m[1]} documented, the tree has ${actual} ${what}`);
        }
      }
      // "N tests across M spec files" also states the file count.
      const m = line.match(/\d+\s+tests across (\d+) spec files/);
      if (m && e2e && Number(m[1]) !== e2e.files) {
        errors.push(`${at}: ${m[1]} E2E spec files documented, tests/e2e/ has ${e2e.files}`);
      }
    });
  }
}

/**
 * The repo's single annotated file tree. Two failure modes, both seen in the wild: it
 * names a path that no longer exists, and — the silent one — a new service or command
 * never gets added. Both are caught here so the tree can be trusted, which is what makes
 * keeping exactly one copy of it safe.
 */
function checkDocTree(errors) {
  const file = path.join(ROOT, 'QUICK_REFERENCE.md');
  if (!existsSync(file)) return;
  const text = readFileSync(file, 'utf8');
  const a = text.indexOf('## File Locations');
  if (a < 0) {
    errors.push('QUICK_REFERENCE.md: could not find the "## File Locations" section');
    return;
  }
  const next = text.indexOf('\n## ', a + 1);
  const block = text.slice(a, next < 0 ? text.length : next);

  // Every filename named in the tree must exist somewhere in the repo.
  const names = new Set();
  for (const m of block.matchAll(/([A-Za-z0-9_.-]+\.(?:php|js|mjs|json|xml|yml|yaml))\b/g)) names.add(m[1]);
  const files = repoFiles();
  for (const n of names) {
    if (!files.some((f) => f === n || f.endsWith('/' + n))) {
      errors.push(`QUICK_REFERENCE.md: the file tree names ${n}, which is not in the repo`);
    }
  }

  // …and it must name every service, command, and top-level directory.
  const required = [];
  for (const [dir, label] of [[['app', 'Services'], 'app/Services'], [['app', 'Console', 'Commands'], 'app/Console/Commands']]) {
    const abs = path.join(ROOT, ...dir);
    if (!existsSync(abs)) continue;
    for (const f of readdirSync(abs)) if (f.endsWith('.php')) required.push([f, `${label}/${f}`]);
  }
  // Only version-controlled directories. Build output and local artefacts
  // (test-results/, playwright-report/, node_modules/, storage/) are not part of the
  // repo's shape and must not force a tree entry.
  const tracked = new Set(
    git(['ls-files'], ROOT).split('\n')
      .map((f) => f.split('/'))
      .filter((parts) => parts.length > 1)
      .map((parts) => parts[0])
  );
  for (const name of [...tracked].sort()) {
    required.push([name + '/', `the ${name}/ directory`]);
  }
  for (const [needle, label] of required) {
    if (!block.includes(needle)) {
      errors.push(`QUICK_REFERENCE.md: the file tree does not list ${label}`);
    }
  }
}

/**
 * The Dutch manual is a section-for-section translation of the English one. Comparing
 * heading *levels* (not text) is language-agnostic, so this catches a section added to
 * one and not the other without pretending to check the translation itself.
 */
function checkManualParity(errors) {
  const en = path.join(ROOT, 'USER_MANUAL.md');
  const nl = path.join(ROOT, 'GEBRUIKERSHANDLEIDING.md');
  if (!existsSync(en) || !existsSync(nl)) return;
  const levels = (f) => (readFileSync(f, 'utf8').match(/^#{1,6}(?= )/gm) || []).map((h) => h.length);
  const a = levels(en);
  const b = levels(nl);
  if (a.length !== b.length) {
    errors.push(`GEBRUIKERSHANDLEIDING.md: has ${b.length} headings, USER_MANUAL.md has ${a.length}`
      + ' — the Dutch manual must track the English one section for section');
    return;
  }
  for (let i = 0; i < a.length; i++) {
    if (a[i] !== b[i]) {
      errors.push(`GEBRUIKERSHANDLEIDING.md: heading ${i + 1} is level ${b[i]}, USER_MANUAL.md has level ${a[i]}`);
      return;
    }
  }
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
      const msg = unresolved(tok, '`# pinned by:`');
      if (msg) err(msg);
    }

    // The bullets are the spec's own coverage claim — hold them to the same standard.
    for (const tok of new Set(proseTestPaths(text))) {
      const msg = unresolved(tok, 'referenced test');
      if (msg) err(msg);
    }

    // Every feature/recipe must say how it is verified. The template mandates this
    // section; without a check that mandate was only a convention.
    if ((r.includes('/features/') || r.includes('/recipes/')) && !/^## Tests & verification\s*$/m.test(text)) {
      err('no `## Tests & verification` section');
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

  // Documented facts: versions named in the docs must match the manifests, and the
  // hand-counted totals must match the tree. Without this a spec can satisfy every
  // structural rule above while stating things that are simply untrue. The root docs are
  // included because the same wrong number used to sit in three files at once.
  const docFiles = [...allMd, ...rootDocs()];
  checkVersions(errors, docFiles);
  checkCounts(errors, docFiles);
  checkDocTree(errors);
  checkManualParity(errors);

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
