<?php

namespace App\Services;

use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Estimasi potensi kerugian cukai/DBHCHT, skor prioritas, dan draf rekomendasi penindakan per kab/kota.
 *
 * Rumus (model awal; semua asumsi dapat diubah admin dan disimpan bersama versi analisis):
 *   proporsi   = outlet berindikasi / outlet menjual rokok dari survei (n >= min_outlet_survei, tanpa bobot desain),
 *                bila belum cukup data: skenario rendah/tengah/tinggi dari admin
 *   volume     = jumlah outlet rokok (ref.data_pendukung) x proporsi x batang per outlet berindikasi per tahun
 *   kerugian   = volume x (tarif cukai + HJE x (pajak rokok% + PPN%))
 *   DBHCHT     = volume x tarif cukai x dbhcht%   (dihitung saat ditampilkan)
 * Hasilnya batas bawah-kasar, bukan angka final: Hájek berbobot dan Fay-Herriot menyusul setelah survei selesai.
 */
class Analisis
{
    public const DASAR = [
        'tarif_id' => null,
        'vol_per_outlet' => 7300,     // batang/tahun per outlet berindikasi (asumsi awal: 1 bungkus isi 20 per hari)
        'dbhcht_persen' => 3,         // bagian DBHCHT dari penerimaan cukai; periksa regulasi yang berlaku
        'skenario_rendah' => 5,
        'skenario_tengah' => 10,
        'skenario_tinggi' => 20,
        'min_outlet_survei' => 5,
    ];

    private const BULAN = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public function hitung(array $param, ?int $penggunaId, ?string $deskripsi = null): int
    {
        $param = array_merge(self::DASAR, array_filter($param, fn ($v) => $v !== null && $v !== ''));

        $tarif = $param['tarif_id'] ? DB::selectOne('SELECT * FROM ref.tarif WHERE id = ?', [$param['tarif_id']]) : null;
        $perBatang = null;
        if ($tarif) {
            $perBatang = (float) $tarif->tarif_cukai_per_batang
                + (float) ($tarif->hje_minimum_per_batang ?? 0) * ((float) ($tarif->pajak_rokok_persen ?? 0) + (float) ($tarif->ppn_ht_persen ?? 0)) / 100;
        }

        $kabs = DB::select('SELECT kode, nama, koridor FROM ref.kabkota ORDER BY kode');
        $outlet = [];
        foreach (DB::select("SELECT DISTINCT ON (kode_wilayah) kode_wilayah, jumlah_outlet_rokok FROM ref.data_pendukung WHERE tingkat = 'kabkota' AND jumlah_outlet_rokok IS NOT NULL ORDER BY kode_wilayah, tahun DESC") as $r) {
            $outlet[$r->kode_wilayah] = (int) $r->jumlah_outlet_rokok;
        }
        $survei = [];
        foreach (DB::select("
            SELECT s.kode_kab, count(*) FILTER (WHERE o.menjual_rokok) AS n, count(*) FILTER (WHERE o.menjual_rokok AND o.berindikasi) AS x
            FROM survei.kunjungan_outlet o JOIN survei.klaster s ON s.id_klaster = o.id_klaster
            WHERE o.jenis_sampel = 'area' AND o.status_outlet = 'buka' GROUP BY s.kode_kab") as $r) {
            $survei[$r->kode_kab] = ['n' => (int) $r->n, 'x' => (int) $r->x];
        }

        // --- estimasi per kab/kota ---
        $est = [];
        foreach ($kabs as $k) {
            $s = $survei[$k->kode] ?? null;
            if ($s && $s['n'] >= (int) $param['min_outlet_survei']) {
                $p = $s['x'] / $s['n'];
                [$lo, $hi] = $this->wilson($s['x'], $s['n']);
                $se = sqrt($p * (1 - $p) / $s['n']);
                $metode = 'langsung';
            } else {
                $p = $param['skenario_tengah'] / 100;
                $lo = $param['skenario_rendah'] / 100;
                $hi = $param['skenario_tinggi'] / 100;
                $se = null;
                $metode = 'skenario';
            }
            $o = $outlet[$k->kode] ?? null;
            $vol = $o !== null ? $o * $p * (float) $param['vol_per_outlet'] : null;
            $vLo = $o !== null ? $o * $lo * (float) $param['vol_per_outlet'] : null;
            $vHi = $o !== null ? $o * $hi * (float) $param['vol_per_outlet'] : null;
            $est[$k->kode] = [
                'metode' => $metode, 'proporsi' => $p, 'se' => $se, 'lo' => $lo, 'hi' => $hi,
                'volume' => $vol,
                'rugi' => ($vol !== null && $perBatang !== null) ? $vol * $perBatang : null,
                'rugi_lo' => ($vLo !== null && $perBatang !== null) ? $vLo * $perBatang : null,
                'rugi_hi' => ($vHi !== null && $perBatang !== null) ? $vHi * $perBatang : null,
            ];
        }

        // --- komponen skor prioritas (0..1; null = belum tersedia) ---
        $pel = [];
        $tahunAda = [];
        foreach (DB::select('SELECT kode_kab, tahun, sum(pelanggar) AS pelanggar FROM penindakan.v_rekap_kabkota GROUP BY kode_kab, tahun') as $r) {
            $pel[$r->kode_kab][(int) $r->tahun] = (int) $r->pelanggar;
            $tahunAda[(int) $r->tahun] = true;
        }
        $tahunAkhir = $tahunAda ? max(array_keys($tahunAda)) : null;
        $terakhir = [];
        $ubah = [];
        foreach ($kabs as $k) {
            $terakhir[$k->kode] = $tahunAkhir ? ($pel[$k->kode][$tahunAkhir] ?? 0) : null;
            $ubah[$k->kode] = $tahunAkhir ? (($pel[$k->kode][$tahunAkhir] ?? 0) - ($pel[$k->kode][$tahunAkhir - 1] ?? 0)) : null;
        }
        $nTerakhir = $this->minmax($terakhir);
        $nUbah = $this->minmax($ubah);
        $tren = [];
        foreach ($kabs as $k) {
            $tren[$k->kode] = ($nTerakhir[$k->kode] === null && $nUbah[$k->kode] === null) ? null
                : 0.6 * ($nTerakhir[$k->kode] ?? 0) + 0.4 * ($nUbah[$k->kode] ?? 0);
        }
        $kerugian = $this->minmax(array_map(fn ($e) => $e['rugi'], $est));
        $langsung = array_filter($est, fn ($e) => $e['metode'] === 'langsung');
        $prop = count($langsung) >= 3 ? $this->minmax(array_map(fn ($e) => $e['metode'] === 'langsung' ? $e['proporsi'] : null, $est)) : array_fill_keys(array_keys($est), null);

        $bobot = [];
        foreach (DB::select('SELECT DISTINCT ON (komponen) komponen, bobot_persen FROM analisis.bobot_skor ORDER BY komponen, berlaku_mulai DESC, id DESC') as $r) {
            $bobot[$r->komponen] = (float) $r->bobot_persen;
        }
        $skor = [];
        $semua = [];
        foreach ($kabs as $k) {
            $semua[$k->kode] = [
                'estimasi_kerugian' => $kerugian[$k->kode] ?? null,
                'proporsi_berindikasi' => $prop[$k->kode] ?? null,
                'hotspot' => null,
                'tren_penindakan' => $tren[$k->kode] ?? null,
                'efek_pembinaan' => null,
            ];
        }
        // Komponen dipakai hanya bila tersedia untuk SEMUA kab/kota; bila tidak, skor antarwilayah tidak sebanding.
        $dipakai = [];
        foreach (array_keys(reset($semua) ?: []) as $nama) {
            $dipakai[$nama] = count(array_filter($semua, fn ($komp) => $komp[$nama] === null)) === 0;
        }
        foreach ($kabs as $k) {
            $komp = $semua[$k->kode];
            $sw = 0.0;
            $sv = 0.0;
            foreach ($komp as $nama => $v) {
                if ($dipakai[$nama] && $v !== null && ($bobot[$nama] ?? 0) > 0) {
                    $sw += $bobot[$nama];
                    $sv += $bobot[$nama] * $v;
                }
            }
            $skor[$k->kode] = ['komp' => $komp, 'skor' => $sw > 0 ? round(100 * $sv / $sw, 2) : 0.0];
        }
        $urut = array_keys($skor);
        usort($urut, fn ($a, $b) => [$skor[$b]['skor'], $terakhir[$b] ?? 0, $a] <=> [$skor[$a]['skor'], $terakhir[$a] ?? 0, $b]);
        $n = count($urut);
        foreach ($urut as $i => $kode) {
            $skor[$kode]['rank'] = $i + 1;
            $skor[$kode]['kat'] = $i < ceil($n / 3) ? 'tinggi' : ($i < ceil(2 * $n / 3) ? 'sedang' : 'rendah');
        }

        $rek = $this->teksRekomendasi($kabs, $skor);
        $tersedia = array_keys(array_filter($dipakai));

        return Audit::jalankan(function () use ($param, $deskripsi, $penggunaId, $tarif, $perBatang, $kabs, $est, $skor, $rek, $tersedia, $bobot, $tahunAkhir) {
            $versi = DB::table('analisis.versi')->insertGetId([
                'dibuat_oleh' => $penggunaId,
                'deskripsi' => $deskripsi ?: 'Estimasi kerugian dan skor prioritas',
                'parameter' => json_encode([
                    'param' => $param,
                    'tarif' => $tarif,
                    'kerugian_per_batang' => $perBatang,
                    'bobot' => $bobot,
                    'komponen_tersedia' => $tersedia,
                    'tahun_terakhir' => $tahunAkhir,
                ]),
                'status' => 'draf',
            ], 'id');

            $baris = [];
            foreach ($kabs as $k) {
                $e = $est[$k->kode];
                $baris[] = [
                    'versi_id' => $versi, 'kode_kab' => $k->kode, 'sisi' => 'outlet', 'metode' => $e['metode'],
                    'proporsi' => $e['proporsi'], 'galat_baku' => $e['se'], 'ci_bawah' => $e['lo'], 'ci_atas' => $e['hi'],
                    'volume_batang_tahun' => $e['volume'], 'kerugian_rp' => $e['rugi'],
                    'kerugian_rp_bawah' => $e['rugi_lo'], 'kerugian_rp_atas' => $e['rugi_hi'],
                ];
            }
            DB::table('analisis.estimasi_kabkota')->insert($baris);

            $baris = [];
            foreach ($kabs as $k) {
                $baris[] = [
                    'versi_id' => $versi, 'kode_kab' => $k->kode, 'komponen' => json_encode($skor[$k->kode]['komp']),
                    'skor' => $skor[$k->kode]['skor'], 'peringkat' => $skor[$k->kode]['rank'], 'kategori' => $skor[$k->kode]['kat'],
                ];
            }
            DB::table('analisis.skor_prioritas')->insert($baris);

            $baris = [];
            foreach ($kabs as $k) {
                $baris[] = ['versi_id' => $versi, 'kode_kab' => $k->kode, 'disusun_oleh' => $penggunaId, 'status' => 'draf'] + $rek[$k->kode];
            }
            DB::table('analisis.rekomendasi')->insert($baris);

            return $versi;
        });
    }

    /** Rentang kepercayaan Wilson 95% untuk proporsi x/n. */
    private function wilson(int $x, int $n): array
    {
        $z = 1.96;
        $p = $x / $n;
        $pembagi = 1 + $z * $z / $n;
        $pusat = ($p + $z * $z / (2 * $n)) / $pembagi;
        $lebar = $z * sqrt($p * (1 - $p) / $n + $z * $z / (4 * $n * $n)) / $pembagi;

        return [max(0, $pusat - $lebar), min(1, $pusat + $lebar)];
    }

    /** Normalisasi min-maks; semua null bila kurang dari dua nilai atau semua nilai sama (tidak ada informasi pembeda). */
    private function minmax(array $nilai): array
    {
        $ada = array_filter($nilai, fn ($v) => $v !== null);
        if (count($ada) < 2 || max($ada) == min($ada)) {
            return array_fill_keys(array_keys($nilai), null);
        }
        $lo = min($ada);
        $rentang = max($ada) - $lo;

        return array_map(fn ($v) => $v === null ? null : ($v - $lo) / $rentang, $nilai);
    }

    private function teksRekomendasi(array $kabs, array $skor): array
    {
        $kec = [];
        foreach (DB::select('
            SELECT kode_kab, nama, n FROM (
              SELECT v.kode_kab, c.nama, count(*) AS n, row_number() OVER (PARTITION BY v.kode_kab ORDER BY count(*) DESC, c.nama) AS rn
              FROM penindakan.v_pelanggar_anonim v JOIN ref.kecamatan c ON c.kode = v.kode_kec GROUP BY v.kode_kab, c.nama) x
            WHERE rn <= 3 ORDER BY kode_kab, rn') as $r) {
            $kec[$r->kode_kab][] = "Kec. {$r->nama} ({$r->n} pelanggar)";
        }
        $bln = [];
        foreach (DB::select('
            SELECT kode_kab, bulan, n FROM (
              SELECT kode_kab, bulan, count(*) AS n, row_number() OVER (PARTITION BY kode_kab ORDER BY count(*) DESC, bulan) AS rn
              FROM penindakan.v_pelanggar_anonim WHERE bulan IS NOT NULL GROUP BY kode_kab, bulan) x
            WHERE rn <= 2 ORDER BY kode_kab, rn') as $r) {
            $bln[$r->kode_kab][] = self::BULAN[(int) $r->bulan];
        }
        $mrk = [];
        foreach (DB::select('
            SELECT kode_kab, merek FROM (
              SELECT v.kode_kab, COALESCE(m.nama_baku, upper(trim(t.merek_asli))) AS merek,
                     row_number() OVER (PARTITION BY v.kode_kab ORDER BY sum(t.jumlah_batang) DESC) AS rn
              FROM penindakan.temuan_merek t JOIN penindakan.v_pelanggar v ON v.id = t.pelanggar_id
              LEFT JOIN ref.merek m ON m.id = t.merek_id GROUP BY v.kode_kab, COALESCE(m.nama_baku, upper(trim(t.merek_asli)))) x
            WHERE rn <= 3 ORDER BY kode_kab, rn') as $r) {
            $mrk[$r->kode_kab][] = $r->merek;
        }
        $rentang = DB::selectOne('SELECT min(tahun) AS a, max(tahun) AS b FROM penindakan.v_rekap_kabkota');
        $periode = $rentang && $rentang->a ? ($rentang->a == $rentang->b ? $rentang->a : "{$rentang->a}–{$rentang->b}") : 'data yang tersedia';

        $hasil = [];
        foreach ($kabs as $k) {
            $kat = $skor[$k->kode]['kat'];
            $pendekatan = match ($kat) {
                'tinggi' => 'Penindakan terarah dan berkala di lokasi prioritas, disertai pembinaan pedagang di sekitarnya.',
                'sedang' => 'Gabungan sosialisasi/pembinaan dan operasi gabungan sesuai jadwal tahunan.',
                default => 'Pembinaan dan sosialisasi; pantau lewat laporan masyarakat dan survei ulang.',
            };
            if ($k->koridor) {
                $pendekatan .= ' Wilayah koridor (pernah ada sitaan besar dalam satu penindakan): koordinasikan pengawasan jalur angkut dengan kab/kota sekitar.';
            }
            $hasil[$k->kode] = [
                'lokasi_prioritas' => isset($kec[$k->kode])
                    ? implode('; ', $kec[$k->kode])
                    : 'Belum ada temuan yang terpetakan sampai kecamatan; mulai dari pasar dan jalur utama kab/kota, lalu lengkapi lewat survei.',
                'waktu_operasi' => isset($bln[$k->kode])
                    ? 'Sekitar bulan '.implode(' dan ', $bln[$k->kode])." (bulan dengan pelanggar terbanyak, {$periode})."
                    : 'Belum cukup data per bulan; susun jadwal setelah data survei masuk.',
                'sasaran' => isset($mrk[$k->kode])
                    ? 'Merek paling banyak ditemukan: '.implode(', ', $mrk[$k->kode]).'.'
                    : 'Belum ada data merek untuk kab/kota ini.',
                'pendekatan' => $pendekatan,
            ];
        }

        return $hasil;
    }
}
