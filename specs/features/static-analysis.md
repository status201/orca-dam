# Static analysis

```yaml
id: static-analysis
status: implemented
version: 5
owner: core
related:
  - architecture
  - security-invariants
  - authorization-policies
  - authentication
source:
  - phpstan.neon
  - .semgrep/orca.yml
  - tests/Security/ArchitectureTest.php
  - .github/workflows/codeql.yml
  - .github/workflows/tests.yml
```

## Background / Why

This spec owns the layer that analyses the code itself — beyond Pint (style) and
`composer audit` / `npm audit` (dependency advisories) — the way
[e2e-testing.md](e2e-testing.md) owns the browser harness. Four layers: Pest arch bans (A),
Semgrep (B), CodeQL (C), PHPStan/Larastan (D).

It is a floor, not a replacement: a missing *decision* (a route mounted with no auth, a
`User::create` with no role) is neither a type error nor a taint flow; the audits in
[security-invariants.md](security-invariants.md) catch that shape of defect. A rule, like a
test, is only known to work once it has been observed to fail — hence the fixture-first
ordering in REQ-5 and the no-baseline rule in REQ-4.

Larastan findings per level, measured at adoption (so a future raise starts from data):

| Level | 0 | 1 | 2 | 3 | 4 | 5 | 6 |
|---|---|---|---|---|---|---|---|
| Findings | 2 | 3 | **41** | 46 | 62 | 73 | 351 |

## Requirements

- **REQ-1** — **Language-level bans are enforced across the whole namespace, not per file.** A set
  of dangerous functions is unusable in `app/` and the seeders. Exemptions are per-function and
  per-class, never global, and each names the reviewed reason. Groups with no exemptions (`eval`,
  `unserialize`, `shell_exec`, `system`, `passthru`, `extract`, `mb_parse_str`, `create_function`,
  `dl`, `assert`, `rand`, `mt_rand`, `str_shuffle`, `shuffle`, `array_rand`, and every
  debug-output function) are load-bearing: these assertions keep them unused.

- **REQ-2** — **CodeQL covers what it can, and the limit is documented.** CodeQL has **no PHP
  support**, so it analyses none of `app/`, `routes/` or `database/` (that is REQ-5's job). It runs
  `javascript-typescript` over `resources/js/` and `actions` over the workflow files, as
  **advanced setup** so the configuration is reviewable in git. GitHub's *default* setup must be
  off (*Settings → Code security → Code scanning*), or the SARIF upload is rejected.

- **REQ-3** — **No step is decorative.** No `|| true`, no `continue-on-error`. A finding is fixed,
  or carries an explicit commented exemption naming the reason. A check that cannot pass honestly
  is removed rather than neutered.

- **REQ-4** — **PHPStan (Larastan) runs at level 2 over `app/`, with nothing suppressed.** No
  `phpstan-baseline.neon`, no `@phpstan-ignore`, no `ignoreErrors`: zero-or-fail. Level 2 catches
  undefined properties and methods. `tests/`, `routes/` and `database/` are out of scope;
  `tmpDir` is project-local so CI can cache it. Model property types the analyser cannot read
  (casts in the `casts()` method form; `laravel/passkeys`' interface-typed `$user`) are described
  by a `@property` block on `User` and a narrowed `$user` on `App\Models\Passkey`. Narrowing uses
  `instanceof` or `@var`, never `assert()` (REQ-1 bans it).

- **REQ-5** — **Semgrep matches the parse tree for four ORCA invariants, and every rule is verified
  against a fixture before it is used.** Rules live in `.semgrep/orca.yml`; each has a fixture in
  `.semgrep/tests/` with per-line expectations, and the `semgrep` CI job runs `--test` (once per
  fixture file) **before** either scan step. Scan targets come from the command line, never a
  `paths:` filter (which would also exclude the fixtures). `database/factories` is not scanned,
  matching `tests/Security/UserProvisioningTest.php`. The engine is pip-installed and exact-pinned
  on the plain runner in `.github/workflows/tests.yml`, which owns the version; the workarounds
  are explained in `.semgrep/orca.yml`'s header and the workflow comments.

  | Rule | Invariant | Must also cover |
  |---|---|---|
  | `orca-user-create-without-role` | [authentication.md](authentication.md) REQ-8 | the last argument of a two-argument `firstOrCreate($find, $attrs)`; the factory chain at any depth |
  | `orca-unfiltered-mass-assignment` | [security-invariants.md](security-invariants.md) REQ-6 | every (write method × payload) shape — the method is a metavariable |
  | `orca-policy-blanket-grant` | ADR-002 / [authorization-policies.md](authorization-policies.md) REQ-1 | `return 1;`; scoped to policy classes by name |
  | `orca-db-raw-from-variable` | forward-looking; zero `DB::raw` calls exist | `\DB::raw` and `\Illuminate\Support\Facades\DB::raw` — the facade is a metavariable |

## Technical design

### Contract / public interface

```yaml
layer_a_arch:
  file: tests/Security/ArchitectureTest.php
  suite: Security                     # rides the existing security CI job
  plugin: pestphp/pest-plugin-arch
  tests: 7

layer_b_semgrep:
  rules: .semgrep/orca.yml            # 4 ORCA rules, all ERROR severity
  fixtures: .semgrep/tests/           # 1 per rule; --test runs per file, not per directory
  registry: [p/php, p/secrets]        # ERROR severity only
  targets:                            # from the CLI, never `paths:` in the rules
    orca: [app, routes, database/seeders, database/migrations]
    registry: [app, routes, database, resources/js]
  ci_job: semgrep                     # pip install on the plain runner; version pinned in tests.yml
  pint: excluded                      # fixtures carry deliberate style oddities

layer_d_phpstan:
  file: phpstan.neon
  package: larastan/larastan          # + phpstan/phpstan, iamcal/sql-parser
  level: 2
  paths: [app]
  baseline: none
  ci_job: phpstan                     # cloned from pint: no node, no build, no app key

layer_c_codeql:
  file: .github/workflows/codeql.yml
  action: github/codeql-action@v4
  languages: [javascript-typescript, actions]   # NOT php — unsupported by CodeQL
  queries: security-extended
  setup: advanced                     # config in git; conflicts with GitHub's default setup
```

`ArchitectureTest.php` does not use `arch()->preset()->security()` as shipped: the preset stops
at the first violation and takes one flat ignore list, so the same functions are regrouped by
*why* they are banned to keep each exemption narrow. `preset()->laravel()` is not used.

Third-party actions (`shivammathur/setup-php`) are pinned to a full commit SHA in `tests.yml`;
`.github/dependabot.yml`'s `github-actions` ecosystem keeps those pins fresh. GitHub-owned
actions stay on tags.

### Exemptions (REQ-1)

Five functions across seven files. Everything else in the ban list has no exemption.

| Function | Exempted in | Reviewed reason |
|---|---|---|
| `exec` | `SystemService` | `which supervisorctl`, `supervisorctl status` — fixed strings |
| `exec` | `TikzCompilerService` | binary lookup via `escapeshellarg()`; `taskkill` on an int PID from `proc_get_status()` |
| `exec` | `TestRunnerService` | `kill`/`taskkill` on an `(int)`-cast PID |
| `md5` | `TestRunnerService`, `TikzCompilerService` | cache keys — no security property |
| `sha1` | `AssetController` | asset-cycle cache key over a JSON-encoded filter context |
| `uniqid` | `TikzCompilerService` | scratch directory name |
| `tempnam` | `AssetBulkController`, `S3Service`, `ToolUploadService`, `TestRunnerService` | private temp paths for files ORCA just produced; TOCTOU accepted |

Adding a class to the `exec` list is a claim that its arguments cannot originate in a request.

## Visual aids

Tooling versions: `pestphp/pest-plugin-arch` (installed with Pest `^4.0`),
`github/codeql-action@v4`.

## Scenarios (BDD)

```gherkin
Scenario: A dangerous function added to a service fails the build
  Given a class in app/ that is not exempted for it
  When it calls eval(), unserialize(), shell_exec() or exec()
  Then the architecture audit fails and names the class and the function
# pinned by: tests/Security/ArchitectureTest.php

Scenario: An exemption is narrow to the function it was granted for
  Given TikzCompilerService is exempted for md5 and exec
  When it calls eval()
  Then the architecture audit still fails
# pinned by: tests/Security/ArchitectureTest.php

Scenario: A stray debug statement fails the build
  Given production code under app/
  When it calls dd(), dump(), var_dump() or print_r()
  Then the architecture audit fails
# pinned by: tests/Security/ArchitectureTest.php

Scenario: A policy placed outside app/Policies is still a policy
  Given PolicyMatrixTest enumerates abilities by reflecting over app/Policies
  When the architecture audit runs
  Then it asserts every class in App\Policies is a class and is suffixed Policy
# pinned by: tests/Security/ArchitectureTest.php

Scenario: A config override read with env() fails the analysis (REQ-4)
  Given production code outside config/
  When it calls env()
  Then PHPStan fails, because env() returns null once config:cache has run
# pinned by: tests/Unit/TestRunnerServiceTest.php

Scenario: The web test runner honours a configured PHP CLI path (REQ-4, system-admin.md REQ-6)
  Given orca.php_cli_path is set to an absolute binary path
  When findPhpCliBinary() runs
  Then it returns that path
  And with the key unset it never consults the environment
# pinned by: tests/Unit/TestRunnerServiceTest.php
```

CodeQL and PHPStan are CI gates with no Pest pin. Semgrep's pin is its fixtures: each file in
`.semgrep/tests/` asserts per line whether a rule must fire, including a non-policy class whose
`return true;` must stay unreported.

## Tests & verification

- Layer A: `tests/Security/ArchitectureTest.php` —
  `php artisan config:clear && php artisan test --testsuite=Security`.
- Layer B: the `semgrep` CI job. Locally (native filesystem, not `/mnt/c`): `semgrep --test` once
  per file in `.semgrep/tests/`, then
  `semgrep scan --error --config .semgrep/orca.yml app routes database/seeders database/migrations`.
- Layer C: `.github/workflows/codeql.yml` on push/PR and weekly; findings land in the
  code-scanning dashboard, not the job log. Inline suppressions are not honoured — fix or
  dismiss in the UI.
- Layer D: `vendor/bin/phpstan analyse` — level 2 over `app/`, 0 errors; the `phpstan` CI job.
- Advisories (`security` job): `composer audit --locked` and `node scripts/npm-audit.mjs`
  (see [security-invariants.md](security-invariants.md) REQ-8).
- Style: `./vendor/bin/pint --test`. Spec structure: `npm run spec:lint`.

## Open questions / future

- Four invariants are guarded twice — Semgrep (REQ-5) and the text scans in
  `tests/Security/Support/SourceScanner.php`. Retire the text half once there is evidence; note
  `DB::table('users')->insert(...)` has no AST counterpart yet.
- Neither layer follows a variable: `$asset->update($updates)`-shaped writes (e.g. in
  `AssetProcessingService`, `ProcessDiscoveredAsset`, `AssetBulkController`, `ImportController`)
  are invisible to REQ-5.
- No PHP taint analysis anywhere. Psalm was evaluated and declined (zero taint findings;
  `psalm/plugin-laravel` pulls `orchestra/testbench-core` v11 onto Laravel 13).
- `orca-db-raw-from-variable` covers `DB::raw` only — not `selectRaw`/`whereRaw`/`orderByRaw`/
  `havingRaw`, nor an argument-less factory `create()`. Follow-up rules, not silent widening.
- `orca-unfiltered-mass-assignment` leaves `$REQ` unconstrained; `$model->update($collection->all())`
  would be a false positive (none exist). Fix with a targeted `pattern-not` if it ever fires.
- Raise PHPStan's level (46 at 3, 73 at 5; level 6 would need a baseline decision) or widen its
  scope beyond `app/`?
- The `@property` on `User` and the shape-typed `$pivot` on `Tag` exist for the analyser only; a
  real pivot model would change hydration ([tags.md](tags.md)), so drop the annotation only if
  one is added for other reasons.
- `arch()->preset()->laravel()` is unused, so structural conventions beyond two naming rules are
  unchecked.
