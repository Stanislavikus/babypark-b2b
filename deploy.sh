#!/bin/bash
cd /var/www/babypark-b2b
git pull
npm run build
php artisan optimize:clear
echo "Готово. Поточна гілка: $(git branch --show-current)"
