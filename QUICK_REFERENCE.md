# ORCA DAM - Quick Reference

The daily-contributor cheat sheet: commands, repo layout, web routes. It deliberately
does **not** restate things other docs own — see the map in
[README.md](README.md#documentation-map). In particular:

| Looking for | Go to |
|---|---|
| Install / `.env` / AWS IAM / PHP limits | [SETUP_GUIDE.md](SETUP_GUIDE.md), and `.env.example` |
| REST API endpoints, auth, query params | [RTE_INTEGRATION.md](RTE_INTEGRATION.md) |
| Role × ability matrix | [specs/features/authorization-policies.md](specs/features/authorization-policies.md) |
| Database schema | `database/migrations/` + [specs/features/asset-model.md](specs/features/asset-model.md) |
| Production deploy, Nginx, Supervisor | [DEPLOYMENT.md](DEPLOYMENT.md) |
| How the system fits together | [specs/architecture.md](specs/architecture.md) |
| What a feature is *supposed* to do | [specs/features/](specs/README.md) |

---

## Common Commands

### Setup

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan db:seed --class=AdminUserSeeder   # dev: admin@orca.dam / password
php artisan serve      # or use Herd
npm run dev
```

The seeder's defaults are development-only. Under `APP_ENV=production` it refuses to run unless
`ORCA_ADMIN_EMAIL` and `ORCA_ADMIN_PASSWORD` are set — see [DEPLOYMENT.md](DEPLOYMENT.md) step 6.

PHP upload limits (chunked vs. direct mode) and the Herd / `public/.user.ini` details are
in [SETUP_GUIDE.md](SETUP_GUIDE.md#changing-upload-limits).

### Daily development

```bash
php artisan optimize:clear               # all caches at once
php artisan config:clear                 # required before running tests
php artisan migrate:fresh --seed
php artisan route:list
tail -f storage/logs/laravel.log
./vendor/bin/pint                        # code style — run before committing
npm run spec:lint                        # spec structure + documented facts
```

### Testing

```bash
php artisan config:clear && php artisan test          # 1198 tests, in-memory SQLite
php artisan config:clear && php artisan test --testsuite=Unit
php artisan config:clear && php artisan test --filter="asset"
./vendor/bin/pest --filter="can update"

npm run test:e2e:install                 # once: Chromium + OS deps
npm run e2e:up                           # MinIO on :9000 (skip → S3 specs skip)
npm run test:e2e                         # 133 Playwright tests, 21 spec files
npm run test:e2e -- tests/e2e/asset-grid.spec.js
npm run e2e:reset                        # rebuild database/e2e.sqlite
npm run e2e:down
```

`config:clear` first, always: a stale `bootstrap/cache/config.php` can point
`RefreshDatabase` at the development database. Contract:
[specs/features/e2e-testing.md](specs/features/e2e-testing.md).

**Web-based test runner:** Admin → System → Tests.

### Project commands

```bash
# API tokens (Sanctum)
php artisan token:list
php artisan token:create user@email.com [--name="…"]     # token for an existing user
# --new provisions a role=api user first (accounts are admin-provisioned; there is no
# self-service registration). It always prompts for the email — the positional argument
# is ignored with --new — and prompts for --user-name when that is omitted.
php artisan token:create --new [--user-name="…"] [--name="…"]
php artisan token:revoke <id|--user=email> [--force]

# JWT secrets
php artisan jwt:list
php artisan jwt:generate user@email.com [--force]
php artisan jwt:revoke user@email.com [--force]

# Two-factor auth
php artisan two-factor:status [--enabled] [--role=admin|editor|api]
php artisan two-factor:disable user@email.com

# Passkeys
php artisan passkeys:list [--user=email] [--role=admin|editor|api]
php artisan passkeys:revoke <id|--user=email> [--force]

# Reference tags
php artisan reference-tag:create <name> [<name>…]

# User audit trail (create / role-change / delete events; not exposed in the web UI)
php artisan users:audit [--user=email] [--event=created|updated|deleted] [--limit=50]

# Maintenance
php artisan uploads:cleanup [--hours=48]      # stale chunked upload sessions
php artisan assets:verify-integrity           # queue S3 integrity checks
php artisan assets:backfill-etags             # fetch etags from S3 for dedup
php artisan assets:deduplicate [--force]      # dry-run, or soft-delete duplicates
php artisan lang:safe-update                  # NEVER raw lang:update — it eats nl.json

# Queue (dev; production uses Supervisor — see DEPLOYMENT.md)
php artisan queue:work --tries=3
```

Full contracts for all 17 commands:
[specs/features/maintenance-commands.md](specs/features/maintenance-commands.md).

---

## File Locations

Complete for `app/Services/`, `app/Console/Commands/` and the top-level directories —
`scripts/spec-lint.mjs` fails if any of those gains a file that is not listed here.

```
orca-dam/
├── app/
│   ├── Console/Commands/                  # 17 artisan commands
│   │   ├── BackfillEtags.php              # assets:backfill-etags
│   │   ├── CleanupStaleUploads.php        # uploads:cleanup
│   │   ├── DeduplicateAssets.php          # assets:deduplicate
│   │   ├── JwtGenerateCommand.php         # jwt:generate
│   │   ├── JwtListCommand.php             # jwt:list
│   │   ├── JwtRevokeCommand.php           # jwt:revoke
│   │   ├── LangSafeUpdate.php             # lang:safe-update
│   │   ├── PasskeysListCommand.php        # passkeys:list
│   │   ├── PasskeysRevokeCommand.php      # passkeys:revoke
│   │   ├── ReferenceTagCreateCommand.php  # reference-tag:create
│   │   ├── TokenCreateCommand.php         # token:create
│   │   ├── TokenListCommand.php           # token:list
│   │   ├── TokenRevokeCommand.php         # token:revoke
│   │   ├── TwoFactorDisableCommand.php    # two-factor:disable
│   │   ├── TwoFactorStatusCommand.php     # two-factor:status
│   │   ├── UsersAuditCommand.php          # users:audit
│   │   └── VerifyAssetIntegrity.php       # assets:verify-integrity
│   ├── Demos/                             # guided onboarding walkthroughs (one class per demo)
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Api/
│   │   │   │   ├── AssetApiController.php # REST asset endpoints
│   │   │   │   └── HealthController.php   # public /api/health
│   │   │   ├── Auth/                      # Breeze scaffold + 2FA + passkey login
│   │   │   ├── AboutController.php
│   │   │   ├── ApiDocsController.php      # /api-docs dashboard
│   │   │   ├── AssetBulkController.php    # bulk tags/trash/move/download/force-delete
│   │   │   ├── AssetController.php        # index, embed, show (+ cycle nav), CRUD
│   │   │   ├── AssetReplaceController.php # replace bytes at the same S3 key
│   │   │   ├── AssetTrashController.php   # trash, restore, force delete
│   │   │   ├── ChunkedUploadController.php
│   │   │   ├── Controller.php             # base: clientError() role-aware errors
│   │   │   ├── DashboardController.php
│   │   │   ├── DiscoverController.php     # unmapped S3 objects → import
│   │   │   ├── ExportController.php       # CSV export
│   │   │   ├── FolderController.php       # folder list / scan / create
│   │   │   ├── GameScoreController.php    # easter-egg leaderboard
│   │   │   ├── ImportController.php       # CSV metadata import
│   │   │   ├── JwtSecretController.php
│   │   │   ├── ProfileController.php      # profile + preferences + passkeys
│   │   │   ├── SystemController.php       # /system: settings, queue, logs, tests
│   │   │   ├── TagController.php
│   │   │   ├── TokenController.php
│   │   │   ├── ToolsController.php        # TikZ / GIF / MathML tools
│   │   │   └── UserController.php         # user CRUD (admin)
│   │   ├── Middleware/
│   │   │   ├── AllowEmbedding.php         # frame-ancestors CSP for /assets/embed
│   │   │   ├── AuthenticateMultiple.php   # auth.multi: session|sanctum|jwt
│   │   │   ├── SecurityHeaders.php        # nosniff, XFO, Referrer-Policy, HSTS
│   │   │   └── SetLocale.php              # user pref → setting → config
│   │   ├── Requests/                      # form requests incl. Auth/LoginRequest
│   │   └── Resources/                     # API resources
│   ├── Jobs/
│   │   ├── GenerateAiTags.php             # Rekognition + Translate
│   │   ├── ProcessDiscoveredAsset.php     # discovery import
│   │   ├── RegenerateResizedImage.php     # S/M/L variants
│   │   ├── RunTestSuiteJob.php            # web test runner
│   │   └── VerifyAssetIntegrity.php       # s3_missing_at
│   ├── Models/
│   │   ├── Asset.php                      # scopes: search, ofType, withTags, applySort, missing
│   │   ├── GameScore.php
│   │   ├── Passkey.php
│   │   ├── Setting.php                    # key-value, 1h cache
│   │   ├── Tag.php                        # type: user | ai | reference
│   │   ├── UploadSession.php              # chunked upload state
│   │   └── User.php                       # role, preferences (plain json), encrypted jwt_secret
│   ├── Policies/
│   │   ├── AssetPolicy.php                # the role × ability matrix
│   │   ├── SystemPolicy.php
│   │   └── UserPolicy.php
│   ├── Rules/                             # AllowedUploadExtension
│   ├── Services/                          # 16 services
│   │   ├── AssetProcessingService.php     # thumbnails, resizes, AI-tag dispatch
│   │   ├── AssetSearchParser.php          # +require / -exclude / "phrases"
│   │   ├── ChunkedUploadService.php       # S3 multipart
│   │   ├── CloudflareService.php          # CDN purge on replace
│   │   ├── CsvExportService.php
│   │   ├── CsvImportService.php
│   │   ├── ImageProcessingService.php     # Intervention Image, animated-GIF sniffing
│   │   ├── PasskeyService.php             # WebAuthn credentials
│   │   ├── QueueService.php               # queue stats + failed jobs
│   │   ├── RekognitionService.php         # DetectLabels + Translate
│   │   ├── S3Service.php                  # keys, streaming, folders, sanitizeSvg
│   │   ├── SystemService.php              # diagnostics, log tail, disk usage
│   │   ├── TestRunnerService.php          # web test runner
│   │   ├── TikzCompilerService.php        # TeX Live → SVG/PNG, 17 font packages
│   │   ├── ToolUploadService.php          # tool output → asset
│   │   └── TwoFactorService.php           # TOTP + recovery codes
│   └── Support/                           # TagInputParser
├── bootstrap/                             # app.php: middleware + scheduled tasks
├── config/                                # incl. jwt.php, uploads.php, tikz.php, services.php
├── database/
│   ├── factories/
│   ├── migrations/                        # the authoritative schema
│   └── seeders/                           # AdminUserSeeder, E2eSeeder
├── deploy/supervisor/                     # orca-queue-worker.conf
├── lang/                                  # nl.json (project) + nl/*.php (laravel-lang)
├── public/
├── resources/
│   ├── css/
│   ├── js/
│   │   ├── alpine/                        # 22 modules registered in app.js, + 5 mixins
│   │   └── app.js                         # module registration + showToast
│   └── views/
│       ├── assets/                        # index, embed, show, create, edit, partials/grid
│       ├── components/                    # incl. asset-cycle-nav
│       ├── layouts/
│       └── ...
├── routes/                                # web.php, api.php, auth.php, console.php
├── scripts/                               # sdd-guard.mjs, spec-lint.mjs
├── specs/                                 # the behavioural source of truth
│   ├── features/                          # 49 feature specs
│   ├── decisions/                         # 17 ADRs
│   └── recipes/                           # repeatable how-tos
├── tests/
│   ├── Feature/                           # incl. Auth/, Console/, Middleware/
│   ├── Unit/                              # incl. Jobs/, Policies/, Services/
│   ├── Security/                           # invariant audits + exploit probes (own suite)
│   └── e2e/                               # Playwright specs + support/ + global.setup.js
├── wordpress-plugin/                      # separate release stream (wp-v* tags)
├── .claude/                               # agents, slash commands, hooks
├── .semgrep/                              # custom AST rules + their test fixtures
├── .github/                               # workflows: tests, sdd, codeql; dependabot; issue templates
├── artisan
├── phpunit.xml
├── playwright.config.js
└── docker-compose.e2e.yml                 # MinIO for the E2E suite
```

---

## Key Routes

### Web Routes
```
GET  /assets                   # List assets
GET  /assets/embed              # Embeddable asset browser (no nav/footer, for iframes)
GET  /assets/create            # Upload form
POST /assets                   # Store assets
GET  /assets/{id}              # View asset
GET  /assets/{id}/edit         # Edit form (filename, metadata, tags)
PATCH /assets/{id}             # Update asset
DELETE /assets/{id}            # Soft delete asset
GET  /assets/{id}/replace      # Replace file form
POST /assets/{id}/replace      # Replace file (same S3 key)
GET  /assets/{id}/download     # Download asset
POST /assets/{id}/ai-tag       # Generate AI tags
POST /assets/{id}/tags         # Add tags
DELETE /assets/{id}/tags/{tag}  # Remove tag
POST /assets/bulk/tags         # Bulk add tags to selected assets
POST /assets/bulk/tags/remove  # Bulk remove tags from selected assets
POST /assets/bulk/tags/list    # Get tags for selected assets
POST /assets/bulk/move         # Bulk move assets between folders (admin, maintenance mode)
DELETE /assets/bulk/force-delete  # Bulk permanent delete (admin, maintenance mode)
POST /assets/bulk/trash        # Bulk soft delete (editors + admins)
POST /assets/bulk/download     # Bulk download as ZIP (all authenticated, max 100/500MB)
GET  /assets/trash/index       # View trash (admin)
POST /assets/{id}/restore      # Restore from trash (admin)
DELETE /assets/{id}/force-delete # Permanent delete (admin)
POST /folders/scan             # Refresh folder list from S3 (admin)
POST /folders                  # Create new folder (admin)
GET  /discover                 # Discovery page (admin)
POST /discover/scan            # Scan S3 bucket
POST /discover/import          # Import objects
GET  /import                   # Import metadata page (admin)
POST /import/preview           # Preview CSV import (JSON)
POST /import/import            # Execute CSV import (JSON)
GET  /tags                     # List tags
GET  /profile                  # User profile & preferences
PATCH /profile/preferences     # Update preferences (locale, home folder, etc.)
POST /profile/passkeys/options # Passkey registration challenge (auth)
POST /profile/passkeys         # Register a new passkey (auth)
PATCH /profile/passkeys/{id}   # Rename a passkey
DELETE /profile/passkeys/{id}  # Remove a passkey
POST /passkey/options          # Passkey login challenge (guest)
POST /passkey/login            # Passkey assertion (guest)
GET  /users                    # User management (admin)
DELETE /users/{user}/passkeys  # Clear all passkeys for a user (admin recovery)
GET  /system                   # System admin (admin)
GET  /system/integrity-status  # S3 integrity status JSON (admin)
POST /system/verify-integrity  # Queue S3 integrity checks (admin)
POST /system/settings          # Update settings (admin)
POST /system/run-tests         # Run automated tests (admin)
GET  /tools                    # Tools index
GET  /tools/tikz-server        # TikZ Server Render
POST /tools/tikz-server/render # Compile TikZ code
GET  /tools/tikz-server/templates       # Search .tex templates
GET  /tools/tikz-server/templates/{id}  # Load template content
POST /tools/tikz-server/templates/upload # Save .tex template
GET  /api-docs                          # API documentation page (admin)
GET  /api-docs/dashboard                # API stats dashboard (admin)
POST /api-docs/settings                 # Update API settings (admin)
GET  /api-docs/tokens                   # List API tokens (admin)
POST /api-docs/tokens                   # Create API token (admin)
DELETE /api-docs/tokens/{id}            # Revoke token (admin)
DELETE /api-docs/tokens/user/{userId}   # Revoke all user tokens (admin)
GET  /api-docs/jwt-secrets              # List JWT secrets (admin)
POST /api-docs/jwt-secrets/{user}       # Generate JWT secret (admin)
DELETE /api-docs/jwt-secrets/{user}     # Revoke JWT secret (admin)
```


### API Routes

The REST surface (assets, tags, folders, reference tags, chunked upload), its
authentication, query parameters and sort values are documented once in
[RTE_INTEGRATION.md](RTE_INTEGRATION.md#api-quick-reference). Behaviour is specified in
[specs/features/rest-api.md](specs/features/rest-api.md).

---

## Reference

| Subject | Authoritative source |
|---|---|
| Database schema | `database/migrations/`, plus [asset-model.md](specs/features/asset-model.md) for the entity contract |
| Environment variables | `.env.example`; annotated for dev in [SETUP_GUIDE.md](SETUP_GUIDE.md), for production in [DEPLOYMENT.md](DEPLOYMENT.md) |
| Role × ability matrix | [authorization-policies.md](specs/features/authorization-policies.md), pinned by `tests/Unit/Policies/AssetPolicyTest.php` |
| User preferences | [user-preferences.md](specs/features/user-preferences.md) — `$user->getPreference()`, `getHomeFolder()`, `getItemsPerPage()`, and the URL-param > user-pref > global override order |
| S3 key layout | [s3-storage.md](specs/features/s3-storage.md) — `assets/{folder}/{uuid}.{ext}`, `thumbnails/…`, `thumbnails/{S,M,L}/…` |
| Settings keys | [settings.md](specs/features/settings.md) |
| Troubleshooting | [SETUP_GUIDE.md](SETUP_GUIDE.md#troubleshooting) (app-level) · [DEPLOYMENT.md](DEPLOYMENT.md#troubleshooting) (server-level) |
| Production checklist | [DEPLOYMENT.md](DEPLOYMENT.md#security-checklist) |

---

## Support Resources

- **Docs map**: [README.md](README.md#documentation-map)
- **Behaviour**: [specs/README.md](specs/README.md) — read this before non-trivial work
- **WordPress plugin**: [`wordpress-plugin/README.md`](wordpress-plugin/README.md)
- **Laravel Docs**: https://laravel.com/docs
- **AWS S3 Docs**: https://docs.aws.amazon.com/s3/
- **AWS Rekognition**: https://docs.aws.amazon.com/rekognition/
