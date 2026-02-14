# ORCA DAM - Complete Setup Guide

## Quick Start Guide

### Prerequisites
```bash
- PHP 8.2 or higher with minimum 256MB memory limit
- Composer
- Node.js & NPM
- MySQL or PostgreSQL
- AWS Account with S3 bucket (public-read)
- GD or Imagick extension for image processing
```

**Important PHP Configuration:**

ORCA DAM includes **chunked upload** support for large files up to 500MB. This allows uploads even with limited PHP `post_max_size` settings (as low as 16MB). The application automatically routes large files (≥10MB) through the chunked upload API.

Choose the configuration that matches your server's capabilities:

**Option A: Chunked Upload Mode (recommended for servers with limited POST size)**
Perfect for shared hosting or servers with `post_max_size` restrictions:
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
1. Locate your Herd PHP configuration file:
   - **macOS/Linux**: `~/.config/herd/bin/php84/php.ini`
   - **Windows**: `C:\Users\<username>\.config\herd\bin\php84\php.ini`
   - **To find yours**: Run `php --ini` and check "Loaded Configuration File"
2. Edit the values based on Option A or B above
3. **Restart Herd** from the system tray (Stop/Start or Restart all services)

**For Apache/Nginx/php-fpm users:**
Create a `.user.ini` file in the `public/` directory with your chosen configuration, then restart your web server.

**Note:** `.user.ini` files do NOT work with Laravel Herd - you must edit Herd's `php.ini` directly.

### Step-by-Step Installation

#### 1. Clone or Set Up Project
```bash
# If cloning from repository
git clone <your-repo-url> orca-dam
cd orca-dam

# Install PHP dependencies
composer install

# Install Node dependencies  
npm install
```

#### 2. Environment Configuration
```bash
# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate
```

#### 3. Configure AWS S3 in .env
```env
AWS_ACCESS_KEY_ID=your_access_key_here
AWS_SECRET_ACCESS_KEY=your_secret_key_here
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=your-bucket-name
AWS_URL=https://your-bucket-name.s3.amazonaws.com

# Optional: Enable AI tagging
AWS_REKOGNITION_ENABLED=false            # Enable/disable AI tagging
AWS_REKOGNITION_MAX_LABELS=3             # Maximum AI tags per asset (default: 3)
AWS_REKOGNITION_MIN_CONFIDENCE=80        # Minimum confidence threshold (default: 80, range: 65-99)
AWS_REKOGNITION_LANGUAGE=nl              # Language for AI tags: en, nl, fr, de, es, etc.

# Optional: JWT Authentication (for frontend RTE integrations)
JWT_ENABLED=false                        # Enable/disable JWT authentication
JWT_MAX_TTL=36000                        # Maximum token lifetime in seconds (default: 10 hours)
JWT_LEEWAY=60                            # Clock skew tolerance in seconds
```

**Important S3 Bucket Settings:**

The application uses **bucket policies** instead of ACLs for better security. Configure your S3 bucket as follows:

1. **Disable ACLs** (recommended by AWS):
   - Go to S3 → Your Bucket → Permissions → Object Ownership
   - Select "ACLs disabled (recommended)"

2. **Add Bucket Policy** to make objects publicly readable:
   - Go to S3 → Your Bucket → Permissions → Bucket Policy
   - Add the following policy (replace `your-bucket-name` with your actual bucket name):

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "PublicReadGetObject",
            "Effect": "Allow",
            "Principal": "*",
            "Action": "s3:GetObject",
            "Resource": "arn:aws:s3:::your-bucket-name/*"
        }
    ]
}
```

**Note:** This policy only makes objects publicly readable. Your IAM user's write/delete permissions come from the IAM policy (see AWS IAM Permissions section below).

3. **Enable CORS** if accessing from different domains:
   - Go to S3 → Your Bucket → Permissions → CORS

```json
[
    {
        "AllowedHeaders": ["*"],
        "AllowedMethods": ["GET", "PUT", "POST", "DELETE"],
        "AllowedOrigins": ["*"],
        "ExposeHeaders": []
    }
]
```

#### 4. Database Setup
```bash
# Configure database in .env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=orca_dam
DB_USERNAME=root
DB_PASSWORD=your_password

# Run migrations
php artisan migrate

# Create admin user
php artisan db:seed --class=AdminUserSeeder
```

Default admin credentials:
- Email: `admin@orca.dam`
- Password: `password`

**⚠️ Change this password immediately after first login!**

#### 5. Compile Assets
```bash
# Development
npm run dev

# Production
npm run build
```

#### 6. Start Development Server
```bash
php artisan serve
```

Visit: `http://localhost:8000`

---

## AWS IAM Permissions

Create an IAM user (e.g., `orca-dam-user`) with the following minimum permissions:

**Note:** `s3:PutObjectAcl` is NOT required since we use bucket policies instead of ACLs.

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "S3BucketAccess",
            "Effect": "Allow",
            "Action": [
                "s3:PutObject",
                "s3:GetObject",
                "s3:DeleteObject",
                "s3:ListBucket",
                "s3:GetBucketLocation",
                "s3:AbortMultipartUpload",
                "s3:ListMultipartUploadParts"
            ],
            "Resource": [
                "arn:aws:s3:::your-bucket-name",
                "arn:aws:s3:::your-bucket-name/*"
            ]
        },
        {
            "Sid": "RekognitionAccess",
            "Effect": "Allow",
            "Action": [
                "rekognition:DetectLabels",
                "rekognition:DetectText"
            ],
            "Resource": "*"
        },
        {
            "Sid": "TranslateAccess",
            "Effect": "Allow",
            "Action": [
                "translate:TranslateText"
            ],
            "Resource": "*",
            "Condition": {
                "StringEquals": {
                    "aws:RequestedRegion": "us-east-1"
                }
            }
        }
    ]
}
```

**Important:** Attach this policy to your IAM user to grant S3 access. The bucket policy (above) only handles public read access.

**Note:** The `TranslateAccess` statement is only required if you enable multilingual AI tags by setting `AWS_REKOGNITION_LANGUAGE` to a language other than `en`. If you only use English tags, you can omit this permission.

---

## Features Overview

### 1. Asset Management
- ✅ Upload multiple files with drag & drop
- ✅ **Chunked upload for large files (up to 500MB)**
- ✅ **Automatic upload method selection (direct <10MB, chunked ≥10MB)**
- ✅ **Smart retry logic with exponential backoff**
- ✅ **Editable filenames** (display name only — S3 key/URL unchanged)
- ✅ Automatic thumbnail generation
- ✅ Image dimension detection
- ✅ File size tracking
- ✅ Grid view with pagination
- ✅ Quick URL copying
- ✅ License type and copyright fields
- ✅ Alt text and captions for accessibility

### 2. Tagging System
- ✅ Manual user tags
- ✅ AI-powered auto-tagging (AWS Rekognition)
- ✅ Manual AI tag generation button
- ✅ Configurable max AI tags per asset
- ✅ Multilingual AI tags via AWS Translate (en, nl, fr, de, es, etc.)
- ✅ Tag filtering and search
- ✅ Tag browsing page
- ✅ Remove AI tags from assets
- ✅ Delete both user and AI tags

### 3. Search & Filter
- ✅ Full-text search
- ✅ Filter by tags
- ✅ Filter by file type
- ✅ Filter by uploader (admin)

### 4. Discovery
- ✅ Scan S3 bucket for unmapped objects
- ✅ Bulk import with preview
- ✅ Auto-tag imported objects
- ✅ Metadata extraction

### 5. User Roles
- **Editors**: Upload and manage all assets (view, edit filenames/metadata, soft delete), set personal preferences
- **Admins**: Full access including trash management, restore/permanent delete, discover, export, user management, system settings, and API token/JWT secret management
- **API Users**: API-only access for external integrations (view, create, update assets; no delete or admin features)

### 6. User Preferences
- ✅ Personal home folder setting (default folder when browsing assets)
- ✅ Personal items per page setting (overrides global default)
- ✅ Personal language preference (overrides global default)
- ✅ Preferences accessible via Profile page
- ✅ URL parameters still override user preferences

### 7. Multi-Language Support
- ✅ UI available in English and Dutch (Nederlands)
- ✅ Global language set by admin via System → Settings
- ✅ Per-user language override via Profile → Preferences
- ✅ Priority: User preference → Global setting → Config default

### 8. API for RTE Integration
- ✅ RESTful API endpoints
- ✅ Laravel Sanctum authentication (long-lived tokens for backends)
- ✅ JWT authentication (short-lived tokens for frontends)
- ✅ Pagination support
- ✅ Search and filter API
- ✅ Public metadata endpoint (no auth required)

### 9. Export & Reporting
- ✅ CSV export with all asset metadata
- ✅ Separate columns for user tags and AI tags
- ✅ Includes license type and copyright information
- ✅ Filter by file type and tags before export
- ✅ Timestamped export filenames

### 10. Trash & Restore (Admin Only)
- ✅ Soft delete keeps S3 objects when assets are deleted
- ✅ Trash page shows all soft-deleted assets
- ✅ Restore assets from trash back to active state
- ✅ Permanent delete removes database record AND S3 objects
- ✅ Discovery marks soft-deleted assets to prevent re-import

### 11. System Administration (Admin Only)
- ✅ System overview (PHP version, Laravel version, disk usage)
- ✅ **Settings panel** with configurable options:
  - S3 root folder prefix
  - Custom domain for asset URLs (CDN support)
  - Items per page (12, 24, 36, 48, 60, 72, 96)
  - Timezone
  - UI language (English, Dutch)
  - AWS Rekognition max labels (1-20)
  - AWS Rekognition language (13 languages)
- ✅ Queue management (retry, flush, restart workers)
- ✅ Log viewer with color-coded output
- ✅ Artisan command execution (whitelisted commands)
- ✅ S3 connection diagnostics
- ✅ API token management (Sanctum tokens)
- ✅ JWT secret management (per-user secrets for frontend integrations)
- ✅ Web-based test runner

---

## API Documentation

### Authentication

ORCA supports two authentication methods:

#### Option 1: Sanctum Tokens (Backend Integrations)

Long-lived tokens for backend-to-backend API calls. **Never expose these to frontend code.**

```javascript
// Generate token (admin generates via UI or CLI)
// Use in backend requests
headers: {
    'Authorization': `Bearer ${sanctumToken}`,
    'Accept': 'application/json'
}
```

Generate tokens via:
- **Web UI**: API Docs → API Tokens tab
- **CLI**: `php artisan token:create user@example.com`

#### Option 2: JWT (Frontend RTE Integrations)

Short-lived tokens ideal for browser-based integrations. Your backend generates JWTs; ORCA validates them.

**Setup:**
1. Enable JWT auth in `.env`: `JWT_ENABLED=true`
2. Generate a JWT secret for a user:
   - **Web UI**: API Docs → JWT Secrets tab → Generate Secret
   - **CLI**: `php artisan jwt:generate user@example.com`
3. Your backend generates JWTs using the secret:

```javascript
// Node.js example
const jwt = require('jsonwebtoken');
const token = jwt.sign(
    { sub: orcaUserId },    // ORCA user ID (required)
    jwtSecret,              // Secret from ORCA
    { expiresIn: '1h', algorithm: 'HS256' }
);
```

```php
// PHP example
use Firebase\JWT\JWT;
$token = JWT::encode([
    'sub' => $orcaUserId,   // ORCA user ID (required)
    'iat' => time(),
    'exp' => time() + 3600,
], $jwtSecret, 'HS256');
```

4. Frontend uses JWT in requests:
```javascript
headers: {
    'Authorization': `Bearer ${jwtToken}`,
    'Accept': 'application/json'
}
```

**Required JWT claims:** `sub` (user ID), `exp` (expiry), `iat` (issued at)

See `RTE_INTEGRATION.md` for complete integration examples.

### Endpoints

#### List Assets
```
GET /api/assets?search=keyword&tags[]=1,2&type=image&per_page=24
```

#### Upload Assets (Direct - files <10MB)
```
POST /api/assets
Content-Type: multipart/form-data

files[]: File[]
```

#### Chunked Upload (Large files ≥10MB)
```
# 1. Initialize upload session
POST /api/chunked-upload/init
Content-Type: application/json

{
    "filename": "large-file.mp4",
    "mime_type": "video/mp4",
    "file_size": 524288000
}

Response:
{
    "session_token": "uuid-here",
    "upload_id": "s3-upload-id",
    "chunk_size": 10485760,
    "total_chunks": 50
}

# 2. Upload each chunk
POST /api/chunked-upload/chunk
Content-Type: multipart/form-data

session_token: "uuid-here"
chunk_number: 1
chunk: <chunk-blob>

Response:
{
    "message": "Chunk uploaded successfully",
    "part_number": 1,
    "etag": "s3-etag",
    "uploaded_chunks": 1,
    "total_chunks": 50
}

# 3. Complete upload
POST /api/chunked-upload/complete
Content-Type: application/json

{
    "session_token": "uuid-here"
}

Response:
{
    "message": "Upload completed successfully",
    "asset": { asset data with tags }
}

# Optional: Abort upload on error
POST /api/chunked-upload/abort
Content-Type: application/json

{
    "session_token": "uuid-here"
}
```

#### Get Asset
```
GET /api/assets/{id}
```

#### Update Asset
```
PATCH /api/assets/{id}

{
    "alt_text": "Description",
    "caption": "Caption text",
    "license_type": "cc_by",
    "copyright": "© 2026 Company Name",
    "tags": ["tag1", "tag2"]
}
```

#### Get Asset Metadata by URL (Public, No Auth Required)
```
GET /api/assets/meta?url=https://bucket.s3.amazonaws.com/assets/abc123.jpg

Returns:
{
    "alt_text": "Description",
    "caption": "Caption text",
    "license_type": "cc_by",
    "copyright": "© 2026 Company Name",
    "filename": "image.jpg",
    "url": "https://bucket.s3.amazonaws.com/assets/abc123.jpg"
}
```

#### Delete Asset
```
DELETE /api/assets/{id}
```

#### Search (Asset Picker)
```
GET /api/assets/search?q=keyword&tags=1,2&type=image
```

#### List Tags
```
GET /api/tags?type=user
```

#### List Folders
```
GET /api/folders

Returns:
{
    "folders": ["assets", "assets/marketing", "assets/docs"]
}
```

---

## Usage Tips

### 1. Discovering Existing S3 Objects
If you're connecting to an existing S3 bucket with files:

1. Go to **Discover** (admin only)
2. Click **Scan Bucket**
3. Review unmapped objects
4. Select objects to import
5. Click **Import Selected**

The system will:
- Create database records
- Generate thumbnails for images
- Auto-tag with AI (if enabled)
- Extract metadata

### 2. Importing Bulk Metadata
To enrich assets with metadata from an external source (spreadsheet, other DAM):

1. Go to user dropdown > **Import** (admin only)
2. Select match field (`s3_key` or `filename`)
3. Paste CSV data or upload/drop a `.csv` file
4. Preview matched assets and changes
5. Click **Import** to apply

### 3. Enabling AI Tagging
Set in `.env`:
```env
AWS_REKOGNITION_ENABLED=true
AWS_REKOGNITION_MAX_LABELS=5       # Maximum AI tags per asset (can also be set in Settings)
AWS_REKOGNITION_MIN_CONFIDENCE=75  # Minimum confidence threshold (can also be set in Settings)
AWS_REKOGNITION_LANGUAGE=en        # Language for AI tags (can also be set in Settings)
```

AI tags are generated automatically on upload and run in a background job queue. They are marked with a purple color in the UI.

**Runtime Configuration:**
Admins can also configure AI tag limits, confidence threshold, and language via **System → Settings** without editing `.env`. Database settings override environment defaults.

**Manual AI Tagging:**
- You can manually trigger AI tag generation on any image asset
- Go to the asset edit page
- Click "Generate AI Tags" button in the purple AI Tags section
- This will queue a job to detect and translate tags
- Existing AI tags will be replaced with new ones

### 4. Copying URLs
Click the copy icon on any asset thumbnail or use the copy button on the asset detail page. URLs are public and permanent (configured via bucket policy for public read access).

### 5. Batch Operations
Select multiple unmapped objects in Discover to import in bulk.

### 6. Using the Trash Feature (Admin Only)

**Soft Delete (Default Behavior):**
- When you delete an asset, it's moved to trash
- The database record is marked as deleted
- S3 objects (file + thumbnail) are **kept** in the bucket
- Asset is hidden from normal views

**Accessing Trash:**
1. Navigate to the "Trash" link in the top navigation (admin only)
2. View all soft-deleted assets with deletion timestamps

**Restoring Assets:**
1. Go to Trash page
2. Find the asset you want to restore
3. Click the green restore button (undo icon)
4. Asset returns to active state immediately

**Permanent Delete:**
1. Go to Trash page
2. Click the red trash icon on an asset
3. Confirm the deletion warning
4. This will:
   - Delete the S3 object (file)
   - Delete the S3 thumbnail
   - Permanently remove the database record
   - **This action cannot be undone!**

**Discovery Integration:**
- When scanning S3 bucket, soft-deleted assets appear with a red "Deleted" badge
- Shows when the asset was deleted
- Prevents accidentally re-importing deleted assets

### 7. Using a Custom Domain (CDN)

By default, asset URLs use your S3 bucket domain (e.g., `https://bucket.s3.amazonaws.com/assets/uuid.jpg`). You can configure a custom domain so all asset URLs use a friendlier address like `https://cdn.example.com/assets/uuid.jpg`.

**Setup Steps:**

1. **Configure your CDN** to point to your S3 bucket:
   - **AWS CloudFront**: Create a distribution with your S3 bucket as origin
   - **Cloudflare**: Add a CNAME record pointing to your S3 bucket URL
   - **Other CDN**: Configure the CDN to proxy requests to your S3 bucket

2. **Set the custom domain in ORCA**:
   - Go to **System → Settings** (admin only)
   - Under **S3 Storage**, enter your custom domain URL (e.g., `https://cdn.example.com`)
   - Save — all asset URLs across the application update immediately

3. **Verify**: Browse to any asset and check that its URL uses the custom domain

**Important notes:**
- The S3 bucket policy must still allow public read access
- Existing assets get the new URL immediately (no migration needed)
- Clearing the custom domain field reverts all URLs back to the S3 bucket domain
- S3 operations (uploads, deletes, thumbnails) always use the real S3 bucket, unaffected
- The `/api/assets/meta` endpoint accepts both custom domain URLs and original S3 URLs

---

## Customization

### Changing Upload Limits

The application supports files up to 500MB by default using chunked uploads for files ≥10MB.

In `AssetController.php` and `Api/AssetApiController.php`:
```php
'files.*' => 'required|file|max:512000', // 500MB (in KB)
```

**For chunked uploads (recommended):**
You can support large files even with limited PHP `post_max_size`:
```ini
upload_max_filesize = 15M   # Per-chunk limit
post_max_size = 16M         # Minimum for chunk handling
memory_limit = 256M         # For image processing
```

**For direct uploads only:**
If you want to increase the direct upload limit (affects files <10MB):
```ini
upload_max_filesize = 100M  # Your desired max
post_max_size = 100M        # Match upload_max_filesize
memory_limit = 256M
```

Update in:
- **For Herd:** `~/.config/herd/bin/php84/php.ini` (restart Herd)
- **For Apache/Nginx:** `public/.user.ini` (restart web server)

### Changing Thumbnail Size
In `S3Service.php`:
```php
$image->resize(300, 300, function ($constraint) {
    // Adjust dimensions
});
```

### Adding More File Types
Update validation rules and add appropriate icons/handling.

---

## Troubleshooting

### Large files (PDFs, GIFs, videos) failing to upload
- **Symptom:** 413 error, 500 error, upload stuck at 100%, or memory exhaustion errors
- **Solution:** Ensure PHP memory limit is at least 256MB
- **For Herd users:** Edit `~/.config/herd/bin/php84/php.ini` directly (see Prerequisites section)
- **For Apache/Nginx users:** Create `public/.user.ini` with the settings shown in Prerequisites
- Restart your web server (Herd from system tray, or `sudo service apache2 restart`)
- Verify changes: Run `php -i | grep "upload_max_filesize\|post_max_size\|memory_limit"`
- Check Laravel logs for errors: `storage/logs/laravel.log`
- For very large files (>100MB), increase memory limit further or reduce max file size

### Images not uploading
- Check S3 bucket policy is configured correctly (see AWS S3 Bucket Settings above)
- Verify IAM user has required S3 permissions
- Verify AWS credentials in `.env`
- Check PHP `upload_max_filesize` and `post_max_size` settings
- Review Laravel logs for specific error: `storage/logs/laravel.log`
- Ensure toast notifications are working (check browser console for errors)

### Thumbnails not generating
- Ensure GD or Imagick PHP extension is installed (`php -m | grep -i gd`)
- Check S3 write permissions for thumbnail uploads
- GIF thumbnails are skipped to avoid memory issues (original GIF is used)
- Review Laravel logs: `storage/logs/laravel.log`

### AI tagging not working
- Verify Rekognition permissions in IAM (see AWS IAM Permissions section)
- For multilingual tags, verify Translate permissions are granted
- Check `AWS_REKOGNITION_ENABLED=true` in `.env`
- Ensure bucket is in same region as Rekognition service
- AI tags are processed via job queue - ensure queue worker is running: `php artisan queue:work`
- Check Laravel logs for errors: `storage/logs/laravel.log`
- Verify `AWS_REKOGNITION_MAX_LABELS` is set to an integer (default: 5)
- Verify `AWS_REKOGNITION_MIN_CONFIDENCE` is set to an integer between 65-99 (default: 75)
- For language issues, check `AWS_REKOGNITION_LANGUAGE` is a valid language code (en, nl, fr, de, es, etc.)
- Test with manual "Generate AI Tags" button on asset edit page to see immediate errors

### Discovery not finding objects
- Check S3 bucket name in `.env`
- Verify IAM permissions include `s3:ListBucket`
- Objects must be in `assets/` prefix (configurable in code)

---

## Production Deployment

### 1. Optimize Application
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
npm run build
```

### 2. Set Up Queue Workers
For background AI tagging:
```bash
php artisan queue:work
```

Or use supervisor/systemd for production.

### 3. Set Up Cron
For scheduled tasks (includes daily cleanup of stale upload sessions):
```bash
* * * * * cd /path-to-orca && php artisan schedule:run >> /dev/null 2>&1
```

Scheduled tasks include:
- Daily cleanup of abandoned chunked upload sessions (>24 hours old)

### 4. API Token Management
```bash
# Sanctum tokens (for backend integrations)
php artisan token:list                   # List all tokens
php artisan token:create user@email.com  # Create token for user
php artisan token:revoke 5               # Revoke token ID 5

# JWT secrets (for frontend integrations)
php artisan jwt:list                     # List users with JWT secrets
php artisan jwt:generate user@email.com  # Generate JWT secret
php artisan jwt:revoke user@email.com    # Revoke JWT secret
```

### 5. Security Checklist
- [ ] Change default admin password
- [ ] Set `APP_ENV=production` and `APP_DEBUG=false`
- [ ] Use strong `APP_KEY`
- [ ] Enable HTTPS
- [ ] Verify PHP memory/upload limits are properly configured (256MB minimum)
- [ ] Restrict IAM permissions to minimum required
- [ ] Set up regular backups
- [ ] Configure rate limiting
- [ ] Securely share JWT secrets with external systems (never expose in frontend code)
- [ ] Use short JWT token lifetimes (1 hour recommended)

---

## Support & Contributing

For issues, feature requests, or contributions, please refer to the repository guidelines.

## License

MIT License - See LICENSE file for details.
