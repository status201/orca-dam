# ORCA DAM - Complete Setup Guide

## Quick Start Guide

### Prerequisites
```bash
- PHP 8.3 or higher (8.4 recommended) with minimum 256MB memory limit
- Composer
- Node.js & NPM
- MySQL or PostgreSQL
- AWS Account with S3 bucket (public-read)
- GD or Imagick extension for image processing
- (Optional) TeX Live for TikZ Server Render (latex + dvisvgm)
- (Optional) librsvg2-bin or inkscape for TikZ PNG output
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

# Optional: Cloudflare cache purging (purges CDN cache on asset replacement)
# Also enable via System → Settings → S3 Storage (requires custom domain)
CLOUDFLARE_ENABLED=false                 # Enable/disable Cloudflare cache purging
CLOUDFLARE_API_TOKEN=                    # API token with Zone.Cache Purge permission
CLOUDFLARE_ZONE_ID=                      # Zone ID from Cloudflare dashboard

# Optional: TikZ Server Render (requires TeX Live on server)
TIKZ_LATEX_PATH=latex                    # Path to latex binary
TIKZ_DVISVGM_PATH=dvisvgm               # Path to dvisvgm binary
TIKZ_TIMEOUT=30                          # Compilation timeout in seconds
TIKZ_PNG_DPI=300                         # Default PNG DPI (72-600)
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

## What ORCA can do

The feature list is in [README.md](README.md#features); how to *use* each feature —
uploading, browsing, tagging, AI tags, replacing, trash, bulk actions, discover, import,
export, preferences, account security, the admin panels — is documented for end users in
[USER_MANUAL.md](USER_MANUAL.md) (Dutch: [GEBRUIKERSHANDLEIDING.md](GEBRUIKERSHANDLEIDING.md)).
The authoritative behaviour of each feature is specified in
[specs/features/](specs/README.md).

Two things that matter while you are still configuring:

- **Roles.** Three of them — `admin`, `editor`, `api`. Editors upload, edit, tag and
  soft-delete; admins add permanent delete, user management, discover, import/export, bulk
  move and the system panels; `api` is for integrations and cannot delete. The full matrix
  is [specs/features/authorization-policies.md](specs/features/authorization-policies.md).
- **Upload mode.** Files under 10MB upload directly; 10MB and over are routed through the
  chunked API automatically. This is why the PHP limits below have two valid shapes.

## API access

The complete REST reference — Sanctum vs. JWT authentication (with generation examples in
Node, PHP, Python and Java), every endpoint, request/response shapes, the four-step
chunked-upload flow, query parameters and editor integrations — lives in
[RTE_INTEGRATION.md](RTE_INTEGRATION.md).

To hand out credentials once ORCA is running:

```bash
php artisan token:create user@example.com   # Sanctum, for backends
php artisan jwt:generate user@example.com   # JWT secret, for frontends (needs JWT_ENABLED=true)
```

Both are also available in the UI under **API Docs**. Never expose a Sanctum token or a
JWT secret to client-side code.

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

### 6. Using the Trash Feature

**Soft Delete (Default Behavior):**
- When you delete an asset, it's moved to trash
- The database record is marked as deleted
- S3 objects (file + thumbnail) are **kept** in the bucket
- Asset is hidden from normal views

**Accessing Trash:**
1. Navigate to the "Trash" link in the top navigation (editors and admins)
2. View all soft-deleted assets with deletion timestamps

**Restoring Assets** (editors and admins):
1. Go to Trash page
2. Find the asset you want to restore
3. Click the green restore button (undo icon)
4. Asset returns to active state immediately

**Permanent Delete** (admin only):
1. Go to Trash page
2. Click the red trash icon on an asset
3. Confirm the deletion warning
4. This will:
   - Delete the S3 object (file)
   - Delete the S3 thumbnail
   - Permanently remove the database record
   - **This action cannot be undone!**

**Bulk Move to Trash:**
1. Select assets on the index page and click "Move to Trash"
2. Confirm — assets are soft-deleted (S3 objects preserved, restorable from trash)

**Bulk Download:**
1. Select assets on the index page and click "Download"
2. A ZIP file is generated and downloaded (max 100 files / 500MB)

**Bulk Permanent Delete:**
1. Enable maintenance mode in System → Settings
2. Select assets on the index page and click the red bulk delete button
3. Confirm — removes S3 objects (original + thumbnail + resize variants) AND database records permanently

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

In `AssetController.php` and `Api/AssetApiController.php` (validation), and `AssetProcessingService.php` (processing):
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

## Going to production

Do not follow this guide for a production install — it is written for getting a working
instance on a workstation. [DEPLOYMENT.md](DEPLOYMENT.md) owns production: server
requirements, the annotated production `.env`, file permissions, `optimize` caching,
Supervisor queue workers, Nginx and Apache vhosts, SSL, the cron entry, log rotation,
backups, the security checklist, and the update/rollback procedure.

## Support & Contributing

For issues, feature requests, or contributions, please refer to the repository guidelines.

## License

MIT License - See LICENSE file for details.
