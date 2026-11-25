#!/usr/bin/env bash
# Memasang fail2ban untuk SSH (memblokir penebak sandi). Jalankan sebagai root:  bash deploy/pasang-fail2ban.sh
#
# PENTING untuk VPS di belakang proxy/NAT penyedia (mis. vpsindonesia.co.id): nginx hanya melihat alamat proxy
# (10.10.0.1), bukan alamat pengunjung. Karena itu TIDAK ADA jail nginx di sini: bila ada, fail2ban akan memblokir
# alamat proxy dan menutup situs untuk semua orang. Alamat jaringan lokal dikecualikan lewat ignoreip.
# SSH tidak terpengaruh karena alamat klien asli terlihat oleh sshd (lihat SSH_CONNECTION).
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo "GALAT: jalankan sebagai root." >&2; exit 1; }

export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a
apt-get update -y
apt-get install -y fail2ban

# Jaringan lokal server (alamat proxy penyedia termasuk di dalamnya) tidak boleh pernah diblokir
LOKAL="$(ip -4 -o addr show scope global | awk '{print $4}' | head -1)"
IGNORE="127.0.0.1/8 ::1"
[[ -n "$LOKAL" ]] && IGNORE="$IGNORE $(python3 - "$LOKAL" <<'PY'
import ipaddress, sys
print(ipaddress.ip_interface(sys.argv[1]).network)
PY
)"

# Port sshd yang sebenarnya (di dalam server)
SSH_PORT="$(sshd -T 2>/dev/null | awk '$1=="port" {print $2; exit}')"
SSH_PORT="${SSH_PORT:-22}"

cat > /etc/fail2ban/jail.local <<EOF
[DEFAULT]
bantime  = 1h
findtime = 10m
maxretry = 5
backend  = systemd
ignoreip = $IGNORE
# pelanggar berulang diblokir makin lama (maksimal 1 minggu)
bantime.increment = true
bantime.factor    = 2
bantime.maxtime   = 1w

[sshd]
enabled = true
port    = $SSH_PORT
EOF

systemctl enable --now fail2ban
systemctl restart fail2ban
sleep 3
echo "--- status:"
fail2ban-client status
fail2ban-client status sshd
echo
echo "Dikecualikan dari pemblokiran: $IGNORE"
echo "Jika Anda sendiri terblokir: buka Konsol di panel VPS lalu jalankan:  fail2ban-client set sshd unbanip ALAMAT_IP_ANDA"
