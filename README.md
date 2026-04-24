# ORCA DAM - ORCA Retrieves Cloud Assets

A Digital Asset Management system for AWS S3 with AI-powered tagging.

## Features

- 🔐 Multi-user support (Editors & Admins)
- 📁 Direct S3 bucket integration
- 🏷️ Manual, AI-powered (AWS Rekognition), and reference tagging
- 🌍 Multilingual AI tags via AWS Translate (en, nl, fr, de, es, etc.)
- 🎯 Manual AI tag generation with configurable limits
- ✏️ **Editable filenames** (display name only — S3 key and URLs stay the same)
- 🌐 **Multi-language UI** (English, Dutch) with global and per-user locale
- 🔗 **Custom domain for asset URLs** (e.g., `https://cdn.example.com` instead of S3 bucket URL)
- ⚙️ Admin Settings panel (pagination, AI tag settings, language, custom domain)
- 🔍 Advanced search with operators (`+require`, `-exclude`)
- 🖼️ Thumbnail generation, grid view & list view
- 🏷️ Bulk tag management (add/remove tags on multiple assets, bulk delete tags)
- 📤 Multi-file upload with drag & drop
- 🚀 **Chunked upload for large files (up to 500MB)**
- ⚡ Automatic upload method selection (direct <10MB, chunked ≥10MB)
- 🔄 Smart retry logic with exponential backoff
- 📝 License type and copyright metadata
- ♿ Accessibility support (alt text, captions)
- 📊 CSV export with separate user/AI/reference tag columns
- 📥 Bulk metadata import from CSV (paste or upload)
- 🔗 Easy URL copying for external integration
- 🔎 Discover unmapped S3 objects
- 🛡️ **Duplicate prevention** (etag-based deduplication on upload)
- 📎 **Keep original filename** option during upload
- 🏷️ **Tag attribution** — shows who last assigned a tag (User or AI)
- 🗑️ Trash & restore system with soft delete (keeps S3 objects)
- ♻️ Restore for editors and admins; permanent delete for admins only
- 📦 Bulk move assets between S3 folders (maintenance mode)
- 🗑️ Bulk permanent delete from index page (maintenance mode)
- 🗑️ Bulk move to trash from index page (editors + admins)
- 📥 Bulk download as ZIP (max 100 files / 500MB)
- ✔️ S3 integrity verification (detect missing assets in cloud storage)
- 📱 Responsive design
- 🖼️ **Embeddable asset browser** (`/assets/embed`) for iframe integration, with configurable allowed domains
- 🌐 OpenAPI 3 for Rich Text Editor or System integration
- 🔓 Public metadata API endpoint (no auth required)
- 🔒 Long-lived token support (Laravel Sanctum Token) for back-ends
- 🔑 Short-lived token support (JWT bearer) for front-ends
- 👤 User preferences (home folder, items per page, language, dark/light mode)
- 🔒 Two-factor authentication (TOTP)
- 🖊️ **TikZ Server Render** — compile TikZ/LaTeX diagrams server-side via TeX Live, with SVG and PNG output variants, 17 font packages, template management, and direct upload to ORCA (renders are linked back to their source `.tex` template via asset parent/child relations)
- ☁️ **Cloudflare cache purge** — automatically purges CDN cache when an asset file is replaced (requires custom domain + toggle in Settings)

## Installation

### Prerequisites
- PHP 8.4+ with minimum 256MB memory limit
- Composer
- MySQL/PostgreSQL
- Node.js & NPM
- AWS Account with S3 bucket
- GD or Imagick extension for image processing
- Supervisor on your production server

### Setup Steps

1. Clone this repository
```bash
git clone <your-repo>
cd orca-dam
```

2. Install dependencies
```bash
composer install
npm install
```

3. Configure environment
```bash
cp .env.example .env
php artisan key:generate
```

4. Configure AWS credentials, optional Rekognition and JWT auth in `.env`:
```env
AWS_ACCESS_KEY_ID=your_key
AWS_SECRET_ACCESS_KEY=your_secret
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=your-bucket-name
AWS_URL=https://your-bucket.s3.amazonaws.com

# Optional: Enable AI tagging
AWS_REKOGNITION_ENABLED=false            # Enable/disable AI tagging
AWS_REKOGNITION_MAX_LABELS=3             # Max AI tags per asset (default: 3)
AWS_REKOGNITION_MIN_CONFIDENCE=80        # Min confidence threshold, 65-99 (default: 80)
AWS_REKOGNITION_LANGUAGE=en              # Language: en, nl, fr, de, es, etc.

# Optional: TikZ Server Render (requires TeX Live on server)
TIKZ_LATEX_PATH=latex
TIKZ_DVISVGM_PATH=dvisvgm
TIKZ_TIMEOUT=30
TIKZ_PNG_DPI=300

# Optional: Cloudflare cache purging on asset replacement
# Also enable via System → Settings → S3 Storage (requires custom domain)
CLOUDFLARE_ENABLED=false
CLOUDFLARE_API_TOKEN=
CLOUDFLARE_ZONE_ID=

# Optional: _Also_ enable JWT auth
JWT_ENABLED=true
# Signature algorithm (HS256 recommended)
JWT_ALGORITHM=HS256
# Maximum token lifetime in seconds (default: 10 hours)
JWT_MAX_TTL=36000
# Clock skew tolerance in seconds
JWT_LEEWAY=60
# Optional: Required issuer claim value
JWT_ISSUER=
```

5. Run migrations
```bash
php artisan migrate
```

6. Create admin user
```bash
php artisan db:seed --class=AdminUserSeeder
```

7. Configure PHP limits

ORCA DAM supports **chunked uploads** which allow uploading files up to 500MB even with limited `post_max_size` settings (as low as 16MB). The application automatically routes large files (≥10MB) through the chunked upload API.

**Option A: Chunked Upload Mode (recommended for limited servers)**
Perfect for servers with `post_max_size` limitations:
```ini
memory_limit = 256M          # For image processing
upload_max_filesize = 15M    # Per-chunk limit
post_max_size = 16M          # Minimum for chunk handling
max_execution_time = 300
```

**Option B: Direct Upload Mode (for unrestricted servers)**
Higher limits allow direct uploads for better performance:
```ini
memory_limit = 256M
upload_max_filesize = 500M   # Maximum file size
post_max_size = 512M         # Slightly larger than upload_max_filesize
max_execution_time = 300
```

**For Laravel Herd users:**
Edit Herd's `php.ini` file:
- **macOS/Linux**: `~/.config/herd/bin/php84/php.ini`
- **Windows**: `C:\Users\<username>\.config\herd\bin\php84\php.ini`
- **To find yours**: Run `php --ini` and check "Loaded Configuration File"

Then restart Herd from the system tray.

**For Apache/Nginx users:**
Create `public/.user.ini` with the settings above, then restart your web server.

8. Compile assets
```bash
npm run dev
```

9. Start development server
```bash
php artisan serve  # Or use Herd
```

## Usage

### User Roles

**Editors:**
- Upload and manage all assets
- Edit filenames and metadata (alt text, caption, license, copyright)
- Add and remove tags
- Search and browse all assets
- Copy URLs
- Soft delete assets (moves to trash)
- Access trash and restore deleted assets
- Set personal preferences (home folder, items per page, language)

**Admins:**
- All editor permissions
- Permanently delete assets (removes S3 objects)
- User management
- Discover unmapped S3 objects
- Export to CSV
- Import metadata from CSV
- Batch operations
- Bulk move assets between folders (requires maintenance mode)
- Bulk permanent delete (requires maintenance mode)
- Bulk move to trash
- Bulk download as ZIP
- System administration (queue management, logs, diagnostics)
- **Settings panel** - Configure items per page, AI tag limits, language, timezone, custom domain
- **API Docs & Management** - API token management, JWT secret management, interactive API docs

### Discovering Unmapped Objects

1. Navigate to Admin > Discover
2. Click "Scan Bucket"
3. Review unmapped objects (soft-deleted assets marked with "Deleted" badge)
4. Select objects to import
5. AI tags will be automatically generated

### Importing Metadata (Admin Only)

1. Navigate to Admin dropdown > Import
2. Select match field (`s3_key` or `filename`)
3. Paste CSV data or upload/drop a `.csv` file
4. Click "Preview Import" to review matched assets and changes
5. Click "Import" to apply updates

### Trash & Restore

**Soft Delete:**
- Deleting an asset moves it to trash
- S3 objects (file + thumbnail) are kept
- Asset hidden from normal views

**Restore** (editors and admins):
1. Navigate to Trash
2. Click restore button (green undo icon)
3. Asset returns to active state

**Permanent Delete** (admin only):
1. Navigate to Trash
2. Click permanent delete button (red trash icon)
3. Confirm deletion
4. Removes S3 objects AND database record (cannot be undone)

**Bulk Move to Trash:**
1. Select assets on the index page and click "Move to Trash"
2. Confirm — assets are soft-deleted (S3 objects preserved, can be restored)

**Bulk Download:**
1. Select assets on the index page and click "Download"
2. A ZIP file is generated and downloaded (max 100 files / 500MB)

**Bulk Permanent Delete:**
1. Enable maintenance mode in System → Settings
2. Select assets on the index page and click the red bulk delete button
3. Confirm — removes S3 objects AND database records permanently

### API Endpoints

For RTE integration:

```
GET    /api/assets              - List assets (paginated)
POST   /api/assets              - Upload assets (direct, files <10MB)
GET    /api/assets/{id}         - Get asset details
PATCH  /api/assets/{id}         - Update asset metadata (alt_text, caption, license, copyright, tags)
DELETE /api/assets/{id}         - Delete asset
GET    /api/assets/search       - Search with filters
GET    /api/assets/meta         - Get metadata by URL (PUBLIC, no auth)
GET    /api/health              - Health check (PUBLIC, no auth, 200/503)
GET    /api/tags                - List tags for autocomplete
GET    /api/folders             - List available S3 folders
POST   /api/reference-tags      - Add reference tags to asset(s) (batch: asset_ids/s3_keys)
DELETE /api/reference-tags      - Remove reference tag(s) by name (tag_name/tag_names + asset identifiers)
DELETE /api/reference-tags/{tag} - Remove reference tag by ID from asset(s) (batch: asset_ids/s3_keys)
```

**Chunked Upload Endpoints** (for large files ≥10MB):
```
POST   /api/chunked-upload/init     - Initialize upload session
POST   /api/chunked-upload/chunk    - Upload chunk (rate-limited: 100/min)
POST   /api/chunked-upload/complete - Complete upload and create asset
POST   /api/chunked-upload/abort    - Cancel and cleanup failed upload
```

Authentication: Laravel Sanctum (SPA token) or JWT bearer token - except `/api/assets/meta` and `/api/health` which are public

> **Note:** Upload endpoints (`POST /api/assets` and chunked upload) can be disabled at runtime via **API Docs → Dashboard → Upload Endpoints** toggle. Returns 403 when disabled.

## Testing

ORCA DAM includes a comprehensive test suite built with Pest PHP.

### Running Tests

```bash
# Run all tests
php artisan test

# Run specific suite
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature

# Run with filter
php artisan test --filter="asset"
```

### Test Coverage

- **Unit Tests:** Model relationships, scopes, attributes, type casting
- **Feature Tests:** Asset CRUD, tag management, export, API endpoints, authorization

### Web-Based Test Runner

Admins can run tests from the browser via **System → Tests** tab:
- Select test suite and filter by name
- Real-time progress and statistics
- Color-coded output with failed tests highlighted
- Results grouped by test suite

**Note for shared hosting:** If you get "php not found" errors, add to `.env`:
```env
PHP_CLI_PATH=/opt/plesk/php/8.4/bin/php  # Adjust path for your server
```
Find your path via SSH: `which php`

## Architecture

- **Backend:** Laravel 13 with AWS SDK v3
- **Frontend:** Blade templates + Alpine.js (modular, 15 components in `resources/js/alpine/`)
- **Styling:** Tailwind CSS with custom ORCA theme
- **Image Processing:** Intervention Image 3.x (GD driver)
- **AI Tagging:** AWS Rekognition (with job queue for background processing)
- **Translation:** AWS Translate (for multilingual AI tags)
- **Storage:** AWS S3 (public-read bucket via bucket policy)
- **Queue:** Database driver for background jobs (AI tagging, integrity checks, image resizing)

## File Structure

```
orca-dam/
├── app/
│   ├── Auth/
│   │   └── JwtGuard.php               # JWT authentication guard
│   ├── Console/Commands/
│   │   ├── BackfillEtags.php          # Backfill etags from S3 for dedup
│   │   ├── CleanupStaleUploads.php    # Cleanup stale chunked uploads
│   │   ├── DeduplicateAssets.php      # Find & soft-delete duplicate assets
│   │   ├── JwtGenerateCommand.php     # Generate JWT secret for user
│   │   ├── JwtListCommand.php         # List users with JWT secrets
│   │   ├── JwtRevokeCommand.php       # Revoke JWT secret
│   │   ├── TokenCreateCommand.php     # Create Sanctum API token
│   │   ├── TokenListCommand.php       # List API tokens
│   │   ├── TokenRevokeCommand.php     # Revoke API token
│   │   ├── TwoFactorDisableCommand.php# Disable 2FA for a user
│   │   ├── TwoFactorStatusCommand.php # Check 2FA status
│   │   └── VerifyAssetIntegrity.php   # S3 integrity verification command
│   ├── Http/Controllers/
│   │   ├── Api/
│   │   │   ├── AssetApiController.php # REST API for assets
│   │   │   └── HealthController.php   # Health check endpoint
│   │   ├── Auth/                      # Laravel Breeze auth controllers
│   │   │   └── TwoFactorAuthController.php # 2FA setup & verification
│   │   ├── ApiDocsController.php      # OpenAPI docs page
│   │   ├── AssetController.php        # Asset CRUD & management
│   │   ├── ChunkedUploadController.php# Large file uploads
│   │   ├── DashboardController.php    # Dashboard stats
│   │   ├── DiscoverController.php     # S3 discovery (admin)
│   │   ├── ExportController.php       # CSV export (admin)
│   │   ├── ImportController.php       # CSV metadata import (admin)
│   │   ├── FolderController.php       # Folder list, scan & create
│   │   ├── ToolsController.php        # Tools (TikZ Server, LaTeX→MathML)
│   │   ├── JwtSecretController.php    # JWT secret management (admin)
│   │   ├── ProfileController.php      # User profile & preferences
│   │   ├── SystemController.php       # System admin (admin)
│   │   ├── TagController.php          # Tag management
│   │   ├── TokenController.php        # API token management (admin)
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
│   │   ├── Asset.php
│   │   ├── Setting.php
│   │   ├── Tag.php
│   │   ├── UploadSession.php
│   │   └── User.php
│   ├── Policies/
│   │   ├── AssetPolicy.php            # Asset authorization
│   │   ├── SystemPolicy.php           # System admin authorization
│   │   └── UserPolicy.php             # User management authorization
│   └── Services/
│       ├── AssetProcessingService.php # Shared asset processing logic
│       ├── ChunkedUploadService.php   # S3 multipart uploads
│       ├── CsvExportService.php       # CSV export formatting
│       ├── CsvImportService.php       # CSV parsing and import logic
│       ├── ImageProcessingService.php # Image manipulation (thumbnails, resize)
│       ├── QueueService.php           # Queue dashboard data
│       ├── RekognitionService.php     # AWS Rekognition AI tagging
│       ├── S3Service.php              # S3 operations, thumbnails & URLs
│       ├── SystemService.php          # System admin utilities
│       ├── TestRunnerService.php      # Web-based test runner
│       ├── TikzCompilerService.php    # Server-side TikZ/LaTeX compilation
│       └── TwoFactorService.php       # 2FA TOTP management
├── config/
│   ├── jwt.php                        # JWT authentication config
│   ├── tikz.php                       # TikZ Server compiler config
│   └── two-factor.php                 # 2FA configuration
├── database/
│   ├── factories/                     # Test factories
│   └── migrations/                    # 33 migrations
├── resources/
│   ├── js/
│   │   ├── app.js                     # App init & Alpine registration
│   │   ├── bootstrap.js               # Bootstrap script
│   │   └── alpine/                    # Alpine.js modules (15 components)
│   │       ├── api-docs.js, asset-detail.js, asset-editor.js, asset-grid.js
│   │       ├── asset-uploader.js, asset-replacer.js, dashboard.js, discover.js
│   │       ├── export.js, import.js, preferences.js, system-admin.js
│   │       └── tags.js, tools-tikz-server.js, trash.js
│   ├── css/app.css
│   └── views/
│       ├── api/                       # OpenAPI documentation view
│       ├── assets/                    # Asset views (index, show, edit, create, replace, trash)
│       ├── auth/                      # Authentication & 2FA views
│       ├── components/                # Blade components
│       ├── discover/, export/, import/, tags/, tools/, users/
│       ├── errors/                    # 404, 419, 500, 503 error pages
│       ├── layouts/                   # App & guest layouts
│       ├── profile/                   # Profile management & preferences
│       ├── system/                    # System admin view
│       ├── vendor/pagination/         # Custom pagination templates
│       └── dashboard.blade.php
├── routes/
│   ├── api.php                        # API routes
│   ├── web.php                        # Web routes
│   ├── auth.php                       # Authentication routes
│   └── console.php                    # Artisan command routes
├── tests/
│   ├── Feature/                       # 17 feature test files
│   │   └── Auth/                      # 6 authentication test files
│   └── Unit/                          # 14 unit test files
└── bootstrap/
    └── app.php                        # Scheduled tasks config
```

## License

MIT License

## Credits

Copyright © 2026 Gijs Oliemans & Studyflow.
Built together with 🤖 Claude Opus 4.5, as part of an AI pilot for Studyflow.
