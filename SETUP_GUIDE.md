# ORCA DAM - Complete Setup Guide

## Quick Start Guide

### Prerequisites
```bash
- PHP 8.1 or higher
- Composer
- Node.js & NPM
- MySQL or PostgreSQL
- AWS Account with S3 bucket (public-read)
```

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
AWS_REKOGNITION_ENABLED=true
```

**Important S3 Bucket Settings:**
- Set bucket to **public-read** ACL
- Enable CORS if accessing from different domains
- Example CORS configuration:
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

Create an IAM user with the following minimum permissions:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": [
                "s3:PutObject",
                "s3:PutObjectAcl",
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
            "Effect": "Allow",
            "Action": [
                "rekognition:DetectLabels",
                "rekognition:DetectText"
            ],
            "Resource": "*"
        }
    ]
}
```

---

## Features Overview

### 1. Asset Management
- ✅ Upload multiple files with drag & drop
- ✅ Automatic thumbnail generation
- ✅ Image dimension detection
- ✅ File size tracking
- ✅ Grid view with pagination
- ✅ Quick URL copying

### 2. Tagging System
- ✅ Manual user tags
- ✅ AI-powered auto-tagging (AWS Rekognition)
- ✅ Tag filtering and search
- ✅ Tag browsing page

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
    "tags": ["tag1", "tag2"]
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
```

AI tags are generated automatically on upload and marked with a robot icon.

### 3. Copying URLs
Click the copy icon on any asset thumbnail or use the copy button on the asset detail page. URLs are public and permanent (public-read bucket).

### 4. Batch Operations
Select multiple unmapped objects in Discover to import in bulk.

---

## Customization

### Changing Upload Limits
In `AssetController.php`:
```php
'files.*' => 'required|file|max:102400', // 100MB default
```

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

### Images not uploading
- Check S3 bucket permissions (public-read)
- Verify AWS credentials in `.env`
- Check PHP `upload_max_filesize` and `post_max_size`

### Thumbnails not generating
- Ensure GD or Imagick PHP extension is installed
- Check S3 write permissions
- Review Laravel logs: `storage/logs/laravel.log`

### AI tagging not working
- Verify Rekognition permissions in IAM
- Check `AWS_REKOGNITION_ENABLED=true` in `.env`
- Ensure bucket is in same region as Rekognition service

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
- [ ] Restrict IAM permissions to minimum required
- [ ] Set up regular backups
- [ ] Configure rate limiting

---

## Support & Contributing

For issues, feature requests, or contributions, please refer to the repository guidelines.

## License

MIT License - See LICENSE file for details.
