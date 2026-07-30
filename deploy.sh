#!/bin/bash
set -e

cd /var/www/babypark-b2b
git pull
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan optimize:clear
php artisan queue:restart
echo "Готово. Поточна гілка: $(git branch --show-current)"
