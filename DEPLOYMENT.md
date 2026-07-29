# Hogan Guards Attendance Portal — Deployment Checklist

## 1. Server Requirements
- PHP 8.2+ with extensions: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `fileinfo`, `gd`, `zip`
- Composer 2
- Node.js 20+ and npm
- MySQL 8+ (or compatible)
- Apache/Nginx with mod_rewrite (or use Laravel's built-in server for testing)

## 2. Environment Setup
```bash
cp .env.example .env
php artisan key:generate --force
```

Edit `.env`:
- Set `APP_ENV=production`
- Set `APP_DEBUG=false`
- Set `APP_URL` to your actual domain
- Configure database credentials
- Configure mail settings
- Set `SANCTUM_STATEFUL_DOMAINS` to your domain(s)

## 3. Install Dependencies
```bash
composer install --no-dev --optimize-autoloader
npm install
npm run build
```

## 4. Database
```bash
php artisan migrate --force
php artisan db:seed --force
php artisan storage:link
```

## 5. Permissions
```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

## 6. Web Server Configuration

### Apache
- DocumentRoot should point to `/path/to/project/public`
- Enable `mod_rewrite`
- Set `.htaccess` to deny access to sensitive files

### Nginx
```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/project/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    add_header X-XSS-Protection "1; mode=block";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
```

## 7. Laravel Optimization
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## 8. Security
- Change default admin password immediately
- Use strong database passwords
- Enable HTTPS (SSL certificate)
- Restrict `/api/scan` IPs in Settings if needed
- Set up regular database backups

## 9. Testing
- Test scan terminal: http://your-domain.com/scan
- Test admin login: http://your-domain.com/dashboard
- Test Excel export with colored formatting
- Test QR/barcode downloads
- Test on mobile devices

## 10. Scheduled Tasks (Optional)
If using scheduled reports, add to crontab:
```bash
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

## Default Credentials (CHANGE IMMEDIATELY)
- superadmin / super123
- admin / admin123
