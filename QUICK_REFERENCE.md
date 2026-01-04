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

# Start development
php artisan serve
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
│   │   ├── Tag.php                   # Tag model
│   │   └── User.php                  # User model
│   ├── Services/
│   │   ├── S3Service.php             # S3 operations
│   │   └── RekognitionService.php    # AI tagging
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

# AI Tagging
AWS_REKOGNITION_ENABLED=true|false

# Database
DB_CONNECTION=mysql
DB_DATABASE=orca_dam
DB_USERNAME=root
DB_PASSWORD=

# App
APP_ENV=local|production
APP_DEBUG=true|false
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

---

## Troubleshooting Quick Fixes

### Can't upload files
1. Check `.env` AWS credentials
2. Verify S3 bucket is public-read
3. Check PHP upload limits

### No thumbnails
1. Install GD: `apt-get install php-gd`
2. Check S3 write permissions
3. Review logs

### AI tags not working
1. Set `AWS_REKOGNITION_ENABLED=true`
2. Check Rekognition IAM permissions
3. Ensure images are in S3 bucket

### Discovery shows nothing
1. Verify S3 bucket name in `.env`
2. Check `s3:ListBucket` permission
3. Ensure files are in `assets/` prefix

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
