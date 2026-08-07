# Contributing

ORCA DAM is a solo/internal project. External PRs are not expected, but if you
open one anyway, please follow the guidelines below.

## Ground rules

- **No AI slob.** All code should be human-read before being committed.
- **DRY and SOLID**, or ask your AI to be.
- **Keep PRs small and focused** — one feature or fix per PR.
- **One home per subject.** Every document owns one thing — the map is in
  [README.md](README.md#documentation-map). Do not copy a role matrix, an endpoint list,
  a command list or a file tree into a second document; link to the one that owns it.
  `npm run spec:lint` guards the counts, versions and paths that used to drift.

## Workflow

1. Branch off `main`.
2. Make your change.
3. Run tests and formatting locally (see below).
4. Open a PR against `main` with a clear description of *why*.

## Specs (Spec-Driven Development)

ORCA uses **Spec-Driven Development**: `specs/` is the architectural/behavioural
source of truth (see [`specs/README.md`](specs/README.md) and
[`specs/architecture.md`](specs/architecture.md)). A change that edits production
code — `app/**`, `routes/**`, `database/migrations/**`, `config/**` (non-framework),
`resources/js/**` — **must create or update a spec in the same change**. A guard
(`scripts/sdd-guard.mjs`) enforces this via Claude Code hooks and a CI job. Write the
spec first (`/feature`, `/fix`, `/spec` slash commands). Everything else (views, CSS,
`lang/**`, factories/seeders, tests, `public/**`, `wordpress-plugin/**`, docs) is
exempt. For a genuinely trivial production tweak, `touch .sdd-skip` (local) or add
`[skip-sdd]` to the commit message / the `skip-sdd` PR label (CI).

`npm run spec:lint` checks more than structure. It also fails on: a `# pinned by:` or
`## Tests & verification` path that does not resolve, a spec with no
`## Tests & verification` section, a spec or ADR missing from an index, a dependency
version stated in the docs that contradicts `composer.json`/`package.json`, a
hand-counted total (specs, ADRs, Alpine modules, services, console commands, test files,
Pest tests, E2E tests) that no longer matches the tree — in any phrasing, so a prose
`all N commands` counts as a claim just like the file tree's `N artisan commands` comment,
and across a line wrap, since a count split from its qualifier by a newline used to match
nothing, a file tree in `QUICK_REFERENCE.md` that has fallen behind `app/Services/`,
`app/Console/Commands/` or the top-level directories, and a heading added to
`USER_MANUAL.md` without its Dutch counterpart. Run it before opening a PR.

Two tallies are derived by a heuristic rather than by listing files, because both suites
generate cases: `tests/e2e/*.spec.js` from `for (const x of …)` loops, and Pest from
datasets. Each counter carries its own fixtures and fails as `spec-lint self-test: …` if it
breaks — a different problem from a stale number, and the one worth reading first, because a
counter that has quietly stopped working produces a plausible total and the docs then get
"corrected" to match it. Each also **errors rather than guesses** on a case it cannot size:

- **E2E** — wrap generated tests in `for (const x of <array literal or named const>)` and
  keep the array resolvable.
- **Pest** — write a dataset as an array literal or declare it with
  `dataset('name', [...])`. `->with($variable)`, `->with(fn () => …)` and a PHPUnit
  `#[DataProvider]` are all refused by name rather than counted as one test. The counter
  knows three shapes: `test()`/`it()`, `arch()`, and class-based `public function test_…`
  methods — the last of which it originally missed, because those files contain no `test(`
  token anywhere and so counted as empty.

Browser-level behaviour is pinned by the feature spec that owns it, never by
`e2e-testing.md` — that spec owns the harness only.

## Before committing

```bash
./vendor/bin/pint                                    # Code style (Laravel Pint)
./vendor/bin/phpstan analyse                         # Static analysis (Larastan, level 2)
php artisan config:clear && php artisan test        # Full Pest suite
```

All three must pass. If a pre-commit hook fails, fix the underlying issue — do not
bypass with `--no-verify`.

PHPStan runs at **level 2 over `app/` with no baseline**, so it is zero-or-fail: there is no
`phpstan-baseline.neon`, and findings are not to be silenced with `@phpstan-ignore` comments or
`ignoreErrors` entries. If a finding is a genuine tool limitation rather than a defect, fix it with
a type annotation that says something true about the code — `@property` on the model, generics on
the relation — and note why. Narrow with `instanceof` or `@var`, never `assert()`: the architecture
audit bans that function.

The full suite includes the `Security` suite — route/policy/source invariants, exploit probes and
the architecture bans ([`specs/features/security-invariants.md`](specs/features/security-invariants.md),
[`static-analysis.md`](specs/features/static-analysis.md)). To run just those, as the dedicated CI
job does:

```bash
php artisan config:clear && php artisan test --testsuite=Security
```

Two things there behave differently from a normal test, and both are deliberate. Several audits
enumerate the application rather than naming what they cover, so **adding a route, a policy
ability, a controller or a runtime setting can fail a test you did not touch** — the fix is to
guard it, or to add it to that file's allowlist with a reason. And each audit carries a *canary*
that mutates the app at runtime to prove the audit still fires; those are meant to be there.

CodeQL runs only in CI, over `resources/js/` and the workflow files — it has no PHP support. The
backend is covered by PHPStan above, by the architecture bans in the `Security` suite, and by the
custom Semgrep rules in `.semgrep/orca.yml`. See
[`specs/features/static-analysis.md`](specs/features/static-analysis.md) for what each layer does
and does not cover.

**If you edit `.semgrep/orca.yml`, add or update the matching fixture in `.semgrep/tests/`.** CI
verifies the rules against those fixtures *before* it uses them, because a rule that has stopped
matching reports a clean codebase — which reads exactly like a clean codebase. Semgrep is not a
required local dependency; if you want to run it, it needs Python (a WSL2 distro plus
`pipx install semgrep` works) and it must scan from a native filesystem — over `/mnt/c` it is
roughly a hundred times slower and looks hung rather than slow. Two gotchas are documented in that
file's header: `--test` takes one fixture file at a time, not the directory, and a fixture must never
contain the annotation keywords followed by a colon in its prose.

If you touched Blade views or anything under `resources/js/`, also run the browser
suite — it is a blocking CI job:

```bash
npm run test:e2e:install     # once: Chromium + OS deps
npm run e2e:up               # MinIO stands in for S3 (needs Docker; without it the
npm run test:e2e             #   storage specs skip and the rest still run)
```

Conventions (locate by `data-testid`, reseed per spec file):
[`specs/recipes/write-an-e2e-test.md`](specs/recipes/write-an-e2e-test.md).

## Commit messages

Use the prefix style already present in the history:
`[FEATURE]`, `[FIX]`, `[TWEAK]`, `[UX]`, `[REFACTOR]`, `[DOCS]`, `[SECURITY]`,
`[MAINTENANCE]`, `[i18n]`.

## Translations

When adding user-facing `__('...')` strings, also add the Dutch translation
to `lang/nl.json` (a test fails if you forget). Framework strings
(validation/auth/passwords) live in `lang/nl/*.php`, published by
laravel-lang. To refresh them, run `php artisan lang:safe-update` — never raw
`lang:update`, which overwrites project translations in `nl.json`.

## Security issues

Do not open public issues for vulnerabilities — see [SECURITY.md](SECURITY.md).

## The music lab

`public/games/music-lab.html` is a mixing desk for the Feeding Frenzy
soundtrack — the easter egg you get by double-clicking the ORCA logo. Open
`/games/music-lab.html` on your local site to play the score without the game,
switch danger levels on demand, and shove the wah around.

It exists because the alternative way to audition the shark music is to let
three sharks corner you, which is a slow and demoralising way to check whether
a filter cutoff is 200 Hz too bright. The soundtrack is synthesised from
nothing — no audio files, just `orca-music.js` and some oscillators — so every
note is a number somebody can argue with.

Yes, this is more tooling than the easter egg strictly warrants. No, it is not
up for debate.
