# Contributing

ORCA DAM is a solo/internal project. External PRs are not expected, but if you
open one anyway, please follow the guidelines below.

## Ground rules

- **No AI slob.** All code should be human-read before being committed.
- **DRY and SOLID**, or ask your AI to be.
- **Keep PRs small and focused** — one feature or fix per PR.

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
`[skip-sdd]` to the commit message / the `skip-sdd` PR label (CI). Run
`npm run spec:lint` to check spec structure.

## Before committing

```bash
./vendor/bin/pint                                    # Code style (Laravel Pint)
php artisan config:clear && php artisan test        # Full Pest suite
```

Both must pass. If a pre-commit hook fails, fix the underlying issue — do not
bypass with `--no-verify`.

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
