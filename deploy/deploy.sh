#!/usr/bin/env bash
# Memperbarui aplikasi di server setelah ada perubahan kode di GitHub. Jalankan sebagai root:
#   sudo bash /var/www/peta/deploy/deploy.sh
# Catatan: perubahan struktur basis data (berkas db/*.sql) tidak otomatis dijalankan; terapkan secara manual
# dan dengan cadangan terlebih dahulu (sudo /usr/local/sbin/peta-cadangkan).
set -euo pipefail
APP_DIR="${APP_DIR:-/var/www/peta}"
[[ $EUID -eq 0 ]] || { echo "Jalankan sebagai root." >&2; exit 1; }
cd "$APP_DIR"
echo "Menarik kode terbaru..."
git pull --ff-only
cd web
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction
chown -R www-data:www-data storage bootstrap/cache
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
systemctl reload "php$PHPV-fpm"
echo "Selesai. Versi: $(git -C "$APP_DIR" log --oneline -1)"
