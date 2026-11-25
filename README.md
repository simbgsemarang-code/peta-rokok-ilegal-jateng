# Aplikasi Peta Digital Peredaran Rokok Ilegal Jawa Tengah

Aplikasi web (Laravel 12, PHP 8.2) di atas basis data PostgreSQL + PostGIS pada folder [db](db/README.md).
Status: **versi lokal untuk pengembangan, sudah berjalan penuh di Docker + PostGIS 3.5.2.** Diuji 25 November 2026: login dan hak akses, impor Excel, rekap, estimasi kerugian, skor, rekomendasi, pengaturan, unduh Excel, log audit, halaman peta (35 batas kab/kota valid, popup, filter), trigger pengisi desa dari titik GPS, dan kueri radius. Kontainer `peta-db` berisi skema, data referensi, dan data gabungan operasi 2023–2026 yang sudah diimpor dan disimpan.

## Situs publik (gerbang masuk)

Alamat `/` kini berupa situs publik tanpa login, dengan navbar di atas: **Beranda**, **Tentang**, **Kenali Rokok Ilegal**, **Data Penindakan**, **Kontak & Pengaduan**, dan tombol **Masuk Petugas** (berubah menjadi "Buka Aplikasi" bila sudah login). Aplikasi petugas ada di `/peta`, `/rekap`, dan seterusnya.

- **Yang tampil untuk publik:** angka gabungan per kab/kota (jumlah operasi, pelaku, dan batang disita), per tahun, beserta peta kab/kota dan unduhan CSV. Tidak ada nama pemilik, nama toko, alamat, titik lokasi, ataupun tingkat kecamatan/desa. API publik: `/data-publik/kabkota` dan `/data-publik/penindakan.csv` (dibatasi 60 permintaan per menit; hasil disimpan sementara 5 menit).
- **Pengaturan lewat `web/.env`** (lihat `config/publik.php`): `PUBLIK_TAMPILKAN_DATA=false` menyembunyikan halaman dan API data dari publik; `PUBLIK_INSTANSI`, `PUBLIK_UNIT`; kanal layanan `PUBLIK_ALAMAT`, `PUBLIK_TELEPON`, `PUBLIK_WHATSAPP`, `PUBLIK_EMAIL`, `PUBLIK_SITUS`, `PUBLIK_JAM_LAYANAN`, `PUBLIK_TAUTAN_PENGADUAN`. Selama kanal kosong, halaman Kontak menyatakan bahwa kanal resmi akan diumumkan; aplikasi tidak mengarang nomor atau alamat.
- **Perlu keputusan Satpol PP sebelum dibuka ke publik:** apakah angka penindakan per kab/kota boleh ditampilkan (bawaan: tampil), kanal pengaduan resmi, serta peninjauan hukum atas teks pada halaman "Kenali Rokok Ilegal" (ringkasan umum tanpa nomor pasal atau besaran sanksi).
- Logo Provinsi Jawa Tengah dan Satpol PP (`web/public/img`) dipakai di navbar dan footer; ganti atau hapus bila tidak dikehendaki.
- Situs publik tidak menerima laporan dan tidak menyimpan data pengunjung.

## Isi aplikasi petugas

| Menu | Fungsi | Siapa |
|---|---|---|
| Peta penindakan | Peta **kab/kota, kecamatan, dan desa/kelurahan** (pilih tingkat dan kab/kota; penelusuran juga lewat tombol di popup) berwarna menurut pelanggar, batang, atau operasi; filter tahun; 10 teratas. Desa sampel survei diberi garis hijau. Ditambah **titik perkiraan lokasi pelanggar** (digabung per koordinat, tanpa nama dan alamat) | Peta: semua. Titik: hanya admin dan operator (operator: kab/kota sendiri) |
| Rekap penindakan | Rekap per kab/kota, per bulan, per merek, dihitung otomatis dari data | Semua (operator hanya kab/kota sendiri) |
| Estimasi kerugian | Perkiraan kerugian cukai dan bagian DBHCHT per kab/kota, dengan skenario rendah/tengah/tinggi dan versi analisis | Lihat: semua; hitung: admin |
| Rekomendasi penindakan | Draf lokasi, waktu, sasaran, dan pendekatan per kab/kota menurut skor prioritas; sunting, setujui, unduh Excel | Lihat: semua; ubah dan setujui: admin |
| Impor data operasi | Unggah Excel → pratinjau → simpan atau batalkan (versi data dan rollback) | Admin |
| Pengaturan | Tarif, bobot skor, data pendukung (outlet dan perokok), merek dan alias, pengguna, log audit | Admin |

Dua poin utama dari paparan terpenuhi di sini: **data gabungan operasi cukai 2023–2026 dapat dimuat** (format sheet `DATA` dan format `RINCIAN HASIL OPS` dikenali otomatis), dan **admin dapat memperbarui data** kapan saja lewat menu Impor tanpa mengubah kode.

## Menjalankan di komputer ini

### 1. Basis data (Docker + PostGIS)

Pasang Docker Desktop (butuh hak admin, WSL2, dan restart). Lalu, di PowerShell, satu per satu (ganti `GantiSandiIni` dengan sandi Anda dan samakan dengan `DB_PASSWORD` di `web\.env`):

```powershell
docker run -d --name peta-db -e POSTGRES_PASSWORD=GantiSandiIni -p 5432:5432 postgis/postgis:17-3.5
```

```powershell
docker cp C:\xampp\htdocs\cukai\aplikasi\db peta-db:/db
```

```powershell
docker exec peta-db createdb -U postgres peta_rokok_ilegal
```

```powershell
docker exec peta-db psql -U postgres -d peta_rokok_ilegal -v ON_ERROR_STOP=1 -f /db/01_skema.sql
```

```powershell
docker exec peta-db psql -U postgres -d peta_rokok_ilegal -v ON_ERROR_STOP=1 -f /db/02_data_referensi.sql
```

### 2. Akun admin pertama

```powershell
cd C:\xampp\htdocs\cukai\aplikasi\web
C:\xampp\php\php.exe artisan pengguna:buat admin@contoh.go.id "Nama Admin" --peran=admin
```

Kata sandi ditanyakan di layar (minimal 10 karakter). Akun lain dibuat dari menu Pengaturan → Pengguna.

### 3. Jalankan aplikasi

```powershell
C:\xampp\php\php.exe artisan serve
```

Buka http://127.0.0.1:8000 (situs publik), lalu klik **Masuk Petugas** atau buka `/masuk`.

### 4. Isi data, berurutan

1. **Impor data operasi:** unggah `DATA GABUNGAN OPERASI CUKAI 2023-2026.xlsx`. Pembacaan berkas ini sudah diuji dan harus menghasilkan **332 operasi, 513 pelanggar, 3.399 temuan merek, 4.189.563 batang** pada pratinjau. Cocok → **Simpan**.
2. **Impor pembaruan:** `HASIL OPERASI CUKAI NOVEMBER 2026.xlsx` menghasilkan 104 operasi, 180 pelanggar, 2.220.695 batang, angka yang sama dengan bagian 2026 pada berkas gabungan. Jadi berkas ini **sudah termuat** lewat berkas gabungan; aplikasi memberi peringatan duplikat (98 operasi). Jangan menyimpan keduanya. Pakai format ini untuk berkas operasi **baru** setelah November 2026.
3. **Pengaturan → Tarif** dan **Data pendukung** (jumlah outlet rokok per kab/kota): diisi dari sumber resmi. Aplikasi sengaja tidak membawa angka tarif bawaan.
4. **Estimasi kerugian → Hitung.** Hasilnya sekaligus menyusun skor dan draf rekomendasi.

## Catatan teknis

- **Skema basis data diubah satu hal:** `analisis.estimasi_kabkota.metode` kini menerima nilai `skenario` (selain `langsung` dan `fay_herriot`), untuk perkiraan yang masih memakai asumsi sebelum data survei masuk.
- **Skor prioritas** memakai sebuah komponen hanya bila datanya ada untuk semua kab/kota, agar skor sebanding. Selama jumlah outlet rokok belum diisi untuk ke-35 kab/kota, komponen kerugian tidak ikut dipakai (hanya intensitas dan tren penindakan).
- **Koordinat perkiraan:** importer membaca kolom Q "Estimasi Koordinat (lat, lon)" dan R "Ketelitian Estimasi" pada sheet DATA bila ada (dibuat oleh `alat/geokode_alamat.php` dan `alat/tulis_koordinat.ps1` dari alamat lewat Nominatim). Titik disimpan di `penindakan.pelanggar.geom` dengan `cara_penentuan = 'geocoding_otomatis'`. Trigger pengisi desa melewati titik jenis ini, karena sebagian besar hanya titik tengah kecamatan. Batch #1 (tanpa titik) dibatalkan dan diganti batch #2 (513 pelanggar, seluruhnya bertitik: 8 alamat/jalan, 114 desa/dusun, 343 kecamatan, 48 kab/kota).
- **Sesi akun nonaktif** langsung berakhir pada permintaan berikutnya (middleware `PastikanAktif`).
- **Hasil uji impor:** berkas gabungan menghasilkan 332 operasi, 513 pelanggar, 4.189.563 batang (4 detik). Rekap tahunan: 2023 = 129, 2024 = 108, 2025 = 96, 2026 = 180 pelanggar; terbanyak Jepara 45, Grobogan 32, Pemalang 31.
- **Sesi dan cache memakai berkas** (bukan tabel), jadi basis data hanya berisi skema rancangan. Tabel `users` bawaan Laravel tidak dipakai; pengguna ada di `app.pengguna`.
- **Log audit:** setiap perubahan lewat aplikasi menyetel `app.pengguna_id` sehingga trigger basis data mencatat pelakunya.
- **Peta dasar** (OpenStreetMap) dan pustaka Leaflet dimuat dari internet; tanpa internet, batas kab/kota tetap tampil tetapi tanpa peta dasar.
- **Pembinaan/sosialisasi** dicatat sebagai operasi, bukan pelanggar, sama seperti rekap Excel. Data pemilik pada baris pembinaan tidak ikut disimpan.
- Dua angka di berkas November berbeda dari hitungan baris: baris total di dasar lembar menyebut 162 pelanggar, sedangkan jumlah baris yang berisi nama pemilik (di luar pembinaan) adalah 180. Total batang sama persis dengan jumlah kolom H. Perlu dikonfirmasi ke pembuat rekap.

## Belum dibangun

- Impor hasil survei dari KoboToolbox (tabel di skema sudah ada).
- Analisis hotspot (Getis-Ord Gi*) dan estimasi area kecil (Fay-Herriot); komponen hotspot dan efek pembinaan pada skor masih dilewati.
- Batas resmi BIG/BPS/Bappeda: batas desa/kelurahan, kecamatan, dan kab/kota saat ini berasal dari cahyadsn/wilayah_boundaries (MIT, disederhanakan, bukan batas resmi); dua desa (Bojongsari 33.05.11.2001, Sodong Basari 33.27.03.2013) belum berpoligon.
- Pelanggar yang alamatnya hanya sampai kecamatan atau kab/kota tidak dapat ditempatkan di tingkat desa: hanya 120 dari 513 pelanggar yang terpetakan di desa/kelurahan (101 desa), 376 di kecamatan. Peta menyebutkan angka ini di panel samping.
- Layanan peta GeoServer (OGC), autentikasi dua langkah, dan tampilan titik outlet berhak akses terbatas.

## Sebelum dipasang di server

- Setel `APP_DEBUG=false`, buat `APP_KEY` baru, dan pakai HTTPS.
- Hubungkan aplikasi lewat akun basis data berperan `peta_aplikasi` (bukan `postgres`); lihat contoh di akhir `db/01_skema.sql`.
- Atur pencadangan harian basis data (`pg_dump`) dan batasi akses port 5432.
