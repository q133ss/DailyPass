#!/bin/sh
set -eu

echo "Waiting for PostgreSQL at ${DB_HOST}:${DB_PORT}..."
i=0
until php -r '
    $dsn = sprintf("pgsql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT"), getenv("DB_DATABASE"));
    new PDO($dsn, getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
' >/dev/null 2>&1; do
    i=$((i + 1))
    if [ "$i" -ge 60 ]; then
        echo "PostgreSQL did not become ready in time" >&2
        exit 1
    fi
    sleep 1
done

php artisan migrate --force

exec php artisan serve --host=0.0.0.0 --port=8000 --no-reload
