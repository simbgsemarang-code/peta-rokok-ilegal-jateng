<?php

namespace App\Http\Controllers;

use App\Services\ImporPenindakan;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ImporController extends Controller
{
    public function index()
    {
        $batch = DB::select("
            SELECT b.id, b.nama_file, b.diunggah_pada, b.jumlah_baris, b.jumlah_ditolak, b.status, p.nama AS pengunggah,
                   (SELECT count(*) FROM penindakan.operasi o WHERE o.batch_id = b.id) AS operasi,
                   (SELECT count(*) FROM penindakan.pelanggar pl JOIN penindakan.operasi o ON o.id = pl.operasi_id WHERE o.batch_id = b.id) AS pelanggar
            FROM app.batch_impor b LEFT JOIN app.pengguna p ON p.id = b.diunggah_oleh
            WHERE b.jenis = 'penindakan'
            ORDER BY b.id DESC");

        return view('impor.index', ['batch' => $batch]);
    }

    public function unggah(Request $request)
    {
        $request->validate([
            'berkas' => ['required', 'file', 'mimes:xlsx,xls', 'max:30720'],
        ], [
            'berkas.required' => 'Pilih berkas Excel terlebih dahulu.',
            'berkas.mimes' => 'Berkas harus berformat .xlsx atau .xls.',
            'berkas.max' => 'Ukuran berkas maksimal 30 MB.',
            'berkas.uploaded' => 'Berkas gagal diunggah. Periksa batas upload_max_filesize di php.ini.',
        ]);

        $berkas = $request->file('berkas');
        try {
            $svc = new ImporPenindakan;
            $hasil = $svc->baca($berkas->getRealPath());
            if (! $hasil['operasi']) {
                return back()->withErrors(['berkas' => 'Tidak ada operasi yang dapat dibaca dari berkas ini.']);
            }
            $id = $svc->simpan($hasil, $berkas->getClientOriginalName(), Auth::id());
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['berkas' => 'Berkas tidak dapat diproses: '.$e->getMessage()]);
        }

        return redirect()->route('impor.tampil', $id)->with('info', 'Berkas terbaca. Periksa ringkasan di bawah, lalu simpan atau batalkan.');
    }

    public function tampil(int $id)
    {
        $batch = DB::selectOne('SELECT b.*, p.nama AS pengunggah FROM app.batch_impor b LEFT JOIN app.pengguna p ON p.id = b.diunggah_oleh WHERE b.id = ? AND b.jenis = ?', [$id, 'penindakan']);
        abort_if(! $batch, 404);

        $ringkas = DB::select('
            SELECT o.tahun, count(DISTINCT o.id) AS operasi, count(DISTINCT p.id) AS pelanggar, COALESCE(sum(t.jumlah_batang), 0) AS batang
            FROM penindakan.operasi o
            LEFT JOIN penindakan.pelanggar p ON p.operasi_id = o.id
            LEFT JOIN penindakan.temuan_merek t ON t.pelanggar_id = p.id
            WHERE o.batch_id = ? GROUP BY o.tahun ORDER BY o.tahun', [$id]);

        $perKab = DB::select('
            SELECT k.nama, count(DISTINCT o.id) AS operasi, count(DISTINCT p.id) AS pelanggar, COALESCE(sum(t.jumlah_batang), 0) AS batang
            FROM penindakan.operasi o JOIN ref.kabkota k ON k.kode = o.kode_kab
            LEFT JOIN penindakan.pelanggar p ON p.operasi_id = o.id
            LEFT JOIN penindakan.temuan_merek t ON t.pelanggar_id = p.id
            WHERE o.batch_id = ? GROUP BY k.nama ORDER BY batang DESC LIMIT 10', [$id]);

        $kecTak = DB::selectOne('
            SELECT count(*) AS n, count(*) FILTER (WHERE p.kode_kec IS NULL) AS tanpa_kec
            FROM penindakan.pelanggar p JOIN penindakan.operasi o ON o.id = p.operasi_id WHERE o.batch_id = ?', [$id]);

        // Operasi yang tampaknya sudah ada di batch tersimpan lain (kab/kota, tahun, dan tanggal sama)
        $dobel = DB::selectOne("
            SELECT count(*) AS n FROM penindakan.operasi n
            WHERE n.batch_id = ? AND EXISTS (
              SELECT 1 FROM penindakan.operasi o JOIN app.batch_impor bi ON bi.id = o.batch_id AND bi.status = 'disimpan'
              WHERE o.aktif AND o.batch_id <> n.batch_id AND o.tahun = n.tahun AND o.kode_kab = n.kode_kab
                AND o.tanggal_asli IS NOT DISTINCT FROM n.tanggal_asli)", [$id])->n;

        return view('impor.tampil', [
            'batch' => $batch,
            'ringkas' => $ringkas,
            'perKab' => $perKab,
            'kecTak' => $kecTak,
            'dobel' => $dobel,
            'catatan' => array_filter(explode("\n", (string) $batch->catatan)),
        ]);
    }

    public function simpan(int $id)
    {
        $batch = DB::selectOne('SELECT status FROM app.batch_impor WHERE id = ?', [$id]);
        abort_if(! $batch, 404);
        if ($batch->status !== 'pratinjau') {
            return back()->withErrors(['status' => 'Hanya batch berstatus pratinjau yang dapat disimpan.']);
        }
        Audit::jalankan(fn () => DB::select('SELECT app.simpan_impor(?)', [$id]));

        return redirect()->route('impor')->with('info', "Batch #{$id} disimpan. Datanya kini tampil di peta dan rekap.");
    }

    public function batal(int $id)
    {
        $batch = DB::selectOne('SELECT status FROM app.batch_impor WHERE id = ?', [$id]);
        abort_if(! $batch, 404);
        if ($batch->status === 'dibatalkan') {
            return back()->withErrors(['status' => 'Batch ini sudah dibatalkan.']);
        }
        Audit::jalankan(fn () => DB::select('SELECT app.batalkan_impor(?)', [$id]));

        return redirect()->route('impor')->with('info', "Batch #{$id} dibatalkan. Datanya tidak lagi dihitung, tetapi tetap tersimpan sebagai arsip.");
    }
}
