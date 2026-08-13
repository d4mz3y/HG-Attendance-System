#!/bin/sh
set -eu

cd /var/www/html

mkdir -p storage/app/private storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs

require_value() {
    variable_name=$1
    eval "variable_value=\${$variable_name:-}"

    if [ -z "$variable_value" ]; then
        echo "$variable_name must be set in .env.docker; refusing to continue." >&2
        exit 1
    fi
}

require_value APP_KEY
require_value APP_URL
require_value DB_DATABASE
require_value DB_USERNAME
require_value DB_PASSWORD

case "$APP_KEY" in
    base64:*) ;;
    *) echo "APP_KEY must be a generated Laravel base64 key; refusing to continue." >&2; exit 1 ;;
esac

if [ "$#" -gt 0 ]; then
    exec "$@"
fi

php artisan config:clear
php artisan optimize

exec /usr/bin/supervisord -c /etc/supervisor/supervisord.conf
