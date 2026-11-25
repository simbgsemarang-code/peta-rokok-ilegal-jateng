<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class PengaturanController extends Controller
{
    private const TAB = ['bobot', 'pendukung', 'pengguna', 'audit'];

    private const KOMPONEN = [
        'estimasi_kerugian' => 'Estimasi kerugian',
        'proporsi_berindikasi' => 'Proporsi outlet berindikasi',
        'hotspot' => 'Hotspot (belum tersedia)',
        'tren_penindakan' => 'Intensitas dan tren penindakan',
        'efek_pembinaan' => 'Efek pembinaan (belum tersedia)',
    ];

    public function index(Request $request)
    {
        if (in_array($request->query('tab'), ['tarif', 'merek'], true)) {   // dipindah ke menu Data Master
            return redirect()->route('master', ['tab' => $request->query('tab')]);
        }
        $tab = in_array($request->query('tab'), self::TAB, true) ? $request->query('tab') : 'bobot';
        $data = ['tab' => $tab];

        switch ($tab) {
            case 'bobot':
                $bobot = [];
                foreach (DB::select('SELECT DISTINCT ON (komponen) komponen, bobot_persen FROM analisis.bobot_skor ORDER BY komponen, berlaku_mulai DESC, id DESC') as $r) {
                    $bobot[$r->komponen] = (float) $r->bobot_persen;
                }
                $data['bobot'] = $bobot;
                $data['komponen'] = self::KOMPONEN;
                break;
            case 'pendukung':
                $tahun = $request->integer('tahun') ?: (int) date('Y');
                $nilai = [];
                foreach (DB::select("SELECT kode_wilayah, jumlah_outlet_rokok, jumlah_perokok_dewasa, sumber FROM ref.data_pendukung WHERE tingkat = 'kabkota' AND tahun = ?", [$tahun]) as $r) {
                    $nilai[$r->kode_wilayah] = $r;
                }
                $data += ['tahun' => $tahun, 'nilai' => $nilai, 'kabkota' => DB::select('SELECT kode, nama FROM ref.kabkota ORDER BY kode')];
                break;
            case 'pengguna':
                $data['pengguna'] = DB::select('SELECT p.id, p.nama, p.email, p.peran, p.kode_kab, p.aktif, k.nama AS kab FROM app.pengguna p LEFT JOIN ref.kabkota k ON k.kode = p.kode_kab ORDER BY p.id');
                $data['kabkota'] = DB::select('SELECT kode, nama FROM ref.kabkota ORDER BY kode');
                break;
            case 'audit':
                $data['log'] = DB::select('
                    SELECT l.id, l.waktu, p.nama AS pelaku, l.aksi, l.tabel, l.id_baris
                    FROM app.log_audit l LEFT JOIN app.pengguna p ON p.id = l.pengguna_id ORDER BY l.id DESC LIMIT 150');
                break;
        }

        return view('pengaturan', $data);
    }

    public function simpanBobot(Request $request)
    {
        $rules = [];
        foreach (array_keys(self::KOMPONEN) as $k) {
            $rules[$k] = ['required', 'numeric', 'min:0', 'max:100'];
        }
        $d = $request->validate($rules);
        if (abs(array_sum($d) - 100) > 0.01) {
            return back()->withInput()->withErrors(['bobot' => 'Jumlah bobot harus tepat 100% (sekarang '.round(array_sum($d), 2).'%).']);
        }
        Audit::jalankan(function () use ($d) {
            foreach ($d as $komponen => $nilai) {
                DB::table('analisis.bobot_skor')->insert(['komponen' => $komponen, 'bobot_persen' => $nilai, 'diubah_oleh' => Auth::id()]);
            }
        });

        return redirect()->route('pengaturan', ['tab' => 'bobot'])->with('info', 'Bobot skor disimpan. Berlaku untuk perhitungan berikutnya.');
    }

    public function simpanPendukung(Request $request)
    {
        $d = $request->validate([
            'tahun' => ['required', 'integer', 'min:2000', 'max:2100'],
            'sumber' => ['nullable', 'string', 'max:200'],
            'outlet' => ['array'],
            'outlet.*' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'perokok' => ['array'],
            'perokok.*' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ]);
        $kode = array_column(DB::select('SELECT kode FROM ref.kabkota'), 'kode');
        $n = 0;
        Audit::jalankan(function () use ($d, $kode, &$n) {
            foreach ($kode as $k) {
                $o = $d['outlet'][$k] ?? null;
                $p = $d['perokok'][$k] ?? null;
                if (($o === null || $o === '') && ($p === null || $p === '')) {
                    continue;
                }
                DB::statement("
                    INSERT INTO ref.data_pendukung (kode_wilayah, tingkat, tahun, jumlah_outlet_rokok, jumlah_perokok_dewasa, sumber)
                    VALUES (?, 'kabkota', ?, ?, ?, ?)
                    ON CONFLICT (kode_wilayah, tahun) DO UPDATE SET jumlah_outlet_rokok = EXCLUDED.jumlah_outlet_rokok,
                      jumlah_perokok_dewasa = EXCLUDED.jumlah_perokok_dewasa, sumber = EXCLUDED.sumber",
                    [$k, $d['tahun'], $o === '' ? null : $o, $p === '' ? null : $p, $d['sumber'] ?? null]);
                $n++;
            }
        });

        return redirect()->route('pengaturan', ['tab' => 'pendukung', 'tahun' => $d['tahun']])->with('info', "Data pendukung {$n} kab/kota disimpan untuk tahun {$d['tahun']}.");
    }

    public function simpanPengguna(Request $request)
    {
        $d = $request->validate([
            'nama' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', Rule::unique('pgsql.app.pengguna', 'email')],
            'peran' => ['required', 'in:admin,operator,penampil'],
            'kode_kab' => ['nullable', 'exists:pgsql.ref.kabkota,kode'],
            'sandi' => ['required', 'string', 'min:10', 'max:100'],
        ], [
            'email.unique' => 'Email itu sudah terdaftar.',
            'sandi.min' => 'Kata sandi minimal 10 karakter.',
        ]);
        if ($d['peran'] === 'operator' && empty($d['kode_kab'])) {
            return back()->withInput()->withErrors(['kode_kab' => 'Operator wajib dikaitkan dengan satu kab/kota.']);
        }
        Audit::jalankan(fn () => DB::table('app.pengguna')->insert([
            'nama' => $d['nama'], 'email' => $d['email'], 'kata_sandi_hash' => Hash::make($d['sandi']),
            'peran' => $d['peran'], 'kode_kab' => $d['peran'] === 'operator' ? $d['kode_kab'] : null,
        ]));

        return redirect()->route('pengaturan', ['tab' => 'pengguna'])->with('info', 'Pengguna ditambahkan.');
    }

    public function ubahPengguna(Request $request, int $id)
    {
        $d = $request->validate([
            'aksi' => ['required', 'in:aktif,nonaktif,sandi'],
            'sandi' => ['nullable', 'string', 'min:10', 'max:100'],
        ], ['sandi.min' => 'Kata sandi minimal 10 karakter.']);
        abort_if(! DB::selectOne('SELECT 1 AS ada FROM app.pengguna WHERE id = ?', [$id]), 404);

        if ($d['aksi'] === 'nonaktif' && $id === (int) Auth::id()) {
            return back()->withErrors(['aksi' => 'Anda tidak dapat menonaktifkan akun sendiri.']);
        }
        if ($d['aksi'] === 'sandi') {
            if (empty($d['sandi'])) {
                return back()->withErrors(['sandi' => 'Isi kata sandi baru (minimal 10 karakter).']);
            }
            Audit::jalankan(fn () => DB::update('UPDATE app.pengguna SET kata_sandi_hash = ? WHERE id = ?', [Hash::make($d['sandi']), $id]));
        } else {
            Audit::jalankan(fn () => DB::update('UPDATE app.pengguna SET aktif = ? WHERE id = ?', [$d['aksi'] === 'aktif' ? 'true' : 'false', $id]));
        }

        return redirect()->route('pengaturan', ['tab' => 'pengguna'])->with('info', 'Akun diperbarui.');
    }
}
