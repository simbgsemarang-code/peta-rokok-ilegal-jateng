<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Situs publik: beranda, informasi, dan data penindakan teragregat per kab/kota.
 * Tidak ada nama pemilik, nama toko, alamat, titik lokasi, ataupun tingkat desa/kecamatan di sini.
 */
class PublikController extends Controller
{
    public function beranda()
    {
        return view('publik.beranda', ['ringkas' => $this->ringkas()]);
    }

    public function tentang()
    {
        return view('publik.tentang');
    }

    public function kenali()
    {
        return view('publik.kenali');
    }

    public function data()
    {
        abort_unless(config('publik.tampilkan_data'), 404);
        $r = $this->ringkas();

        return view('publik.data', ['ringkas' => $r, 'tahun' => array_column($r['per_tahun'], 'tahun')]);
    }

    public function kontak()
    {
        return view('publik.kontak');
    }

    /** GeoJSON kab/kota (disederhanakan) dengan angka teragregat; opsional per tahun. */
    public function kabkota(Request $request)
    {
        abort_unless(config('publik.tampilkan_data'), 404);
        $tahun = $request->integer('tahun') ?: null;

        return response()->json(Cache::remember('publik.kabkota.'.($tahun ?: 'semua'), 300, function () use ($tahun) {
            try {
                $rows = DB::select('
                    SELECT k.kode, k.nama, ST_AsGeoJSON(ST_SimplifyPreserveTopology(k.geom, 0.003), 4) AS geom,
                           COALESCE(r.operasi, 0) AS operasi, COALESCE(r.pelanggar, 0) AS pelanggar, COALESCE(r.jumlah_batang, 0) AS batang
                    FROM ref.kabkota k
                    LEFT JOIN (SELECT kode_kab, sum(operasi) AS operasi, sum(pelanggar) AS pelanggar, sum(jumlah_batang) AS jumlah_batang
                               FROM penindakan.v_rekap_kabkota'.($tahun ? ' WHERE tahun = ?' : '').' GROUP BY kode_kab) r ON r.kode_kab = k.kode
                    ORDER BY k.kode', $tahun ? [$tahun] : []);
            } catch (QueryException $e) {
                report($e);

                return ['type' => 'FeatureCollection', 'features' => [], 'galat' => 'Data peta belum tersedia.'];
            }

            return [
                'type' => 'FeatureCollection',
                'features' => array_map(fn ($r) => [
                    'type' => 'Feature',
                    'geometry' => $r->geom ? json_decode($r->geom) : null,
                    'properties' => ['kode' => $r->kode, 'nama' => $r->nama, 'operasi' => (int) $r->operasi, 'pelanggar' => (int) $r->pelanggar, 'batang' => (int) $r->batang],
                ], $rows),
            ];
        }));
    }

    /** Unduh CSV angka teragregat kab/kota per tahun. */
    public function csv()
    {
        abort_unless(config('publik.tampilkan_data'), 404);
        $rows = DB::select('
            SELECT r.tahun, k.nama, k.wilayah_eks, r.operasi, r.pelanggar, r.jumlah_batang
            FROM penindakan.v_rekap_kabkota r JOIN ref.kabkota k ON k.kode = r.kode_kab
            ORDER BY r.tahun, k.nama');

        return response()->streamDownload(function () use ($rows) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");   // BOM agar Excel membaca UTF-8
            fputcsv($f, ['Tahun', 'Kabupaten/Kota', 'Wilayah eks-karesidenan', 'Jumlah operasi', 'Jumlah pelaku ditemukan melanggar', 'Jumlah batang disita']);
            foreach ($rows as $r) {
                fputcsv($f, [$r->tahun, $r->nama, $r->wilayah_eks, $r->operasi, $r->pelanggar, $r->jumlah_batang]);
            }
            fclose($f);
        }, 'penindakan-rokok-ilegal-jateng-per-kabkota.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Angka kunci untuk beranda dan halaman data. */
    private function ringkas(): array
    {
        return Cache::remember('publik.ringkas', 300, function () {
            try {
                $tahun = DB::select('
                    SELECT tahun, sum(operasi) AS operasi, sum(pelanggar) AS pelanggar, sum(jumlah_batang) AS batang
                    FROM penindakan.v_rekap_kabkota GROUP BY tahun ORDER BY tahun DESC');
                $kab = DB::selectOne('SELECT count(DISTINCT kode_kab) AS n FROM penindakan.v_rekap_kabkota WHERE pelanggar > 0')->n;
                $mutakhir = DB::selectOne("SELECT max(disimpan_pada) AS t FROM app.batch_impor WHERE jenis = 'penindakan' AND status = 'disimpan'")->t;
            } catch (QueryException $e) {
                report($e);
                $tahun = [];
                $kab = 0;
                $mutakhir = null;
            }
            $per = array_map(fn ($r) => ['tahun' => (int) $r->tahun, 'operasi' => (int) $r->operasi, 'pelanggar' => (int) $r->pelanggar, 'batang' => (int) $r->batang], $tahun);

            return [
                'per_tahun' => $per,
                'operasi' => array_sum(array_column($per, 'operasi')),
                'pelanggar' => array_sum(array_column($per, 'pelanggar')),
                'batang' => array_sum(array_column($per, 'batang')),
                'kab_bertemuan' => (int) $kab,
                'tahun_awal' => $per ? min(array_column($per, 'tahun')) : null,
                'tahun_akhir' => $per ? max(array_column($per, 'tahun')) : null,
                'mutakhir' => $mutakhir,
            ];
        });
    }
}
