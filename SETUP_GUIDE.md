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

For handling large file uploads (PDFs, GIFs, videos), you need to increase PHP limits:

**For Laravel Herd users:**
1. Locate your Herd PHP configuration file:
   - **macOS/Linux**: `~/.config/herd/bin/php84/php.ini`
   - **Windows**: `C:\Users\<username>\.config\herd\bin\php84\php.ini`
   - **To find yours**: Run `php --ini` and check "Loaded Configuration File"
2. Edit the following values:
   ```ini
   memory_limit = 256M
   upload_max_filesize = 100M
   post_max_size = 100M
   max_execution_time = 300
   max_input_time = 300
   ```
3. **Restart Herd** from the system tray (Stop/Start or Restart all services)

**For Apache/Nginx/php-fpm users:**
Create a `.user.ini` file in the `public/` directory:
```ini
memory_limit = 256M
upload_max_filesize = 100M
post_max_size = 100M
max_execution_time = 300
```
Then restart your web server.

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
AWS_REKOGNITION_MAX_LABELS=5             # Maximum AI tags per asset (default: 5)
AWS_REKOGNITION_LANGUAGE=en              # Language for AI tags: en, nl, fr, de, es, etc.
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
                "s3:GetBucketLocation"
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
- **Editors**: Upload, manage own assets, view all
- **Admins**: Full access, discover feature, manage all assets

### 6. API for RTE Integration
- ✅ RESTful API endpoints
- ✅ Laravel Sanctum authentication
- ✅ Pagination support
- ✅ Search and filter API
- ✅ Public metadata endpoint (no auth required)

### 7. Export & Reporting
- ✅ CSV export with all asset metadata
- ✅ Separate columns for user tags and AI tags
- ✅ Includes license type and copyright information
- ✅ Filter by file type and tags before export
- ✅ Timestamped export filenames

---

## API Documentation

### Authentication
Use Laravel Sanctum for API authentication:

```javascript
// Generate token (in your app)
const token = await user.createToken('rte-integration').plainTextToken;

// Use in requests
headers: {
    'Authorization': `Bearer ${token}`,
    'Accept': 'application/json'
}
```

### Endpoints

#### List Assets
```
GET /api/assets?search=keyword&tags[]=1,2&type=image&per_page=24
```

#### Upload Assets
```
POST /api/assets
Content-Type: multipart/form-data

files[]: File[]
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

### 2. Enabling AI Tagging
Set in `.env`:
```env
AWS_REKOGNITION_ENABLED=true
AWS_REKOGNITION_MAX_LABELS=5       # Maximum AI tags per asset
AWS_REKOGNITION_LANGUAGE=en        # Language for AI tags (en, nl, fr, de, es, etc.)
```

AI tags are generated automatically on upload and run in a background job queue. They are marked with a purple color in the UI.

**Manual AI Tagging:**
- You can manually trigger AI tag generation on any image asset
- Go to the asset edit page
- Click "Generate AI Tags" button in the purple AI Tags section
- This will queue a job to detect and translate tags
- Existing AI tags will be replaced with new ones

### 3. Copying URLs
Click the copy icon on any asset thumbnail or use the copy button on the asset detail page. URLs are public and permanent (configured via bucket policy for public read access).

### 4. Batch Operations
Select multiple unmapped objects in Discover to import in bulk.

---

## Customization

### Changing Upload Limits
In `AssetController.php`:
```php
'files.*' => 'required|file|max:102400', // 100MB default (in KB)
```

Also update your PHP configuration:
- **For Herd:** Edit `~/.config/herd/bin/php84/php.ini`
- **For Apache/Nginx:** Edit `public/.user.ini`

```ini
upload_max_filesize = 100M  # Match your max file size
post_max_size = 100M        # Match or exceed upload_max_filesize
memory_limit = 256M         # Should be at least 2.5x your max file size
```

Then restart your web server.

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
For scheduled tasks:
```bash
* * * * * cd /path-to-orca && php artisan schedule:run >> /dev/null 2>&1
```

### 4. Security Checklist
- [ ] Change default admin password
- [ ] Set `APP_ENV=production` and `APP_DEBUG=false`
- [ ] Use strong `APP_KEY`
- [ ] Enable HTTPS
- [ ] Verify PHP memory/upload limits are properly configured (256MB minimum)
- [ ] Restrict IAM permissions to minimum required
- [ ] Set up regular backups
- [ ] Configure rate limiting

---

## Support & Contributing

For issues, feature requests, or contributions, please refer to the repository guidelines.

## License

MIT License - See LICENSE file for details.
