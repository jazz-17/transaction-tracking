#!/bin/sh
set -e

php artisan config:cache
php artisan route:cache

exec "$@"
