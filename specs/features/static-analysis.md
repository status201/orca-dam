# Static analysis

```yaml
id: static-analysis
status: implemented
version: 1
owner: core
related:
  - architecture
  - security-invariants
  - authorization-policies
  - authentication
source:
  - tests/Security/ArchitectureTest.php
  - .semgrep/orca.yml
  - .semgrep/tests/
  - .github/workflows/codeql.yml
  - .github/workflows/tests.yml
  - pint.json
```

## Background / Why

Until now the only automated analysis of this codebase was Pint (style) and, as of the security
suite, `composer audit` / `npm audit` (advisories in *dependencies*). Nothing analysed the code
itself. This spec owns the layer that does — the way
[e2e-testing.md](e2e-testing.md) owns the browser harness rather than any particular behaviour.

**What this layer is not.** It would not have caught the registration hole. That was a missing
*decision* — a route mounted with no auth, a `User::create` with no role — not a type error and
not a taint flow. The audits in [security-invariants.md](security-invariants.md) are what catch
that shape of defect. Static analysis is a floor, not a replacement, and the one part of it that
extends the invariant approach is the **custom Semgrep rules**, which re-express those same
invariants as parse-tree patterns instead of text matches.

Three layers were adopted, and one was explicitly declined:

- **Larastan / PHPStan — declined.** With `declare(strict_types=1)` at 0 of 116 files in `app/`,
  99 bare `array`/`?array` return types, 113 methods with no return type and 135 `$request->input()`
  call sites returning `mixed`, a useful level lands somewhere between 150 and 1,200 findings. That
  is a baseline-management project, and it buys correctness rather than security. Revisit
  separately; do not smuggle it in here.

## Requirements

- **REQ-1** — **Language-level bans are enforced across the whole namespace, not per file.** A set
  of dangerous functions is unusable in `app/` and the seeders. The exemptions are per-function and
  per-class, never global: a class excused for `md5` is not thereby excused for `eval`. Every
  exemption names the reviewed reason. Groups with no exemptions (`eval`, `unserialize`,
  `shell_exec`, `system`, `passthru`, `extract`, `mb_parse_str`, `create_function`, `dl`, `assert`,
  `rand`, `mt_rand`, `str_shuffle`, `shuffle`, `array_rand`, and every debug-output function) are
  load-bearing: nothing uses them today and these assertions are what keeps that true.

- **REQ-2** — **ORCA's own invariants are also expressed as parse-tree rules.** The text scans in
  `tests/Security/Support/SourceScanner.php` cannot distinguish a call from the same characters
  inside a comment or a string, and say so in their own docblock. Four Semgrep rules restate the
  load-bearing ones structurally: no `User` creation without a role, no model write handed an
  unfiltered request payload, no policy ability that is a bare `return true`, no `DB::raw` from a
  non-literal. The duplication with the Pest scans is deliberate and temporary — both must catch
  the same defect (see the mutation table) before either is retired.

- **REQ-3** — **Every custom rule is verified against fixtures before it is trusted.** Each rule
  has a file under `.semgrep/tests/` carrying `// ruleid:` (must match) and `// ok:` (must not)
  annotations, and CI runs `semgrep --test` **before** the scan. A rule that has silently stopped
  matching reports a clean codebase, which is indistinguishable from a clean codebase — the same
  failure mode the canaries in [security-invariants.md](security-invariants.md) REQ-8 exist to
  prevent. This step failing means the rules are wrong; the scan failing means the code is.

- **REQ-4** — **Rule scope comes from the command line, not from `paths:` in the rules.** A
  `paths.include` would also exclude `.semgrep/tests/`, making REQ-3 vacuously green.
  `database/factories` is deliberately out of scope, matching
  [security-invariants.md](security-invariants.md) REQ-4: a factory runs only from a test, and its
  `role` default *is* its role declaration.

- **REQ-5** — **CodeQL covers what it can, and the limit is documented rather than implied.**
  CodeQL has **no PHP support**; its languages are C/C++, C#, Go, Java/Kotlin, JS/TS, Python, Ruby,
  Swift, Rust and GitHub Actions. It therefore analyses none of `app/`, `routes/` or `database/`,
  and is not a substitute for REQ-2. It runs `javascript-typescript` over `resources/js/` — roughly
  ten thousand lines of Alpine that carried the entire UI unanalysed — and `actions` over the
  workflow files, which is not hypothetical: the `actions/missing-workflow-permissions` rule
  already produced two autofix commits here (`41a4af2`, `ce61e7f`).

- **REQ-6** — **No step is decorative.** No `|| true`, no `continue-on-error`. A finding is fixed,
  or carries an explicit commented exemption naming the reason. Registry rulesets are filtered to
  `ERROR` severity because `WARNING`/`INFO` from `p/php` is largely idiom advice that Pint and
  review already cover — a deliberate scope choice, not a way of hiding failures.

- **REQ-7** — **The Semgrep fixtures are excluded from Pint.** They contain intentional style
  oddities that are the substance of the test — a double-quoted `"role"` key, spaced concatenation
  — and Pint would rewrite them into passing-by-accident. `pint.json` excludes `.semgrep`.

## Technical design

### Contract / public interface

```yaml
layer_a_arch:
  file: tests/Security/ArchitectureTest.php
  suite: Security                     # rides the existing security CI job
  plugin: pestphp/pest-plugin-arch    # already installed; was unused
  tests: 7

layer_b_semgrep:
  rules: .semgrep/orca.yml            # 4 custom rules
  fixtures: .semgrep/tests/           # 1 file per rule
  ci_job: semgrep                     # container semgrep/semgrep:1.172.0
  steps:
    - semgrep --test --config .semgrep/orca.yml .semgrep/tests/
    - semgrep scan --error --config .semgrep/orca.yml app routes database/seeders database/migrations
    - semgrep scan --error --severity ERROR --config p/php --config p/secrets app routes database resources/js

layer_c_codeql:
  file: .github/workflows/codeql.yml
  languages: [javascript-typescript, actions]   # NOT php — unsupported by CodeQL
  queries: security-extended
  setup: advanced                     # config in git; conflicts with GitHub's default setup
```

### Why the arch preset is not used as shipped

`arch()->preset()->security()` bans the right 20 functions but reports **one** violation and stops,
and takes a single flat ignore list — so excusing `TikzCompilerService` for `md5` would also excuse
it for `eval`. `ArchitectureTest.php` regroups the same functions by *why* they are banned, which
keeps each exemption as narrow as its reason. `preset()->laravel()` is not used at all: its
structural rules would turn this into a rename sweep.

### The exemptions, and why each is accepted

Five functions across eight files. Everything else in the ban list has no exemption.

| Function | Exempted in | Reviewed reason |
|---|---|---|
| `exec` | `SystemService` | `which supervisorctl`, `supervisorctl status` — fixed strings |
| `exec` | `TikzCompilerService` | binary lookup via `escapeshellarg()`; `taskkill` on an int PID from `proc_get_status()` |
| `exec` | `TestRunnerService` | `kill`/`taskkill` on an `(int)`-cast PID |
| `md5` | `TestRunnerService`, `TikzCompilerService` | cache keys over a suite name / TikZ source — no security property |
| `sha1` | `AssetController` | asset-cycle cache key over a JSON-encoded filter context |
| `uniqid` | `TikzCompilerService` | scratch directory name |
| `tempnam` | `AssetBulkController`, `ChunkedUploadService`, `ToolUploadService`, `TestRunnerService` | private temp paths for files ORCA just produced; TOCTOU accepted |

The `exec` exemption is per class precisely so a *fourth* class calling it still fails. Adding a
name to that list is a claim that its arguments cannot originate in a request.

### Layer interaction

Each Semgrep rule shadows a Pest audit rather than replacing it:

| Semgrep rule | Shadows |
|---|---|
| `orca-user-create-without-role` | `tests/Security/UserProvisioningTest.php` (REQ-4 scan) |
| `orca-unfiltered-mass-assignment` | `tests/Security/ModelInvariantsTest.php` (REQ-6 scan) |
| `orca-policy-blanket-grant` | `tests/Security/PolicyMatrixTest.php` (`return true` detector) |
| `orca-db-raw-from-variable` | nothing — forward-looking; there are no `DB::raw` calls today |

The Pest scans keep one advantage the rules do not have: they run locally and offline, in the same
command a developer already uses. That is why they stay for now.

## Visual aids

None. Tooling versions: `pestphp/pest-plugin-arch` (installed with Pest `^4.0`),
`semgrep/semgrep:1.172.0` (pinned container), `github/codeql-action@v3`.

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
  Then the architecture audit fails, because those disclose application state in a response
# pinned by: tests/Security/ArchitectureTest.php

Scenario: A policy placed outside app/Policies is still a policy
  Given PolicyMatrixTest enumerates abilities by reflecting over app/Policies
  When the architecture audit runs
  Then it asserts every class in App\Policies is a class and is suffixed Policy
# pinned by: tests/Security/ArchitectureTest.php
```

The Semgrep and CodeQL layers have **no Pest test** and are deliberately not pinned to one — they
are CI steps, and inventing a pin would be a fabricated one. Their equivalent of a test is
`semgrep --test` against `.semgrep/tests/`, which runs before every scan (REQ-3); the fixtures are
the executable specification of each rule. Their scenarios are therefore recorded here without a
pin, and the gap is stated in Open questions.

```gherkin
Scenario: A User creation without a role is caught structurally
  Given a call to User::create, firstOrCreate or updateOrCreate under app/, routes/ or database/seeders/
  And its attributes array contains no 'role' key
  When the ORCA Semgrep rules run in CI
  Then the scan fails, naming the call site
  But the same characters inside a comment or a string literal are not reported

Scenario: A broken rule fails as a broken rule
  Given a custom rule that no longer matches its fixture
  When CI runs semgrep --test before the scan
  Then the rule-verification step fails, distinctly from a code finding
```

## Tests & verification

- Layer A: `tests/Security/ArchitectureTest.php` — 7 tests, inside the `Security` suite.
  `php artisan config:clear && php artisan test --testsuite=Security` (97 tests).
- Layer A mutation check: a throwaway `app/Services/ArchCanaryService.php` calling `eval`, `dd`,
  `md5`, `exec` and `unserialize` was added and removed. Four of the seven audits fired, including
  `exec` — which confirms the per-class exemption really is narrow, since the canary was not on
  the list. The two naming audits and the `tempnam` audit correctly stayed green.
- Layer B: `semgrep --test --config .semgrep/orca.yml .semgrep/tests/`, then the two scan steps.
  All in the `semgrep` CI job. **Not executed locally** — see Open questions.
- Layer B static checks that *were* run locally: `vendor/bin/yaml-lint .semgrep/orca.yml` (valid),
  and `php -l` on all four fixtures (no syntax errors).
- Layer C: `.github/workflows/codeql.yml`, on push/PR and weekly. Results land in the repository's
  code-scanning dashboard rather than in a job log.
- Style: `./vendor/bin/pint --test` — `.semgrep` excluded per REQ-7.
- Spec structure: `npm run spec:lint`.

### Mutation results

| Mutation | Result |
|---|---|
| Canary service calling `eval` / `unserialize` | REQ-1 code-execution audit fails |
| Canary service calling `dd` | REQ-1 debug audit fails |
| Canary service calling `md5` | REQ-1 weak-hash audit fails |
| Canary service calling `exec` (not on the exemption list) | REQ-1 process-execution audit fails |
| `semgrep --test` with a deliberately broken pattern | rule-verification step fails, scan never runs |

The Semgrep rows of that table are verified by the fixtures themselves rather than by a manual
run — every `// ruleid:` annotation is an assertion that the rule fires, and every `// ok:` that it
does not.

## Open questions / future

- **The Semgrep rules have not been executed.** This environment has no Docker and no working
  Python, so the rules were written, YAML-linted and given fixtures, but never run. The first CI
  run is the real verification, and it can fail in two distinct ways: the `--test` step failing
  means a pattern is wrong (most likely candidates are the PHP array metavariable-regex in
  `orca-user-create-without-role` and the exact-body match in `orca-policy-blanket-grant`); the
  scan step failing means the registry rulesets found something. Both are expected outcomes of a
  first run, and neither should be silenced with `|| true`.
- **The registry-ruleset baseline is unknown** for the same reason. `p/php` and `p/secrets` at
  `ERROR` severity is a guess at the useful tier, not a measured one.
- **CodeQL's default setup may still be enabled**, in which case this workflow conflicts with it
  and fails until default setup is switched off under *Settings → Code security → Code scanning*.
  That is a UI action; the evidence that it was once on is circumstantial (two autofix commits, and
  `ce61e7f` referring to a "pull request finding").
- **No PHP-level dataflow analysis anywhere.** Semgrep matches syntax, not taint. Nothing traces a
  request value through a service into a query. Psalm's taint analysis is the only realistic OSS
  option for PHP and was not evaluated.
- **Larastan remains declined**, with the sizing in Background. If it is ever adopted, a
  `phpstan-baseline.neon` and a `.gitignore` entry for its cache directory are prerequisites.
- **The duplication in REQ-2 is not yet resolved.** Four invariants are now checked twice, by a
  text scan and by a rule. Once the rules have a few CI runs behind them, the corresponding scans
  in `tests/Security/Support/SourceScanner.php` consumers should be retired — but not before,
  because the rules are currently the unproven half.
- **`arch()->preset()->laravel()` is unused**, so structural Laravel conventions beyond the two
  naming rules are unchecked.
