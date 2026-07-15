#!/bin/sh
set -e

cd /var/www/html

# Keep .env aligned with docker-compose DB/cache (host .env often has 127.0.0.1).
set_env() {
    key="$1"
    value="$2"
    if grep -q "^${key}=" .env 2>/dev/null; then
        sed -i "s|^${key}=.*|${key}=${value}|" .env
    else
        printf '%s=%s\n' "$key" "$value" >> .env
    fi
}

if [ ! -f .env ]; then
    cp .env.example .env
fi

set_env DB_HOST "${DB_HOST:-mysql}"
set_env DB_PORT "${DB_PORT:-3306}"
set_env DB_DATABASE "${DB_DATABASE:-hg_attendance}"
set_env DB_USERNAME "${DB_USERNAME:-root}"
set_env DB_PASSWORD "${DB_PASSWORD:-root}"
set_env CACHE_STORE "${CACHE_STORE:-file}"
set_env SESSION_DRIVER "${SESSION_DRIVER:-file}"
set_env QUEUE_CONNECTION "${QUEUE_CONNECTION:-sync}"

if [ ! -d vendor ]; then
    composer install --no-interaction --prefer-dist --no-progress --no-blocking
fi

if [ ! -f public/build/manifest.json ]; then
    npm ci --no-audit --no-fund
    npm run build
fi

if ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
    php artisan key:generate --force
fi

php artisan config:clear 2>/dev/null || true
php artisan migrate --force --seed
php artisan storage:link --force || true

# Remove Vite dev marker so production uses public/build (not localhost:5173)
rm -f public/hot

exec php artisan serve --host=0.0.0.0 --port=8000
