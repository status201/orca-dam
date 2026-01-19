# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

ORCA DAM (ORCA Retrieves Cloud Assets) is a Digital Asset Management system built with Laravel 12 that provides direct AWS S3 integration with AI-powered tagging via AWS Rekognition. The application supports multi-user collaboration with role-based access control and provides both a web interface and RESTful API for Rich Text Editor integration.

## Common Commands

### Development
```bash
# Start development server (or use Laravel Herd)
php artisan serve

# Compile frontend assets
npm run dev          # Development with hot reload
npm run build        # Production build

# Database operations
php artisan migrate
php artisan db:seed --class=AdminUserSeeder
```

### Testing
```bash
# Run all tests
php artisan test

# Run specific test suite
php artisan test --testsuite=Feature
php artisan test --testsuite=Unit

# Run specific test file
php artisan test tests/Feature/AssetTest.php

# Alternative: Use PHPUnit directly
vendor/bin/phpunit
vendor/bin/phpunit --filter=test_name
```

### Code Quality
```bash
# Format code (Laravel Pint)
./vendor/bin/pint

# Format specific files
./vendor/bin/pint app/Models
```

### Cache Management
```bash
# Clear caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Production optimization
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Maintenance Commands
```bash
# Cleanup stale chunked upload sessions (runs automatically daily)
php artisan uploads:cleanup              # Default: 24 hours
php artisan uploads:cleanup --hours=48   # Custom threshold
```

## Architecture

### Core Services Pattern

The application uses a service-oriented architecture with three main services that handle external integrations and large file uploads:

**S3Service** (`app/Services/S3Service.php`)
- Manages all S3 operations using AWS SDK v3
- Handles file uploads with streaming to avoid memory issues on large files
- Generates thumbnails using Intervention Image 3.x (skips GIFs to prevent memory exhaustion)
- Provides discovery functionality to find unmapped S3 objects
- Uses bucket policies (not ACLs) for public read access

**ChunkedUploadService** (`app/Services/ChunkedUploadService.php`)
- Handles large file uploads (≥10MB) using AWS S3 Multipart Upload API
- Overcomes PHP `post_max_size` limitations by splitting files into 10MB chunks
- Supports files up to 500MB
- Streams chunks directly to S3 without local disk storage
- Manages upload sessions via database (`upload_sessions` table)
- Provides idempotent chunk uploads (can retry failed chunks)
- Extracts image dimensions after upload completion

**RekognitionService** (`app/Services/RekognitionService.php`)
- Provides AI-powered tagging via AWS Rekognition
- Can be enabled/disabled via `AWS_REKOGNITION_ENABLED` config
- Detects labels with confidence scoring (default 75% minimum)
- Creates/attaches AI tags (type='ai') to assets automatically
- Only processes image assets
- Max labels configurable via database settings or `AWS_REKOGNITION_MAX_LABELS` env (default: 5)
- Language configurable via database settings or `AWS_REKOGNITION_LANGUAGE` env (default: 'en')
- Supports multilingual tags via AWS Translate (when language != 'en')
- Uses Job queue (`GenerateAiTags`) for background processing
- Settings are read dynamically from database, allowing runtime configuration changes

### Authorization System

Uses Laravel Policies for fine-grained access control:

**AssetPolicy** (`app/Policies/AssetPolicy.php`)
- All authenticated users can view and create assets
- All authenticated users can update and delete any asset (soft delete)
- Only admins can restore soft-deleted assets
- Only admins can permanently delete assets (force delete)
- Only admins can access the "Discover" feature (unmapped S3 objects)
- Only admins can export assets to CSV

**User Roles** (stored in `users.role` column):
- `editor`: Can upload and manage all assets (view, edit, soft delete)
- `admin`: Full access including trash management, discovery, export, and user management

### Model Relationships

**Asset Model** (`app/Models/Asset.php`)
- Belongs to User (uploader)
- Many-to-many with Tags (via `asset_tag` pivot)
- Uses soft deletes
- Appends computed attributes: `url`, `thumbnail_url`, `formatted_size`
- Scopes for search, filtering by tags, type, and user
- Helper methods: `isImage()`, `getFileIcon()`, `userTags()`, `aiTags()`
- Includes license and copyright fields: `license_type`, `copyright`

**Tag Model** (`app/Models/Tag.php`)
- Has type field: `user` (manual) or `ai` (auto-generated)
- Many-to-many with Assets
- Both user and AI tags can be deleted from assets and removed completely
- AI tags can be removed from individual assets without deleting the tag entirely

**Setting Model** (`app/Models/Setting.php`)
- Key-value store for application settings
- Settings cached for 1 hour to reduce database queries
- Static helper methods: `Setting::get('key', default)`, `Setting::set('key', value)`
- Supports type casting: string, integer, boolean, json
- Groups settings by category (display, aws)
- Cache automatically cleared when settings are updated

### API Design

**Authentication**: Laravel Sanctum (SPA tokens)

**Endpoints** (`routes/api.php`):
- `GET /api/assets` - List assets with pagination, search, filters
- `POST /api/assets` - Multi-file upload (direct upload for files <10MB)
- `GET /api/assets/{id}` - Get single asset
- `PATCH /api/assets/{id}` - Update metadata (alt_text, caption, tags, license_type, copyright)
- `DELETE /api/assets/{id}` - Delete asset
- `GET /api/assets/search` - Search with query params
- `GET /api/assets/meta` - Get asset metadata by URL (public, no auth required)
- `GET /api/tags` - List tags (with optional type filter)

**Chunked Upload Endpoints** (for large files ≥10MB):
- `POST /api/chunked-upload/init` - Initialize multipart upload session
- `POST /api/chunked-upload/chunk` - Upload single chunk (rate-limited: 100/min)
- `POST /api/chunked-upload/complete` - Complete upload and create Asset
- `POST /api/chunked-upload/abort` - Cancel upload and cleanup

**Web-based Asset Management Endpoints** (`routes/web.php`, used by inline editing):
- `POST /assets/{asset}/tags` - Add tags to asset (accepts `tags` array, creates if needed)
- `DELETE /assets/{asset}/tags/{tag}` - Remove specific tag from asset
- `PATCH /assets/{asset}` - Update asset metadata (license_type, alt_text, caption, copyright)
- `DELETE /assets/{asset}` - Soft delete asset
- `POST /assets/{asset}/ai-tag` - Manually trigger AI tag generation

All API endpoints require `auth:sanctum` middleware except `/api/assets/meta` which is public. Chunked upload endpoints have additional rate limiting (100 requests/minute). Web-based endpoints use session authentication via `auth` middleware.

### Frontend Stack

- **Templates**: Blade with Alpine.js for interactivity
- **Styling**: Tailwind CSS with custom ORCA theme colors
- **Icons**: Font Awesome 6.4.0
- **Build Tool**: Vite
- **Image Processing**: Intervention Image 3.x (uses GD driver)

### User Interface Features

**Dashboard** (`resources/views/dashboard.blade.php`):
- Two-column layout: Statistics (left) and Feature Tour (right)
- Statistics cards in 2-column inner grid showing:
  - Total Assets, My Assets, Total Storage
  - Tags (User + AI counts), Total Users (admin only), Trashed Assets (admin only)
- Role-based feature tour slideshow (admin sees additional slides)
- Auto-playing carousel with manual controls and play/pause
- All statistics update in real-time based on database state

**Assets Index - Grid/List Toggle** (`resources/views/assets/index.blade.php`):
- **View Toggle**: Switch between Grid and List views
  - Toggle buttons positioned after filters, aligned right
  - Selection persists via localStorage (key: `orcaAssetViewMode`)
  - Default view: Grid

- **Grid View** (default):
  - Responsive grid: 2-12 columns based on screen size
  - Card-based layout with thumbnail, filename, size, upload time, user
  - Hover overlay with download, copy URL, and edit actions
  - Shows up to 2 tags per card with overflow count
  - Click card to view asset details

- **List/Table View**:
  - 7 columns: Thumbnail, Filename, Actions, S3 Key, Size, Tags, License
  - Horizontal scroll on all screen sizes (all columns always visible)
  - **Inline Tag Editing**:
    - Display all tags with color coding (blue=user, purple=AI)
    - Remove any tag (user or AI) with × button
    - Add new user tags with + button → inline input → Enter or Add button
    - Changes save immediately via AJAX (POST `/assets/{id}/tags`)
  - **Inline License Editing**:
    - Dropdown select with all license types
    - Changes save immediately on selection via AJAX (PATCH `/assets/{id}`)
    - Reverts to previous value if save fails
  - **Actions Column** (after Filename):
    - View (eye icon) - Links to asset detail page
    - Copy URL (copy icon) - Copies public S3 URL, shows checkmark confirmation
    - Edit (edit icon) - Links to asset edit page
    - Delete (trash icon) - Confirmation dialog → soft delete via AJAX
  - **Loading States**: Disabled buttons and opacity changes during AJAX operations
  - **Error Handling**: Toast notifications for success/failure

- **Common Features** (both views):
  - Search by filename
  - Filter by file type (images, videos, documents)
  - Filter by tags (multi-select with checkboxes)
  - Sort options (date, size, name - ascending/descending)
  - Pagination (configurable via Settings, default 24 items per page)

**Alpine.js Components**:
- `assetGrid()`: Manages filters, search, sort, tag selection, and view mode
- `assetCard()`: Handles download and copy URL actions in grid view
- `assetRow()`: Manages inline editing (tags, license), delete, and copy URL in list view
  - CSRF token handling for all AJAX requests
  - Optimistic UI updates with rollback on error
  - Toast notifications via `window.showToast()`

### Key Workflows

**File Upload Process** (Direct Upload for files <10MB):
1. Client uploads via multipart/form-data to `POST /assets`
2. `AssetController` validates file (max 512000KB = 500MB)
3. `S3Service->uploadFile()` streams file to S3 bucket
4. Image dimensions extracted using memory-efficient methods
5. Thumbnail generated (skipped for GIFs) and uploaded to S3
6. Asset record created in database with s3_key and etag
7. If Rekognition enabled, `GenerateAiTags` job dispatched to queue
8. Job runs `RekognitionService->autoTagAsset()` in background
9. AI tags created/attached with type='ai', translated if language != 'en'

**Chunked Upload Process** (For large files ≥10MB):
1. Client splits file into 10MB chunks using JavaScript `File.slice()`
2. Client calls `POST /api/chunked-upload/init` with file metadata
3. Server creates `UploadSession` record and initiates S3 multipart upload
4. Server returns `session_token`, `upload_id`, `chunk_size`, `total_chunks`
5. Client uploads each chunk sequentially to `POST /api/chunked-upload/chunk`
6. Each chunk is streamed directly to S3 via `S3Client->uploadPart()`
7. Server stores part ETags in `upload_sessions.part_etags` JSON column
8. Client retries failed chunks up to 3 times with exponential backoff
9. After all chunks uploaded, client calls `POST /api/chunked-upload/complete`
10. Server calls `S3Client->completeMultipartUpload()` to finalize
11. Server extracts image dimensions from completed S3 object
12. Server creates Asset record with all metadata
13. Thumbnail generation and AI tagging proceed as normal
14. Session marked as 'completed' in database

**Chunked Upload Error Handling**:
- Failed chunks trigger automatic retry (3 attempts, 1s/2s/4s backoff)
- If all retries fail, client calls `POST /api/chunked-upload/abort`
- Server aborts S3 multipart upload and marks session as 'aborted'
- Stale sessions (no activity >24h) cleaned up daily via scheduled command
- S3 lifecycle policies can provide additional cleanup (recommended: 2 days)

**Manual AI Tagging**:
- Users can manually trigger AI tagging via "Generate AI Tags" button on edit page
- Route: `POST /assets/{asset}/ai-tag`
- Uses same `GenerateAiTags` job for background processing
- Replaces existing AI tags on the asset

**Discovery Process** (Admin only):
1. `DiscoverController->scan()` calls `S3Service->findUnmappedObjects()`
2. Lists all S3 objects in `assets/` prefix not in database
3. Marks objects that belong to soft-deleted assets with red "Deleted" badge
4. Admin selects objects to import
5. `DiscoverController->import()` creates Asset records
6. Prevents re-importing soft-deleted assets
7. Thumbnails generated and AI tags applied automatically

**Trash/Restore Workflow** (Admin only):
1. **Soft Delete**: User deletes asset → Database record soft-deleted → S3 objects kept
2. **Trash Page**: Admin views `/assets/trash/index` to see all soft-deleted assets
3. **Restore**: Admin clicks restore → Asset returned to active state
4. **Permanent Delete**: Admin clicks force delete → S3 objects deleted → Database record removed forever
5. Discovery shows soft-deleted assets with "Deleted" badge to prevent accidental re-import

### Memory Management

The application handles large files (PDFs, GIFs, videos) by:
- Streaming uploads to S3 instead of loading into memory
- Using `getimagesize()` for GIF dimensions (more efficient than Intervention)
- Skipping thumbnail generation for GIFs
- Requiring minimum 256MB PHP memory limit
- Using fopen/fclose for file streams

### Database Schema

**assets table**:
- `s3_key` (unique) - Full S3 object key path
- `etag` - S3 ETag for duplicate detection
- `filename`, `mime_type`, `size`
- `width`, `height` (for images)
- `thumbnail_s3_key` (nullable)
- `alt_text`, `caption` (nullable)
- `license_type` (nullable) - License type (public_domain, cc_by, cc_by_sa, cc_by_nd, cc_by_nc, cc_by_nc_sa, cc_by_nc_nd, fair_use, all_rights_reserved)
- `copyright` (nullable) - Copyright notice/holder
- `user_id` - Foreign key to uploader
- Soft deletes with `deleted_at`

**upload_sessions table** (for chunked uploads):
- `upload_id` (unique) - S3 multipart upload ID
- `session_token` (unique) - Client-side session identifier (UUID)
- `filename`, `mime_type`, `file_size` - Original file metadata
- `s3_key` - Target S3 key for completed upload
- `chunk_size` - Size of each chunk in bytes (default: 10MB)
- `total_chunks` - Total number of chunks to upload
- `uploaded_chunks` - Number of successfully uploaded chunks
- `part_etags` (JSON) - Array of {PartNumber, ETag} for S3 completion
- `status` - Enum: pending, uploading, completed, failed, aborted
- `user_id` - Foreign key to uploader
- `last_activity_at` - Timestamp of last chunk upload (for cleanup)
- Timestamps (`created_at`, `updated_at`)

**tags table**:
- `name` - Tag text (unique)
- `type` - 'user' or 'ai'

**asset_tag pivot**:
- `asset_id`, `tag_id`
- Timestamps for when tag was attached

**settings table**:
- `key` (unique) - Setting identifier
- `value` (text, nullable) - Setting value stored as string
- `type` - Data type for casting: string, integer, boolean, json
- `group` - Category for grouping: general, display, aws
- `description` (nullable) - Human-readable description
- Timestamps

**Default Settings**:
| Key | Default | Description |
|-----|---------|-------------|
| `items_per_page` | 24 | Assets per page (12, 24, 36, 48, 60, 72, 96) |
| `rekognition_max_labels` | 5 | Max AI tags per asset (1-20) |
| `rekognition_language` | en | AI tag language (en, nl, fr, de, es, etc.) |

## Environment Configuration

### Required AWS Configuration
```env
AWS_ACCESS_KEY_ID=          # IAM user access key
AWS_SECRET_ACCESS_KEY=      # IAM user secret
AWS_DEFAULT_REGION=         # e.g., us-east-1
AWS_BUCKET=                 # S3 bucket name
AWS_URL=                    # Public S3 URL

# Optional: Enable AI tagging
AWS_REKOGNITION_ENABLED=false            # Enable/disable AI tagging
AWS_REKOGNITION_MAX_LABELS=5             # Maximum AI tags per asset (default: 5)
AWS_REKOGNITION_LANGUAGE=en              # Language for AI tags: en, nl, fr, de, es, etc.
```

### S3 Bucket Requirements
- Must have bucket policy allowing public read access (see SETUP_GUIDE.md)
- Disable ACLs (use bucket policies instead)
- IAM user needs: s3:PutObject, s3:GetObject, s3:DeleteObject, s3:ListBucket
- For Rekognition: rekognition:DetectLabels, rekognition:DetectText
- For multilingual AI tags: translate:TranslateText (only if AWS_REKOGNITION_LANGUAGE != 'en')

### PHP Configuration for Large Files

**Chunked Upload Mode** (recommended for servers with low `post_max_size`):
The chunked upload system allows files up to 500MB even with PHP `post_max_size` as low as 16MB by splitting files into 10MB chunks client-side. Minimum required settings:
```ini
memory_limit = 256M          # For image processing
upload_max_filesize = 15M    # Per-chunk limit (10MB chunk + overhead)
post_max_size = 16M          # Must accommodate chunk + request data
max_execution_time = 300     # For chunk processing
```

**Direct Upload Mode** (for servers with higher limits):
If your server allows larger POST sizes, you can use direct uploads for better performance on smaller files:
```ini
memory_limit = 256M
upload_max_filesize = 500M   # Maximum file size
post_max_size = 512M         # Slightly larger than upload_max_filesize
max_execution_time = 300
```

**Note**: The application automatically chooses upload method based on file size:
- Files <10MB: Direct upload via `POST /assets`
- Files ≥10MB: Chunked upload via `/api/chunked-upload/*` endpoints

For Herd: Edit `~/.config/herd/bin/php84/php.ini` (Windows: `C:\Users\<username>\.config\herd\bin\php84\php.ini`) and restart Herd.

## Key Conventions

### File Organization
- Controllers in `app/Http/Controllers/` (API controllers in `Api/` subdirectory)
- Services for external integrations in `app/Services/`
- Policies for authorization in `app/Policies/`
- All S3 assets stored with `assets/` prefix
- Thumbnails stored with `thumbnails/` prefix

### Naming Patterns
- S3 keys use UUID filenames: `assets/{uuid}.{ext}`
- Thumbnail keys: `thumbnails/{uuid}_thumb.{ext}` (always JPEG)
- Routes follow RESTful conventions
- Database columns use snake_case

### Error Handling
- S3Service and RekognitionService catch exceptions and log errors
- Services return null/empty arrays on failure rather than throwing
- Controllers validate input and return appropriate HTTP status codes
- Laravel logs stored in `storage/logs/laravel.log`

### Delete Behavior
- **Soft Delete** (default): `Asset->delete()` marks record as deleted but keeps S3 objects
- **Hard Delete** (admin only): `Asset->forceDelete()` removes database record AND S3 objects
- All delete operations use Laravel's soft delete feature (`deleted_at` timestamp)
- Soft-deleted assets are hidden from normal queries but visible in trash
- Discovery feature marks soft-deleted assets to prevent re-import

## Testing Considerations

- Tests use SQLite in-memory database (configured in phpunit.xml)
- AWS services should be mocked in tests to avoid actual S3/Rekognition calls
- Feature tests should cover: upload flow, authorization policies, search/filter logic
- Test files located in `tests/Feature/` and `tests/Unit/`

## Integration Points

### CSV Export
The export feature (`ExportController`) generates CSV files with comprehensive asset metadata:
- All database fields (id, s3_key, filename, mime_type, size, dimensions, etc.)
- User information (name, email)
- **Separate columns for user tags and AI tags** - allows filtering/analysis by tag type
- License type and copyright information
- Public URLs for both full asset and thumbnail
- Can be filtered by file type and tags before export
- Timestamps in `Y-m-d H:i:s` format

### Rich Text Editor Integration
See `RTE_INTEGRATION.md` for detailed examples integrating with TinyMCE, CKEditor, Quill, and custom implementations. Key points:
- Use Laravel Sanctum tokens for authentication
- API returns paginated results with thumbnails
- All assets have public S3 URLs safe for embedding
- Asset metadata includes alt_text for accessibility

**Public Metadata API**:
- `GET /api/assets/meta?url={asset_url}` - Retrieve metadata by asset URL (no authentication required)
- Returns: alt_text, caption, license_type, copyright, filename, url
- Useful for displaying asset information in external applications
- Example: `https://your-app.test/api/assets/meta?url=https://bucket.s3.amazonaws.com/assets/abc123.jpg`

### External Systems
- S3 bucket can be shared with other applications
- Use Discovery feature to import externally-uploaded objects
- ETag field prevents duplicate imports of same S3 object
- Public URLs are permanent and cacheable

## System Administration

ORCA DAM includes a comprehensive admin-only System page accessible via the user dropdown menu.

### Accessing System Administration
- **Route**: `/system`
- **Authorization**: Admin users only (`role = 'admin'`)
- **Location**: User dropdown → System (icon: cog)

### System Page Features

**Overview Tab:**
- System information (PHP version, Laravel version, environment)
- Database statistics (record counts for all tables)
- Disk usage (storage, logs, cache, total)

**Settings Tab:**
- Configurable application settings stored in database
- **Display Settings**:
  - Items per page (12, 24, 36, 48, 60, 72, 96)
- **AWS Rekognition Settings**:
  - Maximum AI tags per asset (1-20)
  - AI tag language (13 languages supported)
- Changes saved automatically via AJAX
- Settings cached for performance (1 hour TTL)
- Route: `POST /system/settings`

**Queue Tab:**
- Real-time queue statistics (pending, failed, batches)
- Queue management controls:
  - Retry all failed jobs
  - Flush failed jobs queue
  - Restart queue workers (signals graceful restart)
- Failed jobs table with exception details
- Individual job retry capability

**Logs Tab:**
- Laravel log viewer (last 20-200 lines configurable)
- Color-coded output (ERROR=red, WARNING=yellow, INFO=blue)
- Manual refresh with line count selector

**Commands Tab:**
- Custom artisan command execution with security whitelist
- Suggested commands with one-click execution:
  - Cache management (clear, optimize)
  - Queue operations (retry, flush, restart)
  - Maintenance (uploads:cleanup, storage:link)
  - Database (migrate:status, migrate)
- Real-time command output display

**Diagnostics Tab:**
- System configuration overview
- PHP settings display
- S3 connection test

### Queue Worker Management

**Development:**
Run manually in terminal:
```bash
php artisan queue:work --tries=3
```

**Production:**
Use supervisor to manage persistent queue workers. See `DEPLOYMENT.md` for complete setup instructions.

**Key Files:**
- Supervisor config: `deploy/supervisor/orca-queue-worker.conf`
- Deployment guide: `DEPLOYMENT.md`

**Managing from System Page:**
- `queue:restart` - Signal workers to gracefully restart
- `queue:retry all` - Requeue all failed jobs
- `queue:flush` - Delete all failed jobs
- View pending/failed job counts and details

**⚠️ Important:** Do not run `queue:work` from the web UI (causes timeouts). Use supervisor or manual terminal execution.

### Security Features

- Admin-only access via SystemPolicy
- Command whitelist (prevents dangerous operations)
- All command executions logged with user ID
- CSRF protection on all POST requests
- Read-only log access (no deletion)
- Input validation on all endpoints

## Deployment

See `DEPLOYMENT.md` for complete production deployment instructions including:
- Server requirements and configuration
- Supervisor setup for queue workers
- Web server configuration (Nginx/Apache)
- SSL certificate setup
- Monitoring and maintenance
- Backup strategies
- Troubleshooting guide
