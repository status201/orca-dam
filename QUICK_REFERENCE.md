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
│   ├── Http/Controllers/
│   │   ├── AssetController.php       # Main asset CRUD
│   │   ├── DiscoverController.php    # S3 discovery
│   │   ├── TagController.php         # Tag management
│   │   └── Api/
│   │       └── AssetApiController.php # API endpoints
│   ├── Models/
│   │   ├── Asset.php                 # Asset model
│   │   ├── Setting.php               # Application settings
│   │   ├── Tag.php                   # Tag model
│   │   └── User.php                  # User model
│   ├── Services/
│   │   ├── S3Service.php             # S3 operations
│   │   ├── RekognitionService.php    # AI tagging
│   │   └── SystemService.php         # System admin services
│   └── Policies/
│       └── AssetPolicy.php           # Authorization
├── database/migrations/              # Database schema
├── resources/views/
│   ├── layouts/app.blade.php         # Main layout
│   ├── assets/                       # Asset views
│   ├── discover/                     # Discovery views
│   └── tags/                         # Tag views
├── routes/
│   ├── web.php                       # Web routes
│   └── api.php                       # API routes
├── tests/
│   ├── Feature/                      # Feature tests
│   │   ├── AssetTest.php
│   │   ├── TagTest.php
│   │   ├── ExportTest.php
│   │   └── ApiTest.php
│   └── Unit/                         # Unit tests
│       ├── AssetTest.php
│       ├── TagTest.php
│       └── SettingTest.php
└── config/                           # Configuration
```

---

## Key Routes

### Web Routes
```
GET  /assets                   # List assets
GET  /assets/create            # Upload form
POST /assets                   # Store assets
GET  /assets/{id}              # View asset
GET  /assets/{id}/edit         # Edit form
PATCH /assets/{id}             # Update asset
DELETE /assets/{id}            # Delete asset
GET  /discover                 # Discovery page (admin)
POST /discover/scan            # Scan S3 bucket
POST /discover/import          # Import objects
GET  /tags                     # List tags
GET  /system                   # System admin (admin)
POST /system/settings          # Update settings (admin)
POST /system/run-tests         # Run automated tests (admin)
```

### API Routes
```
GET    /api/assets             # List assets
POST   /api/assets             # Upload assets
GET    /api/assets/search      # Search assets
GET    /api/assets/{id}        # Get asset
PATCH  /api/assets/{id}        # Update asset
DELETE /api/assets/{id}        # Delete asset
GET    /api/tags               # List tags
```

---

## Database Schema

### users
- id, name, email, password, role (editor|admin)

### assets
- id, s3_key, filename, mime_type, size
- width, height, thumbnail_s3_key
- alt_text, caption, user_id
- created_at, updated_at, deleted_at

### tags
- id, name, type (user|ai), created_at, updated_at

### asset_tag
- asset_id, tag_id, created_at, updated_at

### settings
- id, key (unique), value, type, group, description
- Default settings: items_per_page, rekognition_max_labels, rekognition_language

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
AWS_REKOGNITION_MAX_LABELS=5
AWS_REKOGNITION_LANGUAGE=en

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
```

---

## User Permissions

### Editor
✅ Upload assets
✅ View all assets
✅ Edit/delete own assets
✅ Add tags to any asset
✅ Search and filter

### Admin
✅ All editor permissions
✅ Edit/delete any asset
✅ Access Discover feature
✅ Manage users
✅ Bulk operations
✅ System administration & settings

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
├── assets/
│   ├── {uuid}.jpg          # Original files
│   ├── {uuid}.png
│   └── ...
└── thumbnails/
    ├── {uuid}_thumb.jpg    # Generated thumbnails
    ├── {uuid}_thumb.png
    └── ...
```

---

## Useful Artisan Commands

```bash
# Create new admin
php artisan tinker
> User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => Hash::make('password'), 'role' => 'admin']);

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

---

## Support Resources

- **Documentation**: See README.md, SETUP_GUIDE.md
- **API Integration**: See RTE_INTEGRATION.md
- **Laravel Docs**: https://laravel.com/docs
- **AWS S3 Docs**: https://docs.aws.amazon.com/s3/
- **AWS Rekognition**: https://docs.aws.amazon.com/rekognition/
