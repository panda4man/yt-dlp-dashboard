#!/bin/sh
set -e
mkdir -p storage/app/private/downloads
chown -R www-data:www-data storage/app/private/downloads
php artisan config:clear
php artisan migrate --force
php-fpm -D
exec nginx -g 'daemon off;'
