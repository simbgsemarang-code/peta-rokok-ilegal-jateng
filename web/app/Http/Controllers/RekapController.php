<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RekapController extends Controller
{
    private const NAMA_BULAN = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public function index(Request $request)
    {
        $tab = in_array($request->query('tab'), ['bulanan', 'merek'], true) ? $request->query('tab') : 'kabkota';
        $pengguna = $request->user();
        // Operator hanya melihat kab/kota sendiri
        $batasKab = $pengguna->peran === 'operator' ? $pengguna->kode_kab : null;

        $data = match ($tab) {
            'bulanan' => $this->bulanan($batasKab),
            'merek' => $this->merek($batasKab),
            default => $this->kabkota($batasKab),
        };

        return view('rekap', $data + ['tab' => $tab, 'batasKab' => $batasKab, 'namaBulan' => self::NAMA_BULAN]);
    }

    private function kabkota(?string $batasKab): array
    {
        $rows = DB::select('
            SELECT k.kode, k.nama, r.tahun, r.operasi, r.pelanggar, r.jumlah_batang
            FROM penindakan.v_rekap_kabkota r JOIN ref.kabkota k ON k.kode = r.kode_kab'
            .($batasKab ? ' WHERE r.kode_kab = ?' : '').' ORDER BY k.kode, r.tahun', $batasKab ? [$batasKab] : []);

        $tahun = [];
        $baris = [];
        foreach ($rows as $r) {
            $tahun[$r->tahun] = true;
            $baris[$r->kode]['nama'] = $r->nama;
            $baris[$r->kode]['t'][$r->tahun] = ['operasi' => (int) $r->operasi, 'pelanggar' => (int) $r->pelanggar, 'batang' => (int) $r->jumlah_batang];
        }
        ksort($tahun);

        return ['tahun' => array_keys($tahun), 'baris' => $baris];
    }

    private function bulanan(?string $batasKab): array
    {
        $rows = DB::select('
            SELECT tahun, bulan, count(*) AS pelanggar, COALESCE(sum(jumlah_batang), 0) AS batang
            FROM penindakan.v_pelanggar_anonim WHERE bulan IS NOT NULL'
            .($batasKab ? ' AND kode_kab = ?' : '').' GROUP BY tahun, bulan', $batasKab ? [$batasKab] : []);

        $tahun = [];
        $baris = [];
        foreach ($rows as $r) {
            $tahun[$r->tahun] = true;
            $baris[(int) $r->bulan][$r->tahun] = ['pelanggar' => (int) $r->pelanggar, 'batang' => (int) $r->batang];
        }
        ksort($tahun);
        ksort($baris);

        return ['tahun' => array_keys($tahun), 'baris' => $baris];
    }

    private function merek(?string $batasKab): array
    {
        $rows = DB::select('
            SELECT COALESCE(m.nama_baku, upper(trim(t.merek_asli))) AS merek, v.tahun,
                   sum(t.jumlah_batang) AS batang, count(DISTINCT v.id) AS pelanggar
            FROM penindakan.temuan_merek t
            JOIN penindakan.v_pelanggar v ON v.id = t.pelanggar_id
            LEFT JOIN ref.merek m ON m.id = t.merek_id'
            .($batasKab ? ' WHERE v.kode_kab = ?' : '').' GROUP BY 1, 2', $batasKab ? [$batasKab] : []);

        $tahun = [];
        $baris = [];
        foreach ($rows as $r) {
            $tahun[$r->tahun] = true;
            $baris[$r->merek]['t'][$r->tahun] = ['pelanggar' => (int) $r->pelanggar, 'batang' => (int) $r->batang];
            $baris[$r->merek]['total'] = ($baris[$r->merek]['total'] ?? 0) + (int) $r->batang;
        }
        ksort($tahun);
        uasort($baris, fn ($a, $b) => $b['total'] <=> $a['total']);

        return ['tahun' => array_keys($tahun), 'baris' => array_slice($baris, 0, 40, true)];
    }
}
