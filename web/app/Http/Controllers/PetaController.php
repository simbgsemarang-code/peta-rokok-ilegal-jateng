<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PetaController extends Controller
{
    public function index()
    {
        $tahun = array_map(fn ($r) => (int) $r->tahun, DB::select('SELECT DISTINCT tahun FROM penindakan.v_rekap_kabkota ORDER BY tahun DESC'));

        return view('peta', [
            'tahun' => $tahun,
            'bolehTitik' => auth()->user()->peran !== 'penampil',
            'kabkota' => DB::select('SELECT kode, nama FROM ref.kabkota ORDER BY nama'),
        ]);
    }

    /**
     * Batas kecamatan atau desa/kelurahan beserta jumlah pelanggar yang lokasinya sudah dapat ditetapkan sampai
     * tingkat itu. Desa/kelurahan wajib per kab/kota (satu provinsi sekaligus terlalu berat). Hanya agregat,
     * tanpa data pribadi. Desa yang menjadi sampel survei diberi penanda klaster.
     */
    public function wilayah(Request $request)
    {
        $tingkat = $request->query('tingkat') === 'desa' ? 'desa' : 'kecamatan';
        $kab = $request->query('kab');
        $tahun = $request->integer('tahun') ?: null;
        if ($kab !== null && ! preg_match('/^33\.\d{2}$/', $kab)) {
            return response()->json(['galat' => 'Kode kab/kota tidak valid.'], 422);
        }
        if ($tingkat === 'desa' && ! $kab) {
            return response()->json(['galat' => 'Pilih satu kab/kota untuk melihat desa/kelurahan.'], 422);
        }

        $ft = $tahun ? ' AND tahun = ?' : '';
        $ikatTahun = $tahun ? [$tahun] : [];
        try {
            if ($tingkat === 'desa') {
                $rows = DB::select("
                    SELECT d.kode, d.nama, d.jenis, c.nama AS kecamatan, k.nama AS kab, ST_AsGeoJSON(d.geom, 5) AS geom,
                           COALESCE(p.pelanggar, 0) AS pelanggar, COALESCE(p.batang, 0) AS batang, kl.id_klaster, kl.jenis AS jenis_klaster
                    FROM ref.desa d
                    JOIN ref.kecamatan c ON c.kode = d.kode_kec
                    JOIN ref.kabkota k ON k.kode = c.kode_kab
                    LEFT JOIN (SELECT kode_desa, count(*) AS pelanggar, COALESCE(sum(jumlah_batang), 0) AS batang
                               FROM penindakan.v_pelanggar_anonim WHERE kode_desa IS NOT NULL{$ft} GROUP BY kode_desa) p ON p.kode_desa = d.kode
                    LEFT JOIN survei.klaster kl ON d.kode = COALESCE(CASE WHEN kl.desa_cadangan_dipakai THEN kl.kode_desa_cadangan END, kl.kode_desa)
                    WHERE c.kode_kab = ? AND d.geom IS NOT NULL
                    ORDER BY d.kode", array_merge($ikatTahun, [$kab]));
            } else {
                $rows = DB::select("
                    SELECT c.kode, c.nama, NULL AS jenis, NULL AS kecamatan, k.nama AS kab,
                           ST_AsGeoJSON(ST_SimplifyPreserveTopology(c.geom, 0.0003), 5) AS geom,
                           COALESCE(p.pelanggar, 0) AS pelanggar, COALESCE(p.batang, 0) AS batang, NULL AS id_klaster, NULL AS jenis_klaster
                    FROM ref.kecamatan c
                    JOIN ref.kabkota k ON k.kode = c.kode_kab
                    LEFT JOIN (SELECT kode_kec, count(*) AS pelanggar, COALESCE(sum(jumlah_batang), 0) AS batang
                               FROM penindakan.v_pelanggar_anonim WHERE kode_kec IS NOT NULL{$ft} GROUP BY kode_kec) p ON p.kode_kec = c.kode
                    WHERE c.geom IS NOT NULL".($kab ? ' AND c.kode_kab = ?' : '').'
                    ORDER BY c.kode', array_merge($ikatTahun, $kab ? [$kab] : []));
            }
            $lingkup = DB::selectOne('
                SELECT count(*) AS pelanggar, COALESCE(sum(jumlah_batang), 0) AS batang
                FROM penindakan.v_pelanggar_anonim WHERE true'.($kab ? ' AND kode_kab = ?' : '').$ft, array_merge($kab ? [$kab] : [], $ikatTahun));
        } catch (QueryException $e) {
            report($e);

            return response()->json(['galat' => 'Batas wilayah tidak dapat dibaca. Pastikan batas desa/kecamatan sudah dimuat (db/04_batas_turunan.sql).'], 500);
        }

        $fitur = [];
        $ringkas = ['pelanggar' => 0, 'jumlah_batang' => 0, 'unit_bertemuan' => 0, 'unit' => count($rows),
            'lingkup_pelanggar' => (int) $lingkup->pelanggar, 'lingkup_batang' => (int) $lingkup->batang, 'klaster' => 0];
        foreach ($rows as $r) {
            $ringkas['pelanggar'] += (int) $r->pelanggar;
            $ringkas['jumlah_batang'] += (int) $r->batang;
            $ringkas['unit_bertemuan'] += ((int) $r->pelanggar > 0) ? 1 : 0;
            $ringkas['klaster'] += $r->id_klaster ? 1 : 0;
            $fitur[] = [
                'type' => 'Feature',
                'geometry' => json_decode($r->geom),
                'properties' => [
                    'kode' => $r->kode, 'nama' => $r->nama, 'jenis' => $r->jenis, 'kecamatan' => $r->kecamatan, 'kab' => $r->kab,
                    'pelanggar' => (int) $r->pelanggar, 'jumlah_batang' => (int) $r->batang,
                    'klaster' => $r->id_klaster, 'jenis_klaster' => $r->jenis_klaster,
                ],
            ];
        }

        return response()->json(['type' => 'FeatureCollection', 'tingkat' => $tingkat, 'features' => $fitur, 'ringkas' => $ringkas]);
    }

    /**
     * Titik perkiraan lokasi pelanggar (hasil geocoding alamat), digabung per koordinat yang sama.
     * Tanpa nama dan alamat. Hanya admin dan operator (operator: kab/kota sendiri); penampil ditolak,
     * karena titik pelaku usaha termasuk data terbatas.
     */
    public function titik(Request $request)
    {
        $pengguna = $request->user();
        abort_if($pengguna->peran === 'penampil', 403, 'Titik perkiraan hanya untuk admin dan operator.');

        $kondisi = ['v.geom IS NOT NULL'];
        $ikat = [];
        if ($tahun = $request->integer('tahun')) {
            $kondisi[] = 'v.tahun = ?';
            $ikat[] = $tahun;
        }
        if ($pengguna->peran === 'operator') {
            $kondisi[] = 'v.kode_kab = ?';
            $ikat[] = $pengguna->kode_kab;
        }

        $rows = DB::select('
            SELECT round(ST_X(v.geom)::numeric, 5) AS lon, round(ST_Y(v.geom)::numeric, 5) AS lat,
                   v.ketelitian_lokasi AS ketelitian, k.nama AS kab, k.kode AS kode_kab,
                   count(*) AS pelanggar, COALESCE(sum(v.jumlah_batang), 0) AS batang, count(DISTINCT v.operasi_id) AS operasi
            FROM penindakan.v_pelanggar v JOIN ref.kabkota k ON k.kode = v.kode_kab
            WHERE '.implode(' AND ', $kondisi).'
            GROUP BY 1, 2, 3, 4, 5 ORDER BY pelanggar DESC', $ikat);

        return response()->json(array_map(fn ($r) => [
            'lat' => (float) $r->lat, 'lon' => (float) $r->lon, 'ketelitian' => $r->ketelitian, 'kab' => $r->kab, 'kode_kab' => $r->kode_kab,
            'pelanggar' => (int) $r->pelanggar, 'batang' => (int) $r->batang, 'operasi' => (int) $r->operasi,
        ], $rows));
    }

    /** Lapisan kab/kota sebagai GeoJSON, dengan jumlah operasi, pelanggar, dan batang (opsional per tahun). */
    public function kabkota(Request $request)
    {
        $tahun = $request->integer('tahun') ?: null;
        $filter = $tahun ? 'WHERE tahun = ?' : '';
        try {
            $rows = DB::select("
                SELECT k.kode, k.nama, k.wilayah_eks, k.koridor, ST_AsGeoJSON(k.geom, 4) AS geom,
                       COALESCE(r.operasi, 0) AS operasi, COALESCE(r.pelanggar, 0) AS pelanggar, COALESCE(r.jumlah_batang, 0) AS jumlah_batang
                FROM ref.kabkota k
                LEFT JOIN (SELECT kode_kab, sum(operasi) AS operasi, sum(pelanggar) AS pelanggar, sum(jumlah_batang) AS jumlah_batang
                           FROM penindakan.v_rekap_kabkota {$filter} GROUP BY kode_kab) r ON r.kode_kab = k.kode
                ORDER BY k.kode", $tahun ? [$tahun] : []);
        } catch (QueryException $e) {
            report($e);

            return response()->json(['galat' => 'Lapisan peta tidak dapat dibaca. Pastikan ekstensi PostGIS aktif dan batas kab/kota sudah dimuat.'], 500);
        }

        $fitur = [];
        $total = ['operasi' => 0, 'pelanggar' => 0, 'jumlah_batang' => 0, 'kab_bertemuan' => 0];
        foreach ($rows as $r) {
            $total['operasi'] += (int) $r->operasi;
            $total['pelanggar'] += (int) $r->pelanggar;
            $total['jumlah_batang'] += (int) $r->jumlah_batang;
            $total['kab_bertemuan'] += ((int) $r->pelanggar > 0) ? 1 : 0;
            $fitur[] = [
                'type' => 'Feature',
                'geometry' => $r->geom ? json_decode($r->geom) : null,
                'properties' => [
                    'kode' => $r->kode,
                    'nama' => $r->nama,
                    'wilayah_eks' => $r->wilayah_eks,
                    'koridor' => (bool) $r->koridor,
                    'operasi' => (int) $r->operasi,
                    'pelanggar' => (int) $r->pelanggar,
                    'jumlah_batang' => (int) $r->jumlah_batang,
                ],
            ];
        }

        return response()->json(['type' => 'FeatureCollection', 'features' => $fitur, 'ringkas' => $total]);
    }
}
