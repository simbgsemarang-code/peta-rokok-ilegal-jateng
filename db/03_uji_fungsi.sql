-- =====================================================================
-- Uji fungsi skema (constraint, trigger, view, impor, audit, hak akses)
-- JALANKAN HANYA PADA BASIS DATA UJI: skrip ini menambah pengguna,
-- operasi, outlet, dan rekomendasi contoh. Perintah yang ditandai
-- "harus DITOLAK" memang diharapkan gagal; jalankan tanpa ON_ERROR_STOP:
--   psql -d peta_rokok_ilegal_uji -f 03_uji_fungsi.sql
-- =====================================================================
\pset footer off
\echo '=== 1. Isi data referensi'
SELECT 'kabkota' AS item, count(*) FROM ref.kabkota
UNION ALL SELECT 'kecamatan', count(*) FROM ref.kecamatan
UNION ALL SELECT 'desa+kelurahan', count(*) FROM ref.desa
UNION ALL SELECT 'kelurahan', count(*) FROM ref.desa WHERE jenis = 'Kelurahan'
UNION ALL SELECT 'klaster', count(*) FROM survei.klaster
UNION ALL SELECT 'klaster indikasi', count(*) FROM survei.klaster WHERE jenis = 'indikasi'
UNION ALL SELECT 'klaster ber-cadangan', count(*) FROM survei.klaster WHERE kode_desa_cadangan IS NOT NULL
UNION ALL SELECT 'kab koridor', count(*) FROM ref.kabkota WHERE koridor
UNION ALL SELECT 'bobot skor total', sum(bobot_persen)::int FROM analisis.bobot_skor;

\echo '=== 2. Pengguna (operator tanpa kab/kota harus DITOLAK)'
INSERT INTO app.pengguna (nama, email, kata_sandi_hash, peran) VALUES ('Admin Uji', 'admin@uji.local', 'x', 'admin');
INSERT INTO app.pengguna (nama, email, kata_sandi_hash, peran) VALUES ('Operator Uji', 'op@uji.local', 'x', 'operator');

\echo '=== 3. Impor penindakan: pratinjau -> simpan -> batalkan'
BEGIN;
SET LOCAL app.pengguna_id = '1';
INSERT INTO app.batch_impor (jenis, nama_file, diunggah_oleh, jumlah_baris) VALUES ('penindakan', 'uji.xlsx', 1, 2);
COMMIT;
INSERT INTO penindakan.operasi (batch_id, tahun, kode_kab, tanggal_mulai, bulan, hasil) VALUES (1, 2026, '33.20', '2026-02-20', 2, 'TEMUAN');
INSERT INTO penindakan.pelanggar (operasi_id, nama_pemilik, kecamatan_teks, kode_kec, ketelitian_lokasi)
SELECT 1, 'Contoh Pemilik', 'KEDUNG', kode, 'kecamatan' FROM ref.kecamatan WHERE nama = 'Kedung' AND kode_kab = '33.20';
INSERT INTO penindakan.temuan_merek (pelanggar_id, merek_asli, merek_id, jumlah_batang)
VALUES (1, 'marbol ', ref.fn_merek_id('marbol '), 400), (1, 'Hummer', ref.fn_merek_id('Hummer'), 200);
SELECT 'pelanggar tampil saat pratinjau (harus 0)' AS uji, count(*) FROM penindakan.v_pelanggar;
BEGIN; SET LOCAL app.pengguna_id = '1'; SELECT app.simpan_impor(1); COMMIT;
SELECT 'setelah disimpan (harus 1 pelanggar, 600 batang)' AS uji, count(*), sum(jumlah_batang) FROM penindakan.v_pelanggar;
SELECT kode_kab, tahun, operasi, pelanggar, jumlah_batang FROM penindakan.v_rekap_kabkota;
SELECT merek, tahun, jumlah_batang, pelanggar FROM penindakan.v_rekap_merek ORDER BY merek;
SELECT kode, pelanggar, jumlah_batang FROM publik.v_pelanggar_kabkota WHERE kode = '33.20';
SELECT kode, nama, pelanggar FROM publik.v_pelanggar_kecamatan WHERE pelanggar > 0;
SELECT id, operasi_id, tahun, kode_kab, kode_kec, jumlah_batang FROM penindakan.v_pelanggar_anonim;
BEGIN; SET LOCAL app.pengguna_id = '1'; SELECT app.batalkan_impor(1); COMMIT;
SELECT 'setelah dibatalkan (harus 0)' AS uji, count(*) FROM penindakan.v_pelanggar;

\echo '=== 4. Log audit'
SELECT id, pengguna_id, aksi, tabel, id_baris, data_baru ->> 'status' AS status_baru FROM app.log_audit WHERE tabel <> 'survei.klaster' AND tabel <> 'analisis.bobot_skor' ORDER BY id;
SELECT 'hash kata sandi ikut tercatat (harus 0)' AS uji, count(*) FROM app.log_audit WHERE data_baru ? 'kata_sandi_hash' OR data_lama ? 'kata_sandi_hash';

\echo '=== 5. Survei: kolom berindikasi (harus f, t, t)'
INSERT INTO app.batch_impor (jenis) VALUES ('survei_kobo');
INSERT INTO survei.kunjungan_outlet (id, batch_id, id_outlet, jenis_sampel, id_klaster, nomor_urut, surveyor_kode, waktu_tiba, status_outlet, jenis_outlet, menjual_rokok, ada_polos, pita_mencurigakan, data_mentah) VALUES
 (gen_random_uuid(), 2, '3320-1-01', 'area', '3320-1', 1, 'S02', now(), 'buka', 1, true, 2, '{e}', '{}'),
 (gen_random_uuid(), 2, '3320-1-02', 'area', '3320-1', 2, 'S02', now(), 'buka', 1, true, 1, '{}', '{}'),
 (gen_random_uuid(), 2, '3320-1-03', 'area', '3320-1', 3, 'S02', now(), 'buka', 2, true, 2, '{a}', '{}');
SELECT id_outlet, ada_polos, pita_mencurigakan, berindikasi FROM survei.kunjungan_outlet ORDER BY id_outlet;
\echo '--- sampel area tanpa klaster harus DITOLAK'
INSERT INTO survei.kunjungan_outlet (id, id_outlet, jenis_sampel, surveyor_kode, waktu_tiba, status_outlet, data_mentah)
VALUES (gen_random_uuid(), 'X-1', 'area', 'S01', now(), 'buka', '{}');
\echo '--- konsumen porsi 11 dari 10 harus DITOLAK'
INSERT INTO survei.konsumen (id, id_klaster, surveyor_kode, waktu, porsi_ilegal_per10, data_mentah)
VALUES (gen_random_uuid(), '3320-1', 'S02', now(), 11, '{}');

\echo '=== 6. Rekomendasi: disetujui tanpa penyetuju harus DITOLAK, lalu yang lengkap DITERIMA'
INSERT INTO analisis.versi (deskripsi) VALUES ('uji');
INSERT INTO analisis.rekomendasi (versi_id, kode_kab, status) VALUES (1, '33.20', 'disetujui');
INSERT INTO analisis.rekomendasi (versi_id, kode_kab, status, disetujui_oleh, disetujui_pada) VALUES (1, '33.20', 'disetujui', 1, now());
SELECT id, kode_kab, status, disetujui_oleh FROM analisis.rekomendasi;

\echo '=== 7. View survei dan publik'
SELECT kode_desa, outlet_menjual, outlet_berindikasi FROM survei.v_proporsi_desa;
SELECT count(*) AS klaster_di_peta FROM publik.v_klaster;
SELECT id_klaster, jenis, desa FROM publik.v_klaster ORDER BY id_klaster LIMIT 3;

\echo '=== 8. Hak akses: baris kedua tiap peran harus DITOLAK'
SET ROLE peta_geoserver;
SELECT 'geoserver baca publik' AS uji, count(*) FROM publik.v_pelanggar_kabkota;
SELECT 'geoserver baca data pribadi' AS uji, count(*) FROM penindakan.pelanggar;
RESET ROLE;
SET ROLE peta_analis;
SELECT 'analis baca view anonim' AS uji, count(*) FROM penindakan.v_pelanggar_anonim;
SELECT 'analis baca nama pemilik' AS uji, nama_pemilik FROM penindakan.pelanggar;
RESET ROLE;
