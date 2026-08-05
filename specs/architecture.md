# Architecture — ORCA DAM

```yaml
id: architecture
status: implemented
version: 1
owner: core
related: []
source:
  - routes/web.php
  - routes/api.php
  - app/Http/Middleware/
  - app/Services/
  - app/Models/Asset.php
  - app/Policies/
```

The system-wide source of truth. Read this before any feature spec — the feature
specs assume the contracts and conventions defined here. This file describes
*architecture and behaviour*; the exhaustive conventions reference is `CLAUDE.md`.

## Background / Why

ORCA DAM (**ORCA Retrieves Cloud Assets**) is a Laravel 13 Digital Asset Management
app: assets live in AWS S3, metadata in MariaDB, with AI tagging via AWS
Rekognition, role-based access, passwordless + MFA auth, and a REST API for Rich
Text Editor / WordPress integration. The frontend is server-rendered Blade with
Alpine.js modules (no SPA framework — see
[ADR-007](decisions/adr-007-blade-alpine-over-spa.md)); the backend is organised as
thin controllers over a **service layer** (see
[ADR-001](decisions/adr-001-service-layer.md)).

The defining shape is: an HTTP request passes a fixed **middleware stack**, hits a
**controller** that validates and authorizes (via a **Policy**), then delegates the
real work to a **service** in `app/Services/` which talks to S3 / the DB / the
queue. Services **swallow-and-log** failures and return `null`/`[]`; controllers
map outcomes to status codes (see
[ADR-010](decisions/adr-010-services-swallow-controllers-map.md)).

## Requirements (system invariants)

- **REQ-1** — All S3 and image-processing work goes through the service layer
  (`S3Service`, `AssetProcessingService`, `ImageProcessingService`), never inline in
  a controller.
- **REQ-2** — Every policy ability encodes its allowed roles **explicitly**; there
  are no `return true` stubs. Adding a role means opting it into each ability (see
  [ADR-002](decisions/adr-002-explicit-policy-roles.md)).
- **REQ-3** — `assets.s3_key` is **immutable** once written; the `filename` is the
  editable display name. Cache invalidation is a Cloudflare purge, never a key
  rewrite (see [ADR-006](decisions/adr-006-immutable-s3-key.md)).
- **REQ-4** — Soft delete keeps the S3 objects; only a hard (force) delete clears
  storage (see [ADR-003](decisions/adr-003-soft-delete-keeps-s3.md)).
- **REQ-5** — Runtime-tunable configuration lives in the DB (`Setting`, 1-hour
  cache), not `.env`; `.env` holds only secrets and deploy-fixed wiring (see
  [ADR-011](decisions/adr-011-settings-in-db.md)).
- **REQ-6** — Uploads are restricted to the `config/uploads.php` allowlist, enforced
  by `App\Rules\AllowedUploadExtension` on **every** upload path (direct, chunked,
  replace); SVGs are sanitized before storage.

## Tech stack

```yaml
language: PHP                   # 8.3+ (CI runs 8.4)
framework: laravel/framework    # ^13
frontend: Blade + Alpine.js     # Alpine 3.4, Tailwind 3.1, Font Awesome 6.4
build: Vite                     # 8
tests: pestphp/pest             # 4 (on PHPUnit 12) — in-memory SQLite, sync queue
style: laravel/pint             # 1.13
aws: aws/aws-sdk-php            # 3.379 (S3, Rekognition, Translate)
images: intervention/image      # 4 (GD driver)
auth_passkeys: laravel/passkeys # 0.2.1 (WebAuthn / FIDO2)
auth_jwt: firebase/php-jwt      # 7
mfa: pragmarx/google2fa-laravel # 3 ; bacon/bacon-qr-code 3
svg: enshrined/svg-sanitize     # 0.22
js_libs:                        # pdfjs-dist 5.5, sortablejs 1.15, gif.js 0.2
commands:
  dev: php artisan serve  /  npm run dev
  build: npm run build
  test: php artisan config:clear && php artisan test   # ALWAYS config:clear first (ADR-008)
  style: ./vendor/bin/pint
  spec_lint: npm run spec:lint
```

## Technical design

### Request lifecycle

```
HTTP request
  └─ web middleware group:  SecurityHeaders → AllowEmbedding → SetLocale → auth.multi
       (or) api middleware:  throttle → auth.multi:sanctum,jwt
     └─ Controller           validate (FormRequest / inline) + authorize (Policy)
        └─ Service            S3Service / AssetProcessingService / … (the real work)
           ├─ S3              stream up/down, thumbnails, resizes
           ├─ Queue (job)     GenerateAiTags, ProcessDiscoveredAsset, VerifyAssetIntegrity, …
           └─ DB (Eloquent)   Asset / Tag / Setting / …
     └─ Response             SecurityHeaders re-applied; AllowEmbedding may relax X-Frame-Options
```

### Middleware stack

Registered in `bootstrap/app.php`; the web group in order:

- **`SecurityHeaders`** — `X-Content-Type-Options: nosniff`, `X-Frame-Options:
  SAMEORIGIN`, `Referrer-Policy`, and HSTS over HTTPS. Runs before `AllowEmbedding`
  on the response so embedding can relax `X-Frame-Options` into a `frame-ancestors`
  CSP. See [`features/security-headers.md`](features/security-headers.md).
- **`AllowEmbedding`** — when `embed_allowed_domains` is non-empty, sets
  `Content-Security-Policy: frame-ancestors 'self' <domains>` and removes
  `X-Frame-Options` on web routes. See [`features/iframe-embedding.md`](features/iframe-embedding.md).
- **`SetLocale`** — resolves locale: user preference → `settings.locale` →
  `config('app.locale')`. See [`features/localization.md`](features/localization.md).
- **`auth.multi`** (`AuthenticateMultiple`) — see "Authentication" below.

### Service-layer map

The heart of the app (`app/Services/`, 16 services). One-line each; read the source
or the governing feature spec for detail:

```yaml
S3Service:               # all S3 ops (upload/delete/list/move), SVG sanitize, CDN URL
AssetProcessingService:  # shared post-upload work (thumbnail, resizes, dimensions, AI dispatch)
ImageProcessingService:  # Intervention/GD wrapper (thumbs, resizes, dimensions, animated-GIF)
AssetSearchParser:       # pure parser for search input (+req -excl "phrase")
ChunkedUploadService:    # S3 multipart for >=10MB uploads; upload_sessions
RekognitionService:      # AI tagging via Rekognition + Translate
CloudflareService:       # non-blocking CDN purge on replace / thumbnail regen
TikzCompilerService:     # TeX Live pipeline (LaTeX -> DVI -> SVG/PNG); paranoid security
ToolUploadService:       # persist tool-generated assets (parent_asset_id link)
PasskeyService:          # passkey list/rename/delete/clear (max 10/user)
TwoFactorService:        # TOTP setup / verification / recovery codes
CsvExportService:        # 33-column export
CsvImportService:        # parse -> diff -> validate -> apply
QueueService:            # System dashboard queue backing
TestRunnerService:       # System dashboard web test runner backing
SystemService:           # System dashboard diagnostics / env / S3 connectivity
```

### Queue / job map

Jobs (`app/Jobs/`, 5) run on the queue (sync in tests):

```yaml
GenerateAiTags:          # Rekognition tagging after upload (if enabled)
ProcessDiscoveredAsset:  # import an S3-discovered object into an Asset row
VerifyAssetIntegrity:    # check the S3 object still exists; set/clear s3_missing_at
RegenerateResizedImage:  # bulk resize regeneration (System endpoint)
RunTestSuiteJob:         # runs the web-based test runner (System -> Tests)
```

### Authentication (four mechanisms behind one middleware)

`auth.multi` (`AuthenticateMultiple`, alias `auth.multi:web,sanctum,jwt`) tries each
named guard in order and authenticates on the first that succeeds — one middleware,
not one unified guard (see [ADR-004](decisions/adr-004-auth-multi.md)). The four:

```yaml
web:      # session (Breeze) — the browser UI; may require TOTP; passkey login bypasses TOTP
sanctum:  # long-lived personal access tokens — backend/WordPress integrations
jwt:      # short-lived per-user JWT (off by default); custom App\Auth\JwtGuard
passkeys: # WebAuthn/FIDO2 on the web guard (laravel/passkeys); ORCA's own routes
```

See [`features/authentication.md`](features/authentication.md),
[`jwt-auth.md`](features/jwt-auth.md), [`passkeys.md`](features/passkeys.md),
[`api-tokens-sanctum.md`](features/api-tokens-sanctum.md),
[`two-factor-auth.md`](features/two-factor-auth.md).

### Authorization — role × ability matrix

Three roles (`users.role`, `NOT NULL`, no DB default — every creation path names it, see
[`features/authentication.md`](features/authentication.md) REQ-8): `admin`, `editor`,
`api`. Policies
(`AssetPolicy`, `SystemPolicy`, `UserPolicy`) encode role lists explicitly (REQ-2).
The asset matrix (see [`features/authorization-policies.md`](features/authorization-policies.md)):

| Action | admin | editor | api |
|---|---|---|---|
| view / viewAny / create / update / bulkDownload | ✓ | ✓ | ✓ |
| replace / delete (soft) / restore / bulkTrash / bulkRestore | ✓ | ✓ | ✗ |
| forceDelete / discover / export | ✓ | ✗ | ✗ |
| move / bulkForceDelete (also require `maintenance_mode`) | ✓ | ✗ | ✗ |

### S3 + CDN topology

```
Upload → S3:  assets/{folder}/{uuid}.{ext}                (original; ContentType server-detected)
              thumbnails/{folder}/{uuid}_thumb.jpg         (300x300 JPEG; skips animated GIFs)
              thumbnails/{S|M|L}/...                       (resize variants, resize_{s,m,l}_s3_key)
Read:   getPublicBaseUrl() → AWS_URL or the configured custom_domain
Purge:  CloudflareService.collectAssetUrls() → purgeUrls() on replace / thumbnail regen
        (requires env + custom_domain + cloudflare_cache_purge toggle; never throws)
```

See [`features/s3-storage.md`](features/s3-storage.md),
[`image-processing.md`](features/image-processing.md),
[`cloudflare-purge.md`](features/cloudflare-purge.md).

### Data model (the "schemas")

```yaml
Asset:                  # assets table — features/asset-model.md
  s3_key: string        # unique, IMMUTABLE (REQ-3)
  etag: string          # S3 etag — the dedup key (features/duplicate-detection.md)
  filename: string      # editable display name
  mime_type / size / width / height
  thumbnail_s3_key / resize_{s,m,l}_s3_key
  alt_text / caption
  license_type / license_expiry_date / copyright / copyright_source
  user_id                # belongsTo User
  parent_id              # nullable self-FK (derived -> source, e.g. TikZ render -> .tex)
  deleted_at             # soft delete (REQ-4)
  s3_missing_at          # set by VerifyAssetIntegrity when the object is gone

Tag:    { name: unique, type: user|reference|ai }   # pivot asset_tag.attached_by
Setting:{ key: unique, value, type, group }         # cached 1h — REQ-5
UploadSession:          # chunked-upload state (upload_sessions) — features/chunked-upload.md
User:   { role: admin|editor|api, jwt_secret, two_factor_*, preferences(plain json) }
Passkey / GameScore
```

## Global conventions (constrain every feature)

- **Services swallow + log + return `null`/`[]`.** Controllers validate and map to
  status codes; API-role users get generic messages via `Controller::clientError()`,
  admins/editors see detail. See [ADR-010](decisions/adr-010-services-swallow-controllers-map.md).
- **Tests always run after `php artisan config:clear`.** A stale
  `bootstrap/cache/config.php` can point `RefreshDatabase` at the dev DB and wipe it.
  See [ADR-008](decisions/adr-008-sqlite-tests.md).
- **`lang/nl.json` is project-owned.** Refresh framework strings with
  `php artisan lang:safe-update`, **never** raw `lang:update` (a hook blocks it). Add
  a Dutch entry for every new `__()` string. See [ADR-009](decisions/adr-009-project-owns-nl-json.md).
- **Reference tags are API-created only** (they track external-system usage);
  editable/deletable in the web UI. See [ADR-012](decisions/adr-012-reference-tags-api-only.md).
- **The WordPress plugin is a separate release stream** (`wp-v*` tags,
  `wordpress-plugin/`). See [ADR-013](decisions/adr-013-wordpress-plugin-separate-stream.md).
- **Free-text tag input** is normalized by `App\Support\TagInputParser::parse()`
  everywhere (comma-splitting, trim, lowercase, dedup). See [`features/tag-input.md`](features/tag-input.md).

## Key decisions (ADRs)

The *why* behind the choices above — and the alternatives each rejected — lives in
[`decisions/`](decisions/). The full list:

- [ADR-000](decisions/adr-000-spec-driven-development.md) — Spec-Driven Development as the working method (enforced).
- [ADR-001](decisions/adr-001-service-layer.md) — service layer over fat controllers.
- [ADR-002](decisions/adr-002-explicit-policy-roles.md) — policies encode role lists explicitly (REQ-2).
- [ADR-003](decisions/adr-003-soft-delete-keeps-s3.md) — soft delete keeps S3; only hard delete clears it (REQ-4).
- [ADR-004](decisions/adr-004-auth-multi.md) — four auth mechanisms behind `auth.multi`.
- [ADR-005](decisions/adr-005-chunked-above-10mb.md) — chunked multipart above 10 MB, direct below.
- [ADR-006](decisions/adr-006-immutable-s3-key.md) — `s3_key` immutable; purge, never rewrite (REQ-3).
- [ADR-007](decisions/adr-007-blade-alpine-over-spa.md) — Blade + Alpine modules over an SPA.
- [ADR-008](decisions/adr-008-sqlite-tests.md) — in-memory SQLite for tests vs MariaDB in prod.
- [ADR-009](decisions/adr-009-project-owns-nl-json.md) — the project owns `lang/nl.json`.
- [ADR-010](decisions/adr-010-services-swallow-controllers-map.md) — services swallow+log; controllers map codes.
- [ADR-011](decisions/adr-011-settings-in-db.md) — runtime settings in the DB (REQ-5).
- [ADR-012](decisions/adr-012-reference-tags-api-only.md) — reference tags are API-created only.
- [ADR-013](decisions/adr-013-wordpress-plugin-separate-stream.md) — WordPress plugin is a separate release stream.
- [ADR-014](decisions/adr-014-playwright-e2e-real-stack.md) — browser E2E against a real stack (MinIO for S3).
- [ADR-015](decisions/adr-015-guided-demos-server-declared.md) — guided demos declared in PHP; spotlight hand-rolled.

## Tests & verification

- `php artisan config:clear && php artisan test` — the full Pest suite (1132 tests,
  91 files: `tests/Feature/` incl. `Auth/`,`Console/`,`Middleware/`; `tests/Unit/`
  incl. `Jobs/`,`Policies/`,`Services/`; `tests/Security/`). In-memory SQLite, sync queue.
- `php artisan config:clear && php artisan test --testsuite=Security` — the security
  invariants and exploit probes on their own, as the CI job runs them. See
  [security-invariants.md](features/security-invariants.md).
- `npm run test:e2e` — the Playwright browser suite (132 tests across 21 spec files)
  against a real `artisan serve` + MinIO. See [e2e-testing.md](features/e2e-testing.md).
- `./vendor/bin/pint --test` — code style.
- `npm run spec:lint` — spec structure (metadata, pins resolve, indexes complete) plus
  the documented-fact checks: dependency versions and hand-counted totals in the specs
  and the root docs must match the tree.

See `CLAUDE.md` for the exhaustive conventions, command catalogue, and testing notes.
