# CLAUDE.md

## Project Overview

ORCA DAM (ORCA Retrieves Cloud Assets) is a Digital Asset Management system built with Laravel 12 with AWS S3 integration, AI-powered tagging via AWS Rekognition, role-based access control, and a RESTful API for Rich Text Editor integration.

**Frontend Stack**: Blade + Alpine.js, Tailwind CSS, Font Awesome 6.4.0, Vite, Intervention Image 3.x (GD driver)

## Common Commands

```bash
# Development
php artisan serve                    # Or use Laravel Herd
npm run dev / npm run build

# Testing (always clear config first)
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

# API Tokens
php artisan token:list [--user=email] [--role=api]
php artisan token:create [user@email.com] [--new] [--name="My App"]
php artisan token:revoke <id|--user=email> [--force]

# JWT Secrets
php artisan jwt:list
php artisan jwt:generate user@email.com [--force]
php artisan jwt:revoke user@email.com [--force]

# Queue (dev)
php artisan queue:work --tries=3
```

## Architecture

### Core Services (`app/Services/`)

**S3Service** - All S3 operations (upload/delete/list). Streams files to avoid memory issues. Generates JPEG thumbnails (skips GIFs). Thumbnails mirror folder structure (`assets/folder/img.jpg` -> `thumbnails/folder/img_thumb.jpg`). Discovery finds unmapped S3 objects. Supports custom domain for CDN URLs via `getPublicBaseUrl()`.

**ChunkedUploadService** - Large file uploads (>=10MB, up to 500MB) via S3 Multipart Upload API. Splits into 10MB chunks, streams directly to S3. Manages sessions via `upload_sessions` table. Idempotent chunk uploads with retry support.

**RekognitionService** - AI tagging via AWS Rekognition. Configurable max labels, min confidence, language. Multilingual via AWS Translate (when language != 'en'). Background processing via `GenerateAiTags` job. Settings read dynamically from database.

### Authentication

- **Sanctum Tokens** - Long-lived tokens for backend integrations
- **JWT** - Short-lived tokens for frontend integrations (disabled by default, enable with `JWT_ENABLED=true`)
  - Guard: `app/Auth/JwtGuard.php`, Config: `config/jwt.php`
  - Middleware: `app/Http/Middleware/AuthenticateMultiple.php`
  - Required claims: `sub` (user ID), `exp`, `iat`. Optional: `iss`
  - Per-user secrets stored encrypted in `users.jwt_secret`

### Authorization (`app/Policies/AssetPolicy.php`)

**Roles** (`users.role`):
- `editor`: View, upload, edit, soft delete all assets
- `admin`: Full access + trash management, discovery, export, user management, system settings
- `api`: API-only (view, create, update; no delete, no admin features)

Admin-only: restore, force delete, discover, export CSV, system page, API docs page

### Locale System

Middleware `SetLocale`: User preference -> Global setting (`settings.locale`) -> `config('app.locale')`. Languages: `en`, `nl`. User preferences in encrypted JSON `users.preferences` column.

### Models

**Asset** (`app/Models/Asset.php`): Belongs to User, many-to-many Tags. Soft deletes. Computed: `url`, `thumbnail_url`, `formatted_size`, `folder`. `filename` is editable display name; `s3_key` is immutable. Scopes: search, filterByTags, type, user, `inFolder`. License fields: `license_type`, `license_expiry_date`, `copyright`, `copyright_source`.

**Tag** (`app/Models/Tag.php`): Type `user` or `ai`, many-to-many Assets.

**Setting** (`app/Models/Setting.php`): Key-value store, cached 1 hour. `Setting::get('key', default)`, `Setting::set('key', value)`. Types: string, integer, boolean, json. Groups: general, display, aws.

## API Endpoints

### REST API (`routes/api.php`, auth: Sanctum/JWT)
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/assets` | List with pagination, search, filters, sorting |
| POST | `/api/assets` | Upload (direct, <10MB) |
| GET | `/api/assets/{id}` | Get single asset |
| PATCH | `/api/assets/{id}` | Update metadata |
| DELETE | `/api/assets/{id}` | Delete asset |
| GET | `/api/assets/search` | Search with filters and sorting |
| GET | `/api/assets/meta` | **Public** (no auth) - metadata by URL |
| GET | `/api/tags` | List tags (optional type filter) |

**Sort values**: `date_desc` (default), `date_asc`, `upload_desc`, `upload_asc`, `size_desc`, `size_asc`, `name_asc`, `name_desc`, `s3key_asc`, `s3key_desc`

### Chunked Upload (`/api/chunked-upload/*`, rate-limited 100/min)
`POST .../init` | `POST .../chunk` | `POST .../complete` | `POST .../abort`

### Web Routes (`routes/web.php`, session auth)
- `POST/DELETE /assets/{asset}/tags[/{tag}]` - Manage tags
- `PATCH/DELETE /assets/{asset}` - Update/delete asset
- `POST /assets/{asset}/ai-tag` - Trigger AI tagging
- `GET /api/folders` | `POST /folders/scan` (admin) | `POST /folders` (admin)
- `/api-docs/*` - Admin: dashboard, settings, tokens, JWT secrets
- `/system` - Admin: overview, settings, queue, logs, commands, diagnostics, tests

## Database Schema

**assets**: `s3_key` (unique), `etag`, `filename`, `mime_type`, `size`, `width`, `height`, `thumbnail_s3_key`, `alt_text`, `caption`, `license_type` (public_domain, cc_by, cc_by_sa, cc_by_nd, cc_by_nc, cc_by_nc_sa, cc_by_nc_nd, fair_use, all_rights_reserved), `license_expiry_date`, `copyright`, `copyright_source`, `user_id`, `deleted_at`

**upload_sessions**: `upload_id`, `session_token`, `filename`, `mime_type`, `file_size`, `s3_key`, `chunk_size`, `total_chunks`, `uploaded_chunks`, `part_etags` (JSON), `status` (pending/uploading/completed/failed/aborted), `user_id`, `last_activity_at`

**tags**: `name` (unique), `type` (user/ai)

**asset_tag**: `asset_id`, `tag_id`, timestamps

**settings**: `key` (unique), `value`, `type` (string/integer/boolean/json), `group` (general/display/aws), `description`

**users** (extra columns): `jwt_secret` (encrypted), `jwt_secret_generated_at`, `two_factor_secret` (encrypted), `two_factor_recovery_codes` (encrypted), `two_factor_confirmed_at`, `preferences` (encrypted JSON: `home_folder`, `items_per_page`, `locale`, `dark_mode`)

**Default Settings**: `items_per_page`=24, `timezone`=UTC, `locale`=en, `s3_root_folder`=assets, `custom_domain`=(empty), `rekognition_max_labels`=3, `rekognition_min_confidence`=80, `rekognition_language`=nl, `s3_folders`=["assets"], `jwt_enabled_override`=true, `api_meta_endpoint_enabled`=true

## Environment Configuration

```env
# Required AWS
AWS_ACCESS_KEY_ID= / AWS_SECRET_ACCESS_KEY= / AWS_DEFAULT_REGION= / AWS_BUCKET= / AWS_URL=

# Optional: AI tagging
AWS_REKOGNITION_ENABLED=false
AWS_REKOGNITION_MAX_LABELS=3          # default: 3
AWS_REKOGNITION_MIN_CONFIDENCE=80     # range: 65-99
AWS_REKOGNITION_LANGUAGE=nl

# Optional: JWT
JWT_ENABLED=false
JWT_ALGORITHM=HS256
JWT_MAX_TTL=36000                     # seconds, default: 10h
JWT_LEEWAY=60
JWT_ISSUER=                           # optional issuer validation

# Optional: PHP CLI path for web test runner
PHP_CLI_PATH=/usr/bin/php
```

**S3 Bucket**: Public read via bucket policy (not ACLs). IAM needs: s3:PutObject/GetObject/DeleteObject/ListBucket. Rekognition: rekognition:DetectLabels/DetectText. Translate: translate:TranslateText (if language != 'en').

**PHP for large files**: Min `memory_limit=256M`. Chunked mode: `upload_max_filesize=15M`, `post_max_size=16M`. Direct mode: `upload_max_filesize=500M`, `post_max_size=512M`. Auto-selects: <10MB direct, >=10MB chunked.

## Key Conventions

**File organization**: Controllers in `app/Http/Controllers/` (API in `Api/`), Services in `app/Services/`, Middleware in `app/Http/Middleware/`, Policies in `app/Policies/`

**Naming**: S3 keys `assets/{folder}/{uuid}.{ext}`, thumbnails `thumbnails/{folder}/{uuid}_thumb.{ext}` (JPEG). RESTful routes, snake_case columns.

**Error handling**: Services catch exceptions and return null/empty arrays. Controllers validate and return appropriate HTTP codes. Logs in `storage/logs/laravel.log`.

**Delete behavior**: Soft delete keeps S3 objects. Hard delete (admin) removes S3 objects + DB record. Discovery marks soft-deleted assets to prevent re-import.

**Memory**: Streams uploads to S3. Uses `getimagesize()` for GIFs. Skips GIF thumbnails. Min 256MB PHP memory.

## Testing

**Pest PHP** with in-memory SQLite. ~253 tests. Config in `phpunit.xml` (testing env, sqlite :memory:, array session/cache, sync queue).

**Factories** (`database/factories/`): AssetFactory (`image()`, `pdf()`, `withLicense()`, `withCopyright()`), TagFactory (`ai()`, `user()`), SettingFactory (`integer()`, `boolean()`)

```
tests/Feature/  - AssetTest, TagTest, ExportTest, ApiTest, SystemTest, JwtAuthTest,
                  JwtSecretManagementTest, LocaleTest, ProfileTest, TwoFactorAuthTest, Auth/
tests/Unit/     - AssetTest, TagTest, SettingTest, UserPreferencesTest, TwoFactorServiceTest, JwtGuardTest
```

Web-based test runner at `/system` -> Tests tab (admin only).

## Key Workflows

**Upload**: Client uploads to `POST /assets` (or chunked via `/api/chunked-upload/*` for >=10MB) -> S3Service streams to S3 -> dimensions extracted -> thumbnail generated (not GIFs) -> Asset record created -> GenerateAiTags job dispatched if Rekognition enabled.

**Discovery** (admin): S3Service finds unmapped objects -> admin selects to import -> Asset records created -> thumbnails + AI tags applied. Soft-deleted assets shown with "Deleted" badge to prevent re-import.

**Trash** (admin): Soft delete keeps S3 files. Restore returns to active. Force delete removes S3 + DB permanently.

## Integration & Deployment

**CSV Export**: All asset fields, user info, separate user/AI tag columns, URLs. Filterable by type and tags.

**RTE Integration**: See `RTE_INTEGRATION.md`. Public metadata API: `GET /api/assets/meta?url={url}` (no auth).

**Deployment**: See `DEPLOYMENT.md`. Production queue: use supervisor (`deploy/supervisor/orca-queue-worker.conf`). Do not run `queue:work` from web UI.
