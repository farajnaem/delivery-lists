#!/bin/bash
set -e

cd /var/www/html

PORT="${PORT:-80}"
if [ "$PORT" != "80" ]; then
    echo "Configuring Apache to listen on port ${PORT}..."
    sed -i "s/^Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
    sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
fi

mkdir -p storage/uploads storage/exports database
chown -R www-data:www-data storage database 2>/dev/null || true

# مفتاح ثابت لجلسات تطبيق الموبايل — ضروري عبر إعادة النشر
if [ -z "${APP_KEY:-}" ]; then
    if [ -f storage/.app_key ]; then
        APP_KEY="$(tr -d '\r\n' < storage/.app_key)"
        export APP_KEY
        echo "Loaded APP_KEY from storage/.app_key"
    else
        APP_KEY="$(php -r 'echo bin2hex(random_bytes(32));')"
        printf '%s' "$APP_KEY" > storage/.app_key
        chown www-data:www-data storage/.app_key 2>/dev/null || true
        export APP_KEY
        echo "WARNING: Generated APP_KEY into storage/.app_key — set APP_KEY in Coolify to keep mobile sessions after redeploy."
    fi
fi

has_mysql_config() {
    [ -n "${DATABASE_URL:-}" ] \
        || [ -n "${MYSQL_URL:-}" ] \
        || [ -n "${DB_URL:-}" ] \
        || [ -n "${MYSQL_HOST:-}" ] \
        || [ -n "${MYSQLHOST:-}" ] \
        || { [ -n "${DB_HOST:-}" ] && [ "${DB_DRIVER:-}" = "mysql" ]; }
}

uses_mysql() {
    # أي إعداد اتصال MySQL صريح له الأولوية (يطابق منطق config في التطبيق)
    if [ -n "${DATABASE_URL:-}" ] \
        || [ -n "${MYSQL_URL:-}" ] \
        || [ -n "${DB_URL:-}" ] \
        || [ -n "${MYSQL_HOST:-}" ] \
        || [ -n "${MYSQLHOST:-}" ]; then
        return 0
    fi
    if [ "${DB_DRIVER:-}" = "sqlite" ]; then
        return 1
    fi
    has_mysql_config
}

if uses_mysql; then
    echo "Waiting for MySQL..."
    attempts=0
    max_attempts=45
    until php docker/wait-db.php; do
        attempts=$((attempts + 1))
        if [ "$attempts" -ge "$max_attempts" ]; then
            echo "WARNING: MySQL not ready — Apache will start anyway."
            break
        fi
        sleep 2
    done
fi

echo "Initializing database schema..."
php database/install.php || echo "WARNING: DB init failed."

echo "Starting Apache on port ${PORT}..."
exec "$@"
