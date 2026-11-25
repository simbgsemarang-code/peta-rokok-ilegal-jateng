#!/usr/bin/env bash
# Cadangan harian basis data (format kustom pg_dump), disimpan 14 hari. Dipasang oleh pasang.sh sebagai
# /usr/local/sbin/peta-cadangkan dan dijadwalkan lewat /etc/cron.d/peta-cadangan (pukul 02.15).
# Pulihkan:  sudo -u postgres pg_restore -d NAMA_DB_BARU --clean --if-exists berkas.dump
set -euo pipefail
DB_NAME="${DB_NAME:-peta_rokok_ilegal}"
TUJUAN="${TUJUAN:-/var/backups/peta}"
SIMPAN_HARI="${SIMPAN_HARI:-14}"
umask 077
mkdir -p "$TUJUAN"
BERKAS="$TUJUAN/${DB_NAME}_$(date +%Y%m%d_%H%M%S).dump"
sudo -u postgres pg_dump -Fc "$DB_NAME" > "$BERKAS"
[[ -s "$BERKAS" ]] || { echo "Cadangan kosong, gagal." >&2; exit 1; }
find "$TUJUAN" -name "${DB_NAME}_*.dump" -mtime +"$SIMPAN_HARI" -delete
echo "$(date '+%F %T') cadangan: $BERKAS ($(du -h "$BERKAS" | cut -f1))"
