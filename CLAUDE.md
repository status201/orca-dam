# CLAUDE.md

## Project Overview

ORCA DAM (ORCA Retrieves Cloud Assets) — Laravel 13 Digital Asset Management with AWS S3, AI tagging via AWS Rekognition, role-based access, and a REST API for Rich Text Editor integration.

## Specs (Spec-Driven Development)

`specs/` is the architectural/behavioural source of truth — read [`specs/README.md`](specs/README.md) (the method) and [`specs/architecture.md`](specs/architecture.md) (system overview) before non-trivial work. It holds 44 feature specs, 15 ADRs (the *why*), and recipes. Specs **link** to this file for conventions; they don't restate it.

**The gate is enforced.** A change that edits production code — `app/**`, `routes/**`, `database/migrations/**`, `config/**` (except Laravel-published framework configs), `resources/js/**` (except `resources/js/vendor/**`) — **must create/update a spec under `specs/` in the same change**. `scripts/sdd-guard.mjs` enforces it (PreToolUse + Stop hooks in `.claude/settings.json`, plus a CI job). Write the spec first — use `/feature`, `/fix`, `/spec`. Exempt: views, CSS, `lang/**`, factories/seeders, `tests/**`, `public/**`, `wordpress-plugin/**`, all `*.md`. Bypass a genuinely trivial production tweak with `touch .sdd-skip` (local), or `[skip-sdd]` in a commit message / the `skip-sdd` PR label (CI). `scripts/spec-lint.mjs` (`npm run spec:lint`) validates spec structure + index completeness; that every test path a spec names — in a `# pinned by:` line *or* a `## Tests & verification` bullet — resolves, and that the section exists at all; and that documented facts still match the tree: dependency versions against `composer.json` / `package.json`, hand-counted totals (specs, ADRs, Alpine modules, test files, E2E tests), the `QUICK_REFERENCE.md` file tree against `app/Services/`, `app/Console/Commands/` and the top-level dirs, and `GEBRUIKERSHANDLEIDING.md`'s heading structure against `USER_MANUAL.md`. It reads the root docs, not just `specs/**` + this file. `CHANGELOG.md` is only checked inside `[Unreleased]` — released entries are history.

**Docs have one home each** — the map is in [`README.md`](README.md#documentation-map). Don't copy a role matrix, endpoint list, command list or file tree into a second doc; link to the owner. Browser-level behaviour is pinned by the feature spec that owns it; [`specs/features/e2e-testing.md`](specs/features/e2e-testing.md) owns only the harness.

## Common Commands

Standard Laravel/Vite invocations (`artisan serve`, `npm run dev|build`, `pint`, `*:clear`) are not listed — see `package.json` scripts. Project commands, which are not guessable:

```bash
# Testing (always clear config first — stale cache can point RefreshDatabase at the dev DB)
php artisan config:clear && php artisan test
php artisan config:clear && php artisan test --testsuite=Feature
php artisan config:clear && php artisan test tests/Feature/AssetTest.php
vendor/bin/phpunit --filter=test_name

# Browser E2E (Playwright — boots `artisan serve --env=e2e` itself; see specs/features/e2e-testing.md)
npm run test:e2e:install                 # once: Chromium + OS deps
npm run e2e:up                           # MinIO on :9000 (stands in for S3; skip → S3 specs skip)
npm run test:e2e                         # whole suite
npm run test:e2e -- tests/e2e/asset-grid.spec.js
npm run e2e:reset                        # migrate:fresh + E2eSeeder on database/e2e.sqlite
npm run e2e:down

# Maintenance
php artisan uploads:cleanup [--hours=48]
php artisan assets:verify-integrity      # Queue S3 integrity checks
php artisan assets:backfill-etags        # Fetch etags from S3
php artisan assets:deduplicate [--force] # Soft-delete duplicates by etag
php artisan lang:safe-update             # Refresh laravel-lang files; protects project nl.json (never use raw lang:update)

# API Tokens / JWT / Passkeys
php artisan token:list / token:create [user@email] [--new] [--name="…"] / token:revoke <id|--user=email> [--force]
php artisan jwt:list / jwt:generate <user@email> [--force] / jwt:revoke <user@email> [--force]
php artisan passkeys:list [--user=email] [--role=admin|editor|api] / passkeys:revoke <id|--user=email> [--force]

# Queue (dev)
php artisan queue:work --tries=3
```

Full command reference: [`specs/features/maintenance-commands.md`](specs/features/maintenance-commands.md).

## Architecture

See [`specs/architecture.md`](specs/architecture.md) for the service-layer map, request lifecycle, middleware stack, queue/job map, authentication mechanisms, S3/CDN topology, and data model. Per-feature behaviour lives in [`specs/features/`](specs/features/). Only conventions that constrain *every* change are restated below.

### Authorization (`app/Policies/`)

`AssetPolicy`, `SystemPolicy`, `UserPolicy`. **All abilities encode role lists explicitly — no `return true` stubs.** Adding a new role requires opting into each ability.

**Roles** (`users.role`, `NOT NULL`, **no DB default** — every creation path must name the role; see [`specs/features/authentication.md`](specs/features/authentication.md) REQ-8):

| Action | admin | editor | api |
|---|---|---|---|
| view / viewAny / create / update / bulkDownload | ✓ | ✓ | ✓ |
| replace / delete (soft) / restore / bulkTrash / bulkRestore | ✓ | ✓ | ✗ |
| forceDelete / discover / export | ✓ | ✗ | ✗ |
| move / bulkForceDelete (also requires `maintenance_mode`) | ✓ | ✗ | ✗ |

`AssetApiController::destroy` routes through `$this->authorize('delete', $asset)`, so API tokens cannot delete assets via the REST API.

### Locale

`SetLocale` middleware: user preference → `settings.locale` → `config('app.locale')`. Languages: `en`, `nl`. User prefs in encrypted JSON `users.preferences`. App strings in `lang/nl.json` (add a Dutch entry for every new `__()` string); framework strings (validation/auth/passwords) in `lang/nl/*.php`, published via `laravel-lang/common` (dev dep — refresh with `php artisan lang:safe-update`; **never raw `lang:update`/`lang:add nl`**, which overwrite project translations in `nl.json` — a PreToolUse hook blocks them and `TranslationIntegrityTest` guards sentinel keys + completeness). JS toasts get translations via `@js(__())` injection into `window.__pageData.translations` (tools views), `window.assetTranslations` (asset grid), or `window.appTranslations` (layout); API responses stay English.

## Environment Configuration

Variables and defaults: `.env.example` plus the `env()` calls in `config/*.php`. The following are *not* derivable from either:

**S3 IAM**: `s3:PutObject/GetObject/DeleteObject/ListBucket`. Public read via bucket policy (not ACLs). Rekognition: `rekognition:DetectLabels/DetectText`. Translate (when language ≠ en): `translate:TranslateText`.

**PHP for large files**: `memory_limit≥256M`. Chunked mode: `upload_max_filesize=15M`, `post_max_size=16M`. Direct mode: `upload_max_filesize=500M`, `post_max_size=512M`. Auto-selects: `<10MB` direct, `≥10MB` chunked.

## Conventions

- **Frontend**: 21 Alpine modules in `resources/js/alpine/` registered in `resources/js/app.js`. Shared mixins/helpers (not top-level): `upload-metadata` (batch metadata form), `thumbnail-generator` (client-side PDF/video thumbs), `tag-input-core` (`parseTagNames` + `tagInputCore` — comma/paste splitting shared by all four tag inputs: asset edit, upload metadata, grid bulk bar, grid row). Asset grid markup is `resources/views/assets/partials/grid.blade.php`, shared between index and embed.
- **S3 keys**: `assets/{folder}/{uuid}.{ext}`; thumbnails `thumbnails/{folder}/{uuid}_thumb.{ext}` (JPEG).
- **Errors**: services swallow + log + return null/[]. Controllers validate + return appropriate codes. API-role users get generic messages (`Controller::clientError()`); admin/editor see exception detail. Logs in `storage/logs/laravel.log`.
- **Delete**: soft delete keeps S3 objects; hard delete (admin) clears S3 + DB. Discovery flags soft-deleted to prevent re-import.

## Testing

**Always run `php artisan config:clear &&` first** — a stale `bootstrap/cache/config.php` can point `RefreshDatabase` at the dev DB and wipe it.

Pest with in-memory SQLite (`phpunit.xml`). Factories in `database/factories/`.

**Browser E2E**: Playwright specs in `tests/e2e/` drive the real app (`artisan serve --env=e2e`) against `database/e2e.sqlite` + a local MinIO bucket, seeded by `database/seeders/E2eSeeder.php`. Locate elements by `data-testid` (the UI renders in `en` *and* `nl`), reseed per spec file, `workers: 1`. Contract: [`specs/features/e2e-testing.md`](specs/features/e2e-testing.md); how-to: [`specs/recipes/write-an-e2e-test.md`](specs/recipes/write-an-e2e-test.md). The separate WordPress-plugin suite (`wordpress-plugin/tests/e2e/`) is unrelated and runs against a mock ORCA.

## Integration & Deployment

- **RTE integration** — see `RTE_INTEGRATION.md`. Public metadata API: `GET /api/assets/meta?url={url}` (no auth).
- **WordPress plugin** — separate release stream in `wordpress-plugin/`, released as `.zip` on GitHub Releases under `wp-v*` tags. v1 is consume-only (no uploads). See `wordpress-plugin/README.md`.
- **Deployment** — see `DEPLOYMENT.md`. Production queue: supervisor (`deploy/supervisor/orca-queue-worker.conf`). Do not run `queue:work` from the web UI.
