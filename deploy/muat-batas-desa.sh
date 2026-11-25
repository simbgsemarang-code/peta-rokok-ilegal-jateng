#!/usr/bin/env bash
# Memuat batas desa/kelurahan, kecamatan, dan kab/kota ke basis data produksi.
# Sumber: cahyadsn/wilayah_boundaries, folder db/kel/33 (lisensi MIT, poligon disederhanakan, BUKAN batas resmi).
# Jalankan sebagai root setelah pasang.sh:  sudo bash deploy/muat-batas-desa.sh
set -euo pipefail
APP_DIR="${APP_DIR:-/var/www/peta}"
DB_NAME="${DB_NAME:-peta_rokok_ilegal}"
SUMBER="$APP_DIR/data-wilayah/batas_desa"
GITHUB_API="https://api.github.com/repos/cahyadsn/wilayah_boundaries/contents/db/kel/33"

[[ $EUID -eq 0 ]] || { echo "Jalankan sebagai root." >&2; exit 1; }
mkdir -p "$SUMBER"
if [[ $(find "$SUMBER" -name 'wilayah_boundaries_kel_33.*.sql' | wc -l) -lt 35 ]]; then
  echo "Mengunduh 35 berkas batas (±30 MB) dari GitHub..."
  curl -fsSL -H 'User-Agent: muat-batas-desa' "$GITHUB_API" | grep -o '"download_url": *"[^"]*"' | cut -d'"' -f4 |
    while read -r url; do curl -fsSL "$url" -o "$SUMBER/$(basename "$url")"; done
fi
n=$(find "$SUMBER" -name 'wilayah_boundaries_kel_33.*.sql' | wc -l)
[[ "$n" -eq 35 ]] || { echo "Berkas batas tidak lengkap ($n dari 35)." >&2; exit 1; }

TMP="$(mktemp --suffix=.sql)"; trap 'rm -f "$TMP"' EXIT
php -d memory_limit=1024M "$APP_DIR/db/buat_batas_desa.php" "$SUMBER" "$TMP"
chmod 644 "$TMP"
sudo -u postgres psql -d "$DB_NAME" -v ON_ERROR_STOP=1 -q -f "$TMP"
sudo -u postgres psql -d "$DB_NAME" -v ON_ERROR_STOP=1 -q -f "$APP_DIR/db/04_batas_turunan.sql"
sudo -u postgres psql -d "$DB_NAME" -v ON_ERROR_STOP=1 -q -c "DROP SCHEMA stg CASCADE"
sudo -u postgres psql -d "$DB_NAME" -P pager=off -c "SELECT 'desa' AS tingkat, count(geom) AS berpoligon, count(*) AS total FROM ref.desa UNION ALL SELECT 'kecamatan', count(geom), count(*) FROM ref.kecamatan UNION ALL SELECT 'kab/kota', count(geom), count(*) FROM ref.kabkota"
echo "Selesai. Harapan: desa 8561 dari 8563, kecamatan 576, kab/kota 35."
