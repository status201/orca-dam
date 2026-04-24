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

## Before committing

```bash
./vendor/bin/pint                                    # Code style (Laravel Pint)
php artisan config:clear && php artisan test        # Full Pest suite
```

Both must pass. If a pre-commit hook fails, fix the underlying issue — do not
bypass with `--no-verify`.

## Commit messages

Use the prefix style already present in the history:
`[FEATURE]`, `[FIX]`, `[TWEAK]`, `[UX]`, `[REFACTOR]`, `[DOCS]`, `[SECURITY]`,
`[MAINTENANCE]`, `[i18n]`.

## Translations

When adding user-facing `__('...')` strings, also add the Dutch translation
to `lang/nl.json`.

## Security issues

Do not open public issues for vulnerabilities — see [SECURITY.md](SECURITY.md).
