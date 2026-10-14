// npm advisory gate (see specs/features/security-invariants.md → REQ-8).
//
// `npm audit --audit-level=high` with one addition: an allowlist of individual advisories that
// have no patched release. npm has no ignore option of its own, and the alternatives were worse
// — `|| true` makes the step decorative, and `--omit=dev` would also drop axios, which ships to
// browsers. So this runs `npm audit --json` and judges the report itself.
//
// Rules, each enforced below:
//   - Only a GHSA id is ever listed, never a package: an unlisted advisory against the same
//     package, or anywhere in the same dependency chain, still fails.
//   - An entry the audit no longer reports fails the step as stale, so the list cannot outlive
//     its reason.
//   - The filter is checked against fixtures on every run, before the real report is trusted. A
//     filter that silently stopped matching would pass exactly like a clean tree.
//
// Usage: node scripts/npm-audit.mjs

import { spawnSync } from 'node:child_process';

/**
 * Advisories with no fix to upgrade to. Each says why it can't be reached and what removes it:
 *   'GHSA-xxxx-xxxx-xxxx': { package: '…', reason: '…', removedBy: '…' },
 * Empty since the Tailwind v4 migration (ADR-019) removed braces, its only entry so far.
 */
const ADVISORY_ALLOWLIST = {};

const FAILING_SEVERITIES = new Set(['high', 'critical']);

/**
 * Judge a parsed `npm audit --json` report. Pure, so the fixtures below can exercise it.
 * Throws on a report shape it does not recognise rather than reading it as clean.
 */
function judge(report, allowlist) {
  if (
    !report
    || report.auditReportVersion !== 2
    || typeof report.vulnerabilities !== 'object'
    || report.vulnerabilities === null
  ) {
    throw new Error('unrecognised `npm audit --json` output (expected auditReportVersion 2 with a vulnerabilities map)');
  }

  // Only advisory objects matter. A `via` entry that is a plain string names another
  // vulnerable package, whose own entry carries the advisory, so every chain bottoms out here.
  const advisories = new Map();
  for (const [pkg, vuln] of Object.entries(report.vulnerabilities)) {
    for (const via of vuln.via ?? []) {
      if (typeof via !== 'object' || via === null) continue;
      const id = via.url?.match(/GHSA(?:-[0-9a-z]{4}){3}/i)?.[0] ?? `npm-advisory-${via.source}`;
      const entry = advisories.get(id) ?? { id, severity: via.severity, title: via.title, url: via.url, packages: new Set() };
      entry.packages.add(pkg);
      advisories.set(id, entry);
    }
  }

  const failing = [...advisories.values()].filter((a) => FAILING_SEVERITIES.has(a.severity));

  return {
    failures: failing.filter((a) => !(a.id in allowlist)),
    ignored: failing.filter((a) => a.id in allowlist),
    stale: Object.keys(allowlist).filter((id) => !advisories.has(id)),
  };
}

/** Fixtures shaped like real `npm audit --json` output (auditReportVersion 2). */
function selfTest() {
  const advisory = (name, ghsa, severity) => ({
    source: 1, name, dependency: name, title: `${name} advisory`, severity,
    url: `https://github.com/advisories/${ghsa}`,
  });
  const braces = advisory('braces', 'GHSA-vfj7-8cjw-p6xm', 'high');
  // A self-contained entry: the fixtures must not depend on what the real allowlist holds.
  const allow = { 'GHSA-vfj7-8cjw-p6xm': { package: 'braces', reason: 'fixture', removedBy: 'fixture' } };
  const report = (vulnerabilities) => ({ auditReportVersion: 2, vulnerabilities, metadata: {} });
  const chain = {
    braces: { severity: 'high', via: [braces] },
    micromatch: { severity: 'high', via: ['braces'] },
    tailwindcss: { severity: 'high', via: ['micromatch'] },
  };

  const cases = [
    ['an allowlisted advisory and the packages above it pass', report(chain), allow,
      { failures: [], ignored: ['GHSA-vfj7-8cjw-p6xm'], stale: [] }],
    ['a second high advisory in the same chain still fails', report({
      ...chain,
      micromatch: { severity: 'high', via: ['braces', advisory('micromatch', 'GHSA-aaaa-bbbb-cccc', 'high')] },
    }), allow, { failures: ['GHSA-aaaa-bbbb-cccc'], ignored: ['GHSA-vfj7-8cjw-p6xm'], stale: [] }],
    ['an unlisted critical advisory fails', report({
      lodash: { severity: 'critical', via: [advisory('lodash', 'GHSA-dddd-eeee-ffff', 'critical')] },
    }), {}, { failures: ['GHSA-dddd-eeee-ffff'], ignored: [], stale: [] }],
    ['a moderate advisory is below the bar', report({
      nanoid: { severity: 'moderate', via: [advisory('nanoid', 'GHSA-gggg-hhhh-jjjj', 'moderate')] },
    }), {}, { failures: [], ignored: [], stale: [] }],
    ['an entry the audit no longer reports is stale', report({}), allow,
      { failures: [], ignored: [], stale: ['GHSA-vfj7-8cjw-p6xm'] }],
  ];

  const errors = [];
  for (const [what, input, allowlist, expected] of cases) {
    const got = judge(input, allowlist);
    const actual = { failures: got.failures.map((a) => a.id), ignored: got.ignored.map((a) => a.id), stale: got.stale };
    if (JSON.stringify(actual) !== JSON.stringify(expected)) {
      errors.push(`${what}: got ${JSON.stringify(actual)}, expected ${JSON.stringify(expected)}`);
    }
  }

  for (const [what, input] of [
    ['an npm error object', { error: { code: 'ENOAUDIT', summary: 'registry unreachable' } }],
    ['a report from another format version', { auditReportVersion: 1, advisories: {} }],
  ]) {
    try {
      judge(input, allow);
      errors.push(`${what}: was judged instead of rejected`);
    } catch {
      // expected
    }
  }

  return errors;
}

const selfTestErrors = selfTest();
if (selfTestErrors.length) {
  console.error('npm-audit self-test FAILED, so the filter cannot be trusted:');
  for (const e of selfTestErrors) console.error(`  ✗ ${e}`);
  process.exit(1);
}

// npm audit exits non-zero whenever anything is reported, so the status is ignored and the JSON
// decides. `shell` lets Windows resolve npm.cmd; the command line is fixed, never input.
const run = spawnSync('npm audit --json', { shell: true, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
let result;
try {
  result = judge(JSON.parse(run.stdout), ADVISORY_ALLOWLIST);
} catch (e) {
  console.error(`npm-audit: could not judge the audit report: ${e.message}`);
  if (run.stderr) console.error(run.stderr.trim());
  process.exit(1);
}

for (const a of result.ignored) {
  const entry = ADVISORY_ALLOWLIST[a.id];
  console.log(`ignored ${a.id} (${a.severity}, ${entry.package}): ${a.title}`);
  console.log(`  why:     ${entry.reason}`);
  console.log(`  removed: by ${entry.removedBy}`);
}
for (const id of result.stale) {
  console.error(`✗ stale allowlist entry ${id} (${ADVISORY_ALLOWLIST[id].package}): the audit no longer reports it — delete it from scripts/npm-audit.mjs`);
}
for (const a of result.failures) {
  console.error(`✗ ${a.severity} ${a.id}: ${a.title}\n    ${a.url}\n    via: ${[...a.packages].join(', ')}`);
}

if (result.failures.length || result.stale.length) {
  console.error('npm-audit FAILED. Fix it with `npm audit fix` or an override; allowlist only an advisory with no patched release.');
  process.exit(1);
}
console.log(`npm-audit: no high or critical advisories${result.ignored.length ? ` beyond ${result.ignored.length} allowlisted` : ''}.`);
