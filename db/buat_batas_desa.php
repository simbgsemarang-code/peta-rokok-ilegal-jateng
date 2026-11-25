<?php
/**
 * Membuat SQL pemuat batas desa/kelurahan dari berkas cahyadsn/wilayah_boundaries (MIT).
 *
 * Pemakaian: php buat_batas_desa.php <folder berkas wilayah_boundaries_kel_33.*.sql> <keluaran.sql>
 *
 * Format sumber: ('33.71.01.1001','Nama',lat,lng,'[[[lat,lng],...]]'), jalur dalam urutan lat,lon.
 * Keluaran memuat setiap cincin sebagai poligon di tabel sementara stg.cincin; langkah penggabungan ke
 * ref.desa dan penurunan batas kecamatan/kab-kota ada di 04_batas_turunan.sql.
 */
if ($argc < 3) { fwrite(STDERR, "Pemakaian: php buat_batas_desa.php <folder> <keluaran.sql>\n"); exit(2); }
$folder = rtrim($argv[1], '/\\');
$out = fopen($argv[2], 'wb');
fwrite($out, "-- Dibuat oleh buat_batas_desa.php. Isi: cincin poligon mentah per desa/kelurahan (SRID 4326).\n");
fwrite($out, "CREATE SCHEMA IF NOT EXISTS stg;\nDROP TABLE IF EXISTS stg.cincin;\nCREATE TABLE stg.cincin (kode varchar(13), nama text, idx int, geom geometry(Polygon, 4326));\nBEGIN;\n");
$berkas = glob($folder . '/wilayah_boundaries_kel_33.*.sql');
sort($berkas);
$desa = 0; $cincin = 0; $lewat = 0; $multi = 0; $kedalaman = [];
foreach ($berkas as $f) {
    $isi = file_get_contents($f);
    if (!preg_match_all("/\('(\d{2}\.\d{2}\.\d{2}\.\d{4})','((?:[^']|'')*)',(-?[\d.]+),(-?[\d.]+),'([^']*)'\)/", $isi, $m, PREG_SET_ORDER)) continue;
    $baris = [];
    foreach ($m as $r) {
        $jalur = json_decode($r[5], true);
        if (!is_array($jalur) || !$jalur) { $lewat++; continue; }
        $dalam = is_array($jalur[0][0][0] ?? null) ? 4 : 3; $kedalaman[$dalam] = ($kedalaman[$dalam] ?? 0) + 1;
        $rings = $dalam === 4 ? array_merge(...$jalur) : $jalur;   // 4 = daftar poligon; ratakan menjadi daftar cincin
        $desa++; if (count($rings) > 1) $multi++;
        foreach ($rings as $i => $ring) {
            $pts = [];
            foreach ($ring as $p) { if (isset($p[0], $p[1])) $pts[] = sprintf('%.6F %.6F', $p[1], $p[0]); }   // lon lat
            if (count($pts) < 3) { $lewat++; continue; }
            if ($pts[0] !== end($pts)) $pts[] = $pts[0];
            if (count($pts) < 4) { $lewat++; continue; }
            $baris[] = sprintf("('%s','%s',%d,ST_GeomFromText('POLYGON((%s))',4326))", $r[1], $r[2], $i, implode(',', $pts));
            $cincin++;
        }
    }
    foreach (array_chunk($baris, 200) as $b) fwrite($out, "INSERT INTO stg.cincin (kode, nama, idx, geom) VALUES\n" . implode(",\n", $b) . ";\n");
}
fwrite($out, "COMMIT;\nCREATE INDEX ON stg.cincin (kode);\n");
fclose($out);
echo "berkas: " . count($berkas) . ", desa/kelurahan: $desa, cincin: $cincin, desa bercincin >1: $multi, dilewati: $lewat, kedalaman jalur: " . json_encode($kedalaman) . "\n";
