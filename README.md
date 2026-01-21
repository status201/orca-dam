# ORCA DAM - ORCA Retrieves Cloud Assets

A Digital Asset Management system for AWS S3 with AI-powered tagging.

## Features

- 🔐 Multi-user support (Editors & Admins)
- 📁 Direct S3 bucket integration
- 🏷️ Manual and AI-powered tagging (AWS Rekognition)
- 🌍 Multilingual AI tags via AWS Translate (en, nl, fr, de, es, etc.)
- 🎯 Manual AI tag generation with configurable limits
- ⚙️ Admin Settings panel (pagination, AI tag limits, language)
- 🔍 Advanced search and filtering
- 🖼️ Thumbnail generation and grid view
- 📤 Multi-file upload with drag & drop
- 🚀 **Chunked upload for large files (up to 500MB)**
- ⚡ Automatic upload method selection (direct <10MB, chunked ≥10MB)
- 🔄 Smart retry logic with exponential backoff
- 📝 License type and copyright metadata
- ♿ Accessibility support (alt text, captions)
- 📊 CSV export with separate user/AI tag columns
- 🔗 Easy URL copying for external integration
- 🌐 Public metadata API endpoint (no auth required)
- 🔎 Discover unmapped S3 objects
- 🗑️ Trash & restore system with soft delete (keeps S3 objects)
- ♻️ Permanent delete option for admins
- 📱 Responsive design
- 🌐 API-ready for Rich Text Editor integration

## Installation

### Prerequisites
- PHP 8.2+ with minimum 256MB memory limit
- Composer
- MySQL/PostgreSQL
- Node.js & NPM
- AWS Account with S3 bucket
- GD or Imagick extension for image processing

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

4. Configure AWS credentials in `.env`:
```env
AWS_ACCESS_KEY_ID=your_key
AWS_SECRET_ACCESS_KEY=your_secret
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=your-bucket-name
AWS_URL=https://your-bucket.s3.amazonaws.com

# Optional: Enable AI tagging
AWS_REKOGNITION_ENABLED=false            # Enable/disable AI tagging
AWS_REKOGNITION_MAX_LABELS=5             # Max AI tags per asset
AWS_REKOGNITION_LANGUAGE=en              # Language: en, nl, fr, de, es, etc.
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
- Add and remove tags
- Search and browse all assets
- Copy URLs
- Soft delete assets (moves to trash)

**Admins:**
- All editor permissions
- Access trash and restore deleted assets
- Permanently delete assets (removes S3 objects)
- User management
- Discover unmapped S3 objects
- Export to CSV
- Batch operations
- System administration (queue management, logs, diagnostics)
- **Settings panel** - Configure items per page, AI tag limits, and language

### Discovering Unmapped Objects

1. Navigate to Admin > Discover
2. Click "Scan Bucket"
3. Review unmapped objects (soft-deleted assets marked with "Deleted" badge)
4. Select objects to import
5. AI tags will be automatically generated

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
```

**Chunked Upload Endpoints** (for large files ≥10MB):
```
POST   /api/chunked-upload/init     - Initialize upload session
POST   /api/chunked-upload/chunk    - Upload chunk (rate-limited: 100/min)
POST   /api/chunked-upload/complete - Complete upload and create asset
POST   /api/chunked-upload/abort    - Cancel and cleanup failed upload
```

Authentication: Laravel Sanctum (SPA token) - except `/api/assets/meta` which is public

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
│   ├── Console/Commands/
│   │   └── CleanupStaleUploads.php
│   ├── Http/Controllers/
│   │   ├── AssetController.php
│   │   ├── ChunkedUploadController.php
│   │   ├── TagController.php
│   │   ├── DiscoverController.php
│   │   ├── ExportController.php
│   │   ├── SystemController.php
│   │   └── Api/AssetApiController.php
│   ├── Jobs/
│   │   └── GenerateAiTags.php
│   ├── Services/
│   │   ├── S3Service.php
│   │   ├── ChunkedUploadService.php
│   │   ├── RekognitionService.php
│   │   └── SystemService.php
│   ├── Models/
│   │   ├── Asset.php
│   │   ├── Setting.php
│   │   ├── UploadSession.php
│   │   ├── Tag.php
│   │   └── User.php
│   └── Policies/
│       └── AssetPolicy.php
├── database/migrations/
├── resources/views/
│   ├── layouts/app.blade.php
│   ├── components/
│   ├── assets/
│   ├── export/
│   └── tags/
├── routes/
│   ├── web.php
│   └── api.php
└── bootstrap/
    └── app.php (scheduled tasks)
```

## License

MIT License

## Credits

Built with ❤️ for managing cloud assets efficiently.
