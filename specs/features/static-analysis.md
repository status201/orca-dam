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
  - .github/workflows/codeql.yml
  - .github/workflows/tests.yml
```

## Background / Why

Until now the only automated analysis of this codebase was Pint (style) and, as of the security
suite, `composer audit` / `npm audit` (advisories in *dependencies*). Nothing analysed the code
itself. This spec owns the layer that does — the way [e2e-testing.md](e2e-testing.md) owns the
browser harness rather than any particular behaviour.

**What this layer is not.** It would not have caught the registration hole. That was a missing
*decision* — a route mounted with no auth, a `User::create` with no role — not a type error and not
a taint flow. The audits in [security-invariants.md](security-invariants.md) are what catch that
shape of defect. Static analysis is a floor, not a replacement.

**Two layers are implemented; the third is designed and deferred.** Semgrep — custom rules that
would restate ORCA's invariants as parse-tree patterns instead of the text matching
`tests/Security/Support/SourceScanner.php` relies on — was written, fixtured and pushed, and its
rule-verification step failed on the first CI run. Nothing in the development environment can
execute Semgrep (no Docker, no working Python), so the patterns cannot be corrected without blind
iteration against CI. The rules were **removed** rather than left failing or made non-blocking, and
the design is preserved under Open questions so the next attempt does not start from scratch. That
is the honest state: the layer with the most security value here is the one not yet in place.

Larastan was evaluated and **declined**. With `declare(strict_types=1)` at 0 of 116 files in `app/`,
99 bare `array`/`?array` return types, 113 methods with no return type and 135 `$request->input()`
call sites returning `mixed`, a useful level lands somewhere between 150 and 1,200 findings. That is
a baseline-management project, and it buys correctness rather than security. Revisit separately; do
not smuggle it in here.

## Requirements

- **REQ-1** — **Language-level bans are enforced across the whole namespace, not per file.** A set
  of dangerous functions is unusable in `app/` and the seeders. The exemptions are per-function and
  per-class, never global: a class excused for `md5` is not thereby excused for `eval`. Every
  exemption names the reviewed reason. Groups with no exemptions (`eval`, `unserialize`,
  `shell_exec`, `system`, `passthru`, `extract`, `mb_parse_str`, `create_function`, `dl`, `assert`,
  `rand`, `mt_rand`, `str_shuffle`, `shuffle`, `array_rand`, and every debug-output function) are
  load-bearing: nothing uses them today and these assertions are what keeps that true.

- **REQ-2** — **CodeQL covers what it can, and the limit is documented rather than implied.**
  CodeQL has **no PHP support**; its languages are C/C++, C#, Go, Java/Kotlin, JS/TS, Python, Ruby,
  Swift, Rust and GitHub Actions. It therefore analyses none of `app/`, `routes/` or `database/`,
  and is not a substitute for the deferred Semgrep layer. It runs `javascript-typescript` over
  `resources/js/` — roughly ten thousand lines of Alpine that carried the entire UI unanalysed —
  and `actions` over the workflow files, which is not hypothetical: the
  `actions/missing-workflow-permissions` rule already produced two autofix commits here
  (`41a4af2`, `ce61e7f`).

  It is configured as **advanced setup**, so the configuration is reviewable in git. Advanced and
  GitHub's *default* setup are mutually exclusive — while default setup is enabled the SARIF upload
  is rejected outright ("CodeQL analyses from advanced configurations cannot be processed when the
  default setup is enabled"). Switching that off under *Settings → Code security → Code scanning*
  is a repository-settings action, not a code change.

- **REQ-3** — **No step is decorative.** No `|| true`, no `continue-on-error`. A finding is fixed,
  or carries an explicit commented exemption naming the reason. When a check cannot be made to pass
  honestly it is removed rather than neutered — which is what happened to the Semgrep job.

## Technical design

### Contract / public interface

```yaml
layer_a_arch:
  file: tests/Security/ArchitectureTest.php
  suite: Security                     # rides the existing security CI job
  plugin: pestphp/pest-plugin-arch    # already installed; was unused
  tests: 7

layer_b_semgrep:
  status: deferred                    # see Open questions for the design and why
  reason: cannot be executed or verified in the development environment

layer_c_codeql:
  file: .github/workflows/codeql.yml
  action: github/codeql-action@v4
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

## Visual aids

None. Tooling versions: `pestphp/pest-plugin-arch` (installed with Pest `^4.0`),
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
  Then the architecture audit fails, because those disclose application state in a response
# pinned by: tests/Security/ArchitectureTest.php

Scenario: A policy placed outside app/Policies is still a policy
  Given PolicyMatrixTest enumerates abilities by reflecting over app/Policies
  When the architecture audit runs
  Then it asserts every class in App\Policies is a class and is suffixed Policy
# pinned by: tests/Security/ArchitectureTest.php
```

CodeQL has no Pest test and is deliberately not pinned to one — it is a CI workflow whose results
land in the repository's code-scanning dashboard rather than in a job log. Recording a fabricated
pin would be worse than recording none.

## Tests & verification

- Layer A: `tests/Security/ArchitectureTest.php` — 7 tests, inside the `Security` suite.
  `php artisan config:clear && php artisan test --testsuite=Security` (97 tests).
- Layer A mutation check: a throwaway `app/Services/ArchCanaryService.php` calling `eval`, `dd`,
  `md5`, `exec` and `unserialize` was added and removed. Four of the seven audits fired, including
  `exec` — which confirms the per-class exemption really is narrow, since the canary was not on the
  list. The two naming audits and the `tempnam` audit correctly stayed green.
- Layer C: `.github/workflows/codeql.yml`, on push/PR and weekly. Cannot pass while GitHub's
  default setup is enabled (REQ-2).
- Advisories, in the `security` job: `composer audit --locked` and `npm audit --audit-level=high`.
  Worth noting what these caught on their first run: two Guzzle advisories
  (`CVE-2026-69245`, `CVE-2026-69246`) published hours after the local run that had reported clean,
  fixed by moving to `guzzlehttp/guzzle` 7.15.2. The value of a dependency check is that it runs
  again tomorrow.
- Style: `./vendor/bin/pint --test`.
- Spec structure: `npm run spec:lint`.

### Mutation results

| Mutation | Result |
|---|---|
| Canary service calling `eval` / `unserialize` | REQ-1 code-execution audit fails |
| Canary service calling `dd` | REQ-1 debug audit fails |
| Canary service calling `md5` | REQ-1 weak-hash audit fails |
| Canary service calling `exec` (not on the exemption list) | REQ-1 process-execution audit fails |

## Open questions / future

- **Semgrep is designed but not in place, and this is the biggest gap in this spec.** It is the only
  adopted-in-principle layer that would analyse PHP for security rather than style. The design, kept
  so the next attempt starts from it:

  Four rules, restating invariants that `SourceScanner` currently checks as text —
  `orca-user-create-without-role` (a `User::create`/`firstOrCreate`/`updateOrCreate` whose
  attributes name no `role`), `orca-unfiltered-mass-assignment` (`$m->fill($request->all())` and
  friends), `orca-policy-blanket-grant` (an ability in a `*Policy` class whose entire body is
  `return true`), and `orca-db-raw-from-variable`. Each with a fixture carrying `// ruleid:` and
  `// ok:` annotations, and a `semgrep --test` step running **before** the scan so a rule that has
  stopped matching fails as "the rules are wrong" rather than reporting a clean codebase. Scope from
  CLI targets, not `paths:` in the rules, because a `paths.include` would also exclude the fixtures
  and make that verification vacuous. Registry rulesets `p/php` and `p/secrets` at `ERROR` severity.

  What went wrong: the rules were pushed unverified, and `semgrep --test` failed. The likely
  culprits are the array `metavariable-regex` in `orca-user-create-without-role` and the exact-body
  match in `orca-policy-blanket-grant`. **Prerequisite for the next attempt: a local runner**
  (`pipx install semgrep`, or Docker) so the rules can be verified before they reach CI. Note also
  that the fixtures must be excluded from Pint — they contain deliberate style oddities (a
  double-quoted `"role"` key, spaced concatenation) that Pint would rewrite into passing by accident.

- **The duplication Semgrep was meant to resolve is still unresolved.** The text scans in
  `tests/Security/Support/SourceScanner.php` remain the only check for those invariants, with the
  weakness their own docblock records: they see text, not semantics.

- **No PHP-level dataflow analysis anywhere.** Even with Semgrep in place, Semgrep matches syntax,
  not taint — nothing traces a request value through a service into a query. Psalm's taint analysis
  is the only realistic OSS option for PHP and was not evaluated.

- **Larastan remains declined**, with the sizing in Background. If it is ever adopted, a
  `phpstan-baseline.neon` and a `.gitignore` entry for its cache directory are prerequisites.

- **CodeQL cannot pass until default setup is switched off** (REQ-2). Until then the job is red for
  a configuration reason rather than a code one, which is exactly the kind of ambiguity REQ-3 exists
  to avoid — if it is not switched, the workflow should be deleted rather than left failing.

- **`arch()->preset()->laravel()` is unused**, so structural Laravel conventions beyond the two
  naming rules are unchecked.
