# ORCA DAM - ORCA Retrieves Cloud Assets

A Digital Asset Management system for AWS S3 with AI-powered tagging.

## Features

- 🔐 Multi-user support (Editors & Admins)
- 📁 Direct S3 bucket integration
- 🏷️ Manual and AI-powered tagging (AWS Rekognition)
- 🌍 Multilingual AI tags via AWS Translate (en, nl, fr, de, es, etc.)
- 🎯 Manual AI tag generation with configurable limits
- ✏️ **Editable filenames** (display name only — S3 key and URLs stay the same)
- 🌐 **Multi-language UI** (English, Dutch) with global and per-user locale
- 🔗 **Custom domain for asset URLs** (e.g., `https://cdn.example.com` instead of S3 bucket URL)
- ⚙️ Admin Settings panel (pagination, AI tag settings, language, custom domain)
- 🔍 Advanced search and filtering
- 🖼️ Thumbnail generation and grid view
- 📤 Multi-file upload with drag & drop
- 🚀 **Chunked upload for large files (up to 500MB)**
- ⚡ Automatic upload method selection (direct <10MB, chunked ≥10MB)
- 🔄 Smart retry logic with exponential backoff
- 📝 License type and copyright metadata
- ♿ Accessibility support (alt text, captions)
- 📊 CSV export with separate user/AI tag columns
- 📥 Bulk metadata import from CSV (paste or upload)
- 🔗 Easy URL copying for external integration
- 🔎 Discover unmapped S3 objects
- 🗑️ Trash & restore system with soft delete (keeps S3 objects)
- ♻️ Permanent delete option for admins
- 📱 Responsive design
- 🌐 OpenAPI 3 for Rich Text Editor or System integration
- 🔓 Public metadata API endpoint (no auth required)
- 🔒 Long-lived token support (Laravel Sanctum Token) for back-ends
- 🔑 Short-lived token support (JWT bearer) for front-ends
- 👤 User preferences (home folder, items per page, language, dark/light mode)
- 🔒 Two-factor authentication (TOTP)

## Installation

### Prerequisites
- PHP 8.3+ with minimum 256MB memory limit
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
AWS_REKOGNITION_MAX_LABELS=5             # Max AI tags per asset
AWS_REKOGNITION_MIN_CONFIDENCE=75        # Min confidence threshold (65-99)
AWS_REKOGNITION_LANGUAGE=en              # Language: en, nl, fr, de, es, etc.

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
- Set personal preferences (home folder, items per page, language)

**Admins:**
- All editor permissions
- Access trash and restore deleted assets
- Permanently delete assets (removes S3 objects)
- User management
- Discover unmapped S3 objects
- Export to CSV
- Import metadata from CSV
- Batch operations
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

### Trash & Restore (Admin Only)

**Soft Delete:**
- Deleting an asset moves it to trash
- S3 objects (file + thumbnail) are kept
- Asset hidden from normal views

**Restore:**
1. Navigate to Admin > Trash
2. Click restore button (green undo icon)
3. Asset returns to active state

**Permanent Delete:**
1. Navigate to Admin > Trash
2. Click permanent delete button (red trash icon)
3. Confirm deletion
4. Removes S3 objects AND database record (cannot be undone)

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
GET    /api/tags                - List tags for autocomplete
GET    /api/folders             - List available S3 folders
```

**Chunked Upload Endpoints** (for large files ≥10MB):
```
POST   /api/chunked-upload/init     - Initialize upload session
POST   /api/chunked-upload/chunk    - Upload chunk (rate-limited: 100/min)
POST   /api/chunked-upload/complete - Complete upload and create asset
POST   /api/chunked-upload/abort    - Cancel and cleanup failed upload
```

Authentication: Laravel Sanctum (SPA token) or JWT bearer token - except `/api/assets/meta` which is public

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
PHP_CLI_PATH=/opt/plesk/php/8.3/bin/php  # Adjust path for your server
```
Find your path via SSH: `which php`

## Architecture

- **Backend:** Laravel 12 with AWS SDK v3
- **Frontend:** Blade templates + Alpine.js
- **Styling:** Tailwind CSS with custom ORCA theme
- **Image Processing:** Intervention Image 3.x
- **AI Tagging:** AWS Rekognition (with job queue for background processing)
- **Translation:** AWS Translate (for multilingual AI tags)
- **Storage:** AWS S3 (public-read bucket via bucket policy)
- **Queue:** Database driver for background jobs (AI tagging)

## File Structure

```
orca-dam/
├── app/
│   ├── Auth/
│   │   └── JwtGuard.php               # JWT authentication guard
│   ├── Console/Commands/
│   │   ├── CleanupStaleUploads.php    # Cleanup stale chunked uploads
│   │   ├── JwtGenerateCommand.php     # Generate JWT secret for user
│   │   ├── JwtListCommand.php         # List users with JWT secrets
│   │   ├── JwtRevokeCommand.php       # Revoke JWT secret
│   │   ├── TokenCreateCommand.php     # Create Sanctum API token
│   │   ├── TokenListCommand.php       # List API tokens
│   │   ├── TokenRevokeCommand.php     # Revoke API token
│   │   ├── TwoFactorDisableCommand.php# Disable 2FA for a user
│   │   └── TwoFactorStatusCommand.php # Check 2FA status
│   ├── Http/Controllers/
│   │   ├── Api/
│   │   │   └── AssetApiController.php # REST API for assets
│   │   ├── Auth/                      # Laravel Breeze auth controllers
│   │   │   └── TwoFactorAuthController.php # 2FA setup & verification
│   │   ├── ApiDocsController.php      # OpenAPI docs page
│   │   ├── AssetController.php        # Asset CRUD & management
│   │   ├── ChunkedUploadController.php# Large file uploads
│   │   ├── DashboardController.php    # Dashboard stats
│   │   ├── DiscoverController.php     # S3 discovery (admin)
│   │   ├── ExportController.php       # CSV export (admin)
│   │   ├── ImportController.php      # CSV metadata import (admin)
│   │   ├── FolderController.php       # Folder list, scan & create
│   │   ├── JwtSecretController.php    # JWT secret management (admin)
│   │   ├── ProfileController.php      # User profile & preferences
│   │   ├── SystemController.php       # System admin (admin)
│   │   ├── TagController.php          # Tag management
│   │   ├── TokenController.php        # API token management (admin)
│   │   └── UserController.php         # User management (admin)
│   ├── Http/Middleware/
│   │   ├── AuthenticateMultiple.php   # Sanctum + JWT dual auth
│   │   └── SetLocale.php             # Locale resolution middleware
│   ├── Jobs/
│   │   ├── GenerateAiTags.php         # AI tagging background job
│   │   └── ProcessDiscoveredAsset.php # Discovery import job
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
│       ├── ChunkedUploadService.php   # S3 multipart uploads
│       ├── RekognitionService.php     # AWS Rekognition AI tagging
│       ├── S3Service.php              # S3 operations, thumbnails & URLs
│       ├── SystemService.php          # System admin utilities
│       └── TwoFactorService.php       # 2FA TOTP management
├── config/
│   └── jwt.php                        # JWT authentication config
├── database/
│   ├── factories/                     # Test factories
│   └── migrations/
├── resources/views/
│   ├── api/                           # OpenAPI documentation view
│   ├── assets/                        # Asset views (index, show, edit, create, replace, trash)
│   ├── auth/                          # Authentication views
│   ├── components/                    # Blade components
│   ├── discover/                      # S3 discovery view
│   ├── export/                        # Export view
│   ├── import/                        # Metadata import view
│   ├── layouts/                       # App & guest layouts
│   ├── profile/                       # Profile management
│   ├── system/                        # System admin view
│   ├── tags/                          # Tag management view
│   ├── users/                         # User management views
│   ├── vendor/pagination/             # Custom pagination templates
│   ├── dashboard.blade.php
│   └── welcome.blade.php              # Landing page
├── routes/
│   ├── api.php                        # API routes
│   └── web.php                        # Web routes
├── tests/
│   ├── Feature/                       # Feature tests
│   └── Unit/                          # Unit tests
└── bootstrap/
    └── app.php                        # Scheduled tasks config
```

## License

MIT License

## Credits

Copyright © 2026 Gijs Oliemans & Studyflow.
Built together with 🤖 Claude Opus 4.5, as part of an AI pilot for Studyflow.
