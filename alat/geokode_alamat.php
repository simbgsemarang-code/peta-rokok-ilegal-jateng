<?php
/**
 * Memperkirakan koordinat alamat operasi lewat Nominatim (OpenStreetMap), satu permintaan per detik.
 *
 * Pemakaian: php geokode_alamat.php <alamat_unik.json> <hasil.json> [batas_jumlah]
 *  - Masukan : JSON {"unik": {kunci: {kab, alamat, kec}}}
 *  - Keluaran: JSON {kunci: {lat, lon, ketelitian, sumber}}; dapat dilanjutkan bila terputus.
 *  - Yang dikirim ke layanan hanya teks alamat/kecamatan/kabupaten, tanpa nama pemilik atau toko.
 *  - Hasil diterima hanya bila berada di dalam (atau <= ±3 km dari) batas kab/kota yang benar
 *    (polygon dibaca dari basis data); selain itu dipakai titik kecamatan, lalu titik tengah kab/kota.
 */
if ($argc < 3) { fwrite(STDERR, "Pemakaian: php geokode_alamat.php alamat_unik.json hasil.json [batas]\n"); exit(2); }
[$masukan, $keluaran] = [$argv[1], $argv[2]];
$batas = isset($argv[3]) ? (int) $argv[3] : PHP_INT_MAX;
require 'C:/xampp/htdocs/cukai/aplikasi/web/vendor/autoload.php';
use App\Services\ImporPenindakan;

// ---- koneksi basis data (dari .env aplikasi) ----
$env = [];
foreach (file('C:/xampp/htdocs/cukai/aplikasi/web/.env', FILE_IGNORE_NEW_LINES) as $l) { if (preg_match('/^(DB_\w+)=(.*)$/', $l, $m)) $env[$m[1]] = trim($m[2], "\"'"); }
$pdo = new PDO("pgsql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$env['DB_DATABASE']}", $env['DB_USERNAME'], $env['DB_PASSWORD']);
$poly = []; $pusat = []; $nama = [];
foreach ($pdo->query("SELECT kode, nama, ST_AsGeoJSON(geom, 5) AS g, ST_Y(ST_PointOnSurface(geom)) AS lat, ST_X(ST_PointOnSurface(geom)) AS lon FROM ref.kabkota") as $r) {
    $poly[$r['kode']] = json_decode($r['g'], true)['coordinates'];
    $pusat[$r['kode']] = [(float) $r['lat'], (float) $r['lon']];
    $nama[ImporPenindakan::kunciKab($r['nama'])] = $r['kode'];
}
function dalamCincin(array $c, float $x, float $y): bool { $d = false; for ($i = 0, $j = count($c) - 1; $i < count($c); $j = $i++) { if ((($c[$i][1] > $y) != ($c[$j][1] > $y)) && ($x < ($c[$j][0] - $c[$i][0]) * ($y - $c[$i][1]) / ($c[$j][1] - $c[$i][1]) + $c[$i][0])) $d = !$d; } return $d; }
function dekatKab(array $multi, float $lat, float $lon, float $toleransi = 0.03): bool {
    foreach ($multi as $poli) {
        if (dalamCincin($poli[0], $lon, $lat)) { $dalamLubang = false; for ($k = 1; $k < count($poli); $k++) if (dalamCincin($poli[$k], $lon, $lat)) $dalamLubang = true; if (!$dalamLubang) return true; }
        foreach ($poli[0] as $p) { if (abs($p[0] - $lon) <= $toleransi && abs($p[1] - $lat) <= $toleransi) return true; }
    }
    return false;
}
function bbox(array $multi): array { $a = [999, 999, -999, -999]; foreach ($multi as $poli) foreach ($poli[0] as $p) { $a[0] = min($a[0], $p[0]); $a[1] = min($a[1], $p[1]); $a[2] = max($a[2], $p[0]); $a[3] = max($a[3], $p[1]); } return $a; }

function derau(string $a): string {
    $a = mb_strtoupper($a);
    $a = preg_replace('/\bRT\.?\s*\d+\s*(\/\s*\d+)?/u', ' ', $a);
    $a = preg_replace('/\bRW\.?\s*\d+/u', ' ', $a);
    $a = preg_replace('/\bNO\.?\s*\d+[A-Z]?\b/u', ' ', $a);
    $a = preg_replace(['/\bJL\.?\s/u'], ['JALAN '], $a);
    return trim(preg_replace('/[\s]+/u', ' ', str_replace(['(', ')'], ' ', $a)));
}
function buangJenis(string $s): string {
    return trim(preg_replace('/\s+/u', ' ', preg_replace('/\b(DESA|DS|KELURAHAN|KEL|KECAMATAN|KEC|KABUPATEN|KAB|KOTA|DUKUH|DUSUN|DSN)\b\.?/u', ' ', $s)), " ,.");
}
function namaKab(string $kab): string {   // "KABUPATEN KLATEN" -> "KLATEN"; "KOTA TEGAL" -> "KOTA TEGAL"
    $k = mb_strtoupper($kab); return str_starts_with($k, 'KABUPATEN ') ? substr($k, 10) : $k;
}
/** @return array{0:string,1:string} [desa/dusun terdekat dengan kecamatan, kecamatan] */
function desaKec(string $alamat, string $kecKolom): array {
    $a = derau($alamat);
    $kec = $kecKolom !== '' ? mb_strtoupper($kecKolom) : (preg_match('/\bKEC(?:AMATAN|\.)?\s*([A-Z][A-Z ]+?)(?=,|\.|\s+KAB|\s+KOTA|$)/u', $a, $m) ? trim($m[1]) : '');
    $desa = '';
    if (preg_match('/\b(?:DESA|DS|KELURAHAN|KEL)\.?\s+([A-Z][A-Z ]+?)(?=,|\s+KEC|\s+KAB|\s+KOTA|$)/u', $a, $m)) {
        $desa = trim($m[1]);
    } else {
        $tok = array_map('trim', explode(',', $a));
        foreach ($tok as $i => $tk) { if ($i > 0 && preg_match('/^(KEC|KECAMATAN)\b/u', $tk)) { $desa = buangJenis($tok[$i - 1]); break; } }
    }
    if ($desa === $kec || preg_match('/^(JALAN|PASAR)/u', $desa) || mb_strlen($desa) < 3) $desa = '';
    return [$desa, $kec];
}
function cari(string $q, array $bb): ?array {
    static $terakhir = 0;
    $tunggu = 1.15 - (microtime(true) - $terakhir); if ($tunggu > 0) usleep((int) ($tunggu * 1e6));
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q' => $q, 'format' => 'jsonv2', 'limit' => 1, 'countrycodes' => 'id', 'addressdetails' => 0,
        'viewbox' => "{$bb[0]},{$bb[3]},{$bb[2]},{$bb[1]}", 'bounded' => 1,
    ]);
    for ($coba = 0; $coba < 3; $coba++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_USERAGENT => 'alamat-geocoder-batch/1.0 (riset internal)', CURLOPT_HTTPHEADER => ['Accept-Language: id']]);
        $isi = curl_exec($ch); $kode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $terakhir = microtime(true);
        if ($kode === 200 && $isi !== false) { $j = json_decode($isi, true); return $j[0] ?? []; }
        if ($kode === 429 || $kode >= 500 || $kode === 0) { sleep(10 * ($coba + 1)); continue; }
        break;
    }
    return null;   // gagal teknis (bukan "tidak ketemu")
}
function ketelitian(array $h): string {
    $t = $h['addresstype'] ?? ($h['type'] ?? ''); $kelas = $h['category'] ?? ($h['class'] ?? '');
    if (in_array($kelas, ['building', 'amenity', 'shop', 'office', 'tourism', 'craft', 'leisure'], true) || in_array($t, ['house', 'building'], true)) return 'titik alamat';
    if ($kelas === 'highway' || in_array($t, ['road', 'residential', 'tertiary', 'secondary', 'primary', 'service', 'unclassified', 'trunk'], true)) return 'jalan';
    if (in_array($t, ['village', 'hamlet', 'suburb', 'neighbourhood', 'quarter', 'isolated_dwelling', 'locality', 'farm', 'allotments'], true)) return 'desa/dusun';
    if (in_array($t, ['city_district', 'municipality', 'district', 'subdistrict', 'borough'], true)) return 'kecamatan';
    return 'kab/kota';
}

$data = json_decode(file_get_contents($masukan), true)['unik'];
$hasil = is_file($keluaran) ? json_decode(file_get_contents($keluaran), true) : [];
$proses = 0; $gagal = 0; $mulai = time();
$tahap2 = ($argv[4] ?? '') === 'tahap2';   // ulangi hanya alamat yang masih jatuh ke titik tengah kab/kota
foreach ($data as $kunci => $d) {
    if ($tahap2) { if (($hasil[$kunci]['sumber'] ?? '') !== 'pusat kab/kota' || !empty($hasil[$kunci]['tahap2'])) continue; }
    elseif (isset($hasil[$kunci])) continue;
    if ($proses >= $batas) break;
    $kode = $nama[ImporPenindakan::kunciKab($d['kab'])] ?? null;
    if (!$kode) { $hasil[$kunci] = ['lat' => null, 'lon' => null, 'ketelitian' => 'tidak diketahui', 'sumber' => 'kab tak dikenali']; continue; }
    $bb = bbox($poly[$kode]); $dipakai = null;

    [$desa, $kec] = desaKec($d['alamat'], $d['kec']);
    $inti = namaKab($d['kab']);
    if ($tahap2) {
        // Pola "dusun, desa, kecamatan, kab": token terakhir sebelum kab = kecamatan, sebelumnya = desa
        $tanpaKota = preg_replace('/^KOTA /u', '', $inti);
        $tok = [];
        foreach (explode(',', derau($d['alamat'])) as $t) {
            $t = trim(str_replace([$inti, $tanpaKota], '', buangJenis($t)), " ,./-");
            if (mb_strlen($t) >= 3 && !preg_match('/^(JALAN|SAMPING|LINGKAR|DEPAN|BELAKANG|PASAR|SPBU)\b/u', $t)) $tok[] = $t;
        }
        $nt = count($tok);
        $kc = $nt >= 2 ? $tok[$nt - 1] : ''; $ds = $nt >= 2 ? $tok[$nt - 2] : ($nt === 1 ? $tok[0] : '');
        $qs = [];
        // label menurut tingkat pertanyaan, bukan jenis objek OSM yang cocok (nama jalan sama dengan nama kecamatan, dsb.)
        if ($ds !== '' && $kc !== '') $qs["$ds, $kc, $inti, JAWA TENGAH"] = 'desa/dusun';
        if ($kc !== '') $qs["$kc, $inti, JAWA TENGAH"] = 'kecamatan';
        if ($ds !== '') $qs["$ds, $inti, JAWA TENGAH"] = 'desa/dusun';
        foreach ($qs as $q => $lbl) {
            $h = cari($q, $bb);
            if ($h === null) { $gagal++; if ($gagal >= 5) break 2; continue; }
            if ($h && dekatKab($poly[$kode], (float) $h['lat'], (float) $h['lon'])) {
                $dipakai = [(float) $h['lat'], (float) $h['lon'], $lbl, 'tahap2']; break;
            }
        }
        if ($dipakai) $hasil[$kunci] = ['lat' => round($dipakai[0], 6), 'lon' => round($dipakai[1], 6), 'ketelitian' => $dipakai[2], 'sumber' => 'tahap2'];
        else $hasil[$kunci]['tahap2'] = true;
        $proses++; $gagal = 0;
        if ($proses % 15 === 0) { file_put_contents($keluaran, json_encode($hasil, JSON_UNESCAPED_UNICODE)); echo date('H:i:s') . " tahap2: $proses diproses\n"; }
        continue;
    }
    $coba = function (string $q, string $label) use (&$dipakai, $poly, $kode, $bb, &$gagal) {
        $h = cari($q, $bb);
        if ($h === null) { $gagal++; return false; }
        if ($h && dekatKab($poly[$kode], (float) $h['lat'], (float) $h['lon'])) {
            $lbl = $label === 'tipe' ? ketelitian($h) : $label;
            $dipakai = [(float) $h['lat'], (float) $h['lon'], $lbl, $label === 'tipe' ? 'alamat' : $label];
        }
        return true;
    };
    if ($desa !== '' && $kec !== '') $coba("$desa, $kec, $inti, JAWA TENGAH", 'desa/dusun');
    if (!$dipakai && $gagal < 5 && preg_match('/\b(JALAN|PASAR|SPBU|TERMINAL|PELABUHAN|STASIUN|PABRIK|GUDANG|RUKO|MALL|TOL)\b/u', derau($d['alamat']))) {
        $coba(buangJenis(derau($d['alamat'])) . ", $inti, JAWA TENGAH", 'tipe');
    }
    if (!$dipakai && $gagal < 5 && $kec !== '') $coba("$kec, $inti, JAWA TENGAH", 'kecamatan');
    if ($gagal >= 5) { fwrite(STDERR, "Terlalu banyak kegagalan teknis; berhenti.\n"); break; }
    if (!$dipakai) { $dipakai = [$pusat[$kode][0], $pusat[$kode][1], 'kab/kota', 'pusat kab/kota']; }
    $hasil[$kunci] = ['lat' => round($dipakai[0], 6), 'lon' => round($dipakai[1], 6), 'ketelitian' => $dipakai[2], 'sumber' => $dipakai[3]];
    $proses++; $gagal = 0;
    if ($proses % 15 === 0) { file_put_contents($keluaran, json_encode($hasil, JSON_UNESCAPED_UNICODE)); echo date('H:i:s') . " $proses diproses, total " . count($hasil) . '/' . count($data) . "\n"; }
}
file_put_contents($keluaran, json_encode($hasil, JSON_UNESCAPED_UNICODE));
$rek = []; foreach ($hasil as $r) { $rek[$r['ketelitian']] = ($rek[$r['ketelitian']] ?? 0) + 1; }
echo 'selesai: ' . count($hasil) . '/' . count($data) . ' alamat unik, ' . (time() - $mulai) . " detik\n" . json_encode($rek, JSON_UNESCAPED_UNICODE) . "\n";
