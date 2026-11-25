# Panduan pemasangan SIPATRI di VPS: rokok.sipadi-jateng.my.id

Sistem ini membutuhkan **PostgreSQL + PostGIS** sehingga tidak dapat berjalan di hosting cPanel bersama yang hanya menyediakan MySQL. Panduan ini memasangnya di **VPS Ubuntu 24.04 LTS** (tanpa Docker) dan mengarahkan subdomain ke VPS itu. SIPADI di hosting cPanel tidak tersentuh.

```
Pengunjung → rokok.sipadi-jateng.my.id → (DNS: catatan A) → VPS: nginx → PHP-FPM (Laravel) → PostgreSQL + PostGIS
```

## 1. Menyewa VPS

| Hal | Syarat |
|---|---|
| Sistem operasi | **Ubuntu 24.04 LTS**, instalasi baru |
| Sumber daya minimal | 2 vCPU, 4 GB RAM, SSD 40 GB (impor Excel dan analisis butuh memori) |
| Lokasi | Pusat data di Indonesia (aplikasi memuat data pemerintah daerah; periksa PP 71/2019 dan UU 27/2022 tentang PDP) |
| Akses | IP publik tetap, akses root atau sudo lewat SSH (disarankan kunci SSH, bukan sandi) |
| Cadangan | Aktifkan snapshot/backup dari penyedia, selain cadangan basis data harian di panduan ini |

Setelah VPS aktif, jalankan pemeriksa berikut di sana dan kirim keluarannya kepada pengembang (hanya membaca, tidak mengubah apa pun):

```bash
bash periksa-server.sh rokok.sipadi-jateng.my.id
```

Repo ini **privat**, jadi berkas tidak bisa diunduh dengan `curl` tanpa login. Salin isi `deploy/periksa-server.sh` ke server (tempel ke `nano periksa-server.sh`, atau kirim dengan `scp`), atau lakukan langkah 3 lebih dulu lalu jalankan `bash /var/www/peta/deploy/periksa-server.sh`.

### Catatan khusus VPS Indonesia (vpsindonesia.co.id): IP bersama

- **SSH memakai port non-standar** dari panel, mis. `ssh -p 20199 root@IP_VPS` (port 22 pada IP yang sama milik server lain, jangan dipakai).
- **IP bawaan dipakai bersama beberapa VPS.** Port 80/443 pada IP itu tidak langsung menuju VPS Anda (halaman IP menyatakan hal ini). Pilih salah satu:
  1. **IP Dedicated** (add-on sekitar Rp15.000 per bulan, minta lewat panel atau dukungan): VPS punya IPv4 sendiri sehingga port 80/443 langsung sampai. **Paling sederhana dan paling andal; disarankan.**
  2. **Fitur "Domain" di detail VPS (gratis):** arahkan catatan A ke IP bersama, lalu daftarkan `rokok.sipadi-jateng.my.id` di detail VPS → Domain; port 80/443 untuk domain itu diteruskan otomatis. Kekurangannya: nginx di VPS mungkin melihat alamat penerus, bukan alamat pengunjung, sehingga pembatas login (per IP) menjadi kurang efektif; cara kerja HTTPS (diteruskan apa adanya atau diakhiri di penerus) harus diuji.
- Catatan A diisi dengan IP yang berlaku untuk pilihan di atas (IP khusus bila membeli, IP bersama bila memakai fitur Domain).
- Firewall `pasang.sh` otomatis mengizinkan port SSH yang dipakai sshd; jangan menutup port itu secara manual.

## 2. Mengarahkan subdomain ke VPS (DNS)

Subdomain cukup diarahkan dengan satu catatan **A** ke IP VPS.

1. Tentukan di mana DNS `sipadi-jateng.my.id` dikelola: di cPanel (menu **Zone Editor**) bila *nameserver* domain menunjuk ke Nusantara Host, atau di registrar domain bila memakai *nameserver* lain. Cek di menu **Domains** atau halaman registrar.
2. Di Zone Editor: **Manage** pada `sipadi-jateng.my.id` → **+ Add Record**:
   - Type: `A`
   - Name: `rokok.sipadi-jateng.my.id.`
   - Address: *IP VPS*
   - TTL: `300` (nanti boleh dinaikkan)
3. Tunggu perambatan DNS (beberapa menit sampai beberapa jam), lalu periksa: `nslookup rokok.sipadi-jateng.my.id` harus menampilkan IP VPS.

Catatan A baru pada subdomain tidak memengaruhi `sipadi-jateng.my.id` dan SIPADI.

## 3. Menaruh kode di server

Kode ada di repo GitHub privat. Pilih salah satu cara.

**Cara A: kunci deploy (disarankan, memudahkan pembaruan).** Di server sebagai root:

```bash
apt-get update && apt-get install -y git
ssh-keygen -t ed25519 -f /root/.ssh/peta_deploy -N "" -C "deploy-peta-rokok"
cat /root/.ssh/peta_deploy.pub     # salin hasilnya
```

Di GitHub: repo → **Settings → Deploy keys → Add deploy key**, tempel kunci, **jangan** centang *Allow write access*. Kembali ke server:

```bash
cat >> /root/.ssh/config <<'EOF'
Host github-peta
  HostName github.com
  User git
  IdentityFile /root/.ssh/peta_deploy
  IdentitiesOnly yes
EOF
chmod 600 /root/.ssh/config
git clone git@github-peta:simbgsemarang-code/peta-rokok-ilegal-jateng.git /var/www/peta
```

**Cara B: salin berkas.** Dari komputer pengembang: `git archive --format=tar.gz -o peta.tar.gz HEAD`, kirim dengan `scp`, lalu `mkdir -p /var/www/peta && tar xzf peta.tar.gz -C /var/www/peta`. Pembaruan berikutnya diulang dengan cara yang sama.

## 4. Menjalankan pemasangan

```bash
sudo DOMAIN=rokok.sipadi-jateng.my.id bash /var/www/peta/deploy/pasang.sh
```

Skrip memasang nginx, PHP-FPM, PostgreSQL + PostGIS, Composer, dan certbot; membuat basis data dan akun `peta_app` (bukan superuser, sandi acak); memuat skema dan data referensi; membuat `web/.env` produksi (`APP_DEBUG=false`, kunci aplikasi baru, cookie aman); mengatur nginx dengan pembatas percobaan masuk; mengaktifkan firewall (hanya SSH, HTTP, HTTPS); dan menjadwalkan cadangan harian. Aman dijalankan ulang. Sandi basis data tersimpan di `/root/peta-rahasia.txt`.

## 5. HTTPS

Setelah DNS mengarah ke VPS:

```bash
certbot --nginx -d rokok.sipadi-jateng.my.id
```

Pilih pengalihan HTTP ke HTTPS. Pembaruan sertifikat berjalan otomatis (`systemctl list-timers | grep certbot`). Setelah HTTPS stabil, boleh menambahkan header HSTS di server-block nginx:
`add_header Strict-Transport-Security "max-age=31536000" always;`

## 6. Batas desa/kelurahan dan akun admin

```bash
sudo bash /var/www/peta/deploy/muat-batas-desa.sh
cd /var/www/peta/web
sudo -u www-data php artisan pengguna:buat nama@instansi.go.id "Nama Admin" --peran=admin
```

Perintah kedua menanyakan sandi (minimal 10 karakter). Akun lain dibuat dari menu **Pengaturan → Pengguna**.

## 7. Mengisi data

1. Masuk di `https://rokok.sipadi-jateng.my.id/masuk`.
2. **Impor data operasi:** unggah `DATA GABUNGAN OPERASI CUKAI 2023-2026.xlsx` dari komputer Anda lewat menu Impor (berkas ini tidak ada di repo karena memuat nama dan alamat pelaku usaha). Periksa pratinjau (332 operasi, 513 pelanggar, 4.189.563 batang), lalu simpan.
3. **Pengaturan:** isi tarif, jumlah outlet rokok per kab/kota, dan bobot skor sesuai sumber resmi.
4. **Situs publik:** isi kanal pengaduan resmi di `web/.env` (variabel `PUBLIK_*`), lalu `sudo -u www-data php artisan config:cache`. Putuskan juga apakah angka penindakan boleh tampil (`PUBLIK_TAMPILKAN_DATA`).

Koordinat perkiraan dari alamat (kolom Q/R di Excel) dibuat di komputer pengembang dengan `alat/geokode_alamat.php`; impor membacanya bila kolom itu ada.

## 8. Daftar periksa sebelum dibuka ke publik

- [ ] `APP_DEBUG=false` dan `APP_ENV=production` di `web/.env`
- [ ] HTTPS aktif dan HTTP dialihkan; `https://…/.env` dan `https://…/.git/config` menghasilkan 403/404
- [ ] Port 5432 tidak terbuka ke luar (`ss -tlnp | grep 5432` hanya `127.0.0.1`); firewall aktif (`ufw status`)
- [ ] Login SSH memakai kunci; login root dengan sandi dimatikan (`PermitRootLogin prohibit-password`, `PasswordAuthentication no`)
- [ ] Akun admin memakai sandi kuat; tidak ada akun uji
- [ ] Cadangan berjalan: `sudo /usr/local/sbin/peta-cadangkan`, lalu cek `/var/backups/peta`; salinan cadangan juga disimpan di luar VPS
- [ ] Keputusan Satpol PP: angka penindakan publik, kanal pengaduan, peninjauan hukum teks "Kenali Rokok Ilegal"
- [ ] Batas desa/kelurahan disadari sebagai **bukan batas resmi** (lihat `db/README.md`)

## 9. Pemeliharaan

| Tugas | Perintah |
|---|---|
| Perbarui aplikasi setelah ada kode baru | `sudo bash /var/www/peta/deploy/deploy.sh` |
| Cadangan manual | `sudo /usr/local/sbin/peta-cadangkan` |
| Pulihkan cadangan | `sudo -u postgres createdb peta_pulih && sudo -u postgres pg_restore -d peta_pulih --clean --if-exists berkas.dump` |
| Log aplikasi | `/var/www/peta/web/storage/logs/laravel.log` |
| Log nginx | `/var/log/nginx/peta-rokok.error.log` |
| Pembaruan keamanan sistem | `apt-get update && apt-get upgrade` (aktifkan `unattended-upgrades`) |
| Ubah pengaturan `.env` | edit `web/.env`, lalu `sudo -u www-data php artisan config:cache` |

Perubahan struktur basis data (berkas `db/*.sql` baru) tidak otomatis dijalankan oleh `deploy.sh`: cadangkan dulu, lalu jalankan berkasnya dengan `sudo -u postgres psql -d peta_rokok_ilegal -f berkas.sql`.

## 10. Bila ada masalah

| Gejala | Periksa |
|---|---|
| Halaman 502 | `systemctl status php8.3-fpm`; versi PHP bisa berbeda, lihat `ls /run/php` |
| 500 setelah login | `storage/logs/laravel.log`; pastikan `storage` dan `bootstrap/cache` milik `www-data` |
| Peta kosong / galat "PostGIS" | `sudo -u postgres psql -d peta_rokok_ilegal -c "SELECT postgis_version()"` |
| Unggah Excel gagal | batas 40 MB di nginx dan `/etc/php/*/fpm/conf.d/90-peta.ini`; `systemctl restart php8.3-fpm` |
| Sertifikat gagal | DNS belum mengarah ke VPS, atau port 80 tertutup |
