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

## Architecture

### Core Services Pattern

The application uses a service-oriented architecture with two main services that handle external integrations:

**S3Service** (`app/Services/S3Service.php`)
- Manages all S3 operations using AWS SDK v3
- Handles file uploads with streaming to avoid memory issues on large files
- Generates thumbnails using Intervention Image 3.x (skips GIFs to prevent memory exhaustion)
- Provides discovery functionality to find unmapped S3 objects
- Uses bucket policies (not ACLs) for public read access

**RekognitionService** (`app/Services/RekognitionService.php`)
- Provides AI-powered tagging via AWS Rekognition
- Can be enabled/disabled via `AWS_REKOGNITION_ENABLED` config
- Detects labels with confidence scoring (default 75% minimum)
- Creates/attaches AI tags (type='ai') to assets automatically
- Only processes image assets
- Configurable max labels per asset via `AWS_REKOGNITION_MAX_LABELS` (default: 5)
- Supports multilingual tags via AWS Translate (configurable via `AWS_REKOGNITION_LANGUAGE`)
- Uses Job queue (`GenerateAiTags`) for background processing

### Authorization System

Uses Laravel Policies for fine-grained access control:

**AssetPolicy** (`app/Policies/AssetPolicy.php`)
- All authenticated users can view and create assets
- Editors can update/delete their own assets only
- Admins can update/delete any asset
- Only admins can access the "Discover" feature (unmapped S3 objects)

**User Roles** (stored in `users.role` column):
- `editor`: Can upload and manage their own assets
- `admin`: Full access including user management and discovery

### Model Relationships

**Asset Model** (`app/Models/Asset.php`)
- Belongs to User (uploader)
- Many-to-many with Tags (via `asset_tag` pivot)
- Uses soft deletes
- Appends computed attributes: `url`, `thumbnail_url`, `formatted_size`
- Scopes for search, filtering by tags, type, and user
- Helper methods: `isImage()`, `getFileIcon()`, `userTags()`, `aiTags()`
- Includes license and copyright fields: `license_type`, `copyright`

**Tag Model**
- Has type field: `user` (manual) or `ai` (auto-generated)
- Many-to-many with Assets
- Both user and AI tags can be deleted from assets and removed completely
- AI tags can be removed from individual assets without deleting the tag entirely

### API Design

**Authentication**: Laravel Sanctum (SPA tokens)

**Endpoints** (`routes/api.php`):
- `GET /api/assets` - List assets with pagination, search, filters
- `POST /api/assets` - Multi-file upload
- `GET /api/assets/{id}` - Get single asset
- `PATCH /api/assets/{id}` - Update metadata (alt_text, caption, tags, license_type, copyright)
- `DELETE /api/assets/{id}` - Delete asset
- `GET /api/assets/search` - Search with query params
- `GET /api/assets/meta` - Get asset metadata by URL (public, no auth required)
- `GET /api/tags` - List tags (with optional type filter)

All API endpoints require `auth:sanctum` middleware except `/api/assets/meta` which is public.

### Frontend Stack

- **Templates**: Blade with Alpine.js for interactivity
- **Styling**: Tailwind CSS with custom ORCA theme colors
- **Icons**: Font Awesome 6.4.0
- **Build Tool**: Vite
- **Image Processing**: Intervention Image 3.x (uses GD driver)

### Key Workflows

**File Upload Process**:
1. Client uploads via multipart/form-data
2. `AssetController` validates file (max 102400KB default)
3. `S3Service->uploadFile()` streams file to S3 bucket
4. Image dimensions extracted using memory-efficient methods
5. Thumbnail generated (skipped for GIFs) and uploaded to S3
6. Asset record created in database with s3_key and etag
7. If Rekognition enabled, `GenerateAiTags` job dispatched to queue
8. Job runs `RekognitionService->autoTagAsset()` in background
9. AI tags created/attached with type='ai', translated if language != 'en'

**Manual AI Tagging**:
- Users can manually trigger AI tagging via "Generate AI Tags" button on edit page
- Route: `POST /assets/{asset}/ai-tag`
- Uses same `GenerateAiTags` job for background processing
- Replaces existing AI tags on the asset

**Discovery Process** (Admin only):
1. `DiscoverController->scan()` calls `S3Service->findUnmappedObjects()`
2. Lists all S3 objects in `assets/` prefix not in database
3. Admin selects objects to import
4. `DiscoverController->import()` creates Asset records
5. Thumbnails generated and AI tags applied automatically

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

**tags table**:
- `name` - Tag text (unique)
- `type` - 'user' or 'ai'

**asset_tag pivot**:
- `asset_id`, `tag_id`
- Timestamps for when tag was attached

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
Minimum required settings (especially important for Laravel Herd users):
```ini
memory_limit = 256M
upload_max_filesize = 100M
post_max_size = 100M
max_execution_time = 300
```

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
