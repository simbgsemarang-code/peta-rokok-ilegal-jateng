<?php

namespace App\Services;

use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlDate;

/**
 * Membaca berkas Excel hasil operasi cukai dan memasukkannya ke skema penindakan
 * sebagai satu batch berstatus "pratinjau" (baru tampil di aplikasi setelah disimpan admin).
 *
 * Dua format dikenali otomatis:
 *  - "gabungan": sheet DATA pada DATA GABUNGAN OPERASI CUKAI 2023-2026.xlsx
 *    (Tahun | Kab/Kota | Tim | Tanggal | Tgl Mulai | Bulan | Pemilik | Toko | Alamat | Merk | Jumlah | Jenis | Operasi | Pelanggar | Sumber | Kecamatan)
 *  - "rincian": sheet RINCIAN HASIL OPS pada berkas rekap tahunan
 *    (No | Kab/Kota | Tanggal Pelaksanaan | Pemilik | Toko | Alamat | Merk | Jumlah), sel kosong berarti "sama dengan baris di atasnya".
 */
class ImporPenindakan
{
    private const BULAN = [
        'JANUARI' => 1, 'FEBRUARI' => 2, 'MARET' => 3, 'APRIL' => 4, 'MEI' => 5, 'JUNI' => 6,
        'JULI' => 7, 'AGUSTUS' => 8, 'SEPTEMBER' => 9, 'OKTOBER' => 10, 'NOVEMBER' => 11, 'DESEMBER' => 12,
    ];

    private array $kab = [];

    private array $kec = [];

    private array $merekId = [];

    /**
     * @param  array|null  $kab  [kunciKab => kode]; bila null dimuat dari ref.kabkota
     * @param  array|null  $kec  ["kode_kab|NAMA" => kode]; bila null dimuat dari ref.kecamatan
     */
    public function __construct(?array $kab = null, ?array $kec = null)
    {
        if ($kab === null) {
            foreach (DB::select('SELECT kode, nama FROM ref.kabkota') as $r) {
                $kab[self::kunciKab($r->nama)] = $r->kode;
            }
        }
        if ($kec === null) {
            foreach (DB::select('SELECT kode, kode_kab, nama FROM ref.kecamatan') as $r) {
                $kec[$r->kode_kab.'|'.mb_strtoupper(trim($r->nama))] = $r->kode;
            }
        }
        $this->kab = $kab ?? [];
        $this->kec = $kec ?? [];
    }

    /** Menyeragamkan "KAB. KLATEN", "KABUPETEN KLATEN", "Kota Tegal" menjadi kunci yang sama dengan ref.kabkota. */
    public static function kunciKab(string $nama): string
    {
        $s = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $nama)));
        $s = preg_replace('/\s+(TIM\s*)?\d+$/u', '', $s);   // "KOTA TEGAL 1", "KABUPATEN PEMALANG TIM 2" = operasi/tim ke-n di wilayah itu
        $s = preg_replace('/^(KABUPATEN|KABUPETEN|KAB\.?)\s*/u', 'KABUPATEN ', $s);
        $s = preg_replace('/^KOTA\s*/u', 'KOTA ', $s);
        if (! str_starts_with($s, 'KABUPATEN ') && ! str_starts_with($s, 'KOTA ')) {
            $s = 'KABUPATEN '.$s;
        }

        return $s;
    }

    /**
     * @return array{format:string, sheet:string, baris:int, operasi:array, ditolak:array}
     */
    public function baca(string $path): array
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '1024M');

        $nama = IOFactory::createReaderForFile($path)->listWorksheetNames($path);
        usort($nama, fn ($a, $b) => [$a !== 'DATA', $a] <=> [$b !== 'DATA', $b]);

        foreach ($nama as $sheet) {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $reader->setLoadSheetsOnly([$sheet]);
            $rows = $reader->load($path)->getSheetByName($sheet)->toArray(null, false, false, false);
            [$format, $header] = $this->kenaliHeader($rows);
            if ($format === null) {
                continue;
            }
            $hasil = $format === 'gabungan'
                ? $this->bacaGabungan($rows, $header + 1)
                : $this->bacaRincian($rows, $header + 1);
            $hasil['format'] = $format;
            $hasil['sheet'] = $sheet;

            return $hasil;
        }

        throw new \RuntimeException('Format berkas tidak dikenali. Gunakan sheet DATA (gabungan) atau RINCIAN HASIL OPS, lengkap dengan baris judul kolom.');
    }

    private function kenaliHeader(array $rows): array
    {
        foreach (array_slice($rows, 0, 12, true) as $i => $r) {
            $a = mb_strtoupper(trim((string) ($r[0] ?? '')));
            $b = mb_strtoupper(trim((string) ($r[1] ?? '')));
            $c = mb_strtoupper(trim((string) ($r[2] ?? '')));
            if ($a === 'TAHUN' && str_contains($b, 'KAB')) {
                return ['gabungan', $i];
            }
            if (in_array($a, ['NO', 'NO.'], true) && str_contains($b, 'KAB') && str_contains($c, 'TANGGAL')) {
                return ['rincian', $i];
            }
        }

        return [null, 0];
    }

    private function bacaGabungan(array $rows, int $mulai): array
    {
        $ops = [];
        $ditolak = [];
        $baris = 0;
        $op = null;
        $pl = null;

        for ($i = $mulai, $n = count($rows); $i < $n; $i++) {
            $r = $rows[$i];
            if (! $this->terisi($r)) {
                continue;
            }
            $baris++;
            $no = $i + 1;
            $flagOp = (int) ($r[12] ?? 0) === 1;
            $flagPl = (int) ($r[13] ?? 0) === 1;
            $hasilBaris = $this->hasil($r[11] ?? '');

            if ($flagOp) {
                $tahun = (int) ($r[0] ?? 0);
                $kode = $this->kab[self::kunciKab((string) ($r[1] ?? ''))] ?? null;
                if ($tahun < 2000 || $tahun > 2100) {
                    $ditolak[] = "Baris {$no}: tahun tidak valid ({$r[0]}).";
                    $op = $pl = null;

                    continue;
                }
                if (! $kode) {
                    $ditolak[] = "Baris {$no}: kab/kota tidak dikenali ({$r[1]}).";
                    $op = $pl = null;

                    continue;
                }
                $tgl = $this->tanggalExcel($r[4] ?? null);
                $ops[] = [
                    'tahun' => $tahun,
                    'kode_kab' => $kode,
                    'tim' => $this->teks($r[2] ?? null),
                    'tanggal_asli' => $this->teks($r[3] ?? null),
                    'tanggal_mulai' => $tgl,
                    'bulan' => $tgl ? (int) substr($tgl, 5, 2) : null,
                    'hasil' => $hasilBaris,
                    'sumber' => $this->teks($r[14] ?? null),
                    'pelanggar' => [],
                ];
                $op = array_key_last($ops);
                $pl = null;
            } elseif ($op === null) {
                $ditolak[] = "Baris {$no}: tidak punya operasi induk (baris pembuka operasi sebelumnya ditolak atau tidak ada).";

                continue;
            }

            // Pelanggar baru hanya pada baris bertanda Pelanggar = 1. Operasi pembinaan/nihil
            // tidak punya penanda itu, sehingga hanya tercatat sebagai operasi (sama dengan rekap Excel).
            if ($flagPl) {
                $kecTeks = $this->teks($r[15] ?? null);
                $xy = $this->koordinat($r[16] ?? null);   // kolom Q "Estimasi Koordinat (lat, lon)", bila ada
                $ops[$op]['pelanggar'][] = [
                    'nama_pemilik' => $this->teks($r[6] ?? null),
                    'nama_toko' => $this->teks($r[7] ?? null),
                    'alamat' => $this->teks($r[8] ?? null),
                    'kecamatan_teks' => $kecTeks,
                    'kode_kec' => $this->cocokKecamatan($ops[$op]['kode_kab'], $kecTeks),
                    'lat' => $xy[0] ?? null,
                    'lon' => $xy[1] ?? null,
                    'ketelitian' => $xy ? $this->ketelitianDb($r[17] ?? null) : null,
                    'temuan' => [],
                ];
                $pl = array_key_last($ops[$op]['pelanggar']);
            }

            $merek = $this->teks($r[9] ?? null);
            if ($merek !== null && $hasilBaris === 'TEMUAN') {
                $jml = $r[10] ?? null;
                if ($pl === null) {
                    $ditolak[] = "Baris {$no}: temuan merek {$merek} tanpa pelanggar induk (kolom Pelanggar tidak bernilai 1); dilewati.";
                } elseif (is_numeric($jml) && $jml >= 0) {
                    $ops[$op]['pelanggar'][$pl]['temuan'][] = [$merek, (int) round($jml)];
                } else {
                    $ditolak[] = "Baris {$no}: jumlah batang bukan angka ({$jml}); temuan merek {$merek} dilewati.";
                }
            }
        }

        return ['baris' => $baris, 'operasi' => $ops, 'ditolak' => $ditolak];
    }

    private function bacaRincian(array $rows, int $mulai): array
    {
        $ops = [];
        $ditolak = [];
        $baris = 0;
        $op = null;
        $pl = null;
        $pembinaan = [];
        $pend = null;   // data pelanggar yang baru dibaca, dimasukkan setelah ada temuan merek (bukan pembinaan)
        $peringatan = [];
        $tahunTerakhir = 0;
        $tahunAwal = 0;
        foreach (array_slice($rows, $mulai) as $r) {   // tahun pertama yang tertulis di kolom tanggal, untuk baris tanpa tahun
            if (preg_match('/(20\d\d)/', (string) ($r[2] ?? ''), $m)) {
                $tahunAwal = (int) $m[1];
                break;
            }
        }

        for ($i = $mulai, $n = count($rows); $i < $n; $i++) {
            $r = $rows[$i];
            $a = $r[0] ?? null;
            if (is_string($a) && preg_match('/^\s*JUMLAH/iu', $a)) {
                break;   // baris total di akhir lembar
            }
            if (! $this->terisi($r)) {
                continue;
            }
            $baris++;
            $no = $i + 1;

            if ($a !== null && trim((string) $a) !== '') {
                $tgl = trim((string) ($r[2] ?? ''));
                $kode = $this->kab[self::kunciKab((string) ($r[1] ?? ''))] ?? null;
                $tahun = preg_match('/(20\d\d)/', $tgl, $m) ? (int) $m[1] : 0;
                if (! $tahun && $kode) {
                    $tahun = $tahunTerakhir ?: $tahunAwal;
                    if ($tahun) {
                        $peringatan[] = "Baris {$no}: tanggal \"{$tgl}\" tanpa tahun; dipakai tahun {$tahun} (dari operasi sebelumnya).";
                    }
                }
                if (! $kode || ! $tahun) {
                    $ditolak[] = "Baris {$no}: ".(! $kode ? "kab/kota tidak dikenali ({$r[1]})." : "tahun tidak terbaca dari tanggal ({$tgl}).");
                    $op = $pl = null;

                    continue;
                }
                $tahunTerakhir = $tahun;
                $tglMulai = $this->tanggalIndonesia($tgl, $tahun);
                $ops[] = [
                    'tahun' => $tahun,
                    'kode_kab' => $kode,
                    'tim' => null,
                    'tanggal_asli' => $tgl,
                    'tanggal_mulai' => $tglMulai,
                    'bulan' => $tglMulai ? (int) substr($tglMulai, 5, 2) : null,
                    'hasil' => 'NIHIL',
                    'sumber' => null,
                    'pelanggar' => [],
                ];
                $op = array_key_last($ops);
                $pl = null;
                $pend = null;
            } elseif ($op === null) {
                $ditolak[] = "Baris {$no}: tidak punya operasi induk (baris pembuka operasi sebelumnya ditolak).";

                continue;
            }

            $pemilik = $this->teks($r[3] ?? null);
            $merek = $this->teks($r[6] ?? null);
            if ($pemilik !== null || ($pend === null && $pl === null && ($merek !== null || $this->teks($r[4] ?? null) !== null))) {
                $alamat = $this->teks($r[5] ?? null);
                $kecTeks = $this->kecamatanDariAlamat($alamat);
                $pend = [
                    'nama_pemilik' => $pemilik,
                    'nama_toko' => $this->teks($r[4] ?? null),
                    'alamat' => $alamat,
                    'kecamatan_teks' => $kecTeks,
                    'kode_kec' => $this->cocokKecamatan($ops[$op]['kode_kab'], $kecTeks),
                    'temuan' => [],
                ];
                $pl = null;
            }
            if ($merek === null) {
                continue;
            }
            if (str_contains(mb_strtoupper($merek), 'PEMBINAAN')) {
                // Pembinaan/sosialisasi: dicatat sebagai operasi, bukan pelanggar (sama dengan rekap Excel)
                $pembinaan[$op] = true;
                $pend = null;
                $pl = null;

                continue;
            }
            $jml = $r[7] ?? null;
            if (is_numeric($jml) && $jml >= 0) {
                if ($pl === null) {
                    $ops[$op]['pelanggar'][] = $pend;
                    $pl = array_key_last($ops[$op]['pelanggar']);
                }
                $ops[$op]['pelanggar'][$pl]['temuan'][] = [$merek, (int) round($jml)];
                $ops[$op]['hasil'] = 'TEMUAN';
            } else {
                $ditolak[] = "Baris {$no}: jumlah batang bukan angka ({$jml}); temuan merek {$merek} dilewati.";
            }
        }
        foreach ($pembinaan as $k => $_) {
            if ($ops[$k]['hasil'] !== 'TEMUAN') {
                $ops[$k]['hasil'] = 'PEMBINAAN/SOSIALISASI';
            }
        }

        return ['baris' => $baris, 'operasi' => $ops, 'ditolak' => $ditolak, 'peringatan' => $peringatan];
    }

    /** Menyimpan hasil bacaan sebagai batch baru berstatus pratinjau. Mengembalikan id batch. */
    public function simpan(array $hasil, string $namaFile, ?int $penggunaId): int
    {
        return Audit::jalankan(function () use ($hasil, $namaFile, $penggunaId) {
            $catatan = 'Format: '.$hasil['format'].' (sheet '.$hasil['sheet'].')';
            foreach (array_slice($hasil['peringatan'] ?? [], 0, 20) as $w) {
                $catatan .= "\nPeringatan - ".$w;
            }
            if ($hasil['ditolak']) {
                $catatan .= "\n".implode("\n", array_slice($hasil['ditolak'], 0, 50));
                if (count($hasil['ditolak']) > 50) {
                    $catatan .= "\n... dan ".(count($hasil['ditolak']) - 50).' pesan lain.';
                }
            }
            $batch = DB::table('app.batch_impor')->insertGetId([
                'jenis' => 'penindakan',
                'nama_file' => $namaFile,
                'diunggah_oleh' => $penggunaId,
                'jumlah_baris' => $hasil['baris'],
                'jumlah_ditolak' => count($hasil['ditolak']),
                'status' => 'pratinjau',
                'catatan' => $catatan,
            ], 'id');

            $buf = [];
            $kosongkan = function () use (&$buf) {
                foreach (array_chunk($buf, 500) as $potong) {
                    DB::table('penindakan.temuan_merek')->insert($potong);
                }
                $buf = [];
            };

            foreach ($hasil['operasi'] as $op) {
                $opId = DB::table('penindakan.operasi')->insertGetId([
                    'batch_id' => $batch,
                    'tahun' => $op['tahun'],
                    'kode_kab' => $op['kode_kab'],
                    'tim' => $op['tim'],
                    'tanggal_asli' => $op['tanggal_asli'],
                    'tanggal_mulai' => $op['tanggal_mulai'],
                    'bulan' => $op['bulan'],
                    'hasil' => $op['hasil'],
                    'sumber' => $op['sumber'] ?? $namaFile,
                ], 'id');
                foreach ($op['pelanggar'] as $p) {
                    $baris = [
                        'operasi_id' => $opId,
                        'nama_pemilik' => $p['nama_pemilik'],
                        'nama_toko_kendaraan' => $p['nama_toko'],
                        'alamat' => $p['alamat'],
                        'kecamatan_teks' => $p['kecamatan_teks'],
                        'kode_kec' => $p['kode_kec'],
                        'ketelitian_lokasi' => $p['ketelitian'] ?? ($p['kode_kec'] ? 'kecamatan' : 'kabkota'),
                    ];
                    if (isset($p['lat'], $p['lon'])) {   // titik perkiraan dari geocoding alamat, bukan GPS
                        $baris['geom'] = DB::raw(sprintf('ST_SetSRID(ST_MakePoint(%.6F, %.6F), 4326)', $p['lon'], $p['lat']));
                        $baris['cara_penentuan'] = 'geocoding_otomatis';
                    }
                    $plId = DB::table('penindakan.pelanggar')->insertGetId($baris, 'id');
                    foreach ($p['temuan'] as [$merek, $jml]) {
                        $buf[] = [
                            'pelanggar_id' => $plId,
                            'merek_asli' => $merek,
                            'merek_id' => $this->idMerek($merek),
                            'jumlah_batang' => $jml,
                        ];
                    }
                }
                if (count($buf) >= 2000) {
                    $kosongkan();
                }
            }
            $kosongkan();

            // Titik setingkat desa/alamat ditetapkan ke desa/kelurahan yang melingkupinya (hanya bila berada di kab/kota
            // operasinya). Titik setingkat kecamatan atau kab/kota tidak ditetapkan ke desa karena hanya titik tengah.
            DB::update("
                UPDATE penindakan.pelanggar p SET kode_desa = d.kode, kode_kec = d.kode_kec
                FROM penindakan.operasi o, ref.desa d
                WHERE o.id = p.operasi_id AND o.batch_id = ? AND p.geom IS NOT NULL
                  AND p.ketelitian_lokasi IN ('titik', 'desa') AND d.geom IS NOT NULL
                  AND ST_Intersects(d.geom, p.geom) AND substr(d.kode, 1, 5) = o.kode_kab", [$batch]);

            return $batch;
        });
    }

    /** "-6.504982, 110.803320" -> [lat, lon]; null bila bukan koordinat wajar untuk Jawa Tengah dan sekitarnya. */
    private function koordinat(mixed $v): ?array
    {
        if (is_string($v) && preg_match('/^\s*(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)\s*$/', $v, $m)) {
            $lat = (float) $m[1];
            $lon = (float) $m[2];
            if ($lat >= -9.5 && $lat <= -5.5 && $lon >= 107.5 && $lon <= 112.5) {
                return [$lat, $lon];
            }
        }

        return null;
    }

    /** Label "Ketelitian Estimasi" di Excel -> nilai kolom ketelitian_lokasi. */
    private function ketelitianDb(mixed $label): string
    {
        return match (mb_strtolower(trim((string) $label))) {
            'titik alamat', 'jalan' => 'titik',
            'desa/dusun' => 'desa',
            'kecamatan' => 'kecamatan',
            default => 'kabkota',
        };
    }

    private function idMerek(string $merek): ?int
    {
        $k = mb_strtoupper(trim($merek));
        if (! array_key_exists($k, $this->merekId)) {
            $this->merekId[$k] = DB::selectOne('SELECT ref.fn_merek_id(?) AS id', [$merek])->id;
        }

        return $this->merekId[$k];
    }

    private function cocokKecamatan(string $kodeKab, ?string $nama): ?string
    {
        if ($nama === null) {
            return null;
        }
        $nama = preg_replace('/^(KECAMATAN|KEC\.?)\s*/iu', '', $nama);

        return $this->kec[$kodeKab.'|'.mb_strtoupper(trim($nama))] ?? null;
    }

    private function kecamatanDariAlamat(?string $alamat): ?string
    {
        if ($alamat !== null && preg_match('/\bKEC(?:AMATAN|\.)?\s*([A-Z][A-Z ]+?)(?=,|\.|\s+KAB|\s+KOTA|$)/u', mb_strtoupper($alamat), $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function hasil(?string $jenis): string
    {
        $j = mb_strtoupper(trim((string) $jenis));
        if (str_contains($j, 'PEMBINAAN')) {
            return 'PEMBINAAN/SOSIALISASI';
        }
        if (str_contains($j, 'NIHIL')) {
            return 'NIHIL';
        }

        return 'TEMUAN';
    }

    private function terisi(array $r): bool
    {
        foreach ($r as $v) {
            if ($v !== null && trim((string) $v) !== '') {
                return true;
            }
        }

        return false;
    }

    private function teks(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }

    private function tanggalExcel(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v) && $v > 20000) {
            return XlDate::excelToDateTimeObject((float) $v)->format('Y-m-d');
        }
        $t = strtotime((string) $v);

        return $t ? date('Y-m-d', $t) : null;
    }

    /** "28 - 29 JANUARI 2026" atau "30 JANUARI - 1 FEBRUARI 2026" -> 2026-01-28 / 2026-01-30 (hari dan bulan pertama). */
    private function tanggalIndonesia(string $teks, int $tahunDefault): ?string
    {
        $t = mb_strtoupper($teks);
        if (! preg_match('/(\d{1,2})/', $t, $hari)) {
            return null;
        }
        $tahun = [1 => preg_match('/(20\d\d)/', $t, $m) ? $m[1] : $tahunDefault];
        $pos = null;
        $bulan = null;
        foreach (self::BULAN as $nama => $angka) {
            $p = mb_strpos($t, $nama);
            if ($p !== false && ($pos === null || $p < $pos)) {
                $pos = $p;
                $bulan = $angka;
            }
        }
        if ($bulan === null || ! checkdate($bulan, (int) $hari[1], (int) $tahun[1])) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $tahun[1], $bulan, $hari[1]);
    }
}
