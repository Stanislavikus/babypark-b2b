#!/bin/bash
set -e

cd /var/www/babypark-b2b

echo "== Перевіряю, які міграції ще не застосовані =="
php artisan migrate:status | grep -i pending || echo "Немає нових міграцій — нічого запускати не треба."

read -p "Зробити бекап і застосувати міграції? (yes/no): " CONFIRM
if [ "$CONFIRM" != "yes" ]; then
  echo "Скасовано."
  exit 0
fi

mkdir -p /backups
BACKUP_FILE="/backups/pre_migrate_$(date +%Y%m%d_%H%M).sql.gz"
echo "== Роблю бекап у $BACKUP_FILE =="
mysqldump -u "$(grep DB_USERNAME .env | cut -d '=' -f2)" \
          -p"$(grep DB_PASSWORD .env | cut -d '=' -f2)" \
          "$(grep DB_DATABASE .env | cut -d '=' -f2)" | gzip > "$BACKUP_FILE"
echo "Бекап готовий: $(ls -la $BACKUP_FILE)"

echo "== Застосовую міграції =="
php artisan migrate --force

echo "== Готово =="
