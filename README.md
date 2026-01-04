# ORCA DAM - ORCA Retrieves Cloud Assets

A Digital Asset Management system for AWS S3 with AI-powered tagging.

## Features

- 🔐 Multi-user support (Editors & Admins)
- 📁 Direct S3 bucket integration
- 🏷️ Manual and AI-powered tagging (AWS Rekognition)
- 🔍 Advanced search and filtering
- 🖼️ Thumbnail generation and grid view
- 📤 Multi-file upload with drag & drop
- 🔗 Easy URL copying for external integration
- 🔎 Discover unmapped S3 objects
- 📱 Responsive design
- 🚀 API-ready for Rich Text Editor integration

## Installation

### Prerequisites
- PHP 8.1+
- Composer
- MySQL/PostgreSQL
- Node.js & NPM
- AWS Account with S3 bucket

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
AWS_REKOGNITION_ENABLED=true
```

5. Run migrations
```bash
php artisan migrate
```

6. Create admin user
```bash
php artisan db:seed --class=AdminUserSeeder
```

7. Compile assets
```bash
npm run dev
```

8. Start development server
```bash
php artisan serve
```

## Usage

### User Roles

**Editors:**
- Upload and manage their own assets
- Add tags
- Search and browse all assets
- Copy URLs

**Admins:**
- All editor permissions
- Manage all assets (edit/delete)
- User management
- Discover unmapped S3 objects
- Batch operations

### Discovering Unmapped Objects

1. Navigate to Admin > Discover
2. Click "Scan Bucket"
3. Review unmapped objects
4. Select objects to import
5. AI tags will be automatically generated

### API Endpoints

For RTE integration:

```
GET    /api/assets              - List assets (paginated)
POST   /api/assets              - Upload assets
GET    /api/assets/{id}         - Get asset details
PATCH  /api/assets/{id}         - Update asset metadata
DELETE /api/assets/{id}         - Delete asset
GET    /api/assets/search       - Search with filters
GET    /api/tags                - List tags for autocomplete
```

Authentication: Laravel Sanctum (SPA token)

## Architecture

- **Backend:** Laravel 10+ with AWS SDK
- **Frontend:** Blade templates + Alpine.js
- **Styling:** Tailwind CSS
- **Image Processing:** Intervention Image
- **AI Tagging:** AWS Rekognition
- **Storage:** AWS S3 (public-read bucket)

## File Structure

```
orca-dam/
├── app/
│   ├── Http/Controllers/
│   │   ├── AssetController.php
│   │   ├── TagController.php
│   │   ├── DiscoverController.php
│   │   └── Api/AssetApiController.php
│   ├── Services/
│   │   ├── S3Service.php
│   │   └── RekognitionService.php
│   ├── Models/
│   │   ├── Asset.php
│   │   ├── Tag.php
│   │   └── User.php
│   └── Policies/
│       └── AssetPolicy.php
├── database/migrations/
├── resources/views/
│   ├── layouts/app.blade.php
│   ├── components/
│   └── assets/
└── routes/
    ├── web.php
    └── api.php
```

## License

MIT License

## Credits

Built with ❤️ for managing cloud assets efficiently.
