# Microi Production Deployment Guide

## 1. Hosting & Prerequisites
- **Domain:** `micro.micromek.net`
- **Operating Environment:** Linux (Ubuntu 22.04+ / 24.04 LTS), PHP 8.3+, Nginx, MySQL 8.0+ or SQLite / PostgreSQL.
- **Node.js:** Node.js v20+ & npm for asset compilation.
- **Certificates:** Valid TLS/HTTPS certificate (e.g. Let's Encrypt Certbot) required for secure GPS IoT transmission.

## 2. Environment Configuration
Ensure `.env` contains:
```env
APP_NAME=Microi
APP_ENV=production
APP_KEY=base64:...
APP_DEBUG=false
APP_URL=https://micro.micromek.net

DB_CONNECTION=sqlite
# Or MySQL
# DB_CONNECTION=mysql
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=microi
# DB_USERNAME=microi_user
# DB_PASSWORD=secure_password

CACHE_STORE=database
QUEUE_CONNECTION=database
SESSION_DRIVER=database
```

## 3. Deployment Commands
```bash
# 1. Clone repository
git clone git@github.com:micromek/microi.git /var/www/microi
cd /var/www/microi

# 2. Install production dependencies
composer install --no-dev --optimize-autoloader
npm ci

# 3. Compile frontend production bundle
npm run build

# 4. Migrate database
php artisan migrate --force

# 5. Seed default administrative credentials (First-time setup only)
php artisan db:seed --class=MicroiDatabaseSeeder --force

# 6. Optimize Laravel framework
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## 4. Hardware Gateway Configuration
MicroMek GPS trackers transmitting location packets over cellular 2G/4G/LTE-M should be directed to:
- Endpoint: `https://micro.micromek.net/api/v1/device/telemetry`
- Method: `POST`
- Content-Type: `application/json`
