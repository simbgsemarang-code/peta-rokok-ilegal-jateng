-- =====================================================================
-- Batas desa/kelurahan, kecamatan, dan kab/kota dari cincin poligon mentah
-- Prasyarat: 01_skema.sql, 02_data_referensi.sql, lalu SQL keluaran buat_batas_desa.php
-- (membuat tabel stg.cincin). Sumber: cahyadsn/wilayah_boundaries (MIT), poligon sudah disederhanakan
-- dan BUKAN batas resmi; ganti dengan batas BIG/BPS/Bappeda sebelum dipakai menetapkan batas.
-- =====================================================================
BEGIN;

-- 1. Desa/kelurahan: cincin pertama + cincin lain yang terpisah = bagian; cincin yang berada di dalam
--    cincin pertama = lubang (enklaf).
WITH r AS (SELECT kode, idx, ST_MakeValid(geom) AS g FROM stg.cincin),
     induk AS (SELECT kode, g FROM r WHERE idx = 0),
     bagian AS (SELECT r.kode, r.g FROM r JOIN induk i ON i.kode = r.kode WHERE r.idx = 0 OR NOT ST_Contains(i.g, r.g)),
     lubang AS (SELECT r.kode, r.g FROM r JOIN induk i ON i.kode = r.kode WHERE r.idx > 0 AND ST_Contains(i.g, r.g)),
     gab AS (SELECT kode, ST_Union(g) AS g FROM bagian GROUP BY kode),
     lub AS (SELECT kode, ST_Union(g) AS g FROM lubang GROUP BY kode)
UPDATE ref.desa d
SET geom = ST_Multi(ST_CollectionExtract(ST_MakeValid(COALESCE(ST_Difference(gab.g, lub.g), gab.g)), 3))
FROM gab LEFT JOIN lub ON lub.kode = gab.kode
WHERE gab.kode = d.kode;

-- 2. Kecamatan = gabungan desa/kelurahannya (celah tipis akibat penyederhanaan ditutup ±45 m)
UPDATE ref.kecamatan c
SET geom = ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_Buffer(ST_Buffer(u.g, 0.0004), -0.0004)), 3))
FROM (SELECT kode_kec, ST_Union(geom) AS g FROM ref.desa WHERE geom IS NOT NULL GROUP BY kode_kec) u
WHERE u.kode_kec = c.kode;

-- 3. Kab/kota = gabungan kecamatannya, agar tingkatannya saling sambung (menggantikan batas sementara)
UPDATE ref.kabkota k
SET geom = ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_Buffer(ST_Buffer(u.g, 0.0006), -0.0006)), 3))
FROM (SELECT kode_kab, ST_Union(geom) AS g FROM ref.kecamatan WHERE geom IS NOT NULL GROUP BY kode_kab) u
WHERE u.kode_kab = k.kode;

COMMIT;
-- Setelah diperiksa: DROP SCHEMA stg CASCADE;
