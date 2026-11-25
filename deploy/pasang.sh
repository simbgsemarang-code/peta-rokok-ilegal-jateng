#!/usr/bin/env bash
# Pemasangan langsung (tanpa Docker) di Ubuntu 24.04 LTS yang BARU/bersih. Jalankan sebagai root:
#   sudo DOMAIN=rokok.sipadi-jateng.my.id bash deploy/pasang.sh
# Prasyarat: kode sudah ada di $APP_DIR (lihat PANDUAN-PEMASANGAN.md) dan catatan A DNS sudah mengarah ke server ini
# (HTTPS dipasang terpisah dengan certbot setelah DNS aktif).
# Aman dijalankan ulang: basis data, sandi, dan .env yang sudah ada tidak ditimpa.
set -euo pipefail

DOMAIN="${DOMAIN:-rokok.sipadi-jateng.my.id}"
APP_DIR="${APP_DIR:-/var/www/peta}"
DB_NAME="${DB_NAME:-peta_rokok_ilegal}"
DB_LOGIN="${DB_LOGIN:-peta_app}"
RAHASIA=/root/peta-rahasia.txt

galat() { echo "GALAT: $*" >&2; exit 1; }
info()  { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
acak()  { openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c "${1:-28}"; }

[[ $EUID -eq 0 ]] || galat "jalankan sebagai root (sudo)."
. /etc/os-release
[[ "${ID:-}" == "ubuntu" && "${VERSION_ID:-}" == "24.04" ]] || galat "skrip ini disiapkan untuk Ubuntu 24.04 LTS (terdeteksi: ${PRETTY_NAME:-tidak diketahui})."
[[ -f "$APP_DIR/web/artisan" ]] || galat "kode belum ada di $APP_DIR (harus ada $APP_DIR/web/artisan)."
[[ -f "$APP_DIR/db/01_skema.sql" ]] || galat "berkas $APP_DIR/db/01_skema.sql tidak ditemukan."

info "1/8 Memasang paket (nginx, PHP, PostgreSQL + PostGIS, certbot)"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y nginx php-fpm php-cli php-pgsql php-mbstring php-xml php-curl php-zip php-gd php-intl \
  unzip curl git openssl ufw postgresql postgresql-16-postgis-3 certbot python3-certbot-nginx
PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' || galat "PHP $PHPV terlalu lama; Laravel 12 butuh 8.2 atau lebih baru."
echo "PHP $PHPV terpasang."

info "2/8 Composer (diperiksa dengan checksum resmi)"
if ! command -v composer >/dev/null; then
  curl -fsSL https://getcomposer.org/download/latest-stable/composer.phar -o /tmp/composer.phar
  curl -fsSL https://getcomposer.org/download/latest-stable/composer.phar.sha256sum -o /tmp/composer.sha
  [[ "$(sha256sum /tmp/composer.phar | cut -d' ' -f1)" == "$(cut -d' ' -f1 /tmp/composer.sha)" ]] || galat "checksum composer tidak cocok."
  install -m 0755 /tmp/composer.phar /usr/local/bin/composer
fi

info "3/8 Pengaturan PHP-FPM (unggah Excel sampai 40 MB, impor butuh memori dan waktu)"
cat > "/etc/php/$PHPV/fpm/conf.d/90-peta.ini" <<'INI'
upload_max_filesize = 40M
post_max_size = 40M
memory_limit = 1024M
max_execution_time = 300
expose_php = Off
session.cookie_httponly = 1
INI
systemctl restart "php$PHPV-fpm"

info "4/8 Basis data PostgreSQL + PostGIS"
systemctl enable --now postgresql
[[ -f "$RAHASIA" ]] || { umask 077; : > "$RAHASIA"; }
if ! grep -q '^DB_PASSWORD=' "$RAHASIA"; then echo "DB_PASSWORD=$(acak 28)" >> "$RAHASIA"; fi
DB_PASSWORD="$(grep '^DB_PASSWORD=' "$RAHASIA" | cut -d= -f2-)"
if ! sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='$DB_NAME'" | grep -q 1; then
  sudo -u postgres createdb "$DB_NAME"
fi
if ! sudo -u postgres psql -d "$DB_NAME" -tAc "SELECT 1 FROM pg_namespace WHERE nspname='ref'" | grep -q 1; then
  echo "Memuat skema dan data referensi..."
  sudo -u postgres psql -d "$DB_NAME" -v ON_ERROR_STOP=1 -q -f "$APP_DIR/db/01_skema.sql"
  sudo -u postgres psql -d "$DB_NAME" -v ON_ERROR_STOP=1 -q -f "$APP_DIR/db/02_data_referensi.sql"
else
  echo "Skema sudah ada; dilewati."
fi
# Akun aplikasi: bukan superuser, hanya mewarisi peran peta_aplikasi (hak terbatas)
if ! sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='$DB_LOGIN'" | grep -q 1; then
  sudo -u postgres psql -v ON_ERROR_STOP=1 -q -c "CREATE ROLE $DB_LOGIN LOGIN PASSWORD '$DB_PASSWORD' IN ROLE peta_aplikasi"
else
  sudo -u postgres psql -v ON_ERROR_STOP=1 -q -c "ALTER ROLE $DB_LOGIN PASSWORD '$DB_PASSWORD'"
fi
sudo -u postgres psql -v ON_ERROR_STOP=1 -q -c "GRANT CONNECT ON DATABASE $DB_NAME TO $DB_LOGIN"
# PostgreSQL hanya mendengarkan localhost (bawaan Ubuntu); pastikan
ss -tln | awk '$4 ~ /:5432$/ {print $4}' | grep -qv '^127\.0\.0\.1:\|^\[::1\]:' && galat "PostgreSQL mendengarkan di antarmuka publik; periksa listen_addresses." || true

info "5/8 Berkas .env produksi"
ENV="$APP_DIR/web/.env"
if [[ ! -f "$ENV" ]]; then
  sed -e "s|__DOMAIN__|$DOMAIN|g" -e "s|__DB_PASSWORD__|$DB_PASSWORD|g" "$APP_DIR/deploy/env.produksi.contoh" > "$ENV"
  chmod 640 "$ENV"
  echo ".env dibuat."
else
  echo ".env sudah ada; tidak ditimpa."
fi

info "6/8 Dependensi dan cache aplikasi"
cd "$APP_DIR/web"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force
mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chown root:www-data .env
chmod -R ug+rwX storage bootstrap/cache
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache

info "7/8 nginx"
cat > /etc/nginx/conf.d/peta-batas.conf <<'CONF'
# Pembatas percobaan masuk: hanya permintaan POST yang dihitung
map $request_method $kunci_masuk { POST $binary_remote_addr; default ""; }
limit_req_zone $kunci_masuk zone=masuk:10m rate=10r/m;
CONF
sed -e "s|__DOMAIN__|$DOMAIN|g" -e "s|__APP_DIR__|$APP_DIR|g" -e "s|__PHPV__|$PHPV|g" \
  "$APP_DIR/deploy/nginx-rokok.conf" > /etc/nginx/sites-available/peta-rokok
ln -sf /etc/nginx/sites-available/peta-rokok /etc/nginx/sites-enabled/peta-rokok
nginx -t
systemctl enable --now nginx
systemctl reload nginx

info "8/8 Firewall (SSH, HTTP, HTTPS) dan cadangan harian"
# Buka SEMUA port yang dipakai sshd (penyedia dapat memakai port non-standar, mis. 20199) agar tidak terkunci
SSH_PORTS="$(sshd -T 2>/dev/null | awk '$1=="port" {print $2}')"
[[ -n "${SSH_CONNECTION:-}" ]] && SSH_PORTS="$SSH_PORTS $(echo "$SSH_CONNECTION" | awk '{print $4}')"
[[ -n "${SSH_PORTS// /}" ]] || SSH_PORTS=22
for p in $(echo "$SSH_PORTS" | tr ' ' '\n' | sort -u); do
  ufw allow "$p/tcp" >/dev/null
  echo "Firewall: port SSH $p diizinkan."
done
ufw allow 'Nginx Full' >/dev/null
ufw --force enable >/dev/null
install -m 0755 "$APP_DIR/deploy/cadangkan.sh" /usr/local/sbin/peta-cadangkan
echo "15 2 * * * root DB_NAME=$DB_NAME /usr/local/sbin/peta-cadangkan >> /var/log/peta-cadangan.log 2>&1" > /etc/cron.d/peta-cadangan
chmod 644 /etc/cron.d/peta-cadangan

cat <<SELESAI

Pemasangan dasar selesai. Langkah berikutnya (lihat PANDUAN-PEMASANGAN.md):
  1. Pastikan DNS: catatan A $DOMAIN mengarah ke IP server ini, lalu pasang HTTPS:
       certbot --nginx -d $DOMAIN
  2. Muat batas desa/kelurahan:        sudo bash $APP_DIR/deploy/muat-batas-desa.sh
  3. Buat akun admin pertama:          cd $APP_DIR/web && sudo -u www-data php artisan pengguna:buat email@instansi.go.id "Nama Admin" --peran=admin
  4. Isi kanal pengaduan di $ENV (PUBLIK_*), lalu: cd $APP_DIR/web && sudo -u www-data php artisan config:cache
Sandi database tersimpan di $RAHASIA (hanya root). Simpan salinannya di tempat aman.
SELESAI
