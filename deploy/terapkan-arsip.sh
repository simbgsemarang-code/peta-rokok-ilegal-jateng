#!/usr/bin/env bash
# Menerapkan rilis baru dari arsip (peta.tar.gz) ke server yang sedang berjalan. Jalankan sebagai root:
#   bash /var/www/peta/deploy/terapkan-arsip.sh [/root/peta.tar.gz]
# Langkah: ekstrak arsip, perbaiki izin, perbarui dependensi, bangun cache sebagai www-data, muat ulang PHP-FPM,
# lalu uji beberapa halaman. Berkas .env, vendor, storage, dan basis data tidak tertimpa.
set -euo pipefail
APP_DIR="${APP_DIR:-/var/www/peta}"
ARSIP="${1:-/root/peta.tar.gz}"
galat() { echo "GALAT: $*" >&2; exit 1; }
info()  { printf '\n\033[1m==> %s\033[0m\n' "$*"; }

[[ $EUID -eq 0 ]] || galat "jalankan sebagai root."
[[ -f "$ARSIP" ]] || galat "arsip $ARSIP tidak ditemukan (kirim dulu dengan scp)."
[[ -d "$APP_DIR/web" ]] || galat "$APP_DIR/web tidak ada; jalankan deploy/pasang.sh untuk pemasangan awal."
[[ -f "$APP_DIR/web/.env" ]] || galat "$APP_DIR/web/.env tidak ada."

info "1/5 Mengekstrak $(basename "$ARSIP") ($(du -h "$ARSIP" | cut -f1), dibuat $(date -r "$ARSIP" '+%F %T'))"
tar xzf "$ARSIP" -C "$APP_DIR"
cd "$APP_DIR/web"

info "2/5 Izin berkas"
chown -R www-data:www-data storage bootstrap/cache
chown root:www-data .env
chmod 640 .env
chmod -R ug+rwX storage bootstrap/cache

info "3/5 Dependensi (cepat bila tidak ada perubahan)"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction 2>&1 | tail -n 3

info "4/5 Cache aplikasi (dibangun sebagai www-data agar berkas milik www-data)"
sudo -u www-data php artisan optimize:clear >/dev/null
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php artisan route:cache || echo "(route:cache dilewati; situs tetap berjalan tanpa cache rute)"
PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
systemctl reload "php$PHPV-fpm"

info "5/5 Uji halaman (dari server sendiri)"
DOMAIN="$(grep -E '^APP_URL=' .env | head -1 | sed -E 's#^APP_URL=https?://##; s#/.*$##; s#"##g')"
gagal=0
for jalur in / /tentang /kenali-rokok-ilegal /data /peta-publik /kontak /masuk /data-publik/kabkota /data-publik/sebaran; do
  kode="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 20 -H "Host: $DOMAIN" "https://127.0.0.1$jalur" || true)"
  printf '  %-26s %s\n' "$jalur" "$kode"
  [[ "$kode" == "200" ]] || gagal=1
done
kode="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 20 -H "Host: $DOMAIN" "https://127.0.0.1/.env" || true)"
printf '  %-26s %s (harus 403/404)\n' "/.env" "$kode"
[[ "$kode" == "403" || "$kode" == "404" ]] || gagal=1

echo
echo "Versi terpasang: $(grep -c 'publik.nama' resources/views/layouts/publik.blade.php) penanda nama, rute peta-publik: $(grep -c 'peta-publik' routes/web.php)"
# Petunjuk data (tidak mengubah apa pun)
KEC="$(sudo -u postgres psql -d peta_rokok_ilegal -tAc 'SELECT count(geom) FROM ref.kecamatan' 2>/dev/null || echo '?')"
OPS="$(sudo -u postgres psql -d peta_rokok_ilegal -tAc "SELECT count(*) FROM app.batch_impor WHERE jenis='penindakan' AND status='disimpan'" 2>/dev/null || echo '?')"
echo "Batas kecamatan di basis data: $KEC (harus 576; bila 0 jalankan: bash $APP_DIR/deploy/muat-batas-desa.sh)"
echo "Batch operasi yang sudah disimpan: $OPS (bila 0, impor dan SIMPAN Excel lewat menu Impor)"
if [[ $gagal -ne 0 ]]; then
  echo; echo "ADA HALAMAN YANG GAGAL. Galat terakhir di log:"; tail -n 15 storage/logs/laravel.log 2>/dev/null || true
  exit 1
fi
echo; echo "Selesai: rilis diterapkan dan semua halaman menjawab 200."
