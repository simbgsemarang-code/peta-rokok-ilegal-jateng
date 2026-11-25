-- =====================================================================
-- Skema basis data
-- Aplikasi Peta Digital Geospasial Peredaran Rokok Ilegal Jawa Tengah
-- PostgreSQL 15 ke atas, PostGIS 3. Sistem koordinat: WGS84 (EPSG:4326)
-- Kode wilayah: Kepmendagri (mis. kab '33.20', kec '33.20.01', desa '33.20.01.2001')
--
-- Urutan menjalankan: 01_skema.sql, lalu 02_data_referensi.sql
-- Status: rancangan awal, belum diuji pada server PostgreSQL.
-- =====================================================================

CREATE EXTENSION IF NOT EXISTS postgis;

CREATE SCHEMA IF NOT EXISTS ref;        -- wilayah, merek, tarif, data pendukung
CREATE SCHEMA IF NOT EXISTS app;        -- pengguna, batch impor, log audit
CREATE SCHEMA IF NOT EXISTS penindakan; -- DATA GABUNGAN OPERASI CUKAI dan pembaruannya
CREATE SCHEMA IF NOT EXISTS survei;     -- hasil survei lapangan (KoboToolbox)
CREATE SCHEMA IF NOT EXISTS analisis;   -- bobot, estimasi, hotspot, skor, rekomendasi
CREATE SCHEMA IF NOT EXISTS publik;     -- view tanpa data pribadi untuk GeoServer/penampil

-- =====================================================================
-- 1. REFERENSI WILAYAH DAN MASTER DATA
-- =====================================================================

CREATE TABLE ref.kabkota (
  kode         varchar(5)  PRIMARY KEY,               -- '33.20'
  kode_bps     char(4)     NOT NULL UNIQUE,           -- '3320'
  nama         text        NOT NULL,                  -- 'Kabupaten Jepara'
  jenis        text        NOT NULL CHECK (jenis IN ('Kabupaten', 'Kota')),
  wilayah_eks  text        NOT NULL CHECK (wilayah_eks IN ('Semarang', 'Pati', 'Surakarta', 'Pekalongan', 'Banyumas', 'Kedu')),
  koridor      boolean     NOT NULL DEFAULT false,    -- sitaan >= 50.000 batang dalam satu baris data
  geom         geometry(MultiPolygon, 4326)
);
COMMENT ON TABLE ref.kabkota IS '35 kabupaten/kota Jawa Tengah dengan batas wilayah';

CREATE TABLE ref.kecamatan (
  kode      varchar(8)  PRIMARY KEY,                  -- '33.20.01'
  kode_kab  varchar(5)  NOT NULL REFERENCES ref.kabkota (kode),
  nama      text        NOT NULL,
  geom      geometry(MultiPolygon, 4326)
);

CREATE TABLE ref.desa (
  kode      varchar(13) PRIMARY KEY,                  -- '33.20.01.2001'
  kode_kec  varchar(8)  NOT NULL REFERENCES ref.kecamatan (kode),
  nama      text        NOT NULL,
  jenis     text        GENERATED ALWAYS AS (CASE WHEN substr(kode, 10, 1) = '1' THEN 'Kelurahan' ELSE 'Desa' END) STORED,
  geom      geometry(MultiPolygon, 4326)
);

CREATE TABLE ref.poi (                                -- pasar, terminal, SPBU, pabrik, pelabuhan
  id      bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  jenis   text   NOT NULL,
  nama    text,
  geom    geometry(Point, 4326) NOT NULL,
  sumber  text
);

CREATE TABLE ref.merek (
  id         integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  nama_baku  text    NOT NULL UNIQUE,                 -- 'MARBOL'
  alias      text[]  NOT NULL DEFAULT '{}',           -- {'MARBOL FILTER BLACK','MARBOLL'}
  catatan    text
);

CREATE TABLE ref.data_pendukung (                     -- Podes/Dinas Perdagangan, Susenas
  kode_wilayah           varchar(13) NOT NULL,        -- kode kab atau kecamatan
  tingkat                text        NOT NULL CHECK (tingkat IN ('kabkota', 'kecamatan')),
  tahun                  smallint    NOT NULL,
  jumlah_outlet_rokok    integer,
  jumlah_perokok_dewasa  integer,
  sumber                 text,
  PRIMARY KEY (kode_wilayah, tahun)
);

-- =====================================================================
-- 2. APLIKASI: PENGGUNA, BATCH IMPOR, AUDIT
-- =====================================================================

CREATE TABLE app.pengguna (
  id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  nama             text        NOT NULL,
  email            text        NOT NULL UNIQUE,
  kata_sandi_hash  text        NOT NULL,
  peran            text        NOT NULL CHECK (peran IN ('admin', 'operator', 'penampil')),
  kode_kab         varchar(5)  REFERENCES ref.kabkota (kode),   -- wajib untuk operator
  totp_rahasia     text,                                         -- 2FA opsional
  aktif            boolean     NOT NULL DEFAULT true,
  dibuat_pada      timestamptz NOT NULL DEFAULT now(),
  CHECK (peran <> 'operator' OR kode_kab IS NOT NULL)
);

CREATE TABLE app.batch_impor (
  id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  jenis            text        NOT NULL CHECK (jenis IN ('penindakan', 'survei_kobo', 'batas_wilayah', 'data_pendukung')),
  nama_file        text,
  diunggah_oleh    bigint      REFERENCES app.pengguna (id),
  diunggah_pada    timestamptz NOT NULL DEFAULT now(),
  jumlah_baris     integer,
  jumlah_ditolak   integer     NOT NULL DEFAULT 0,
  status           text        NOT NULL DEFAULT 'pratinjau' CHECK (status IN ('pratinjau', 'disimpan', 'dibatalkan')),
  disimpan_pada    timestamptz,
  dibatalkan_pada  timestamptz,
  catatan          text
);
COMMENT ON TABLE app.batch_impor IS 'Setiap unggahan menjadi satu versi data; batch dibatalkan = rollback';

CREATE TABLE app.log_audit (
  id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  waktu        timestamptz NOT NULL DEFAULT now(),
  pengguna_id  bigint,                 -- tanpa FK agar log tetap ada walau pengguna dihapus
  aksi         text        NOT NULL,   -- INSERT, UPDATE, DELETE, BATAL_IMPOR, ...
  tabel        text        NOT NULL,
  id_baris     text,
  data_lama    jsonb,
  data_baru    jsonb
);

-- Tarif untuk modul estimasi kerugian (diisi dan diubah admin)
CREATE TABLE ref.tarif (
  id                      integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  berlaku_mulai           date          NOT NULL,
  jenis_produk            text          NOT NULL,   -- SKM, SPM, SKT, ...
  golongan                text          NOT NULL,
  tarif_cukai_per_batang  numeric(12,2) NOT NULL,
  hje_minimum_per_batang  numeric(12,2),
  pajak_rokok_persen      numeric(5,2),             -- isi sesuai regulasi berlaku
  ppn_ht_persen           numeric(5,2),             -- isi sesuai regulasi berlaku
  dasar_regulasi          text,
  diubah_oleh             bigint REFERENCES app.pengguna (id),
  diubah_pada             timestamptz   NOT NULL DEFAULT now(),
  UNIQUE (berlaku_mulai, jenis_produk, golongan)
);

-- =====================================================================
-- 3. DATA PENINDAKAN (DATA GABUNGAN OPERASI CUKAI 2023-2026 dan seterusnya)
--    Satu operasi -> banyak pelanggar -> banyak temuan per merek
-- =====================================================================

CREATE TABLE penindakan.operasi (
  id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  batch_id       bigint      NOT NULL REFERENCES app.batch_impor (id),
  tahun          smallint    NOT NULL CHECK (tahun BETWEEN 2000 AND 2100),
  kode_kab       varchar(5)  NOT NULL REFERENCES ref.kabkota (kode),
  tim            text,
  tanggal_asli   text,                   -- teks asli dari berkas, mis. '1 - 2 APRIL 2026'
  tanggal_mulai  date,
  bulan          smallint    CHECK (bulan BETWEEN 1 AND 12),
  hasil          text        NOT NULL CHECK (hasil IN ('TEMUAN', 'NIHIL', 'PEMBINAAN/SOSIALISASI')),
  sumber         text,                   -- nama berkas/sheet asal
  aktif          boolean     NOT NULL DEFAULT true
);

CREATE TABLE penindakan.pelanggar (
  id                    bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  operasi_id            bigint      NOT NULL REFERENCES penindakan.operasi (id) ON DELETE CASCADE,
  nama_pemilik          text,       -- data pribadi: hanya untuk admin
  nama_toko_kendaraan   text,       -- data pribadi: hanya untuk admin
  alamat                text,       -- data pribadi: hanya untuk admin
  kecamatan_teks        text,       -- kecamatan hasil ekstraksi alamat, sebelum dicocokkan
  kode_kec              varchar(8)  REFERENCES ref.kecamatan (kode),
  kode_desa             varchar(13) REFERENCES ref.desa (kode),
  geom                  geometry(Point, 4326),
  ketelitian_lokasi     text        NOT NULL DEFAULT 'kabkota' CHECK (ketelitian_lokasi IN ('titik', 'desa', 'kecamatan', 'kabkota')),
  cara_penentuan        text        CHECK (cara_penentuan IN ('gps', 'geocoding_otomatis', 'manual'))
);

CREATE TABLE penindakan.temuan_merek (
  id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  pelanggar_id   bigint  NOT NULL REFERENCES penindakan.pelanggar (id) ON DELETE CASCADE,
  merek_asli     text    NOT NULL,
  merek_id       integer REFERENCES ref.merek (id),
  jumlah_batang  integer NOT NULL CHECK (jumlah_batang >= 0)
);

-- =====================================================================
-- 4. SURVEI LAPANGAN (kode kolom mengikuti XLSForm dan formulir cetak)
-- =====================================================================

CREATE TABLE survei.surveyor (
  kode        varchar(3) PRIMARY KEY,   -- 'S01'
  nama        text,
  pendamping  text                      -- tenaga ahli pendamping
);

CREATE TABLE survei.klaster (
  id_klaster            varchar(10) PRIMARY KEY,   -- '3317-1'
  kode_kab              varchar(5)  NOT NULL REFERENCES ref.kabkota (kode),
  nomor                 smallint    NOT NULL,
  jenis                 text        NOT NULL CHECK (jenis IN ('indikasi', 'pembanding')),
  kode_kec              varchar(8)  REFERENCES ref.kecamatan (kode),
  kode_desa             varchar(13) REFERENCES ref.desa (kode),
  kode_desa_cadangan    varchar(13) REFERENCES ref.desa (kode),
  desa_cadangan_dipakai boolean     NOT NULL DEFAULT false,
  alasan_cadangan       text,
  surveyor_kode         varchar(3)  REFERENCES survei.surveyor (kode),
  urutan_kerja          smallint,
  hari_mulai            smallint,
  hari_selesai          smallint,
  outlet_target         smallint,
  konsumen_target       smallint,
  peluang_kecamatan     numeric,    -- untuk bobot desain
  peluang_desa          numeric,
  titik_awal            text,       -- K3
  titik_awal_geom       geometry(Point, 4326),
  interval_outlet       smallint    CHECK (interval_outlet IN (1, 2)),   -- K4
  status                text        NOT NULL DEFAULT 'belum' CHECK (status IN ('belum', 'berjalan', 'selesai')),
  UNIQUE (kode_kab, nomor)
);

CREATE TABLE survei.lembar_klaster (                 -- formulir Lembar Klaster (bagian D)
  id               uuid        PRIMARY KEY,          -- _uuid KoboToolbox
  batch_id         bigint      REFERENCES app.batch_impor (id),
  id_klaster       varchar(10) NOT NULL REFERENCES survei.klaster (id_klaster),
  surveyor_kode    varchar(3)  NOT NULL REFERENCES survei.surveyor (kode),
  waktu            timestamptz NOT NULL,
  titik_awal       text,
  titik_awal_geom  geometry(Point, 4326),
  interval_outlet  smallint,
  sekitar_klaster  text[],     -- D1: agen/grosir, gudang, ekspedisi, kendaraan bongkar muat
  outlet_selesai   smallint,   -- K5
  catatan          text,
  data_mentah      jsonb       NOT NULL
);

CREATE TABLE survei.titik_distribusi (               -- D2, satu baris per titik
  id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  lembar_id    uuid        REFERENCES survei.lembar_klaster (id) ON DELETE CASCADE,
  id_klaster   varchar(10) NOT NULL REFERENCES survei.klaster (id_klaster),
  jenis        text        NOT NULL,
  geom         geometry(Point, 4326) NOT NULL,
  keterangan   text
);

CREATE TABLE survei.kunjungan_outlet (               -- formulir observasi (A-C, E)
  id                     uuid        PRIMARY KEY,    -- _uuid KoboToolbox
  batch_id               bigint      REFERENCES app.batch_impor (id),
  id_outlet              varchar(30) NOT NULL UNIQUE,   -- A1, mis. '3315-2-04' atau 'U-...'
  jenis_sampel           text        NOT NULL CHECK (jenis_sampel IN ('area', 'ulang')),   -- A2
  pelanggar_ref_id       bigint      REFERENCES penindakan.pelanggar (id),   -- kunjungan ulang
  referensi_ulang        text,       -- bila outlet binaan tidak ada di data penindakan
  id_klaster             varchar(10) REFERENCES survei.klaster (id_klaster),
  nomor_urut             smallint,
  surveyor_kode          varchar(3)  NOT NULL REFERENCES survei.surveyor (kode),
  waktu_tiba             timestamptz NOT NULL,                 -- A3
  geom                   geometry(Point, 4326),                -- A5
  akurasi_gps_m          real,
  kode_desa              varchar(13) REFERENCES ref.desa (kode),   -- diisi otomatis dari geom
  patokan                text,                                 -- A7
  foto_depan_url         text,                                 -- A8
  status_outlet          text        NOT NULL CHECK (status_outlet IN ('buka', 'tutup', 'tidak_ada')),   -- A9
  jenis_outlet           smallint    CHECK (jenis_outlet BETWEEN 1 AND 7),   -- B1
  lingkungan             text[]      NOT NULL DEFAULT '{}',    -- B2
  menjual_rokok          boolean,                              -- B3
  jumlah_merek_kelas     smallint,                             -- C1
  ada_polos              smallint    CHECK (ada_polos IN (1, 2, 3)),   -- C2
  pita_mencurigakan      text[]      NOT NULL DEFAULT '{}',    -- C3: a, b, c, d, f atau e
  letak_ilegal           smallint,                             -- C4
  legal_termurah_merek   text,                                 -- C6
  legal_termurah_isi     smallint,
  legal_termurah_harga   integer,
  situasi_tidak_aman     boolean,                              -- E2
  catatan                text,                                 -- E3
  berindikasi            boolean GENERATED ALWAYS AS (
                           COALESCE(ada_polos = 1, false)
                           OR COALESCE(pita_mencurigakan && ARRAY['a', 'b', 'c', 'd', 'f']::text[], false)
                         ) STORED,
  data_mentah            jsonb       NOT NULL,
  dibuat_pada            timestamptz NOT NULL DEFAULT now(),
  CHECK (jenis_sampel <> 'area' OR id_klaster IS NOT NULL)
);
COMMENT ON COLUMN survei.kunjungan_outlet.berindikasi IS 'Outlet berindikasi rokok ilegal: C2 = 1 atau C3 berisi a-d/f';

CREATE TABLE survei.rokok_terindikasi (              -- rincian C5 dan kuesioner 2.3
  id                   bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  kunjungan_id         uuid     NOT NULL REFERENCES survei.kunjungan_outlet (id) ON DELETE CASCADE,
  nomor                smallint NOT NULL CHECK (nomor BETWEEN 1 AND 5),
  merek_asli           text,
  merek_id             integer  REFERENCES ref.merek (id),
  isi_per_bungkus      smallint,
  jenis_pita           smallint CHECK (jenis_pita IN (1, 2)),   -- 1 polos, 2 mencurigakan
  hje_pita             integer,
  stok_bungkus         integer,
  harga_beli           integer,
  harga_jual           integer,
  terjual_per_minggu   integer,
  foto_url             text,
  UNIQUE (kunjungan_id, nomor)
);

CREATE TABLE survei.wawancara_pedagang (
  kunjungan_id          uuid PRIMARY KEY REFERENCES survei.kunjungan_outlet (id) ON DELETE CASCADE,
  hasil_permintaan      smallint,   -- 0.1
  responden             smallint,   -- 0.2
  lama_berjualan_tahun  smallint,   -- 1.1
  porsi_pendapatan      smallint,   -- 1.2
  penjualan_mingguan    smallint,   -- 1.3 (kelas)
  jual_ketengan         boolean,    -- 1.4
  sumber_pasokan        smallint[], -- 3.1
  sumber_pasokan_ilegal smallint,   -- 3.2
  asal_pemasok_kab      varchar(5)  REFERENCES ref.kabkota (kode),   -- 3.3 (Jawa Tengah)
  asal_pemasok_luar     text,       -- 3.3 (luar Jawa Tengah)
  cara_antar            smallint,   -- 3.4
  frekuensi_pasok       smallint,   -- 3.5
  cara_bayar            smallint,   -- 3.7
  ada_nota              smallint,   -- 3.8
  lama_menjual_ilegal   smallint,   -- 3.9
  tahu_pita             boolean,    -- 4.1
  bisa_ciri_pita        boolean,    -- 4.2
  pernah_sosialisasi    boolean,    -- 4.3
  persepsi_jumlah_warung smallint,  -- 4.4
  tren                  smallint,   -- 4.5
  peluang_ditindak      smallint,   -- 4.6
  didatangi_petugas     smallint,   -- 4.7
  perubahan_setelah     smallint,   -- 4.8
  menjual_ilegal        smallint,   -- 4.9
  alasan_menjual        smallint,   -- 4.10
  alasan_berhenti       smallint,   -- 4.11
  cara_efektif          smallint[], -- 4.12
  kelancaran            smallint,   -- 5.2
  data_mentah           jsonb       NOT NULL
);

CREATE TABLE survei.konsumen (                       -- tanpa titik GPS demi anonimitas
  id                     uuid        PRIMARY KEY,
  batch_id               bigint      REFERENCES app.batch_impor (id),
  id_responden           varchar(20) UNIQUE,      -- '3315-2-K3'
  id_klaster             varchar(10) NOT NULL REFERENCES survei.klaster (id_klaster),
  surveyor_kode          varchar(3)  NOT NULL REFERENCES survei.surveyor (kode),
  waktu                  timestamptz NOT NULL,
  jenis_lokasi           smallint,    -- A4
  bersedia               boolean,     -- 0.1
  usia_18                boolean,     -- 0.2
  merokok_30hari         boolean,     -- 0.3
  jenis_kelamin          smallint,    -- 1.1
  kelompok_umur          smallint,    -- 1.2
  pekerjaan              smallint,    -- 1.3
  penghasilan            smallint,    -- 1.4
  jenis_rokok            smallint,    -- 2.1
  batang_per_hari        smallint     CHECK (batang_per_hari BETWEEN 1 AND 80),   -- 2.2
  cara_beli              smallint,    -- 2.3
  merek_utama            text,        -- 2.4
  harga_biasa            integer,     -- 2.5
  tempat_beli            smallint,    -- 2.6
  cek_bungkus            smallint,    -- 3.1
  bungkus_merek          text,        -- 3.2
  bungkus_isi            smallint,
  kondisi_pita           smallint,    -- 3.3
  pita_mencurigakan      text[]       NOT NULL DEFAULT '{}',   -- 3.4
  hje_pita               integer,     -- 3.5
  harga_beli_bungkus     integer,     -- 3.6
  pernah_ilegal_30hari   smallint,    -- 4.1
  porsi_ilegal_per10     smallint     CHECK (porsi_ilegal_per10 BETWEEN 0 AND 10),   -- 4.2
  alasan_ilegal          smallint[],  -- 4.3
  harga_ilegal           integer,     -- 4.4
  sumber_ilegal          smallint,    -- 4.5
  bila_tidak_dijual      smallint,    -- 4.6
  alasan_tidak           smallint,    -- 4.7
  tahu_pita              boolean,     -- 5.1
  bisa_ciri_pita         boolean,     -- 5.2
  pernah_info            boolean,     -- 5.3
  kemudahan_dapat        smallint,    -- 5.4
  tren                   smallint,    -- 5.5
  setuju_ditindak        smallint,    -- 5.6
  kelancaran             smallint,    -- 6.1
  situasi_tidak_aman     boolean,     -- 6.2
  data_mentah            jsonb        NOT NULL,
  dibuat_pada            timestamptz  NOT NULL DEFAULT now()
);

-- =====================================================================
-- 5. ANALISIS: VERSI, BOBOT, ESTIMASI, HOTSPOT, SKOR, REKOMENDASI
-- =====================================================================

CREATE TABLE analisis.versi (
  id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  dibuat_pada  timestamptz NOT NULL DEFAULT now(),
  dibuat_oleh  bigint      REFERENCES app.pengguna (id),
  deskripsi    text,
  parameter    jsonb,      -- deff, tarif yang dipakai, bobot skor, dll.
  status       text        NOT NULL DEFAULT 'draf' CHECK (status IN ('draf', 'final'))
);

CREATE TABLE analisis.bobot_skor (
  id            integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  komponen      text          NOT NULL CHECK (komponen IN ('estimasi_kerugian', 'proporsi_berindikasi', 'hotspot', 'tren_penindakan', 'efek_pembinaan')),
  bobot_persen  numeric(5,2)  NOT NULL CHECK (bobot_persen BETWEEN 0 AND 100),
  berlaku_mulai timestamptz   NOT NULL DEFAULT now(),
  diubah_oleh   bigint        REFERENCES app.pengguna (id)
);

CREATE TABLE analisis.bobot_outlet (
  versi_id      bigint  NOT NULL REFERENCES analisis.versi (id) ON DELETE CASCADE,
  kunjungan_id  uuid    NOT NULL REFERENCES survei.kunjungan_outlet (id) ON DELETE CASCADE,
  bobot_desain  numeric NOT NULL,
  bobot_akhir   numeric NOT NULL,   -- setelah penyesuaian nonrespons dan kalibrasi
  PRIMARY KEY (versi_id, kunjungan_id)
);

CREATE TABLE analisis.estimasi_kabkota (
  versi_id             bigint     NOT NULL REFERENCES analisis.versi (id) ON DELETE CASCADE,
  kode_kab             varchar(5) NOT NULL REFERENCES ref.kabkota (kode),
  sisi                 text       NOT NULL CHECK (sisi IN ('outlet', 'konsumen')),
  metode               text       NOT NULL CHECK (metode IN ('langsung', 'fay_herriot', 'skenario')),   -- skenario: belum ada data survei, memakai asumsi admin
  proporsi             numeric,
  galat_baku           numeric,
  ci_bawah             numeric,
  ci_atas              numeric,
  volume_batang_tahun  numeric,
  kerugian_rp          numeric,
  kerugian_rp_bawah    numeric,
  kerugian_rp_atas     numeric,
  PRIMARY KEY (versi_id, kode_kab, sisi, metode)
);

CREATE TABLE analisis.hotspot (
  versi_id      bigint      NOT NULL REFERENCES analisis.versi (id) ON DELETE CASCADE,
  tingkat       text        NOT NULL CHECK (tingkat IN ('desa', 'kecamatan')),
  kode_wilayah  varchar(13) NOT NULL,
  nilai_gi      numeric,
  nilai_p       numeric,
  kategori      text        NOT NULL CHECK (kategori IN ('hotspot_99', 'hotspot_95', 'hotspot_90', 'tidak_signifikan', 'coldspot_90', 'coldspot_95', 'coldspot_99')),
  PRIMARY KEY (versi_id, tingkat, kode_wilayah)
);

CREATE TABLE analisis.skor_prioritas (
  versi_id   bigint     NOT NULL REFERENCES analisis.versi (id) ON DELETE CASCADE,
  kode_kab   varchar(5) NOT NULL REFERENCES ref.kabkota (kode),
  komponen   jsonb      NOT NULL,   -- nilai ternormalisasi tiap komponen
  skor       numeric    NOT NULL,
  peringkat  smallint,
  kategori   text       CHECK (kategori IN ('tinggi', 'sedang', 'rendah')),
  PRIMARY KEY (versi_id, kode_kab)
);

CREATE TABLE analisis.rekomendasi (
  id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  versi_id          bigint      NOT NULL REFERENCES analisis.versi (id),
  kode_kab          varchar(5)  NOT NULL REFERENCES ref.kabkota (kode),
  lokasi_prioritas  text,
  waktu_operasi     text,
  sasaran           text,       -- merek dan jalur
  pendekatan        text,       -- penindakan atau pembinaan
  status            text        NOT NULL DEFAULT 'draf' CHECK (status IN ('draf', 'disetujui', 'ditolak')),
  disusun_oleh      bigint      REFERENCES app.pengguna (id),
  disunting_oleh    bigint      REFERENCES app.pengguna (id),
  disetujui_oleh    bigint      REFERENCES app.pengguna (id),
  disetujui_pada    timestamptz,
  UNIQUE (versi_id, kode_kab),
  CHECK (status <> 'disetujui' OR (disetujui_oleh IS NOT NULL AND disetujui_pada IS NOT NULL))
);
COMMENT ON TABLE analisis.rekomendasi IS 'Draf rekomendasi; keputusan akhir tetap pada Satpol PP';

-- =====================================================================
-- 6. INDEKS
-- =====================================================================

CREATE INDEX ON ref.kabkota USING gist (geom);
CREATE INDEX ON ref.kecamatan USING gist (geom);
CREATE INDEX ON ref.kecamatan (kode_kab);
CREATE INDEX ON ref.desa USING gist (geom);
CREATE INDEX ON ref.desa (kode_kec);
CREATE INDEX ON ref.poi USING gist (geom);
CREATE INDEX ON ref.merek USING gin (alias);
CREATE INDEX ON penindakan.operasi (kode_kab, tahun);
CREATE INDEX ON penindakan.operasi (batch_id);
CREATE INDEX ON penindakan.pelanggar (operasi_id);
CREATE INDEX ON penindakan.pelanggar (kode_kec);
CREATE INDEX ON penindakan.pelanggar USING gist (geom);
CREATE INDEX ON penindakan.temuan_merek (pelanggar_id);
CREATE INDEX ON penindakan.temuan_merek (merek_id);
CREATE INDEX ON survei.klaster (kode_kab);
CREATE INDEX ON survei.klaster USING gist (titik_awal_geom);
CREATE INDEX ON survei.titik_distribusi USING gist (geom);
CREATE INDEX ON survei.kunjungan_outlet (id_klaster);
CREATE INDEX ON survei.kunjungan_outlet (kode_desa);
CREATE INDEX ON survei.kunjungan_outlet USING gist (geom);
CREATE INDEX ON survei.rokok_terindikasi (kunjungan_id);
CREATE INDEX ON survei.konsumen (id_klaster);

-- =====================================================================
-- 7. FUNGSI DAN TRIGGER
-- =====================================================================

-- 7.1 Isi kode desa/kecamatan otomatis dari titik GPS
CREATE OR REPLACE FUNCTION survei.fn_isi_desa_outlet() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.geom IS NOT NULL THEN
    SELECT d.kode INTO NEW.kode_desa
    FROM ref.desa d
    WHERE d.geom IS NOT NULL AND ST_Intersects(d.geom, NEW.geom)
    LIMIT 1;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER trg_isi_desa_outlet
BEFORE INSERT OR UPDATE OF geom ON survei.kunjungan_outlet
FOR EACH ROW EXECUTE FUNCTION survei.fn_isi_desa_outlet();

CREATE OR REPLACE FUNCTION penindakan.fn_isi_wilayah_pelanggar() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
  v_desa varchar(13);
BEGIN
  -- Titik hasil geocoding alamat hanyalah perkiraan (sering titik tengah kecamatan): tidak dipakai
  -- untuk menentukan desa atau menaikkan ketelitian menjadi 'titik'.
  IF NEW.geom IS NOT NULL AND COALESCE(NEW.cara_penentuan, '') <> 'geocoding_otomatis' THEN
    SELECT d.kode INTO v_desa
    FROM ref.desa d
    WHERE d.geom IS NOT NULL AND ST_Intersects(d.geom, NEW.geom)
    LIMIT 1;
    IF v_desa IS NOT NULL THEN
      NEW.kode_desa := v_desa;
      NEW.kode_kec := substr(v_desa, 1, 8);
      NEW.ketelitian_lokasi := 'titik';
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER trg_isi_wilayah_pelanggar
BEFORE INSERT OR UPDATE OF geom ON penindakan.pelanggar
FOR EACH ROW EXECUTE FUNCTION penindakan.fn_isi_wilayah_pelanggar();

-- 7.2 Normalisasi nama merek ke ref.merek
CREATE OR REPLACE FUNCTION ref.fn_merek_id(p_nama text) RETURNS integer
LANGUAGE sql STABLE AS $$
  SELECT m.id
  FROM ref.merek m
  WHERE upper(trim(p_nama)) = m.nama_baku
     OR upper(trim(p_nama)) = ANY (m.alias)
  LIMIT 1;
$$;

-- 7.3 Log audit umum. Aplikasi menyetel id pengguna per transaksi:
--     SET LOCAL app.pengguna_id = '12';
CREATE OR REPLACE FUNCTION app.fn_audit() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  INSERT INTO app.log_audit (pengguna_id, aksi, tabel, id_baris, data_lama, data_baru)
  VALUES (
    NULLIF(current_setting('app.pengguna_id', true), '')::bigint,
    TG_OP,
    TG_TABLE_SCHEMA || '.' || TG_TABLE_NAME,
    CASE WHEN TG_OP = 'DELETE'
         THEN COALESCE(to_jsonb(OLD) ->> 'id', to_jsonb(OLD) ->> 'id_klaster')
         ELSE COALESCE(to_jsonb(NEW) ->> 'id', to_jsonb(NEW) ->> 'id_klaster') END,
    -- kata sandi dan rahasia 2FA tidak ikut disalin ke log
    CASE WHEN TG_OP = 'INSERT' THEN NULL ELSE to_jsonb(OLD) - 'kata_sandi_hash' - 'totp_rahasia' END,
    CASE WHEN TG_OP = 'DELETE' THEN NULL ELSE to_jsonb(NEW) - 'kata_sandi_hash' - 'totp_rahasia' END
  );
  RETURN NULL;
END;
$$;

CREATE TRIGGER trg_audit AFTER INSERT OR UPDATE OR DELETE ON app.pengguna         FOR EACH ROW EXECUTE FUNCTION app.fn_audit();
CREATE TRIGGER trg_audit AFTER INSERT OR UPDATE OR DELETE ON app.batch_impor      FOR EACH ROW EXECUTE FUNCTION app.fn_audit();
CREATE TRIGGER trg_audit AFTER INSERT OR UPDATE OR DELETE ON ref.tarif            FOR EACH ROW EXECUTE FUNCTION app.fn_audit();
CREATE TRIGGER trg_audit AFTER INSERT OR UPDATE OR DELETE ON analisis.bobot_skor  FOR EACH ROW EXECUTE FUNCTION app.fn_audit();
CREATE TRIGGER trg_audit AFTER INSERT OR UPDATE OR DELETE ON analisis.rekomendasi FOR EACH ROW EXECUTE FUNCTION app.fn_audit();
CREATE TRIGGER trg_audit AFTER INSERT OR UPDATE OR DELETE ON survei.klaster       FOR EACH ROW EXECUTE FUNCTION app.fn_audit();

-- 7.4 Simpan dan batalkan impor (versi data dan rollback)
CREATE OR REPLACE FUNCTION app.simpan_impor(p_batch bigint) RETURNS void
LANGUAGE sql AS $$
  UPDATE app.batch_impor SET status = 'disimpan', disimpan_pada = now()
  WHERE id = p_batch AND status = 'pratinjau';
$$;

CREATE OR REPLACE FUNCTION app.batalkan_impor(p_batch bigint) RETURNS void
LANGUAGE plpgsql AS $$
BEGIN
  UPDATE app.batch_impor SET status = 'dibatalkan', dibatalkan_pada = now() WHERE id = p_batch;
  UPDATE penindakan.operasi SET aktif = false WHERE batch_id = p_batch;
END;
$$;

-- =====================================================================
-- 8. VIEW: DATA AKTIF, REKAP (pengganti sheet REKAP), DAN LAPISAN PETA
-- =====================================================================

-- 8.1 Pelanggar aktif (hanya batch yang disimpan). Memuat data pribadi: khusus admin.
CREATE VIEW penindakan.v_pelanggar AS
SELECT p.*, o.tahun, o.kode_kab, o.tanggal_mulai, o.bulan, o.batch_id,
       COALESCE(t.jumlah_batang, 0) AS jumlah_batang
FROM penindakan.pelanggar p
JOIN penindakan.operasi o ON o.id = p.operasi_id
JOIN app.batch_impor b ON b.id = o.batch_id
LEFT JOIN (SELECT pelanggar_id, sum(jumlah_batang) AS jumlah_batang
           FROM penindakan.temuan_merek GROUP BY pelanggar_id) t ON t.pelanggar_id = p.id
WHERE o.aktif AND b.status = 'disimpan';

-- 8.2 Versi tanpa data pribadi, untuk analis dan operator
CREATE VIEW penindakan.v_pelanggar_anonim AS
SELECT id, operasi_id, tahun, kode_kab, kode_kec, kode_desa, tanggal_mulai, bulan,
       ketelitian_lokasi, jumlah_batang
FROM penindakan.v_pelanggar;

-- 8.3 Rekap otomatis (menggantikan sheet REKAP KAB-KOTA, REKAP BULANAN, REKAP MERK)
CREATE VIEW penindakan.v_rekap_kabkota AS
SELECT o.kode_kab, o.tahun,
       count(DISTINCT o.id) AS operasi,
       count(DISTINCT p.id) AS pelanggar,
       COALESCE(sum(t.jumlah_batang), 0) AS jumlah_batang
FROM penindakan.operasi o
JOIN app.batch_impor b ON b.id = o.batch_id AND b.status = 'disimpan'
LEFT JOIN penindakan.pelanggar p ON p.operasi_id = o.id
LEFT JOIN penindakan.temuan_merek t ON t.pelanggar_id = p.id
WHERE o.aktif
GROUP BY o.kode_kab, o.tahun;

CREATE VIEW penindakan.v_rekap_bulanan AS
SELECT tahun, bulan, count(*) AS pelanggar, sum(jumlah_batang) AS jumlah_batang
FROM penindakan.v_pelanggar
GROUP BY tahun, bulan;

CREATE VIEW penindakan.v_rekap_merek AS
SELECT COALESCE(m.nama_baku, upper(trim(t.merek_asli))) AS merek,
       v.tahun, sum(t.jumlah_batang) AS jumlah_batang, count(DISTINCT v.id) AS pelanggar
FROM penindakan.temuan_merek t
JOIN penindakan.v_pelanggar v ON v.id = t.pelanggar_id
LEFT JOIN ref.merek m ON m.id = t.merek_id
GROUP BY 1, 2;

-- 8.4 Agregat survei per desa (masukan analisis hotspot)
CREATE VIEW survei.v_proporsi_desa AS
SELECT kode_desa,
       count(*) FILTER (WHERE menjual_rokok)                AS outlet_menjual,
       count(*) FILTER (WHERE menjual_rokok AND berindikasi) AS outlet_berindikasi
FROM survei.kunjungan_outlet
WHERE jenis_sampel = 'area' AND status_outlet = 'buka'
GROUP BY kode_desa;

-- 8.5 Lapisan peta untuk GeoServer dan peran penampil (tanpa titik outlet dan data pribadi)
CREATE VIEW publik.v_pelanggar_kabkota AS
SELECT k.kode, k.nama, k.wilayah_eks, k.koridor, k.geom,
       COALESCE(r.pelanggar, 0) AS pelanggar,
       COALESCE(r.jumlah_batang, 0) AS jumlah_batang
FROM ref.kabkota k
LEFT JOIN (SELECT kode_kab, sum(pelanggar) AS pelanggar, sum(jumlah_batang) AS jumlah_batang
           FROM penindakan.v_rekap_kabkota GROUP BY kode_kab) r ON r.kode_kab = k.kode;

CREATE VIEW publik.v_pelanggar_kecamatan AS
SELECT c.kode, c.nama, c.kode_kab, c.geom, COALESCE(v.pelanggar, 0) AS pelanggar
FROM ref.kecamatan c
LEFT JOIN (SELECT kode_kec, count(*) AS pelanggar
           FROM penindakan.v_pelanggar_anonim GROUP BY kode_kec) v ON v.kode_kec = c.kode;

CREATE VIEW publik.v_klaster AS
SELECT s.id_klaster, s.jenis, s.status, d.nama AS desa, d.geom
FROM survei.klaster s
JOIN ref.desa d ON d.kode = COALESCE(CASE WHEN s.desa_cadangan_dipakai THEN s.kode_desa_cadangan END, s.kode_desa);

CREATE VIEW publik.v_zona_rawan_desa AS
SELECT h.kode_wilayah AS kode_desa, d.nama, h.kategori, h.nilai_gi, d.geom
FROM analisis.hotspot h
JOIN ref.desa d ON d.kode = h.kode_wilayah
WHERE h.tingkat = 'desa'
  AND h.versi_id = (SELECT max(id) FROM analisis.versi WHERE status = 'final');

CREATE VIEW publik.v_estimasi_kabkota AS
SELECT k.kode, k.nama, k.geom, e.sisi, e.metode, e.proporsi, e.ci_bawah, e.ci_atas,
       e.volume_batang_tahun, e.kerugian_rp, e.kerugian_rp_bawah, e.kerugian_rp_atas
FROM analisis.estimasi_kabkota e
JOIN ref.kabkota k ON k.kode = e.kode_kab
WHERE e.versi_id = (SELECT max(id) FROM analisis.versi WHERE status = 'final');

-- =====================================================================
-- 9. PERAN BASIS DATA
--    Peran pengguna aplikasi (admin/operator/penampil) diatur di Laravel.
--    Peran di bawah ini untuk koneksi teknis.
-- =====================================================================

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'peta_aplikasi')  THEN CREATE ROLE peta_aplikasi NOLOGIN;  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'peta_analis')    THEN CREATE ROLE peta_analis NOLOGIN;    END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'peta_geoserver') THEN CREATE ROLE peta_geoserver NOLOGIN; END IF;
END;
$$;

-- Aplikasi (Laravel): baca-tulis seluruh skema
GRANT USAGE ON SCHEMA ref, app, penindakan, survei, analisis, publik TO peta_aplikasi;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA ref, app, penindakan, survei, analisis TO peta_aplikasi;
GRANT SELECT ON ALL TABLES IN SCHEMA publik TO peta_aplikasi;
GRANT USAGE ON ALL SEQUENCES IN SCHEMA ref, app, penindakan, survei, analisis TO peta_aplikasi;

-- Analis (R/Python): baca survei dan referensi, data penindakan tanpa data pribadi, tulis hasil analisis
GRANT USAGE ON SCHEMA ref, penindakan, survei, analisis, publik TO peta_analis;
GRANT SELECT ON ALL TABLES IN SCHEMA ref, survei, analisis, publik TO peta_analis;
GRANT SELECT ON penindakan.v_pelanggar_anonim, penindakan.v_rekap_kabkota, penindakan.v_rekap_bulanan, penindakan.v_rekap_merek TO peta_analis;
GRANT INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA analisis TO peta_analis;
GRANT USAGE ON ALL SEQUENCES IN SCHEMA analisis TO peta_analis;

-- GeoServer: hanya lapisan publik dan batas wilayah
GRANT USAGE ON SCHEMA ref, publik TO peta_geoserver;
GRANT SELECT ON ref.kabkota, ref.kecamatan, ref.desa TO peta_geoserver;
GRANT SELECT ON ALL TABLES IN SCHEMA publik TO peta_geoserver;

-- Contoh membuat akun login yang mewarisi peran (ganti kata sandi):
-- CREATE ROLE app_laravel LOGIN PASSWORD 'ganti-ini' IN ROLE peta_aplikasi;
-- CREATE ROLE geoserver   LOGIN PASSWORD 'ganti-ini' IN ROLE peta_geoserver;
