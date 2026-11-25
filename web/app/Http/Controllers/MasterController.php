<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Menu Data Master (khusus admin): CRUD data operasi (sheet DATA "Data Gabungan Operasi Cukai") dan master dinamis
 * (merek, tarif, surveyor, titik acuan). Kab/kota, kecamatan, dan desa/kelurahan sengaja hanya baca.
 */
class MasterController extends Controller
{
    private const TAB = [
        'operasi' => 'Data operasi',
        'merek' => 'Merek',
        'tarif' => 'Tarif',
        'surveyor' => 'Surveyor',
        'poi' => 'Titik acuan',
    ];

    private const HASIL = ['TEMUAN', 'NIHIL', 'PEMBINAAN/SOSIALISASI'];
    private const PER_HALAMAN = 25;
    private const NAMA_BATCH_MANUAL = 'Input manual (menu Data Master)';

    // ------------------------------------------------------------------ daftar

    public function index(Request $request)
    {
        $tab = array_key_exists($request->query('tab'), self::TAB) ? $request->query('tab') : 'operasi';
        $data = ['tab' => $tab, 'daftarTab' => self::TAB, 'hasil' => self::HASIL];
        $ubah = $request->query('ubah');

        switch ($tab) {
            case 'operasi':
                $data += $this->daftarOperasi($request);
                break;
            case 'merek':
                $data['merek'] = DB::select('
                    SELECT m.id, m.nama_baku, array_to_string(m.alias, \', \') AS alias, m.catatan, count(t.id) AS pemakaian
                    FROM ref.merek m LEFT JOIN penindakan.temuan_merek t ON t.merek_id = m.id
                    GROUP BY m.id ORDER BY m.nama_baku');
                $data['belum'] = DB::select('
                    SELECT upper(trim(merek_asli)) AS merek, sum(jumlah_batang) AS batang, count(*) AS baris
                    FROM penindakan.temuan_merek WHERE merek_id IS NULL GROUP BY 1 ORDER BY batang DESC LIMIT 40');
                $data['edit'] = $ubah ? collect($data['merek'])->firstWhere('id', (int) $ubah) : null;
                break;
            case 'tarif':
                $data['tarif'] = DB::select('SELECT * FROM ref.tarif ORDER BY berlaku_mulai DESC, jenis_produk, golongan');
                $data['edit'] = $ubah ? collect($data['tarif'])->firstWhere('id', (int) $ubah) : null;
                break;
            case 'surveyor':
                $data['surveyor'] = DB::select('
                    SELECT s.kode, s.nama, s.pendamping, count(k.id_klaster) AS klaster
                    FROM survei.surveyor s LEFT JOIN survei.klaster k ON k.surveyor_kode = s.kode
                    GROUP BY s.kode ORDER BY s.kode');
                $data['edit'] = $ubah ? collect($data['surveyor'])->firstWhere('kode', (string) $ubah) : null;
                break;
            case 'poi':
                $data['poi'] = DB::select('SELECT id, jenis, nama, sumber, ST_Y(geom) AS lat, ST_X(geom) AS lon FROM ref.poi ORDER BY jenis, nama NULLS LAST, id');
                $data['edit'] = $ubah ? collect($data['poi'])->firstWhere('id', (int) $ubah) : null;
                break;
        }

        return view('master', $data);
    }

    private function daftarOperasi(Request $request): array
    {
        $tahun = $request->integer('tahun') ?: null;
        $kab = preg_match('/^33\.\d{2}$/', (string) $request->query('kab')) ? $request->query('kab') : null;
        $hasil = in_array($request->query('hasil'), self::HASIL, true) ? $request->query('hasil') : null;
        $q = trim((string) $request->query('q', ''));
        $halaman = max(1, $request->integer('hal', 1));

        $where = ["b.status = 'disimpan'"];
        $ikat = [];
        if ($tahun) {
            $where[] = 'o.tahun = ?';
            $ikat[] = $tahun;
        }
        if ($kab) {
            $where[] = 'o.kode_kab = ?';
            $ikat[] = $kab;
        }
        if ($hasil) {
            $where[] = 'o.hasil = ?';
            $ikat[] = $hasil;
        }
        if ($q !== '') {
            $like = '%'.$q.'%';
            $where[] = '(o.tim ILIKE ? OR o.tanggal_asli ILIKE ? OR EXISTS (SELECT 1 FROM penindakan.pelanggar pp WHERE pp.operasi_id = o.id AND (pp.nama_pemilik ILIKE ? OR pp.nama_toko_kendaraan ILIKE ? OR pp.alamat ILIKE ?)))';
            array_push($ikat, $like, $like, $like, $like, $like);
        }
        $kondisi = implode(' AND ', $where);

        $total = (int) DB::selectOne("SELECT count(*) AS n FROM penindakan.operasi o JOIN app.batch_impor b ON b.id = o.batch_id WHERE {$kondisi}", $ikat)->n;
        $baris = DB::select("
            SELECT o.id, o.tahun, o.kode_kab, k.nama AS kab, o.tim, o.tanggal_asli, o.tanggal_mulai, o.hasil, o.aktif, b.nama_file,
                   (SELECT count(*) FROM penindakan.pelanggar p WHERE p.operasi_id = o.id) AS pelanggar,
                   (SELECT COALESCE(sum(t.jumlah_batang), 0) FROM penindakan.temuan_merek t JOIN penindakan.pelanggar p ON p.id = t.pelanggar_id WHERE p.operasi_id = o.id) AS batang
            FROM penindakan.operasi o
            JOIN app.batch_impor b ON b.id = o.batch_id
            JOIN ref.kabkota k ON k.kode = o.kode_kab
            WHERE {$kondisi}
            ORDER BY o.tahun DESC, o.tanggal_mulai DESC NULLS LAST, o.id DESC
            LIMIT ".self::PER_HALAMAN.' OFFSET '.(($halaman - 1) * self::PER_HALAMAN), $ikat);

        return [
            'baris' => $baris, 'total' => $total, 'halaman' => $halaman, 'perHalaman' => self::PER_HALAMAN,
            'tahunFilter' => $tahun, 'kabFilter' => $kab, 'hasilFilter' => $hasil, 'q' => $q,
            'daftarTahun' => $this->daftarTahun(), 'kabkota' => DB::select('SELECT kode, nama FROM ref.kabkota ORDER BY nama'),
        ];
    }

    /** Tahun yang ada di data operasi aktif; bertambah sendiri bila admin memasukkan operasi tahun baru. */
    private function daftarTahun(): array
    {
        return array_map(fn ($r) => (int) $r->tahun, DB::select("
            SELECT DISTINCT o.tahun FROM penindakan.operasi o JOIN app.batch_impor b ON b.id = o.batch_id AND b.status = 'disimpan' ORDER BY o.tahun"));
    }

    // ------------------------------------------------------------------ operasi

    public function operasiBaru()
    {
        return view('master_operasi', [
            'op' => null, 'pelanggar' => [], 'hasil' => self::HASIL, 'kabkota' => DB::select('SELECT kode, nama FROM ref.kabkota ORDER BY nama'),
            'tahunIni' => (int) date('Y'), 'daftarMerek' => [],
        ]);
    }

    public function operasiTampil(int $id)
    {
        $op = DB::selectOne('
            SELECT o.*, k.nama AS kab, b.nama_file FROM penindakan.operasi o
            JOIN ref.kabkota k ON k.kode = o.kode_kab JOIN app.batch_impor b ON b.id = o.batch_id WHERE o.id = ?', [$id]);
        abort_if(! $op, 404);

        $pelanggar = DB::select('
            SELECT p.id, p.nama_pemilik, p.nama_toko_kendaraan, p.alamat, p.kecamatan_teks, p.ketelitian_lokasi, p.cara_penentuan,
                   ST_Y(p.geom) AS lat, ST_X(p.geom) AS lon
            FROM penindakan.pelanggar p WHERE p.operasi_id = ? ORDER BY p.id', [$id]);
        foreach ($pelanggar as $p) {
            $p->temuan = DB::select('SELECT merek_asli, jumlah_batang FROM penindakan.temuan_merek WHERE pelanggar_id = ? ORDER BY id', [$p->id]);
            $p->batang = array_sum(array_map(fn ($t) => (int) $t->jumlah_batang, $p->temuan));
        }

        return view('master_operasi', [
            'op' => $op, 'pelanggar' => $pelanggar, 'hasil' => self::HASIL,
            'kabkota' => DB::select('SELECT kode, nama FROM ref.kabkota ORDER BY nama'), 'tahunIni' => (int) date('Y'),
            'daftarMerek' => DB::select('SELECT nama_baku FROM ref.merek ORDER BY nama_baku'),
        ]);
    }

    private function aturanOperasi(): array
    {
        return [
            'tahun' => ['required', 'integer', 'min:2000', 'max:2100'],
            'kode_kab' => ['required', 'exists:pgsql.ref.kabkota,kode'],
            'tim' => ['nullable', 'string', 'max:200'],
            'tanggal_asli' => ['nullable', 'string', 'max:100'],
            'tanggal_mulai' => ['nullable', 'date'],
            'bulan' => ['nullable', 'integer', 'between:1,12'],
            'hasil' => ['required', 'in:'.implode(',', self::HASIL)],
        ];
    }

    /** @return array{0: ?string, 1: ?int}|RedirectResponse tanggal_mulai dan bulan yang konsisten dengan tahun */
    private function tanggalBulan(array $d)
    {
        $tgl = $d['tanggal_mulai'] ?? null;
        if ($tgl) {
            $t = strtotime($tgl);
            if ((int) date('Y', $t) !== (int) $d['tahun']) {
                return back()->withInput()->withErrors(['tanggal_mulai' => 'Tanggal mulai harus berada di tahun '.$d['tahun'].' (atau ubah tahun operasi).']);
            }

            return [date('Y-m-d', $t), (int) date('n', $t)];
        }

        return [null, isset($d['bulan']) && $d['bulan'] !== '' ? (int) $d['bulan'] : null];
    }

    public function operasiSimpan(Request $request): RedirectResponse
    {
        $d = $request->validate($this->aturanOperasi(), ['required' => 'Kolom :attribute wajib diisi.']);
        $tb = $this->tanggalBulan($d);
        if ($tb instanceof RedirectResponse) {
            return $tb;
        }

        $id = Audit::jalankan(function () use ($d, $tb) {
            $id = DB::table('penindakan.operasi')->insertGetId([
                'batch_id' => $this->batchManual(), 'tahun' => $d['tahun'], 'kode_kab' => $d['kode_kab'],
                'tim' => $this->teks($d['tim'] ?? null), 'tanggal_asli' => $this->teks($d['tanggal_asli'] ?? null),
                'tanggal_mulai' => $tb[0], 'bulan' => $tb[1], 'hasil' => $d['hasil'], 'sumber' => self::NAMA_BATCH_MANUAL, 'aktif' => true,
            ], 'id');
            Audit::catat('INSERT', 'penindakan.operasi', $id, null, $this->ringkasOperasi($d, $tb));

            return $id;
        });
        $this->segarkanPublik();

        return redirect()->route('master.operasi.tampil', $id)->with('info', 'Operasi ditambahkan. Tambahkan pelanggar di bawah bila hasilnya TEMUAN.');
    }

    public function operasiUbah(Request $request, int $id): RedirectResponse
    {
        $lama = DB::selectOne('SELECT * FROM penindakan.operasi WHERE id = ?', [$id]);
        abort_if(! $lama, 404);
        $d = $request->validate($this->aturanOperasi(), ['required' => 'Kolom :attribute wajib diisi.']);
        $tb = $this->tanggalBulan($d);
        if ($tb instanceof RedirectResponse) {
            return $tb;
        }
        if ($d['hasil'] !== 'TEMUAN') {
            $n = (int) DB::selectOne('SELECT count(*) AS n FROM penindakan.pelanggar WHERE operasi_id = ?', [$id])->n;
            if ($n > 0) {
                return back()->withInput()->withErrors(['hasil' => "Operasi ini punya {$n} pelanggar; hapus pelanggarnya dulu sebelum mengubah hasil menjadi {$d['hasil']}."]);
            }
        }

        Audit::jalankan(function () use ($id, $d, $tb, $lama) {
            DB::update('UPDATE penindakan.operasi SET tahun = ?, kode_kab = ?, tim = ?, tanggal_asli = ?, tanggal_mulai = ?, bulan = ?, hasil = ? WHERE id = ?', [
                $d['tahun'], $d['kode_kab'], $this->teks($d['tim'] ?? null), $this->teks($d['tanggal_asli'] ?? null), $tb[0], $tb[1], $d['hasil'], $id,
            ]);
            Audit::catat('UPDATE', 'penindakan.operasi', $id, $this->ringkasOperasi((array) $lama, [$lama->tanggal_mulai, $lama->bulan]), $this->ringkasOperasi($d, $tb));
        });
        $this->segarkanPublik();

        return redirect()->route('master.operasi.tampil', $id)->with('info', 'Operasi diperbarui.');
    }

    public function operasiAktif(int $id): RedirectResponse
    {
        $op = DB::selectOne('SELECT id, aktif FROM penindakan.operasi WHERE id = ?', [$id]);
        abort_if(! $op, 404);
        $baru = ! $op->aktif;
        Audit::jalankan(function () use ($id, $baru, $op) {
            DB::update('UPDATE penindakan.operasi SET aktif = ? WHERE id = ?', [$baru ? 'true' : 'false', $id]);
            Audit::catat('UPDATE', 'penindakan.operasi', $id, ['aktif' => (bool) $op->aktif], ['aktif' => $baru]);
        });
        $this->segarkanPublik();

        return back()->with('info', $baru ? 'Operasi diaktifkan kembali; ikut dihitung di rekap dan peta.' : 'Operasi dinonaktifkan; tidak lagi dihitung di rekap dan peta (data tetap tersimpan).');
    }

    public function operasiHapus(int $id): RedirectResponse
    {
        $op = DB::selectOne('SELECT id, tahun, kode_kab, hasil, tanggal_asli FROM penindakan.operasi WHERE id = ?', [$id]);
        abort_if(! $op, 404);
        $n = (int) DB::selectOne('SELECT count(*) AS n FROM penindakan.pelanggar WHERE operasi_id = ?', [$id])->n;
        Audit::jalankan(function () use ($id, $op, $n) {
            DB::delete('DELETE FROM penindakan.operasi WHERE id = ?', [$id]);   // pelanggar dan temuan ikut terhapus (CASCADE)
            Audit::catat('DELETE', 'penindakan.operasi', $id, (array) $op + ['pelanggar_terhapus' => $n], null);
        });
        $this->segarkanPublik();

        return redirect()->route('master', ['tab' => 'operasi'])->with('info', "Operasi #{$id} dihapus permanen beserta {$n} pelanggar dan rincian merek-nya.");
    }

    // ------------------------------------------------------------------ pelanggar

    private function aturanPelanggar(): array
    {
        return [
            'nama_pemilik' => ['nullable', 'string', 'max:200'],
            'nama_toko_kendaraan' => ['nullable', 'string', 'max:200'],
            'alamat' => ['nullable', 'string', 'max:500'],
            'kecamatan_teks' => ['nullable', 'string', 'max:100'],
            'lat' => ['nullable', 'numeric', 'between:-8.9,-5.7'],
            'lon' => ['nullable', 'numeric', 'between:107.9,112.1'],
            'lat_asal' => ['nullable', 'string', 'max:30'],
            'lon_asal' => ['nullable', 'string', 'max:30'],
            'temuan' => ['nullable', 'array', 'max:60'],
            'temuan.*.merek' => ['nullable', 'string', 'max:120'],
            'temuan.*.batang' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ];
    }

    private function pesanPelanggar(): array
    {
        return [
            'lat.between' => 'Lintang di luar wilayah Jawa Tengah (sekitar -8,9 sampai -5,7).',
            'lon.between' => 'Bujur di luar wilayah Jawa Tengah (sekitar 107,9 sampai 112,1).',
            'temuan.*.batang.integer' => 'Jumlah batang harus bilangan bulat.',
            'temuan.*.batang.min' => 'Jumlah batang tidak boleh negatif.',
        ];
    }

    /** Baris merek yang terisi; null bila ada baris batang tanpa nama merek. */
    private function barisTemuan(array $d): ?array
    {
        $out = [];
        foreach ($d['temuan'] ?? [] as $t) {
            $m = mb_strtoupper(trim((string) ($t['merek'] ?? '')));
            $b = $t['batang'] ?? null;
            if ($m === '' && ($b === null || $b === '')) {
                continue;
            }
            if ($m === '') {
                return null;
            }
            $out[] = [$m, (int) ($b === null || $b === '' ? 0 : $b)];
        }

        return $out;
    }

    public function pelanggarTambah(Request $request, int $id): RedirectResponse
    {
        $op = DB::selectOne('SELECT id, hasil FROM penindakan.operasi WHERE id = ?', [$id]);
        abort_if(! $op, 404);
        if ($op->hasil !== 'TEMUAN') {
            return back()->withErrors(['hasil' => 'Pelanggar hanya dapat ditambahkan pada operasi berhasil TEMUAN. Ubah hasil operasi lebih dulu.']);
        }
        $d = $request->validate($this->aturanPelanggar(), $this->pesanPelanggar());
        if (($lat = $this->pasangKoordinat($d)) instanceof RedirectResponse) {
            return $lat;
        }
        $temuan = $this->barisTemuan($d);
        if ($temuan === null) {
            return back()->withInput()->withErrors(['temuan' => 'Setiap baris jumlah batang harus punya nama merek.']);
        }

        $pid = Audit::jalankan(function () use ($id, $d, $temuan) {
            $pid = DB::table('penindakan.pelanggar')->insertGetId([
                'operasi_id' => $id, 'nama_pemilik' => $this->teks($d['nama_pemilik'] ?? null), 'nama_toko_kendaraan' => $this->teks($d['nama_toko_kendaraan'] ?? null),
                'alamat' => $this->teks($d['alamat'] ?? null), 'kecamatan_teks' => $this->teks($d['kecamatan_teks'] ?? null),
            ], 'id');
            if ($this->punyaKoordinat($d)) {
                $this->setTitikManual($pid, (float) $d['lon'], (float) $d['lat']);
            }
            $this->gantiTemuan($pid, $temuan);
            Audit::catat('INSERT', 'penindakan.pelanggar', $pid, null, ['operasi_id' => $id, 'merek' => count($temuan), 'batang' => array_sum(array_column($temuan, 1)), 'koordinat' => $this->punyaKoordinat($d)]);

            return $pid;
        });
        $this->segarkanPublik();

        return redirect()->route('master.operasi.tampil', $id)->with('info', "Pelanggar #{$pid} ditambahkan.");
    }

    public function pelanggarUbah(Request $request, int $pid): RedirectResponse
    {
        $p = DB::selectOne('SELECT id, operasi_id FROM penindakan.pelanggar WHERE id = ?', [$pid]);
        abort_if(! $p, 404);
        $d = $request->validate($this->aturanPelanggar(), $this->pesanPelanggar());
        $temuan = $this->barisTemuan($d);
        if ($temuan === null) {
            return back()->withInput()->withErrors(['temuan' => 'Setiap baris jumlah batang harus punya nama merek.']);
        }
        $latBaru = trim((string) ($d['lat'] ?? ''));
        $lonBaru = trim((string) ($d['lon'] ?? ''));
        $koordinatBerubah = $latBaru !== trim((string) ($d['lat_asal'] ?? '')) || $lonBaru !== trim((string) ($d['lon_asal'] ?? ''));
        if ($koordinatBerubah && ($latBaru === '') !== ($lonBaru === '')) {
            return back()->withInput()->withErrors(['lat' => 'Isi lintang dan bujur sekaligus, atau kosongkan keduanya.']);
        }

        Audit::jalankan(function () use ($pid, $d, $temuan, $koordinatBerubah, $latBaru, $lonBaru) {
            DB::update('UPDATE penindakan.pelanggar SET nama_pemilik = ?, nama_toko_kendaraan = ?, alamat = ?, kecamatan_teks = ? WHERE id = ?', [
                $this->teks($d['nama_pemilik'] ?? null), $this->teks($d['nama_toko_kendaraan'] ?? null), $this->teks($d['alamat'] ?? null), $this->teks($d['kecamatan_teks'] ?? null), $pid,
            ]);
            if ($koordinatBerubah) {
                if ($latBaru === '' && $lonBaru === '') {   // koordinat dikosongkan: kembali ke tingkat kab/kota
                    DB::update("UPDATE penindakan.pelanggar SET geom = NULL, cara_penentuan = NULL, kode_desa = NULL, ketelitian_lokasi = 'kabkota' WHERE id = ?", [$pid]);
                } else {
                    $this->setTitikManual($pid, (float) $lonBaru, (float) $latBaru);
                }
            }
            $this->gantiTemuan($pid, $temuan);
            Audit::catat('UPDATE', 'penindakan.pelanggar', $pid, null, ['merek' => count($temuan), 'batang' => array_sum(array_column($temuan, 1)), 'koordinat_diubah' => $koordinatBerubah]);
        });
        $this->segarkanPublik();

        return redirect()->route('master.operasi.tampil', $p->operasi_id)->with('info', "Pelanggar #{$pid} diperbarui.");
    }

    public function pelanggarHapus(int $pid): RedirectResponse
    {
        $p = DB::selectOne('SELECT id, operasi_id FROM penindakan.pelanggar WHERE id = ?', [$pid]);
        abort_if(! $p, 404);
        Audit::jalankan(function () use ($pid, $p) {
            DB::delete('DELETE FROM penindakan.pelanggar WHERE id = ?', [$pid]);
            Audit::catat('DELETE', 'penindakan.pelanggar', $pid, ['operasi_id' => $p->operasi_id], null);
        });
        $this->segarkanPublik();

        return redirect()->route('master.operasi.tampil', $p->operasi_id)->with('info', "Pelanggar #{$pid} dihapus.");
    }

    private function punyaKoordinat(array $d): bool
    {
        return isset($d['lat'], $d['lon']) && trim((string) $d['lat']) !== '' && trim((string) $d['lon']) !== '';
    }

    private function pasangKoordinat(array $d)
    {
        $a = trim((string) ($d['lat'] ?? '')) !== '';
        $b = trim((string) ($d['lon'] ?? '')) !== '';

        return $a !== $b ? back()->withInput()->withErrors(['lat' => 'Isi lintang dan bujur sekaligus, atau kosongkan keduanya.']) : true;
    }

    /** Titik manual; trigger basis data mengisi desa/kecamatan dari batas desa bila titik jatuh di dalamnya. */
    private function setTitikManual(int $pid, float $lon, float $lat): void
    {
        DB::update("UPDATE penindakan.pelanggar SET kode_desa = NULL, ketelitian_lokasi = 'titik', cara_penentuan = 'manual', geom = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?", [$lon, $lat, $pid]);
    }

    private function gantiTemuan(int $pid, array $baris): void
    {
        DB::delete('DELETE FROM penindakan.temuan_merek WHERE pelanggar_id = ?', [$pid]);
        foreach ($baris as [$merek, $batang]) {
            DB::insert('INSERT INTO penindakan.temuan_merek (pelanggar_id, merek_asli, merek_id, jumlah_batang) VALUES (?, ?, ref.fn_merek_id(?), ?)', [$pid, $merek, $merek, $batang]);
        }
    }

    // ------------------------------------------------------------------ master kecil (merek, tarif, surveyor, poi)

    public function simpan(Request $request, string $entitas): RedirectResponse
    {
        abort_unless(in_array($entitas, ['merek', 'tarif', 'surveyor', 'poi'], true), 404);

        return match ($entitas) {
            'merek' => $this->simpanMerek($request),
            'tarif' => $this->simpanTarif($request),
            'surveyor' => $this->simpanSurveyor($request),
            'poi' => $this->simpanPoi($request),
        };
    }

    public function hapus(string $entitas, string $id): RedirectResponse
    {
        abort_unless(in_array($entitas, ['merek', 'tarif', 'surveyor', 'poi'], true), 404);
        try {
            $pesan = Audit::jalankan(function () use ($entitas, $id) {
                switch ($entitas) {
                    case 'merek':
                        DB::update('UPDATE penindakan.temuan_merek SET merek_id = NULL WHERE merek_id = ?', [(int) $id]);
                        DB::update('UPDATE survei.rokok_terindikasi SET merek_id = NULL WHERE merek_id = ?', [(int) $id]);
                        $n = DB::delete('DELETE FROM ref.merek WHERE id = ?', [(int) $id]);

                        return $n ? 'Merek dihapus. Temuan lama yang memakainya kembali berstatus "belum dinormalkan".' : null;
                    case 'tarif':
                        return DB::delete('DELETE FROM ref.tarif WHERE id = ?', [(int) $id]) ? 'Tarif dihapus.' : null;
                    case 'surveyor':
                        return DB::delete('DELETE FROM survei.surveyor WHERE kode = ?', [$id]) ? 'Surveyor dihapus.' : null;
                    default:
                        return DB::delete('DELETE FROM ref.poi WHERE id = ?', [(int) $id]) ? 'Titik acuan dihapus.' : null;
                }
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23503') {   // foreign key
                return back()->withErrors(['hapus' => 'Data itu masih dipakai data lain sehingga tidak dapat dihapus.']);
            }
            throw $e;
        }
        abort_if($pesan === null, 404);

        return redirect()->route('master', ['tab' => $entitas])->with('info', $pesan);
    }

    private function simpanMerek(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'id' => ['nullable', 'integer'],
            'nama_baku' => ['required', 'string', 'max:80'],
            'alias' => ['nullable', 'string', 'max:500'],
            'catatan' => ['nullable', 'string', 'max:300'],
        ], ['nama_baku.required' => 'Nama baku merek wajib diisi.']);
        $nama = mb_strtoupper(trim($d['nama_baku']));
        $alias = array_values(array_unique(array_filter(array_map(
            fn ($a) => mb_strtoupper(trim($a)), explode(',', (string) ($d['alias'] ?? ''))
        ), fn ($a) => $a !== '' && $a !== $nama)));
        $literal = '{'.implode(',', array_map(fn ($a) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $a).'"', $alias)).'}';
        $id = $d['id'] ?? null;

        if (DB::selectOne('SELECT 1 AS ada FROM ref.merek WHERE nama_baku = ? AND id <> ?', [$nama, $id ?? 0])) {
            return back()->withInput()->withErrors(['nama_baku' => "Merek {$nama} sudah ada."]);
        }
        $diterapkan = Audit::jalankan(function () use ($id, $nama, $literal, $d) {
            if ($id) {
                DB::update('UPDATE ref.merek SET nama_baku = ?, alias = ?::text[], catatan = ? WHERE id = ?', [$nama, $literal, $this->teks($d['catatan'] ?? null), $id]);
                $nid = $id;
            } else {
                $nid = DB::selectOne('INSERT INTO ref.merek (nama_baku, alias, catatan) VALUES (?, ?::text[], ?) RETURNING id', [$nama, $literal, $this->teks($d['catatan'] ?? null)])->id;
            }

            // samakan ulang temuan: yang memakai merek ini, ditambah yang belum dikenali
            return DB::update('UPDATE penindakan.temuan_merek SET merek_id = ref.fn_merek_id(merek_asli) WHERE merek_id = ? OR merek_id IS NULL', [$nid]);
        });

        return redirect()->route('master', ['tab' => 'merek'])->with('info', "Merek {$nama} disimpan; temuan yang cocok dinormalkan ulang.");
    }

    private function simpanTarif(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'id' => ['nullable', 'integer'],
            'berlaku_mulai' => ['required', 'date'],
            'jenis_produk' => ['required', 'string', 'max:20'],
            'golongan' => ['required', 'string', 'max:30'],
            'tarif_cukai_per_batang' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'hje_minimum_per_batang' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'pajak_rokok_persen' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'ppn_ht_persen' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'dasar_regulasi' => ['nullable', 'string', 'max:300'],
        ], ['required' => 'Kolom :attribute wajib diisi.']);
        $id = $d['id'] ?? null;
        $produk = strtoupper(trim($d['jenis_produk']));
        $gol = trim($d['golongan']);
        if (DB::selectOne('SELECT 1 AS ada FROM ref.tarif WHERE berlaku_mulai = ? AND jenis_produk = ? AND golongan = ? AND id <> ?', [$d['berlaku_mulai'], $produk, $gol, $id ?? 0])) {
            return back()->withInput()->withErrors(['golongan' => 'Tarif untuk tanggal, produk, dan golongan itu sudah ada; ubah baris yang ada.']);
        }
        $nilai = [$d['berlaku_mulai'], $produk, $gol, $d['tarif_cukai_per_batang'], $d['hje_minimum_per_batang'] ?? null, $d['pajak_rokok_persen'] ?? null, $d['ppn_ht_persen'] ?? null, $this->teks($d['dasar_regulasi'] ?? null), Auth::id()];
        Audit::jalankan(function () use ($id, $nilai) {
            if ($id) {
                DB::update('UPDATE ref.tarif SET berlaku_mulai = ?, jenis_produk = ?, golongan = ?, tarif_cukai_per_batang = ?, hje_minimum_per_batang = ?, pajak_rokok_persen = ?, ppn_ht_persen = ?, dasar_regulasi = ?, diubah_oleh = ?, diubah_pada = now() WHERE id = ?', array_merge($nilai, [$id]));
            } else {
                DB::insert('INSERT INTO ref.tarif (berlaku_mulai, jenis_produk, golongan, tarif_cukai_per_batang, hje_minimum_per_batang, pajak_rokok_persen, ppn_ht_persen, dasar_regulasi, diubah_oleh, diubah_pada) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, now())', $nilai);
            }
        });

        return redirect()->route('master', ['tab' => 'tarif'])->with('info', 'Tarif disimpan.');
    }

    private function simpanSurveyor(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'kode_asal' => ['nullable', 'string', 'size:3'],
            'kode' => ['required', 'alpha_num', 'size:3'],
            'nama' => ['nullable', 'string', 'max:120'],
            'pendamping' => ['nullable', 'string', 'max:120'],
        ], ['kode.size' => 'Kode surveyor tepat 3 karakter (mis. S01).', 'kode.alpha_num' => 'Kode surveyor hanya huruf dan angka.']);
        $kode = strtoupper($d['kode']);
        $asal = $d['kode_asal'] ?? null;
        if ($asal && strtoupper($asal) !== $kode) {
            return back()->withInput()->withErrors(['kode' => 'Kode surveyor tidak dapat diubah karena dipakai klaster dan kunjungan. Hapus lalu buat baru bila perlu.']);
        }
        if (! $asal && DB::selectOne('SELECT 1 AS ada FROM survei.surveyor WHERE kode = ?', [$kode])) {
            return back()->withInput()->withErrors(['kode' => "Kode {$kode} sudah ada."]);
        }
        Audit::jalankan(function () use ($asal, $kode, $d) {
            if ($asal) {
                DB::update('UPDATE survei.surveyor SET nama = ?, pendamping = ? WHERE kode = ?', [$this->teks($d['nama'] ?? null), $this->teks($d['pendamping'] ?? null), $kode]);
            } else {
                DB::insert('INSERT INTO survei.surveyor (kode, nama, pendamping) VALUES (?, ?, ?)', [$kode, $this->teks($d['nama'] ?? null), $this->teks($d['pendamping'] ?? null)]);
            }
        });

        return redirect()->route('master', ['tab' => 'surveyor'])->with('info', "Surveyor {$kode} disimpan.");
    }

    private function simpanPoi(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'id' => ['nullable', 'integer'],
            'jenis' => ['required', 'string', 'max:40'],
            'nama' => ['nullable', 'string', 'max:160'],
            'lat' => ['required', 'numeric', 'between:-8.9,-5.7'],
            'lon' => ['required', 'numeric', 'between:107.9,112.1'],
            'sumber' => ['nullable', 'string', 'max:200'],
        ], [
            'required' => 'Kolom :attribute wajib diisi.',
            'lat.between' => 'Lintang di luar wilayah Jawa Tengah (sekitar -8,9 sampai -5,7).',
            'lon.between' => 'Bujur di luar wilayah Jawa Tengah (sekitar 107,9 sampai 112,1).',
        ]);
        $id = $d['id'] ?? null;
        Audit::jalankan(function () use ($id, $d) {
            $v = [trim($d['jenis']), $this->teks($d['nama'] ?? null), (float) $d['lon'], (float) $d['lat'], $this->teks($d['sumber'] ?? null)];
            if ($id) {
                DB::update('UPDATE ref.poi SET jenis = ?, nama = ?, geom = ST_SetSRID(ST_MakePoint(?, ?), 4326), sumber = ? WHERE id = ?', array_merge($v, [$id]));
            } else {
                DB::insert('INSERT INTO ref.poi (jenis, nama, geom, sumber) VALUES (?, ?, ST_SetSRID(ST_MakePoint(?, ?), 4326), ?)', $v);
            }
        });

        return redirect()->route('master', ['tab' => 'poi'])->with('info', 'Titik acuan disimpan.');
    }

    // ------------------------------------------------------------------ pembantu

    private function teks(?string $s): ?string
    {
        $s = trim((string) $s);

        return $s === '' ? null : $s;
    }

    private function ringkasOperasi(array|object $d, array $tb): array
    {
        $d = (array) $d;

        return ['tahun' => (int) $d['tahun'], 'kode_kab' => $d['kode_kab'], 'tim' => $d['tim'] ?? null, 'tanggal_asli' => $d['tanggal_asli'] ?? null,
            'tanggal_mulai' => $tb[0], 'bulan' => $tb[1], 'hasil' => $d['hasil']];
    }

    /** Batch "Input manual": satu batch berstatus disimpan yang menampung semua operasi yang dibuat lewat menu ini. */
    private function batchManual(): int
    {
        $b = DB::selectOne("SELECT id FROM app.batch_impor WHERE jenis = 'penindakan' AND nama_file = ? AND status = 'disimpan' ORDER BY id LIMIT 1", [self::NAMA_BATCH_MANUAL]);
        if ($b) {
            return (int) $b->id;
        }

        return (int) DB::table('app.batch_impor')->insertGetId([
            'jenis' => 'penindakan', 'nama_file' => self::NAMA_BATCH_MANUAL, 'diunggah_oleh' => Auth::id(), 'status' => 'disimpan',
            'disimpan_pada' => now(), 'catatan' => 'Operasi yang dimasukkan atau disunting manual oleh admin.',
        ], 'id');
    }

    /** Buang cache angka publik (5 menit) agar perubahan langsung terlihat di beranda, data, dan peta. */
    private function segarkanPublik(): void
    {
        Cache::forget('publik.ringkas');
        foreach (array_merge(['semua'], $this->daftarTahun()) as $t) {
            Cache::forget('publik.kabkota.'.$t);
            Cache::forget('publik.sebaran.'.$t);
        }
    }
}
