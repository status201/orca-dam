# Static analysis

```yaml
id: static-analysis
status: implemented
version: 3
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

Until now the only automated analysis of this codebase was Pint (style) and, as of the security
suite, `composer audit` / `npm audit` (advisories in *dependencies*). Nothing analysed the code
itself. This spec owns the layer that does — the way [e2e-testing.md](e2e-testing.md) owns the
browser harness rather than any particular behaviour.

**What this layer is not.** It would not have caught the registration hole. That was a missing
*decision* — a route mounted with no auth, a `User::create` with no role — not a type error and not
a taint flow. The audits in [security-invariants.md](security-invariants.md) are what catch that
shape of defect. Static analysis is a floor, not a replacement.

**All four layers are now in place.** Semgrep — custom rules restating ORCA's invariants as
parse-tree patterns instead of the text matching `tests/Security/Support/SourceScanner.php` relies
on — was written, fixtured and pushed in version 1, failed its own rule-verification step on the
first CI run, and was **removed** rather than left failing or made non-blocking, because nothing in
the development environment could execute Semgrep. That history is kept deliberately: it is why the
layer is shaped the way REQ-5 describes.

The prerequisite version 2 recorded — *a local runner, so the rules can be verified before they
reach CI* — was then met with a WSL2 distro and `pipx install semgrep`. What the local runner found
in one afternoon is the argument for having insisted on it:

- The original CI failure **was not a rule bug at all.** `semgrep --test` with a single-file
  `--config` and a *directory* target crashes with an `IndexError` inside Semgrep's own
  `test.py`, so no rule ever ran. Version 2 blamed two specific patterns; both guesses were wrong.
- Three of the four rules had **coverage holes that review had not noticed** and that only mutation
  testing exposed: a fully-qualified `\DB::raw($x)` went unreported, five of twelve mass-assignment
  shapes were missing, and the factory creation path the rule's own message promised to cover was
  not implemented. See REQ-5.

The lesson generalises past Semgrep: a rule, like a test, is only known to work once it has been
observed to fail.

**Larastan was declined in version 1 of this spec, on an estimate that turned out to be wrong.**
That estimate — "a useful level lands somewhere between 150 and 1,200 findings", inferred from
`declare(strict_types=1)` at 0 of 116 files, 99 bare `array` return types and 135
`$request->input()` call sites — was never measured. When it was, it was out by 3–4×:

| Level | 0 | 1 | 2 | 3 | 4 | 5 | 6 |
|---|---|---|---|---|---|---|---|
| Findings | 2 | 3 | **41** | 46 | 62 | 73 | 351 |

`larastan/larastan` v3.10.0 resolves against this project's Laravel 13 and PHP 8.3+ constraints —
the compatibility gate that
was assumed to be the blocker — and adds three packages. **Level 2 is adopted, with no baseline**:
all 41 findings were resolved rather than recorded. The ladder is kept above so a future raise
starts from data rather than another estimate.

Two things the count concealed, and the reason a count alone was a bad basis for the decision.
First, the findings were **concentrated**: 34 of the 41 traced to 14 relation methods carrying no
PHPStan generics, so the fix was roughly a dozen high-leverage docblocks rather than 41 independent
edits. Second, among them were **real defects** — see REQ-4.

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
  Swift, Rust and GitHub Actions. It therefore analyses none of `app/`, `routes/` or `database/` —
  that is REQ-5's job, not this one. It runs `javascript-typescript` over
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
  honestly it is removed rather than neutered — which is what happened to the Semgrep job in version
  1, and the reason it took two versions of this spec to land instead of one green-looking job that
  verified nothing.

- **REQ-4** — **PHPStan (Larastan) runs at level 2 over `app/`, with nothing suppressed.** No
  `phpstan-baseline.neon`, no `@phpstan-ignore` comments, no `ignoreErrors` entries: the gate is
  zero-or-fail, so a new finding cannot be absorbed silently. Version 1 of this spec pre-committed
  to a baseline as a prerequisite for adoption; that is deliberately **not** what happened, because
  the measured count made it unnecessary and a baseline tends to become permanent.

  Level 2 is the level that catches undefined properties and methods, which is where Laravel bugs
  surface. Scope is `app/` because that is what was measured; `tests/`, `routes/` and `database/`
  are unmeasured and out of scope. `tmpDir` is project-local so CI can cache it.

  It found **two real defects**, both invisible to every other layer here:

  - `TestRunnerService::findPhpCliBinary()` read its override as
    `config('app.php_cli_path') ?: env('PHP_CLI_PATH')` — a config key that was never defined, and
    an `env()` call that returns null once `config:cache` has run. Fixed under
    [system-admin.md](system-admin.md) REQ-6, which did not exist before this.
  - `ChunkedUploadService::completeUpload()` threw `DuplicateAssetException` **without declaring
    it**, and `ChunkedUploadController::complete()` catches exactly that. With no `@throws`, static
    analysis concluded the handler was reachable only from before the `UploadSession` lookup and
    every read of `$session` there was a read of `null` — which is what the missing declaration
    actually meant. There was no `@throws DuplicateAssetException` anywhere in `app/`.

  Two tool limitations are worth recording, because the remedies look redundant otherwise.
  Larastan resolves model property types from the `$casts` **property** and falls back to the
  migration's column type; this codebase declares casts in the newer `casts()` **method**
  form, which it does not read — so `preferences` read as `string` (its `json` column) and every
  `datetime` cast read as `string`. Hence the `@property` block on `User`. And the
  `laravel/passkeys` package annotates its own `$user` as the `PasskeyUser` *interface*, which is
  narrowed in `App\Models\Passkey` rather than at each of the five call sites.

  Narrowing uses `instanceof` or `@var`, never `assert()` — REQ-1 bans that function, so the
  usual PHPStan idiom is unavailable here.

- **REQ-5** — **Semgrep matches the parse tree for four ORCA invariants, and every rule is verified
  against a fixture before it is used.** The rules live in `.semgrep/orca.yml`; each has a fixture in
  `.semgrep/tests/` carrying per-line expectations, and the `semgrep` CI job runs `--test` over those
  fixtures **before** either scan step. Without that ordering, a rule that has stopped matching
  reports a clean codebase, which is indistinguishable from a clean codebase. The scan targets come
  from the command line rather than a `paths:` filter in the rules, because a `paths.include` would
  also exclude the fixtures and make the verification vacuous. `database/factories` is deliberately
  not scanned, matching `tests/Security/UserProvisioningTest.php`.

  The four rules, and what each covers *after* mutation testing corrected it:

  | Rule | Invariant | What mutation testing added |
  |---|---|---|
  | `orca-user-create-without-role` | [authentication.md](authentication.md) REQ-8 | last-argument binding, so a two-argument `firstOrCreate($find, $attrs)` is judged on its attributes and not its lookup key; and the factory chain the message already promised, at any depth |
  | `orca-unfiltered-mass-assignment` | [security-invariants.md](security-invariants.md) REQ-6 | the write method as a metavariable, covering all twelve (method × payload) shapes instead of a hand-written seven |
  | `orca-policy-blanket-grant` | ADR-002 / [authorization-policies.md](authorization-policies.md) REQ-1 | `return 1;`, which the text scan already accepted, so this layer is not the weaker of the two |
  | `orca-db-raw-from-variable` | forward-looking; zero `DB::raw` calls exist | the facade as a metavariable, because Semgrep does not resolve `\Illuminate\Support\Facades\DB` or `\DB` back to the imported short name — both spellings were silently unreported |

  Two upstream constraints are worked around rather than hidden, both recorded in
  `.semgrep/orca.yml`'s header because they look arbitrary otherwise. `--test` is invoked **once per
  fixture file**: with a single-file `--config` and a directory target, `relatively_eq()` in
  Semgrep's `test.py` takes `config.relative_to(parent_config).parts` — an empty tuple when the two
  are the same file — and indexes it. And a fixture must never contain the words `ruleid` or `ok`
  followed by a colon in prose, because the annotation parser reads them as annotations naming a rule
  that does not exist and fails the whole file. The original fixture's docblock did exactly that.

  The engine is installed with `pip` on the plain runner and exact-pinned there, rather than run in
  the `semgrep/semgrep` container. The container is Alpine-based, and GitHub injects a glibc Node into
  a container in order to run JavaScript actions such as `actions/checkout` — a musl mismatch whose
  failure looks nothing like a rule problem. The pip path is also the one these rules were developed
  and mutation-checked against, so the pinned version is one that has been run rather than one that
  merely exists.

  `.github/workflows/tests.yml` owns that version. This spec does not repeat it, for the same reason
  it does not repeat the `setup-php` SHA: `spec-lint` checks documented versions only against
  `composer.json`/`package.json`, and a pip pin in a workflow appears in neither, so a copy here would
  be drift with nothing to catch it. `.github/dependabot.yml` will not bump it either — its
  `github-actions` ecosystem has no manifest to watch for this — so the pin moves only deliberately,
  and the fixture step is what reports it if a future version changes rule semantics.

## Technical design

### Contract / public interface

```yaml
layer_a_arch:
  file: tests/Security/ArchitectureTest.php
  suite: Security                     # rides the existing security CI job
  plugin: pestphp/pest-plugin-arch    # already installed; was unused
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
  baseline: none                      # 41 findings resolved, not recorded
  ci_job: phpstan                     # cloned from pint: no node, no build, no app key

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

```gherkin
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

CodeQL has no Pest test and is deliberately not pinned to one — it is a CI workflow whose results
land in the repository's code-scanning dashboard rather than in a job log. Recording a fabricated
pin would be worse than recording none. PHPStan is the same: its gate is the `phpstan` CI job, and
the scenarios above are pinned to the tests that cover the *defects it found*, not to the analyser.

Semgrep is the same again, with one difference worth stating: its rules **do** have executable
expectations, just not Pest ones. Each fixture in `.semgrep/tests/` asserts per line whether the rule
must fire, and the `semgrep` job runs those before it runs anything else. That is the pin; it is a
`--test` invocation rather than a `# pinned by:` path, and inventing a Pest file for it would be the
fabrication this method warns against.

## Tests & verification

- Layer A: `tests/Security/ArchitectureTest.php` — 7 tests, inside the `Security` suite.
  `php artisan config:clear && php artisan test --testsuite=Security` (97 tests).
- Layer A mutation check: a throwaway `app/Services/ArchCanaryService.php` calling `eval`, `dd`,
  `md5`, `exec` and `unserialize` was added and removed. Four of the seven audits fired, including
  `exec` — which confirms the per-class exemption really is narrow, since the canary was not on the
  list. The two naming audits and the `tempnam` audit correctly stayed green.
- Layer B: the `semgrep` CI job — `--test` over each fixture, then the ORCA rules, then the registry
  rulesets. Locally, run `--test` once per file in `.semgrep/tests/` (see the header of
  `.semgrep/orca.yml` for why it cannot take the directory), then `semgrep scan --error --config
  .semgrep/orca.yml app routes database/seeders database/migrations`.
  Current state: 4/4 fixtures pass, **0 findings on 171 files** for the ORCA
  rules and **0 on 208 files across 46 rules** for `p/php` + `p/secrets`.
- Layer B needs a **native filesystem**, which is worth knowing before anyone tries it under WSL. A
  scan of three files over `/mnt/c` did not finish in 240 seconds, of which 0.7s was CPU — it is all
  9p stat latency, not analysis. The same three files on ext4 took 2.1 seconds. Copy the scan targets
  into the distro first, or the tool looks broken when it is merely on the wrong side of a filesystem
  boundary. CI is unaffected: the container runs on ext4.
- Layer B mutation check: the whole point of the exercise, since all four rules report zero on a
  clean tree and a rule that reports nothing looks identical whether it works or not. Seven
  mutations, each applied to a throwaway copy, scanned, and reverted — results in the table below.
  Two of them changed the rules: `\DB::raw($column)` and `\Illuminate\Support\Facades\DB::raw($column)`
  were **not** reported by the first version, and stripping `->editor()` from `DatabaseSeeder`'s
  factory chain was **not** reported either. Neither hole was visible by reading the rules.
- Layer B's negative assertion, which matters as much as the positives: the ORCA scan covers `app/`,
  and `app/Http/Requests/` contains three `authorize()` methods whose entire body is `return true;`
  (`LoginRequest`, `StoreAssetRequest`, `UpdateAssetRequest`). All three stay unreported, which is
  real-code proof that `orca-policy-blanket-grant`'s class-name scoping binds. `.semgrep/tests/`
  asserts the same thing with a dedicated non-policy class, so the guard does not depend on those
  three files continuing to exist.
- Layer C: `.github/workflows/codeql.yml`, on push/PR and weekly. Cannot pass while GitHub's
  default setup is enabled (REQ-2). Findings land in the repository's code-scanning dashboard, not
  in the job log — a green job means the *analysis* succeeded, not that it found nothing.
- Layer C's first run produced a real finding, from the `actions` language rather than the
  JavaScript: `actions/unpinned-tag` on `shivammathur/setup-php`, the only third-party action in the
  workflows. A mutable major tag is re-pointable by its owner, so a compromised upstream release
  would execute in CI on the next run with nothing changing here. It is now pinned to a full commit
  SHA in all four jobs of `tests.yml`, which owns the exact value — this spec deliberately does not
  name it, because `.github/dependabot.yml` will change it and `spec-lint` does not check action
  versions, so repeating it here would be a drift vector with nothing to catch it. GitHub-owned
  actions stay on tags because they ship as immutable releases, which is why the rule flagged one
  line and not twenty. Worth noting as the concrete answer to "what does the `actions` language buy
  that nothing else here does".
- Pinning traded a supply-chain risk for a staleness one, so `.github/dependabot.yml` closes it: the
  `github-actions` ecosystem raises a weekly grouped PR and rewrites both the SHA and its trailing
  version comment. Scoped to that ecosystem only — a composer or npm *version* bump can name a
  version the docs also state, which fails `spec-lint` until the docs are edited, whereas action
  versions live in no manifest. Composer and npm *security* updates arrive without any config and
  are unaffected.
- Layer D: `vendor/bin/phpstan analyse` — level 2 over `app/`, **0 errors, no baseline**. Runs as
  the `phpstan` CI job. Reaching zero took 41 → 35 → 23 → 1 → 0: the `@property` block on `User`,
  generics on 14 relation methods, `instanceof` narrowing for Sanctum's `MorphTo` tokenable, a
  narrowed `$user` on `App\Models\Passkey`, `getAttribute()` for a joined column on `GameScore`, and
  the two real defects in REQ-4.
- Layer D found its own next finding mid-fix, which is worth recording: initialising `$session = null`
  in `ChunkedUploadController::complete()` cleared the original error and surfaced a new one, because
  the analyser could then see the variable was null on the path it thought reached the handler. That
  is what led to the missing `@throws` — the count rising before it fell was the useful signal, not
  noise.
- Layer D mutation check: reverting `findPhpCliBinary()` to
  `config('app.php_cli_path') ?: env('PHP_CLI_PATH')` makes PHPStan fail on the `env()` call **and**
  fails two tests in `tests/Unit/TestRunnerServiceTest.php`, including the one that pins "read from
  config, not from the environment". Both nets fire independently.
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
| Drop `'role'` from `AdminUserSeeder`'s two-argument `firstOrCreate` | `orca-user-create-without-role` fires — the arity fix, since the role sits in the second argument |
| Strip `->editor()` from `DatabaseSeeder`'s factory chain | `orca-user-create-without-role` fires. **Missed by the first rule version**; the message promised factory coverage the patterns did not implement |
| `$request->user()->fill($request->all())` in `ProfileController` | `orca-unfiltered-mass-assignment` fires |
| `SystemPolicy::access` reduced to `return true;` | `orca-policy-blanket-grant` fires |
| The three `FormRequest::authorize()` bodies left as `return true;` | nothing fires — class-name scoping confirmed on real code, not just the fixture |
| `DB::raw($column)` via the imported facade | `orca-db-raw-from-variable` fires |
| Same call as `\DB::raw($column)` and `\Illuminate\Support\Facades\DB::raw($column)` | both fire. **Missed by the first rule version** — Semgrep does not resolve a FQN to its short form |
| A metavariable typo'd in one pattern | `--test` fails, *before* either scan step reports clean. This is the assertion that the fixtures are load-bearing |

## Open questions / future

- **Four invariants are now guarded twice, and that is deliberate but temporary.** Both the Semgrep
  rules (REQ-5) and the text scans in `tests/Security/Support/SourceScanner.php` cover the same
  ground, from opposite directions: one sees the parse tree, one sees text. The mutation table
  asserts both catch the same defect, which is the precondition for eventually retiring the text
  half. Not yet — the rules have run in CI for one change. Retire them when there is evidence, not
  because the duplication is untidy. Note the two are not yet exactly equivalent: `SourceScanner`
  covers `DB::table('users')->insert(` and `User::factory(` under six idioms, and the Semgrep rule
  covers three named methods plus the factory chain, so `DB::table('users')->insert(...)` has no AST
  counterpart.

- **Neither layer can follow a variable.** Four writes in `app/` are handed a prepared array rather
  than a payload expression — `AssetProcessingService:80`, `ProcessDiscoveredAsset:43`,
  `AssetBulkController:236`, `ImportController:163`, all `$asset->update($updates)`-shaped. A
  syntactic rule cannot see what is in `$updates` and neither can a text scan. This is the honest
  boundary of REQ-5, and the reason the bullet below is not closed by having adopted Semgrep.

- **No PHP-level dataflow analysis anywhere.** Semgrep matches syntax, not taint — nothing traces a
  request value through a service into a query. Psalm's taint analysis is the only realistic OSS
  option for PHP; it was evaluated and declined (zero taint findings, verified not to be a silent
  skip since plain mode found 295 on the same files; 14 packages; and `psalm/plugin-laravel` pulls
  `orchestra/testbench-core` v11 onto a Laravel 13 project).

- **`orca-db-raw-from-variable` covers `DB::raw` only.** `selectRaw`, `whereRaw`, `orderByRaw` and
  `havingRaw` take raw SQL too and are more common in practice; `DB::table('users')->selectRaw($col)`
  is unreported today. Worth adding, but it widens the rule past what its id and message claim, so it
  is a deliberate follow-up rather than a silent extension. Also unreported: a factory `create()`
  called with **no arguments**, since the rule binds an attributes metavariable — nothing in the tree
  does that, and covering it needs a second rule rather than a wider pattern.

- **`orca-unfiltered-mass-assignment` leaves `$REQ` unconstrained**, which makes it stronger than the
  text scan (that one only matches a variable literally spelled `$request`) at the cost of one
  theoretical false positive: `->all()` is also `Collection`'s method. Nothing in `app/` writes
  `$model->update($collection->all())`. If it ever fires that way the fix is a targeted `pattern-not`,
  not a looser metavariable.

- **PHPStan's scope is `app/` only.** `tests/`, `routes/`, `database/` and `config/` are
  unanalysed — unmeasured, so out of scope rather than assumed cheap. Raising the level is the
  other axis: the ladder in Background gives 46 at level 3 and 73 at level 5, so the next step up
  is a known quantity rather than a guess. Level 6 (351) is where the iterable-generics tax lands
  and would need a different decision about baselines.
- **`@property` on `User` and the shape-typed `$pivot` on `Tag` describe the model to the analyser,
  not to the reader.** Both work around tool limitations (the `casts()` method form; a pivot with
  no pivot model). The `Tag` one is deliberately a shape rather than a `->using()` pivot class,
  because introducing one would change how attribution rows are hydrated at runtime — a type fix
  should not alter behaviour [tags.md](tags.md) pins. If a pivot model is ever added for other
  reasons, that annotation should go.

- **CodeQL cannot pass until default setup is switched off** (REQ-2). Until then the job is red for
  a configuration reason rather than a code one, which is exactly the kind of ambiguity REQ-3 exists
  to avoid — if it is not switched, the workflow should be deleted rather than left failing.

- **`arch()->preset()->laravel()` is unused**, so structural Laravel conventions beyond the two
  naming rules are unchecked.
