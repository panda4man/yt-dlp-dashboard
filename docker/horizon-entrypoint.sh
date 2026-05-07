#!/bin/sh
set -e
mkdir -p storage/app/private/downloads
chown -R www-data:www-data storage/app/private/downloads
exec php artisan horizon
