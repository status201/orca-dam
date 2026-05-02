# CLAUDE.md

## Project Overview

ORCA DAM (ORCA Retrieves Cloud Assets) — Laravel 13 Digital Asset Management with AWS S3, AI tagging via AWS Rekognition, role-based access, and a REST API for Rich Text Editor integration.

**Frontend**: Blade + Alpine.js (15 modules in `resources/js/alpine/`), Tailwind, Font Awesome 6.4, Vite, Intervention Image 3.x (GD).

## Common Commands

```bash
# Development
php artisan serve                    # Or use Laravel Herd
npm run dev / npm run build

# Testing (always clear config first — stale cache can point RefreshDatabase at the dev DB)
php artisan config:clear && php artisan test
php artisan config:clear && php artisan test --testsuite=Feature
php artisan config:clear && php artisan test tests/Feature/AssetTest.php
vendor/bin/phpunit --filter=test_name

# Code formatting
./vendor/bin/pint

# Cache
php artisan cache:clear / config:clear / route:clear / view:clear

# Maintenance
php artisan uploads:cleanup [--hours=48]
php artisan assets:verify-integrity      # Queue S3 integrity checks
php artisan assets:backfill-etags        # Fetch etags from S3
php artisan assets:deduplicate [--force] # Soft-delete duplicates by etag

# API Tokens / JWT / Passkeys
php artisan token:list / token:create [user@email] [--new] [--name="…"] / token:revoke <id|--user=email> [--force]
php artisan jwt:list / jwt:generate <user@email> [--force] / jwt:revoke <user@email> [--force]
php artisan passkeys:list [--user=email] [--role=admin|editor|api] / passkeys:revoke <id|--user=email> [--force]

# Queue (dev)
php artisan queue:work --tries=3
```

## Architecture

### Services (`app/Services/`)

One-line summaries; read the source for detail.

- **S3Service** — All S3 ops (upload/delete/list/move). Streams files. `uploadFile()` supports `keepOriginalFilename`. JPEG thumbnails (skips animated GIFs). `generateResizedImages()` writes S/M/L variants under `thumbnails/S|M|L/`. `deleteAssetFiles()` clears original + thumbnail + resizes. CDN URL via `getPublicBaseUrl()` honors `custom_domain`.
- **AssetProcessingService** — Shared post-upload work (thumbnail, resizes, dimensions, AI dispatch). Used by `AssetController`, `AssetApiController`, `ChunkedUploadController`, `ProcessDiscoveredAsset` job. `applyUploadMetadata()` applies batch metadata after `processImageAsset()`.
- **AssetSearchParser** — Pure parser for asset search input (`+req`, `-excl`, `"phrase"`, mixed). Strips configured S3 / custom-domain URL prefixes. Used by `Asset::scopeSearch`.
- **ChunkedUploadService** — S3 Multipart for ≥10MB / ≤500MB uploads. 10MB chunks, idempotent retries, sessions in `upload_sessions`. Etag dedup on complete (`DuplicateAssetException`).
- **RekognitionService** — AI tagging via Rekognition + AWS Translate when `AWS_REKOGNITION_LANGUAGE != 'en'`. Background via `GenerateAiTags` job. Settings read live from DB.
- **CloudflareService** — Non-blocking CDN purge on asset replace / thumbnail regen. `collectAssetUrls()` then `purgeUrls()`. Requires env + `custom_domain` + `cloudflare_cache_purge` toggle. Logs errors, never throws. Config: `config/cloudflare.php`.
- **TikzCompilerService** — TeX Live pipeline (LaTeX→DVI→SVG/PNG, optional embedded WOFF2 / paths-only). 17 font packages, configurable border/DPI/libraries, custom preambles. Security: blocks `\write18`/`\openin`, `--no-shell-escape`, paranoid file mode. Config: `config/tikz.php`.
- **WebAuthnService** — Passkey list/rename/delete/clear on top of `laragear/webauthn`. `MAX_CREDENTIALS_PER_USER = 10`. `TouchPasskeyLastUsed` listener stamps `users.last_passkey_used_at` and `webauthn_credentials.last_used_at` on assertion.
- **TwoFactorService** — TOTP setup / verification / recovery codes.
- **CsvExportService** / **CsvImportService** — `generateHeaders()` + `formatRow()` for export (33 columns, separated user/ai/reference tag columns). Import: `parseCsv()`, `calculateChanges()` diff vs existing, `validateRow()` for license + dates.
- **ImageProcessingService** — Intervention/GD wrapper. `createThumbnailContent()` (300×300 JPEG, null for animated GIFs), `createResizedContent()` (preserves format, GIF→JPEG), `getImageDimensions()`, `isAnimatedGif()`.
- **QueueService**, **TestRunnerService**, **SystemService** — Backing services for the System admin dashboard.

### Asset Controllers (split, post-refactor)

- `AssetController` — index, embed, show, create, store, edit, update, unlinkParent, addTags, removeTag.
- `AssetTrashController` — destroy, trash index, restore, forceDelete, bulkTrash, bulkRestore, bulkForceDeleteTrashed.
- `AssetBulkController` — bulkAddTags, bulkRemoveTags, bulkGetTags, bulkForceDelete, bulkMove, bulkDownload.
- `AssetReplaceController` — showReplace, replace, storeThumbnail, generateAiTags, download.

### Authentication

- **Sanctum** — long-lived tokens for backend integrations.
- **JWT** — short-lived (off by default; `JWT_ENABLED=true`). Guard `app/Auth/JwtGuard.php`, config `config/jwt.php`. Required claims: `sub`, `exp`, `iat`. Per-user secret encrypted in `users.jwt_secret`.
- **Passkeys (WebAuthn / FIDO2)** — `laragear/webauthn` v5, provider `eloquent-webauthn` with `password_fallback: true`. Passwordless login on `/login` (conditional UI). Successful passkey login bypasses TOTP. Profile → Security: register/rename/remove (max 10/user, admins + editors only — API users blocked). Admin recovery: "Clear all passkeys" + `passkeys:revoke --user=email`. Frontend: `resources/js/alpine/passkeys.js` (`@simplewebauthn/browser`). Config: `config/webauthn.php`. Env: `WEBAUTHN_ID`, `WEBAUTHN_ORIGINS` (default = `APP_URL` host).
- **Multi-auth middleware**: `app/Http/Middleware/AuthenticateMultiple.php` (`auth.multi:web,sanctum,jwt`).

### Authorization (`app/Policies/`)

`AssetPolicy`, `SystemPolicy`, `UserPolicy`. **All abilities encode role lists explicitly — no `return true` stubs.** Adding a new role requires opting into each ability.

**Roles** (`users.role`, default `editor`):

| Action | admin | editor | api |
|---|---|---|---|
| view / viewAny / create / update / bulkDownload | ✓ | ✓ | ✓ |
| replace / delete (soft) / restore / bulkTrash / bulkRestore | ✓ | ✓ | ✗ |
| forceDelete / discover / export | ✓ | ✗ | ✗ |
| move / bulkForceDelete (also requires `maintenance_mode`) | ✓ | ✗ | ✗ |

`AssetApiController::destroy` routes through `$this->authorize('delete', $asset)`, so API tokens cannot delete assets via the REST API.

### Locale

`SetLocale` middleware: user preference → `settings.locale` → `config('app.locale')`. Languages: `en`, `nl`. User prefs in encrypted JSON `users.preferences`.

### Iframe Embedding

`AllowEmbedding` middleware: when `embed_allowed_domains` is non-empty, sets `Content-Security-Policy: frame-ancestors 'self' <domains>` and removes `X-Frame-Options` on web routes.

### Models

- **Asset** — Belongs to User. Self-FK `parent()`/`children()` (e.g. TikZ render → source `.tex`). Many-to-many Tags (pivot `attached_by`). Soft deletes. Computed: `url`, `thumbnail_url`, `formatted_size`, `folder`, `is_missing`. `filename` editable; `s3_key` immutable. `syncTagsWithAttribution()` = "last attacher wins". Scopes: `search` (delegates to `AssetSearchParser`), `withTags`, `ofType` (accepts plurals like `images`), `byUser`, `inFolder`, `missing`, `applySort`. Search operators: `+req`, `-excl`, `"phrase"`, `+"phrase"`, `-"phrase"`. License fields: `license_type`, `license_expiry_date`, `copyright`, `copyright_source`.
- **Tag** — Type `user`/`ai`/`reference`, many-to-many Assets. Reference tags created via API only (track external system usage), editable/deletable in web UI.
- **Setting** — Key-value, cached 1 hour. `Setting::get('key', $default)` / `Setting::set($key, $value)`. Types: string/integer/boolean/json. Groups: general/display/aws/api.

## API Endpoints

### REST API (`routes/api.php`, `auth.multi:sanctum,jwt`)

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/assets` | List (pagination, search, filters, sort) |
| POST | `/api/assets` | Upload direct (<10MB) |
| GET | `/api/assets/{id}` | Single asset |
| PATCH | `/api/assets/{id}` | Update metadata |
| DELETE | `/api/assets/{id}` | Delete asset (admin/editor only — API role gets 403) |
| GET | `/api/assets/search` | Search with filters/sort |
| GET | `/api/assets/meta` | **Public** — metadata by URL |
| GET | `/api/health` | **Public** — 200/503 |
| GET | `/api/tags` | List tags (optional `?type=`) |
| POST | `/api/reference-tags` | Add ref tags to asset(s) (`asset_id`/`asset_ids`/`s3_key`/`s3_keys`) |
| DELETE | `/api/reference-tags/{tag}` | Remove ref tag from asset(s) |

**Sort values**: `date_desc` (default), `date_asc`, `upload_desc`, `upload_asc`, `size_desc`, `size_asc`, `name_asc`, `name_desc`, `s3key_asc`, `s3key_desc`.

### Chunked Upload (`/api/chunked-upload/*`, throttle 100/min)

`POST init` | `POST chunk` | `POST complete` | `POST abort`

### Web (`routes/web.php`, session auth)

- `GET /assets/embed` — embeddable browser (no nav/footer); supports all index query params.
- `POST/DELETE /assets/{asset}/tags[/{tag}]` — single-asset tag mgmt.
- `POST /assets/bulk/{tags|tags/remove|tags/list|trash|download}` — bulk ops.
- `POST /assets/bulk/move`, `DELETE /assets/bulk/force-delete` — admin + `maintenance_mode`.
- `DELETE /tags/bulk` — bulk tag delete.
- `POST /assets/{asset}/ai-tag` — trigger Rekognition.
- `GET /api/folders`, `POST /folders/scan`, `POST /folders` (admin) — folder ops.
- `/api-docs/*` (admin) — dashboard, settings, tokens, JWT secrets.
- `/system/*` (admin) — overview, settings, queue, logs, commands, diagnostics, tests, S3 integrity.
- `/tools` (editor + admin) — TikZ Server Render etc. Upload endpoints accept batch metadata: `metadata_tags`, `metadata_license_type`, `metadata_copyright`, `metadata_copyright_source`.

## Database Schema

- **assets** — `s3_key` (unique), `etag`, `filename`, `mime_type`, `size`, `width`, `height`, `thumbnail_s3_key`, `resize_{s,m,l}_s3_key`, `alt_text`, `caption`, `license_type` (public_domain, cc_by, cc_by_sa, cc_by_nd, cc_by_nc, cc_by_nc_sa, cc_by_nc_nd, fair_use, all_rights_reserved), `license_expiry_date`, `copyright`, `copyright_source`, `user_id`, `parent_id` (nullable self-FK, nullOnDelete — derived → source link), `deleted_at`, `s3_missing_at`.
- **upload_sessions** — `upload_id`, `session_token`, `filename`, `mime_type`, `file_size`, `s3_key`, `chunk_size`, `total_chunks`, `uploaded_chunks`, `part_etags` (JSON), `status`, `user_id`, `last_activity_at`.
- **tags** — `name` (unique), `type` (user/ai/reference).
- **asset_tag** — `asset_id`, `tag_id`, `attached_by` (nullable), timestamps.
- **settings** — `key` (unique), `value`, `type`, `group`, `description`.
- **users** (extras) — `role` (admin/editor/api, default `editor`), `jwt_secret` (encrypted), `jwt_secret_generated_at`, `last_passkey_used_at`, `two_factor_secret` (encrypted), `two_factor_recovery_codes` (encrypted), `two_factor_confirmed_at`, `preferences` (encrypted JSON: `home_folder`, `items_per_page`, `locale`, `dark_mode`).
- **webauthn_credentials** (package) — credential id PK, morph to User, alias, counter, rp_id, origin, transports (JSON), aaguid, public_key (encrypted), attestation_format, certificates (JSON), disabled_at, last_used_at.

**Default Settings**: `items_per_page=24`, `timezone=UTC`, `locale=en`, `s3_root_folder=assets`, `custom_domain=""`, `embed_allowed_domains=[]`, `rekognition_max_labels=3`, `rekognition_min_confidence=80`, `rekognition_language=nl`, `s3_folders=["assets"]`, `jwt_enabled_override=true`, `api_meta_endpoint_enabled=true`, `api_upload_enabled=true`, `resize_{s,m,l}_width = 250 / 600 / 1200`, `resize_{s,m,l}_height=""`, `maintenance_mode=false`, `cloudflare_cache_purge=false`.

## Environment Configuration

```env
# Required AWS
AWS_ACCESS_KEY_ID= / AWS_SECRET_ACCESS_KEY= / AWS_DEFAULT_REGION= / AWS_BUCKET= / AWS_URL=

# Optional: AI tagging
AWS_REKOGNITION_ENABLED=false
AWS_REKOGNITION_MAX_LABELS=3
AWS_REKOGNITION_MIN_CONFIDENCE=80     # 65-99
AWS_REKOGNITION_LANGUAGE=nl

# Optional: JWT
JWT_ENABLED=false
JWT_ALGORITHM=HS256
JWT_MAX_TTL=36000                     # seconds (default 10h)
JWT_LEEWAY=60
JWT_ISSUER=                           # optional issuer validation

# Optional: Passkeys (defaults derive from APP_URL host)
WEBAUTHN_ID=                          # Relying-party host
WEBAUTHN_ORIGINS=                     # Comma-separated extras (rare)

# Optional: Cloudflare CDN purge (also needs custom_domain + cloudflare_cache_purge toggle)
CLOUDFLARE_ENABLED=false
CLOUDFLARE_API_TOKEN=                 # Zone.Cache Purge permission
CLOUDFLARE_ZONE_ID=

# Optional: Web test runner
PHP_CLI_PATH=/usr/bin/php

# Optional: TikZ Server (needs TeX Live)
TIKZ_LATEX_PATH=latex
TIKZ_DVISVGM_PATH=dvisvgm
TIKZ_TIMEOUT=30
TIKZ_PNG_DPI=300                      # 72-600
```

**S3 IAM**: `s3:PutObject/GetObject/DeleteObject/ListBucket`. Public read via bucket policy (not ACLs). Rekognition: `rekognition:DetectLabels/DetectText`. Translate (when language ≠ en): `translate:TranslateText`.

**PHP for large files**: `memory_limit≥256M`. Chunked mode: `upload_max_filesize=15M`, `post_max_size=16M`. Direct mode: `upload_max_filesize=500M`, `post_max_size=512M`. Auto-selects: `<10MB` direct, `≥10MB` chunked.

## Conventions

- **Layout**: Controllers in `app/Http/Controllers/` (API in `Api/`, Auth in `Auth/`), Services in `app/Services/`, Middleware/Requests in `app/Http/`, Policies in `app/Policies/`, Jobs in `app/Jobs/`, Console in `app/Console/Commands/`, Exceptions in `app/Exceptions/` (e.g. `DuplicateAssetException`).
- **Frontend**: 15 Alpine modules in `resources/js/alpine/` registered in `resources/js/app.js`. Shared mixins (not top-level): `upload-metadata` (batch metadata form), `thumbnail-generator` (client-side PDF/video thumbs). Asset grid markup is `resources/views/assets/partials/grid.blade.php`, shared between index and embed.
- **S3 keys**: `assets/{folder}/{uuid}.{ext}`; thumbnails `thumbnails/{folder}/{uuid}_thumb.{ext}` (JPEG).
- **Errors**: services swallow + log + return null/[]. Controllers validate + return appropriate codes. Logs in `storage/logs/laravel.log`.
- **Delete**: soft delete keeps S3 objects; hard delete (admin) clears S3 + DB. Discovery flags soft-deleted to prevent re-import.

## Testing

**Pest** with in-memory SQLite (config in `phpunit.xml`: testing env, `:memory:`, array session/cache, sync queue). 907 tests at last count.

**Always run `php artisan config:clear &&` first** — a stale `bootstrap/cache/config.php` can point `RefreshDatabase` at the dev DB and wipe it.

**Factories** (`database/factories/`):
- `UserFactory` — defaults `role => 'editor'`. States: `admin()`, `editor()`, `apiUser()`, `unverified()`.
- `AssetFactory` — `image()`, `pdf()`, `withLicense()`, `withCopyright()`.
- `TagFactory` — `ai()`, `user()`, `reference()`.
- `SettingFactory` — `integer()`, `boolean()`.

Web test runner at `/system → Tests` (admin only).

## Key Workflows

- **Upload** — `POST /assets` (or `/api/chunked-upload/*` for ≥10MB) → etag dedup check (rejects with link to existing) → S3 stream upload (optional `keepOriginalFilename`) → dimensions → thumbnail (skips GIFs) → S/M/L resizes → Asset row → `GenerateAiTags` job (if Rekognition enabled) → `applyUploadMetadata()` (`metadata_tags[]`, `metadata_license_type`, `metadata_copyright`, `metadata_copyright_source`). TikZ tool uploads can pass `parent_asset_id` so the new asset's `parent_id` links back to the source `.tex` (surfaced as a Relations card on Asset Show). API upload toggleable runtime via `api_upload_enabled` (web chunked uploads unaffected).
- **Discovery** (admin) — `S3Service` lists unmapped objects → admin imports → Asset rows + thumbnails + resizes + AI tags. Soft-deleted assets shown with "Deleted" badge to prevent re-import.
- **Import metadata** (admin) — paste/upload CSV → preview diffs → import. Match by `s3_key` then `filename`. Updates alt_text/caption/license/copyright. Tags lowercased, `syncWithoutDetaching` (never removed). Empty fields skipped. Invalid license/dates rejected.
- **Trash** (editor + admin) — soft delete keeps S3. Restore returns to active. Force delete (admin only) clears S3 (original + thumbnail + resizes) + DB.
- **Bulk move** (admin + `maintenance_mode`) — pick destination → S3 copy+delete for original + thumbnail + resize variants → DB keys updated. Destination must be within configured S3 folders.
- **Bulk permanent delete** (admin + `maintenance_mode`) — confirm → S3 + DB cleared.
- **Bulk trash** (editor + admin) — soft-delete; S3 preserved.
- **Bulk download** (all auth) — fetch from S3 → ZIP → stream. Limits: 100 files / 500MB. Duplicate filenames disambiguated `_1`, `_2`. Failed S3 fetches skipped.
- **S3 integrity** (admin) — `assets:verify-integrity` dispatches `VerifyAssetIntegrity` jobs → each calls `getObjectMetadata()` → sets `s3_missing_at` if missing, clears if found. System page card with AJAX refresh. Index supports `?missing=1`.

## Integration & Deployment

- **CSV export** — all asset fields, user info, separated user/AI/reference tag columns, URLs. Filterable by type and tags.
- **RTE integration** — see `RTE_INTEGRATION.md`. Public metadata API: `GET /api/assets/meta?url={url}` (no auth).
- **Deployment** — see `DEPLOYMENT.md`. Production queue: supervisor (`deploy/supervisor/orca-queue-worker.conf`). Do not run `queue:work` from the web UI.
