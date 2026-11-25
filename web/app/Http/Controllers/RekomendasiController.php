<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class RekomendasiController extends Controller
{
    private function baris(?int $versi, ?string $batasKab): array
    {
        return DB::select('
            SELECT r.id, r.kode_kab, r.lokasi_prioritas, r.waktu_operasi, r.sasaran, r.pendekatan, r.status,
                   r.disetujui_pada, k.nama, k.koridor, s.skor, s.peringkat, s.kategori, e.kerugian_rp, pb.nama AS penyetuju
            FROM analisis.rekomendasi r
            JOIN ref.kabkota k ON k.kode = r.kode_kab
            LEFT JOIN analisis.skor_prioritas s ON s.versi_id = r.versi_id AND s.kode_kab = r.kode_kab
            LEFT JOIN analisis.estimasi_kabkota e ON e.versi_id = r.versi_id AND e.kode_kab = r.kode_kab AND e.sisi = ?
            LEFT JOIN app.pengguna pb ON pb.id = r.disetujui_oleh
            WHERE r.versi_id = ?'.($batasKab ? ' AND r.kode_kab = ?' : '').'
            ORDER BY s.peringkat NULLS LAST, k.kode', array_merge(['outlet', $versi], $batasKab ? [$batasKab] : []));
    }

    private function pilihVersi(Request $request): array
    {
        $versi = DB::select('SELECT id, dibuat_pada, status, deskripsi FROM analisis.versi ORDER BY id DESC LIMIT 30');
        $pilih = $request->integer('versi') ?: ($versi[0]->id ?? null);

        return [$versi, $pilih];
    }

    public function index(Request $request)
    {
        $pengguna = $request->user();
        [$versi, $pilih] = $this->pilihVersi($request);
        $batas = $pengguna->peran === 'operator' ? $pengguna->kode_kab : null;
        $rows = $pilih ? $this->baris($pilih, $batas) : [];
        $info = $pilih ? json_decode(DB::selectOne('SELECT parameter FROM analisis.versi WHERE id = ?', [$pilih])->parameter ?? 'null', true) : null;

        return view('rekomendasi', ['versi' => $versi, 'pilih' => $pilih, 'rows' => $rows, 'info' => $info]);
    }

    public function sunting(Request $request, int $id)
    {
        $d = $request->validate([
            'lokasi_prioritas' => ['nullable', 'string', 'max:1000'],
            'waktu_operasi' => ['nullable', 'string', 'max:1000'],
            'sasaran' => ['nullable', 'string', 'max:1000'],
            'pendekatan' => ['nullable', 'string', 'max:1000'],
        ]);
        abort_if(! DB::selectOne('SELECT 1 AS ada FROM analisis.rekomendasi WHERE id = ?', [$id]), 404);
        // Isi yang diubah membatalkan persetujuan sebelumnya: kembali menjadi draf
        Audit::jalankan(fn () => DB::update(
            "UPDATE analisis.rekomendasi SET lokasi_prioritas = ?, waktu_operasi = ?, sasaran = ?, pendekatan = ?,
                    disunting_oleh = ?, status = 'draf', disetujui_oleh = NULL, disetujui_pada = NULL WHERE id = ?",
            [$d['lokasi_prioritas'] ?? null, $d['waktu_operasi'] ?? null, $d['sasaran'] ?? null, $d['pendekatan'] ?? null, Auth::id(), $id]
        ));

        return back()->with('info', 'Rekomendasi disimpan sebagai draf. Setujui kembali setelah ditinjau.');
    }

    public function status(Request $request, int $id)
    {
        $aksi = $request->validate(['aksi' => ['required', 'in:setujui,tolak,draf']])['aksi'];
        abort_if(! DB::selectOne('SELECT 1 AS ada FROM analisis.rekomendasi WHERE id = ?', [$id]), 404);
        Audit::jalankan(function () use ($aksi, $id) {
            if ($aksi === 'setujui') {
                DB::update("UPDATE analisis.rekomendasi SET status = 'disetujui', disetujui_oleh = ?, disetujui_pada = now() WHERE id = ?", [Auth::id(), $id]);
            } else {
                DB::update('UPDATE analisis.rekomendasi SET status = ?, disetujui_oleh = NULL, disetujui_pada = NULL WHERE id = ?', [$aksi === 'tolak' ? 'ditolak' : 'draf', $id]);
            }
        });

        return back()->with('info', 'Status rekomendasi diperbarui.');
    }

    public function unduh(Request $request)
    {
        $pengguna = $request->user();
        [, $pilih] = $this->pilihVersi($request);
        abort_if(! $pilih, 404);
        $rows = $this->baris($pilih, $pengguna->peran === 'operator' ? $pengguna->kode_kab : null);

        $xl = new Spreadsheet;
        $ws = $xl->getActiveSheet()->setTitle('Rekomendasi');
        $ws->setCellValue('A1', 'REKOMENDASI PENINDAKAN PEREDARAN ROKOK ILEGAL — JAWA TENGAH (DRAF)');
        $ws->setCellValue('A2', "Versi analisis #{$pilih}. Keputusan akhir ada pada Satpol PP Provinsi Jawa Tengah.");
        $kepala = ['Peringkat', 'Kab/Kota', 'Skor', 'Kategori', 'Estimasi kerugian (Rp/tahun)', 'Lokasi prioritas', 'Waktu operasi', 'Sasaran', 'Pendekatan', 'Status'];
        $ws->fromArray($kepala, null, 'A4');
        $i = 5;
        foreach ($rows as $r) {
            $ws->fromArray([
                $r->peringkat, $r->nama, $r->skor !== null ? (float) $r->skor : null, $r->kategori,
                $r->kerugian_rp !== null ? (float) $r->kerugian_rp : null,
                $r->lokasi_prioritas, $r->waktu_operasi, $r->sasaran, $r->pendekatan, $r->status,
            ], null, "A{$i}");
            $i++;
        }
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $ws->getStyle('A4:J4')->getFont()->setBold(true);
        $ws->getStyle('A4:J4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DCE6F1');
        $ws->getStyle('E5:E'.max(5, $i - 1))->getNumberFormat()->setFormatCode('#,##0');
        $ws->getStyle('A4:J'.max(5, $i - 1))->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        foreach (['A' => 10, 'B' => 22, 'C' => 8, 'D' => 10, 'E' => 22, 'F' => 38, 'G' => 34, 'H' => 34, 'I' => 50, 'J' => 12] as $kol => $lebar) {
            $ws->getColumnDimension($kol)->setWidth($lebar);
        }
        $ws->freezePane('C5');

        return response()->streamDownload(function () use ($xl) {
            (new Xlsx($xl))->save('php://output');
        }, "rekomendasi-penindakan-versi-{$pilih}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
