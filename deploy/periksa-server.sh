#!/usr/bin/env bash
# Pemeriksa server (HANYA MEMBACA, tidak mengubah apa pun). Jalankan di server calon tempat pasang:
#   bash periksa-server.sh rokok.sipadi-jateng.my.id
# Kirim seluruh keluarannya kepada pengembang. Keluaran tidak memuat kata sandi atau kunci.
DOMAIN="${1:-rokok.sipadi-jateng.my.id}"
ada() { command -v "$1" >/dev/null 2>&1; }
baris() { printf '\n== %s ==\n' "$1"; }

baris "Sistem"
. /etc/os-release 2>/dev/null && echo "OS        : $PRETTY_NAME"
echo "Kernel    : $(uname -r)"
echo "CPU       : $(nproc) inti"
echo "RAM       : $(free -m | awk '/Mem:/ {print $2" MB (bebas "$7" MB)"}')"
echo "Swap      : $(free -m | awk '/Swap:/ {print $2" MB"}')"
echo "Disk /    : $(df -h / | awk 'NR==2 {print $2" total, "$4" bebas"}')"
echo "Alamat IP : $(hostname -I 2>/dev/null)"
echo "Pengguna  : $(id -un) (uid $(id -u))  | sudo: $(sudo -n true 2>/dev/null && echo ya || echo 'perlu kata sandi/tidak ada')"

baris "SSH dan jaringan (apakah IP bersama/NAT?)"
echo "Port sshd : $(sshd -T 2>/dev/null | awk '$1=="port" {printf "%s ", $2}')"
echo "Koneksi SSH ini (klien port-server): ${SSH_CONNECTION:-tidak tersedia}"
echo "Alamat IPv4 pada antarmuka:"
ip -4 -o addr show scope global 2>/dev/null | awk '{print "  "$2": "$4}'
if ip -4 -o addr show scope global 2>/dev/null | grep -Eq 'inet (10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)'; then
  echo "PERHATIAN: antarmuka memakai alamat PRIBADI. Server ini kemungkinan berada di belakang NAT/IP bersama:"
  echo "  port 80/443 dari luar tidak otomatis sampai ke sini (butuh IP khusus atau fitur teruskan-domain dari penyedia)."
fi

baris "Layanan web dan port terbuka"
for s in nginx apache2 httpd caddy; do ada "$s" && echo "terpasang : $s ($(systemctl is-active "$s" 2>/dev/null))"; done
(ss -tlnp 2>/dev/null || netstat -tlnp 2>/dev/null) | awk 'NR==1 || /:(22|80|443|3306|5432|8080)\s/' | head -20
echo "Situs nginx aktif:"; ls /etc/nginx/sites-enabled 2>/dev/null | sed 's/^/  /'
echo "Firewall (ufw): $(ada ufw && ufw status 2>/dev/null | head -1 || echo 'tidak ada')"

baris "PHP (Laravel 12 butuh PHP 8.2 atau lebih baru)"
if ada php; then php -v | head -1; else echo "php (CLI) belum ada"; fi
ls /etc/php 2>/dev/null | sed 's/^/versi terpasang: /'
for e in pgsql pdo_pgsql mbstring xml curl zip gd intl openssl fileinfo; do
  if ada php; then php -m 2>/dev/null | grep -qix "$e" && echo "  ekstensi $e: ada" || echo "  ekstensi $e: BELUM"; fi
done
ada composer && echo "composer: $(composer --version 2>/dev/null | head -1)" || echo "composer: belum ada"

baris "Basis data"
for s in postgresql mysql mariadb; do systemctl list-unit-files 2>/dev/null | grep -q "^$s" && echo "layanan $s: $(systemctl is-active "$s" 2>/dev/null)"; done
ada psql && psql --version
ls /usr/lib/postgresql 2>/dev/null | sed 's/^/PostgreSQL terpasang versi: /'
if ada apt-cache; then
  echo "Paket PostGIS yang tersedia di apt:"
  apt-cache search --names-only '^postgresql-[0-9]+-postgis-3$' 2>/dev/null | sed 's/^/  /' | head -5
fi

baris "Alat lain"
for t in git curl unzip certbot docker openssl; do ada "$t" && echo "ada   : $t" || echo "belum : $t"; done

baris "DNS untuk $DOMAIN"
echo "Menurut server ini: $(getent hosts "$DOMAIN" | awk '{print $1}' | head -1 || true)"
echo "(harus sama dengan IP server di atas setelah catatan A dibuat; boleh kosong sebelum itu)"
echo; echo "Selesai. Salin seluruh keluaran ini."
