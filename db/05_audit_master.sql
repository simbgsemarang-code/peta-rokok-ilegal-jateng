-- Audit untuk data master dinamis yang kini dapat diubah lewat menu Data Master.
-- Idempotent: aman dijalankan berulang (dipanggil otomatis oleh deploy/terapkan-arsip.sh).
-- Dijalankan sebagai pemilik basis data (postgres), bukan peran aplikasi.
--
-- Tabel penindakan (operasi, pelanggar, temuan_merek) SENGAJA tidak diberi trigger: impor Excel memasukkan
-- ribuan baris sekaligus berisi nama dan alamat, yang tidak boleh membanjiri log. Perubahan manual atas data
-- operasi dicatat oleh aplikasi (App\Support\Audit::catat) tanpa data pribadi.

-- Fungsi audit: kunci baris diambil dari id, id_klaster, atau kode (surveyor memakai kode sebagai kunci).
CREATE OR REPLACE FUNCTION app.fn_audit() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  INSERT INTO app.log_audit (pengguna_id, aksi, tabel, id_baris, data_lama, data_baru)
  VALUES (
    NULLIF(current_setting('app.pengguna_id', true), '')::bigint,
    TG_OP,
    TG_TABLE_SCHEMA || '.' || TG_TABLE_NAME,
    CASE WHEN TG_OP = 'DELETE'
         THEN COALESCE(to_jsonb(OLD) ->> 'id', to_jsonb(OLD) ->> 'id_klaster', to_jsonb(OLD) ->> 'kode')
         ELSE COALESCE(to_jsonb(NEW) ->> 'id', to_jsonb(NEW) ->> 'id_klaster', to_jsonb(NEW) ->> 'kode') END,
    -- kata sandi dan rahasia 2FA tidak ikut disalin ke log
    CASE WHEN TG_OP = 'INSERT' THEN NULL ELSE to_jsonb(OLD) - 'kata_sandi_hash' - 'totp_rahasia' END,
    CASE WHEN TG_OP = 'DELETE' THEN NULL ELSE to_jsonb(NEW) - 'kata_sandi_hash' - 'totp_rahasia' END
  );
  RETURN NULL;
END;
$$;

DO $$
DECLARE
  t text;
BEGIN
  -- bersihkan trigger yang mungkin terpasang dari percobaan sebelumnya
  FOREACH t IN ARRAY ARRAY['penindakan.operasi', 'penindakan.pelanggar', 'penindakan.temuan_merek'] LOOP
    EXECUTE format('DROP TRIGGER IF EXISTS trg_audit ON %s', t);
  END LOOP;

  FOREACH t IN ARRAY ARRAY['ref.merek', 'ref.poi', 'survei.surveyor', 'ref.data_pendukung'] LOOP
    EXECUTE format('DROP TRIGGER IF EXISTS trg_audit ON %s', t);
    EXECUTE format('CREATE TRIGGER trg_audit AFTER INSERT OR UPDATE OR DELETE ON %s FOR EACH ROW EXECUTE FUNCTION app.fn_audit()', t);
  END LOOP;
END
$$;
