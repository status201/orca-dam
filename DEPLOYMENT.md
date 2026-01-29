# ORCA DAM - Production Deployment Guide

This guide covers deploying ORCA DAM to a production environment.

## Prerequisites

- PHP 8.4+ with required extensions (GD, SQLite/MySQL, curl, mbstring, xml, zip)
- Composer
- Node.js & NPM (for asset compilation)
- Web server (Nginx or Apache)
- Supervisor (for queue workers)
- AWS S3 bucket with proper IAM credentials
- (Optional) AWS Rekognition for AI tagging

## Server Requirements

### PHP Configuration

Edit your `php.ini` file with these minimum settings:

```ini
memory_limit = 256M
upload_max_filesize = 100M
post_max_size = 100M
max_execution_time = 300
```

### Required PHP Extensions

```bash
php -m | grep -E 'gd|pdo|mbstring|xml|curl|zip'
```

All of these should be present.

## Deployment Steps

### 1. Clone Repository

```bash
cd /var/www
git clone https://github.com/yourusername/orca-dam.git
cd orca-dam
```

### 2. Install Dependencies

```bash
# Install PHP dependencies
composer install --no-dev --optimize-autoloader

# Install Node dependencies and build assets
npm install
npm run build
```

### 3. Configure Environment

```bash
# Copy example environment file
cp .env.example .env

# Generate application key
php artisan key:generate

# Edit .env with production values
nano .env
```

**Important `.env` settings for production:**

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

# Database
DB_CONNECTION=mysql  # or sqlite for smaller deployments
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=orca_dam
DB_USERNAME=orca_user
DB_PASSWORD=your-secure-password

# Queue
QUEUE_CONNECTION=database

# AWS S3
AWS_ACCESS_KEY_ID=your-access-key
AWS_SECRET_ACCESS_KEY=your-secret-key
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=your-bucket-name
AWS_URL=https://your-bucket-name.s3.us-east-1.amazonaws.com

# AWS Rekognition (optional)
AWS_REKOGNITION_ENABLED=true
AWS_REKOGNITION_MAX_LABELS=3
AWS_REKOGNITION_MIN_CONFIDENCE=80
AWS_REKOGNITION_LANGUAGE=nl

# JWT Authentication (optional, for frontend RTE integrations)
JWT_ENABLED=false              # Enable JWT auth alongside Sanctum
JWT_ALGORITHM=HS256            # Signature algorithm
JWT_MAX_TTL=36000              # Max token lifetime (10 hours)
JWT_LEEWAY=60                  # Clock skew tolerance
JWT_ISSUER=                    # Optional: Required issuer claim
```

### 4. Set Permissions

```bash
# Set ownership (adjust user/group as needed)
chown -R www-data:www-data /var/www/orca-dam

# Set directory permissions
chmod -R 755 /var/www/orca-dam
chmod -R 775 /var/www/orca-dam/storage
chmod -R 775 /var/www/orca-dam/bootstrap/cache
```

### 5. Run Migrations

```bash
php artisan migrate --force
```

### 6. Create Admin User

```bash
php artisan db:seed --class=AdminUserSeeder
```

Default credentials:
- Email: `admin@example.com`
- Password: `password`

**⚠️ Important:** Change the admin password immediately after first login!

### 7. Optimize for Production

```bash
# Cache configuration
php artisan config:cache

# Cache routes
php artisan route:cache

# Cache views
php artisan view:cache

# Optimize framework
php artisan optimize
```

## Queue Worker Setup with Supervisor

### 1. Install Supervisor

**Ubuntu/Debian:**
```bash
apt-get install supervisor
```

**CentOS/RHEL:**
```bash
yum install supervisor
systemctl enable supervisord
systemctl start supervisord
```

### 2. Configure Queue Worker

Copy the supervisor configuration:

```bash
cp deploy/supervisor/orca-queue-worker.conf /etc/supervisor/conf.d/
```

**Edit the configuration file** `/etc/supervisor/conf.d/orca-queue-worker.conf`:

```ini
[program:orca-queue-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/orca-dam/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/orca-dam/storage/logs/queue-worker.log
stopwaitsecs=3600
```

**Configuration Options Explained:**

- `command`: The artisan command to run
  - `--sleep=3`: Sleep 3 seconds when no jobs available
  - `--tries=3`: Retry failed jobs up to 3 times
  - `--max-time=3600`: Restart worker every hour (prevents memory leaks)
- `numprocs=2`: Run 2 worker processes (adjust based on load)
- `user=www-data`: Run as web server user (adjust for your system)
- `autostart=true`: Start workers on system boot
- `autorestart=true`: Restart workers if they crash
- `stopwaitsecs=3600`: Wait up to 1 hour for graceful shutdown

**Adjust paths:**
- Replace `/var/www/orca-dam` with your actual path
- Replace `www-data` with your web server user (might be `nginx`, `apache`, etc.)

### 3. Start Supervisor

```bash
# Reload supervisor configuration
supervisorctl reread
supervisorctl update

# Start the queue workers
supervisorctl start orca-queue-worker:*

# Check status
supervisorctl status
```

### 4. Managing Queue Workers

**View Status:**
```bash
supervisorctl status orca-queue-worker:*
```

**Restart Workers:**
```bash
supervisorctl restart orca-queue-worker:*
```

**Stop Workers:**
```bash
supervisorctl stop orca-queue-worker:*
```

**View Logs:**
```bash
tail -f /var/www/orca-dam/storage/logs/queue-worker.log
```

## Web Server Configuration

### Nginx Configuration

Create `/etc/nginx/sites-available/orca-dam`:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name your-domain.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name your-domain.com;
    root /var/www/orca-dam/public;

    # SSL Certificate (use Let's Encrypt)
    ssl_certificate /etc/letsencrypt/live/your-domain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/your-domain.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    # File upload limits
    client_max_body_size 100M;

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;

        # Increase timeouts for large uploads
        fastcgi_read_timeout 300;
        fastcgi_send_timeout 300;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

**Enable the site:**
```bash
ln -s /etc/nginx/sites-available/orca-dam /etc/nginx/sites-enabled/
nginx -t
systemctl restart nginx
```

### Apache Configuration

Create `/etc/apache2/sites-available/orca-dam.conf`:

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    Redirect permanent / https://your-domain.com/
</VirtualHost>

<VirtualHost *:443>
    ServerName your-domain.com
    DocumentRoot /var/www/orca-dam/public

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/your-domain.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/your-domain.com/privkey.pem

    <Directory /var/www/orca-dam/public>
        AllowOverride All
        Require all granted
    </Directory>

    # Security headers
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-XSS-Protection "1; mode=block"

    # PHP settings
    php_value upload_max_filesize 100M
    php_value post_max_size 100M
    php_value max_execution_time 300
    php_value memory_limit 256M

    ErrorLog ${APACHE_LOG_DIR}/orca-dam-error.log
    CustomLog ${APACHE_LOG_DIR}/orca-dam-access.log combined
</VirtualHost>
```

**Enable the site:**
```bash
a2ensite orca-dam
a2enmod rewrite ssl headers
apache2ctl configtest
systemctl restart apache2
```

## SSL Certificate (Let's Encrypt)

```bash
# Install Certbot
apt-get install certbot python3-certbot-nginx  # For Nginx
# OR
apt-get install certbot python3-certbot-apache  # For Apache

# Obtain certificate
certbot --nginx -d your-domain.com  # For Nginx
# OR
certbot --apache -d your-domain.com  # For Apache

# Auto-renewal is configured automatically
```

## Post-Deployment

### 1. Test the Application

- Visit `https://your-domain.com`
- Login with admin credentials
- Test file upload
- Check queue status in System admin page
- Test AI tagging (if enabled)

### 2. Configure API Authentication (if needed)

**For Sanctum Tokens (backend integrations):**
```bash
# Via CLI
php artisan token:create user@email.com --name="My Integration"

# Or via Admin Panel: API Docs → Tokens
```

**For JWT Authentication (frontend integrations):**
1. Enable JWT in `.env`: `JWT_ENABLED=true`
2. Generate a JWT secret for the user:
   ```bash
   php artisan jwt:generate user@email.com
   ```
   Or via Admin Panel: API Docs → JWT Secrets
3. Share the secret with your external system securely
4. External system generates short-lived JWTs for API requests

### 3. Monitor Queue Workers

Check that workers are running:
```bash
supervisorctl status orca-queue-worker:*
```

Should show:
```
orca-queue-worker:orca-queue-worker_00   RUNNING   pid 12345, uptime 0:00:05
orca-queue-worker:orca-queue-worker_01   RUNNING   pid 12346, uptime 0:00:05
```

### 4. Setup Log Rotation

Create `/etc/logrotate.d/orca-dam`:

```
/var/www/orca-dam/storage/logs/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0640 www-data www-data
    sharedscripts
    postrotate
        supervisorctl restart orca-queue-worker:* > /dev/null 2>&1 || true
    endscript
}
```

### 5. Setup Scheduled Tasks (Cron)

Add to crontab (`crontab -e -u www-data`):

```cron
* * * * * cd /var/www/orca-dam && php artisan schedule:run >> /dev/null 2>&1
```

## Updating the Application

### Standard Update Process

```bash
# Navigate to application directory
cd /var/www/orca-dam

# Put application in maintenance mode
php artisan down

# Pull latest changes
git pull origin main

# Update dependencies
composer install --no-dev --optimize-autoloader
npm install
npm run build

# Run migrations
php artisan migrate --force

# Clear and rebuild caches
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize

# Restart queue workers (important!)
supervisorctl restart orca-queue-worker:*

# Bring application back online
php artisan up
```

### Zero-Downtime Deployment (Advanced)

For zero-downtime deployments, consider using:
- **Laravel Envoyer** (paid service)
- **Deployer** (open source)
- **Custom deployment scripts** with blue-green deployment

## Monitoring & Maintenance

### System Administration Panel

Access the admin panel at: `https://your-domain.com/system`

**Features:**
- Queue status monitoring (pending, failed, batches)
- Queue management (retry, flush, restart)
- Log viewer (last 20-200 lines)
- Artisan command executor
- System diagnostics (S3 connection test, PHP config)

### Important Commands from Admin Panel

- **queue:restart** - Gracefully restart all queue workers
- **queue:retry all** - Retry all failed jobs
- **queue:flush** - Delete all failed jobs
- **cache:clear** - Clear application cache
- **config:clear** - Clear configuration cache
- **migrate:status** - Check migration status

### Health Checks

**Application Health:**
```bash
curl https://your-domain.com
```

**Queue Worker Health:**
```bash
supervisorctl status orca-queue-worker:*
```

**Database Check:**
```bash
php artisan migrate:status
```

**S3 Connection:**
Use the System admin panel → Diagnostics → Test S3 Connection

### Log Monitoring

**Application Logs:**
```bash
tail -f /var/www/orca-dam/storage/logs/laravel.log
```

**Queue Worker Logs:**
```bash
tail -f /var/www/orca-dam/storage/logs/queue-worker.log
```

**Nginx/Apache Logs:**
```bash
tail -f /var/log/nginx/error.log
# OR
tail -f /var/log/apache2/error.log
```

## Troubleshooting

### Queue Workers Not Processing Jobs

**Check supervisor status:**
```bash
supervisorctl status orca-queue-worker:*
```

**Restart workers:**
```bash
supervisorctl restart orca-queue-worker:*
```

**Check logs:**
```bash
tail -f /var/www/orca-dam/storage/logs/queue-worker.log
```

### File Upload Failures

**Check PHP limits:**
```bash
php -i | grep -E 'upload_max_filesize|post_max_size|memory_limit'
```

**Check web server limits:**
- Nginx: `client_max_body_size 100M;`
- Apache: `php_value upload_max_filesize 100M`

**Check S3 credentials:**
```bash
php artisan tinker
>>> Storage::disk('s3')->exists('test.txt');
```

### Permission Issues

**Reset permissions:**
```bash
chown -R www-data:www-data /var/www/orca-dam
chmod -R 755 /var/www/orca-dam
chmod -R 775 /var/www/orca-dam/storage
chmod -R 775 /var/www/orca-dam/bootstrap/cache
```

### Cache Issues

**Clear all caches:**
```bash
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
```

## Security Checklist

- [ ] Change default admin password
- [ ] Set `APP_DEBUG=false` in production
- [ ] Use strong `APP_KEY` (generated with `php artisan key:generate`)
- [ ] Enable HTTPS with valid SSL certificate
- [ ] Restrict S3 bucket access (use IAM policies, not public buckets)
- [ ] Regularly update dependencies (`composer update`, `npm update`)
- [ ] Enable Laravel's CSRF protection (enabled by default)
- [ ] Configure firewall (allow only ports 80, 443, 22)
- [ ] Use strong database passwords
- [ ] Regularly backup database and storage
- [ ] Monitor logs for suspicious activity
- [ ] Keep PHP and server software updated
- [ ] Securely share JWT secrets with external systems (never expose in frontend)
- [ ] Use short JWT token lifetimes (default: 1 hour recommended)

## Backup Strategy

### Database Backup

**Daily automated backup:**
```bash
#!/bin/bash
# /usr/local/bin/orca-backup.sh
DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR=/var/backups/orca-dam
mkdir -p $BACKUP_DIR

# Backup database
mysqldump -u orca_user -p'password' orca_dam | gzip > $BACKUP_DIR/db_$DATE.sql.gz

# Keep last 7 days
find $BACKUP_DIR -name "db_*.sql.gz" -mtime +7 -delete
```

**Add to cron** (`crontab -e`):
```cron
0 2 * * * /usr/local/bin/orca-backup.sh
```

### S3 Backup

Your assets are already in S3, which is highly durable. Consider:
- Enable **S3 Versioning** for your bucket
- Configure **S3 Lifecycle policies** for cost optimization
- Use **S3 Cross-Region Replication** for disaster recovery

## Support & Documentation

- **CLAUDE.md** - Development guidelines and architecture
- **RTE_INTEGRATION.md** - Rich text editor integration guide
- **USER_MANUAL.md** - End-user documentation
- **QUICK_REFERENCE.md** - Quick command reference
- **System Admin Panel** - `https://your-domain.com/system`
- **API Documentation** - `https://your-domain.com/api-docs`

For issues or questions, refer to the project repository.
