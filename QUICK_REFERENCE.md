# ORCA DAM - Quick Reference

## Common Commands

### Setup
```bash
# Initial setup
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed --class=AdminUserSeeder

# Configure PHP for large file uploads
# For Herd: Edit ~/.config/herd/bin/php84/php.ini (see docs)
# For Apache/Nginx: Create public/.user.ini:
echo "memory_limit = 256M
upload_max_filesize = 100M
post_max_size = 100M
max_execution_time = 300" > public/.user.ini

# Restart web server
# For Herd: Restart from system tray
# For Apache: sudo service apache2 restart
# For Nginx: sudo service nginx restart

# Start development
php artisan serve  # Or use Herd
npm run dev
```

### Daily Development
```bash
# Clear caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Run migrations
php artisan migrate
php artisan migrate:rollback
php artisan migrate:fresh --seed

# View logs
tail -f storage/logs/laravel.log
```

### Testing
```bash
# Run all tests
php artisan test

# Run specific suite
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature

# Run tests matching pattern
php artisan test --filter="asset"

# Using Pest directly
./vendor/bin/pest
./vendor/bin/pest --filter="can update"
```

**Web-based test runner:** Admin → System → Tests tab

---

## File Locations

```
orca-dam/
├── app/
│   ├── Auth/
│   │   └── JwtGuard.php              # JWT authentication guard
│   ├── Console/Commands/
│   │   ├── BackfillEtags.php         # Backfill etags from S3 for dedup
│   │   ├── CleanupStaleUploads.php   # Cleanup stale chunked uploads
│   │   ├── DeduplicateAssets.php     # Find & soft-delete duplicate assets
│   │   ├── JwtGenerateCommand.php    # Generate JWT secret
│   │   ├── JwtListCommand.php        # List JWT secrets
│   │   ├── JwtRevokeCommand.php      # Revoke JWT secret
│   │   ├── TokenCreateCommand.php    # Create Sanctum API token
│   │   ├── TokenListCommand.php      # List API tokens
│   │   ├── TokenRevokeCommand.php    # Revoke API token
│   │   ├── TwoFactorDisableCommand.php # Disable 2FA
│   │   ├── TwoFactorStatusCommand.php  # Check 2FA status
│   │   └── VerifyAssetIntegrity.php  # S3 integrity verification
│   ├── Http/Controllers/
│   │   ├── Api/
│   │   │   ├── AssetApiController.php # REST API endpoints
│   │   │   └── HealthController.php   # Health check endpoint
│   │   ├── Auth/                      # Laravel Breeze + 2FA controllers
│   │   ├── ApiDocsController.php      # OpenAPI docs page
│   │   ├── AssetController.php        # Asset CRUD & management
│   │   ├── ChunkedUploadController.php# Large file uploads
│   │   ├── DashboardController.php    # Dashboard stats
│   │   ├── DiscoverController.php     # S3 discovery (admin)
│   │   ├── ExportController.php       # CSV export (admin)
│   │   ├── ImportController.php       # CSV metadata import (admin)
│   │   ├── FolderController.php       # Folder list, scan & create
│   │   ├── ToolsController.php        # Tools (TikZ Server, LaTeX→MathML)
│   │   ├── JwtSecretController.php    # JWT secret management
│   │   ├── ProfileController.php      # User profile & preferences
│   │   ├── SystemController.php       # System admin (admin)
│   │   ├── TagController.php          # Tag management
│   │   ├── TokenController.php        # API token management
│   │   └── UserController.php         # User management (admin)
│   ├── Http/Middleware/
│   │   ├── AllowEmbedding.php        # CSP frame-ancestors for iframe embedding
│   │   ├── AuthenticateMultiple.php   # Sanctum + JWT dual auth
│   │   └── SetLocale.php              # Locale resolution middleware
│   ├── Jobs/
│   │   ├── GenerateAiTags.php         # AI tagging background job
│   │   ├── ProcessDiscoveredAsset.php # Discovery import job
│   │   ├── RegenerateResizedImage.php # Bulk image resize regeneration
│   │   └── VerifyAssetIntegrity.php   # S3 object existence check job
│   ├── Models/
│   │   ├── Asset.php                  # Asset model
│   │   ├── Setting.php                # Application settings
│   │   ├── Tag.php                    # Tag model
│   │   ├── UploadSession.php          # Chunked upload tracking
│   │   └── User.php                   # User model
│   ├── Policies/
│   │   ├── AssetPolicy.php            # Asset authorization
│   │   ├── SystemPolicy.php           # System admin authorization
│   │   └── UserPolicy.php             # User management authorization
│   └── Services/
│       ├── AssetProcessingService.php # Shared asset processing logic
│       ├── ChunkedUploadService.php   # S3 multipart uploads
│       ├── RekognitionService.php     # AWS Rekognition AI tagging
│       ├── S3Service.php              # S3 operations, thumbnails & URLs
│       ├── SystemService.php          # System admin utilities
│       ├── TwoFactorService.php       # 2FA TOTP management
│       ├── CsvExportService.php       # CSV export row generation
│       ├── CsvImportService.php       # CSV parsing, diffing & validation
│       ├── ImageProcessingService.php # Thumbnails, resizing, dimensions
│       ├── QueueService.php           # Queue stats & job listings
│       ├── TestRunnerService.php      # Web test runner subprocess
│       └── TikzCompilerService.php    # Server-side TikZ/LaTeX compilation
├── config/
│   ├── jwt.php                        # JWT authentication config
│   ├── tikz.php                       # TikZ Server compiler config
│   └── two-factor.php                 # 2FA configuration
├── database/migrations/               # 33 migrations
├── resources/
│   ├── js/
│   │   ├── app.js                     # App init & Alpine registration
│   │   └── alpine/                    # Alpine.js modules (15 components)
│   │       ├── api-docs.js, asset-detail.js, asset-editor.js, asset-grid.js
│   │       ├── asset-uploader.js, asset-replacer.js, dashboard.js, discover.js
│   │       ├── export.js, import.js, preferences.js, system-admin.js
│   │       └── tags.js, tools-tikz-server.js, trash.js
│   └── views/
│       ├── api/                       # OpenAPI documentation view
│       ├── assets/                    # Asset views (index, show, edit, create, replace, trash, embed)
│       ├── auth/                      # Authentication & 2FA views
│       ├── components/                # Blade components
│       ├── discover/, export/, import/, tags/, tools/, users/
│       ├── errors/                    # 404, 419, 500, 503 error pages
│       ├── layouts/                   # App, guest & embed layouts
│       ├── profile/                   # Profile & preferences
│       ├── system/                    # System admin view
│       └── vendor/pagination/         # Custom pagination templates
├── routes/
│   ├── web.php                        # Web routes
│   ├── api.php                        # API routes
│   ├── auth.php                       # Authentication routes
│   └── console.php                    # Artisan command routes
├── tests/
│   ├── Feature/
│   │   ├── ApiTest.php                # API endpoints, sorting, meta
│   │   ├── AssetTest.php              # Asset CRUD, sorting, permissions
│   │   ├── EmbedTest.php             # Embeddable asset browser
│   │   ├── BulkForceDeleteTest.php    # Bulk permanent delete
│   │   ├── BulkMoveTest.php           # Bulk asset move
│   │   ├── BulkTrashTest.php          # Bulk soft delete & restore
│   │   ├── BulkDownloadTest.php      # Bulk ZIP download
│   │   ├── DuplicatePreventionTest.php# Duplicate asset detection
│   │   ├── ExportTest.php             # CSV export
│   │   ├── ImportTest.php             # CSV metadata import
│   │   ├── IntegrityTest.php          # S3 integrity verification
│   │   ├── JwtAuthTest.php            # JWT authentication
│   │   ├── JwtSecretManagementTest.php# JWT secret management
│   │   ├── LocaleTest.php             # Language/locale
│   │   ├── ProfileTest.php            # User profile & preferences
│   │   ├── SystemTest.php             # System settings
│   │   ├── TagTest.php                # Tag management
│   │   ├── TagAttributionTest.php     # Tag attribution (User/AI)
│   │   ├── TwoFactorAuthTest.php      # 2FA functionality
│   │   └── Auth/                      # Authentication tests (6 files)
│   └── Unit/
│       ├── AssetTest.php                  # Model relationships, scopes
│       ├── AssetProcessingServiceTest.php # Asset processing logic
│       ├── AssetSortScopeTest.php         # Asset sorting scopes
│       ├── JwtGuardTest.php               # JWT guard
│       ├── S3ServiceTest.php              # S3 service tests
│       ├── SettingTest.php                # Setting model, caching
│       ├── TagTest.php                    # Tag model
│       ├── TwoFactorServiceTest.php       # 2FA service
│       ├── UserPreferencesTest.php        # User preference helpers
│       ├── CsvExportServiceTest.php       # CSV export service
│       ├── CsvImportServiceTest.php       # CSV import service
│       ├── ImageProcessingServiceTest.php # Image processing service
│       ├── QueueServiceTest.php           # Queue service
│       └── TestRunnerServiceTest.php      # Test runner service
└── bootstrap/
    └── app.php                        # Scheduled tasks config
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
GET  /users                    # User management (admin)
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
Authentication: Sanctum token OR JWT bearer token (if JWT_ENABLED=true)
```
GET    /api/assets             # List assets (supports ?sort=)
POST   /api/assets             # Upload assets
GET    /api/assets/search      # Search assets (supports ?sort=)
GET    /api/assets/meta        # Get metadata by URL (PUBLIC, no auth)
GET    /api/health             # Health check (PUBLIC, no auth, 200/503)
GET    /api/assets/{id}        # Get asset
PATCH  /api/assets/{id}        # Update asset
DELETE /api/assets/{id}        # Delete asset
GET    /api/tags               # List tags
GET    /api/folders            # List S3 folders
POST   /api/reference-tags         # Add reference tags to asset(s) (batch: asset_ids/s3_keys)
DELETE /api/reference-tags         # Remove reference tag(s) by name (tag_name/tag_names + asset identifiers)
DELETE /api/reference-tags/{tag}   # Remove reference tag by ID from asset(s) (batch: asset_ids/s3_keys)

# Chunked uploads (for large files ≥10MB)
POST   /api/chunked-upload/init      # Initialize upload
POST   /api/chunked-upload/chunk     # Upload chunk
POST   /api/chunked-upload/complete  # Complete upload
POST   /api/chunked-upload/abort     # Cancel upload
```

**API Sort Options** (`?sort=`):
- `date_desc` (default), `date_asc` - Sort by last modified
- `upload_desc`, `upload_asc` - Sort by upload date
- `size_desc`, `size_asc` - Sort by file size
- `name_asc`, `name_desc` - Sort by filename
- `s3key_asc`, `s3key_desc` - Sort by S3 key

---

## Database Schema

### users
- id, name, email, password, role (editor|admin|api)
- jwt_secret, jwt_secret_generated_at (for JWT auth)
- two_factor_secret, two_factor_recovery_codes, two_factor_confirmed_at (2FA)
- preferences (encrypted JSON: home_folder, items_per_page, locale, dark_mode)

### assets
- id, s3_key, filename, mime_type, size, etag
- width, height, thumbnail_s3_key
- resize_s_s3_key, resize_m_s3_key, resize_l_s3_key
- alt_text, caption, user_id, last_modified_by
- license_type, license_expiry_date, copyright, copyright_source
- s3_missing_at (nullable, set when S3 object detected missing)
- created_at, updated_at, deleted_at

### tags
- id, name, type (user|ai|reference), created_at, updated_at

### asset_tag
- asset_id, tag_id, attached_by (nullable: User/AI), created_at, updated_at

### upload_sessions
- id, upload_id, session_token, filename, mime_type, file_size
- s3_key, chunk_size, total_chunks, uploaded_chunks, part_etags (JSON)
- status (pending|uploading|completed|failed|aborted), user_id, last_activity_at

### settings
- id, key (unique), value, type, group, description
- Default settings: items_per_page, timezone, locale, s3_root_folder, custom_domain,
  embed_allowed_domains, rekognition_max_labels, rekognition_min_confidence,
  rekognition_language, jwt_enabled_override, api_meta_endpoint_enabled, api_upload_enabled

---

## Configuration

### .env Keys
```env
# AWS S3
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=
AWS_URL=https://bucket.s3.amazonaws.com

# AI Tagging (also configurable via System → Settings)
AWS_REKOGNITION_ENABLED=true|false
AWS_REKOGNITION_MAX_LABELS=3
AWS_REKOGNITION_MIN_CONFIDENCE=80
AWS_REKOGNITION_LANGUAGE=nl

# JWT Authentication (optional, for frontend integrations)
JWT_ENABLED=true|false
JWT_ALGORITHM=HS256
JWT_MAX_TTL=36000
JWT_LEEWAY=60
JWT_ISSUER=                    # Optional issuer validation

# Cloudflare cache purging (optional, also needs toggle in Settings)
CLOUDFLARE_ENABLED=true|false
CLOUDFLARE_API_TOKEN=          # Zone.Cache Purge permission
CLOUDFLARE_ZONE_ID=            # From Cloudflare dashboard

# Database
DB_CONNECTION=mysql
DB_DATABASE=orca_dam
DB_USERNAME=root
DB_PASSWORD=

# App
APP_ENV=local|production
APP_DEBUG=true|false

# PHP CLI (for web-based test runner on shared hosting)
PHP_CLI_PATH=/usr/bin/php      # Find via: which php

# TikZ Server Render (optional, requires TeX Live)
TIKZ_LATEX_PATH=latex          # Path to latex binary
TIKZ_DVISVGM_PATH=dvisvgm     # Path to dvisvgm binary
TIKZ_TIMEOUT=30                # Compilation timeout (seconds)
TIKZ_PNG_DPI=300               # Default PNG DPI (72-600)
```

**Runtime settings** (configured via System → Settings, no .env needed):
- `custom_domain` — Custom CDN domain for asset URLs (e.g., `https://cdn.example.com`)
- `s3_root_folder` — S3 prefix for uploads (default: `assets`)
- `timezone` — Application timezone
- `locale` — Global UI language (`en` or `nl`)
- `items_per_page` — Default pagination
- `cloudflare_cache_purge` — Purge Cloudflare CDN cache on asset replacement (requires `CLOUDFLARE_*` env vars + custom domain)
- `embed_allowed_domains` — Domains allowed to embed ORCA in an iframe (sets CSP `frame-ancestors`)

**API settings** (configured via API Docs → Dashboard):
- `jwt_enabled_override` — Enable/disable JWT authentication at runtime
- `api_meta_endpoint_enabled` — Enable/disable the public `/api/assets/meta` endpoint
- `api_upload_enabled` — Enable/disable API upload endpoints (direct and chunked)

---

## User Permissions

### Editor
✅ Upload assets
✅ View all assets
✅ Edit filenames and metadata
✅ Edit/delete any asset
✅ Add tags to any asset
✅ Search and filter
✅ Access Trash & restore deleted assets
✅ Set personal preferences (home folder, items per page, language)

### Admin
✅ All editor permissions
✅ Permanently delete assets
✅ Access Discover feature
✅ Manage users
✅ Export to CSV
✅ Import metadata from CSV
✅ System administration & settings
✅ Manage API tokens & JWT secrets

### API User
✅ View all assets (API only)
✅ Upload assets (API only)
✅ Update asset metadata (API only)
❌ Delete assets
❌ Admin features

---

## User Preferences

Users can set personal preferences via **Profile → Preferences**:

| Preference | Description | Override Hierarchy |
|------------|-------------|-------------------|
| **Home Folder** | Default folder when browsing assets | URL param > User pref > Global root |
| **Items Per Page** | Default pagination (12-96) | URL param > User pref > Global setting |
| **Language** | UI language (English, Dutch) | User pref > Global setting > Config |

```php
// In code, access via User model:
$user->getPreference('home_folder');
$user->getPreference('items_per_page');
$user->getPreference('locale');
$user->getHomeFolder();      // Validated against root
$user->getItemsPerPage();    // Falls back to global
```

---

## Troubleshooting Quick Fixes

### Can't upload files / 413 or Memory errors
1. **Configure PHP limits:**
   - **Herd:** Edit `~/.config/herd/bin/php84/php.ini` (Windows: `C:\Users\<user>\.config\herd\bin\php84\php.ini`)
   - **Apache/Nginx:** Create `public/.user.ini`
   - Set: `memory_limit=256M`, `upload_max_filesize=100M`, `post_max_size=100M`
2. **Restart web server** (Herd from system tray, or service restart)
3. **Verify:** Run `php -i | grep "upload_max_filesize\|post_max_size\|memory_limit"`
4. Check `.env` AWS credentials
5. Verify S3 bucket is public-read
6. Check `storage/logs/laravel.log` for errors

### No thumbnails
1. Install GD: `apt-get install php-gd` or `php -m | grep -i gd`
2. GIF thumbnails are skipped (uses original)
3. Check S3 write permissions
4. Review logs

### AI tags not working
1. Set `AWS_REKOGNITION_ENABLED=true`
2. Check Rekognition IAM permissions
3. Ensure images are in S3 bucket

### Discovery shows nothing
1. Verify S3 bucket name in `.env`
2. Check `s3:ListBucket` permission
3. Ensure files are in `assets/` prefix

### Web-based test runner: "php not found"
1. SSH into server: `which php`
2. Add to `.env`: `PHP_CLI_PATH=/path/from/which/php`
3. Common paths:
   - Plesk: `/opt/plesk/php/8.2/bin/php`
   - cPanel: `/opt/cpanel/ea-php82/root/usr/bin/php`
   - Linux: `/usr/bin/php`
4. Clear config: `php artisan config:clear`

---

## S3 Bucket Structure

```
your-bucket/
│   {uuid}.jpg              # Original files (when settings/s3 root folder is empty)
├── {assets}/
│   ├── {uuid}.jpg          # Original files
│   ├── {uuid}.png
│   └── ...
└── thumbnails/
    ├── {uuid}_thumb.jpg    # Generated thumbnails
    ├── {assets}/
    │   └── {uuid}_thumb.jpg
    ├── S/                   # Small resize preset
    │   └── {assets}/{uuid}.jpg
    ├── M/                   # Medium resize preset
    │   └── {assets}/{uuid}.jpg
    └── L/                   # Large resize preset
        └── {assets}/{uuid}.jpg
```

---

## Useful Artisan Commands

```bash
# Create new admin
php artisan tinker
> User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => Hash::make('password'), 'role' => 'admin']);

# API Token management
php artisan token:list                   # List all tokens
php artisan token:create user@email.com  # Create token for user
php artisan token:revoke 5               # Revoke token ID 5

# JWT Secret management
php artisan jwt:list                     # List users with JWT secrets
php artisan jwt:generate user@email.com  # Generate JWT secret
php artisan jwt:revoke user@email.com    # Revoke JWT secret

# Two-Factor Authentication management
php artisan two-factor:status            # Check 2FA status for all users
php artisan two-factor:disable user@email.com  # Disable 2FA for a user

# Maintenance
php artisan uploads:cleanup              # Clean up stale chunked uploads (>24h)
php artisan assets:verify-integrity      # Queue S3 integrity checks for all assets
php artisan assets:backfill-etags        # Fetch & store etags from S3 for dedup
php artisan assets:deduplicate           # Dry-run: find duplicate assets by etag
php artisan assets:deduplicate --force   # Soft-delete duplicates (keeps oldest)

# Clear all caches
php artisan optimize:clear

# Rebuild production caches
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Check routes
php artisan route:list

# Check policies
php artisan policy:make AssetPolicy
```

---

## Production Checklist

- [ ] Set `APP_ENV=production`
- [ ] Set `APP_DEBUG=false`
- [ ] Configure strong `APP_KEY`
- [ ] Verify PHP limits are configured (256MB memory, 100MB upload)
- [ ] Run `php -i | grep upload_max_filesize` to verify settings
- [ ] Enable HTTPS
- [ ] Set up queue workers
- [ ] Configure cron for scheduler
- [ ] Optimize with caches
- [ ] Restrict IAM permissions
- [ ] Change default admin password
- [ ] Set up backups
- [ ] Configure rate limiting
- [ ] Enable CORS if needed
- [ ] Check all System settings before importing uploading assets
- [ ] Securely share JWT secrets (never expose in frontend code)
- [ ] Use short JWT token lifetimes (1 hour recommended) 

---

## Support Resources

- **Documentation**: See README.md, SETUP_GUIDE.md, USER_MANUAL.md
- **API Integration**: See RTE_INTEGRATION.md
- **Deployment**: See DEPLOYMENT.md
- **Laravel Docs**: https://laravel.com/docs
- **AWS S3 Docs**: https://docs.aws.amazon.com/s3/
- **AWS Rekognition**: https://docs.aws.amazon.com/rekognition/
