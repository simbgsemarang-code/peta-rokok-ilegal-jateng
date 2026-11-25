<?php

namespace App\Http\Controllers;

use App\Services\Analisis;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class KerugianController extends Controller
{
    public function index(Request $request)
    {
        $pengguna = $request->user();
        $versi = DB::select('
            SELECT v.id, v.dibuat_pada, v.status, v.deskripsi, p.nama AS pembuat
            FROM analisis.versi v LEFT JOIN app.pengguna p ON p.id = v.dibuat_oleh ORDER BY v.id DESC LIMIT 30');
        $pilih = $request->integer('versi') ?: ($versi[0]->id ?? null);
        $aktif = null;
        foreach ($versi as $v) {
            if ((int) $v->id === (int) $pilih) {
                $aktif = $v;
            }
        }

        $rows = [];
        $param = Analisis::DASAR;
        $info = null;
        if ($aktif) {
            $info = json_decode(DB::selectOne('SELECT parameter FROM analisis.versi WHERE id = ?', [$aktif->id])->parameter ?? 'null', true);
            $param = array_merge($param, $info['param'] ?? []);
            $batas = $pengguna->peran === 'operator' ? $pengguna->kode_kab : null;
            $rows = DB::select("
                SELECT k.kode, k.nama, e.metode, e.proporsi, e.ci_bawah, e.ci_atas, e.volume_batang_tahun,
                       e.kerugian_rp, e.kerugian_rp_bawah, e.kerugian_rp_atas,
                       (SELECT jumlah_outlet_rokok FROM ref.data_pendukung d WHERE d.kode_wilayah = k.kode AND d.tingkat = 'kabkota' AND d.jumlah_outlet_rokok IS NOT NULL ORDER BY tahun DESC LIMIT 1) AS outlet
                FROM ref.kabkota k
                LEFT JOIN analisis.estimasi_kabkota e ON e.kode_kab = k.kode AND e.versi_id = ? AND e.sisi = 'outlet'"
                .($batas ? ' WHERE k.kode = ?' : '').' ORDER BY e.kerugian_rp DESC NULLS LAST, k.kode', $batas ? [$aktif->id, $batas] : [$aktif->id]);
        }

        $tarif = DB::select('SELECT id, berlaku_mulai, jenis_produk, golongan, tarif_cukai_per_batang FROM ref.tarif ORDER BY berlaku_mulai DESC, jenis_produk, golongan');
        $tarifPakai = $info['tarif'] ?? null;
        $cukaiPerBatang = $tarifPakai ? (float) $tarifPakai['tarif_cukai_per_batang'] : null;

        return view('kerugian', [
            'versi' => $versi, 'aktif' => $aktif, 'rows' => $rows, 'param' => $param, 'info' => $info,
            'tarif' => $tarif, 'cukaiPerBatang' => $cukaiPerBatang,
            'jumlahPendukung' => (int) DB::selectOne("SELECT count(DISTINCT kode_wilayah) AS n FROM ref.data_pendukung WHERE tingkat = 'kabkota' AND jumlah_outlet_rokok IS NOT NULL")->n,
        ]);
    }

    public function hitung(Request $request, Analisis $analisis)
    {
        $d = $request->validate([
            'tarif_id' => ['nullable', 'integer', 'exists:pgsql.ref.tarif,id'],
            'vol_per_outlet' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'dbhcht_persen' => ['required', 'numeric', 'min:0', 'max:100'],
            'skenario_rendah' => ['required', 'numeric', 'min:0', 'max:100'],
            'skenario_tengah' => ['required', 'numeric', 'min:0', 'max:100'],
            'skenario_tinggi' => ['required', 'numeric', 'min:0', 'max:100'],
            'deskripsi' => ['nullable', 'string', 'max:200'],
        ], [
            'vol_per_outlet.required' => 'Volume per outlet wajib diisi.',
            'skenario_rendah.required' => 'Ketiga skenario proporsi wajib diisi.',
            'skenario_tengah.required' => 'Ketiga skenario proporsi wajib diisi.',
            'skenario_tinggi.required' => 'Ketiga skenario proporsi wajib diisi.',
        ]);
        if (! ($d['skenario_rendah'] <= $d['skenario_tengah'] && $d['skenario_tengah'] <= $d['skenario_tinggi'])) {
            return back()->withInput()->withErrors(['skenario_tengah' => 'Urutan skenario harus rendah ≤ tengah ≤ tinggi.']);
        }

        $id = $analisis->hitung($d, Auth::id(), $d['deskripsi'] ?? null);

        return redirect()->route('kerugian', ['versi' => $id])->with('info', "Versi analisis #{$id} dibuat sebagai draf. Rekomendasi penindakan ikut disusun ulang.");
    }

    public function final(int $versi)
    {
        abort_if(! DB::selectOne('SELECT 1 AS ada FROM analisis.versi WHERE id = ?', [$versi]), 404);
        Audit::jalankan(fn () => DB::update("UPDATE analisis.versi SET status = 'final' WHERE id = ?", [$versi]));

        return back()->with('info', "Versi #{$versi} ditetapkan final. Layer peta dan view publik memakai versi final terbaru.");
    }
}
