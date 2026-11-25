# Membuat 02_data_referensi.sql dari berkas proyek:
#  - data-wilayah\wilayah.sql (kode Kepmendagri 2025)
#  - laporan\data\rep_alokasi.csv dan rep_penugasan.csv
#  - data-wilayah\hasil_undian.csv
#  - data-peta\jawa-tengah-simplified-topo.json (batas kab/kota sementara)
$ErrorActionPreference = 'Stop'
$root = 'C:\xampp\htdocs\cukai'
$out = "$root\aplikasi\db\02_data_referensi.sql"
$ci = [Globalization.CultureInfo]::InvariantCulture
function Q([string]$s) { if ($s -eq $null) { return 'NULL' }; return "'" + $s.Replace("'", "''") + "'" }
$sb = New-Object System.Text.StringBuilder
function L([string]$s) { [void]$sb.AppendLine($s) }

# ---------- wilayah ----------
$kab = [ordered]@{}; $kec = New-Object System.Collections.ArrayList; $desa = New-Object System.Collections.ArrayList; $desaByKec = @{}
foreach ($line in [IO.File]::ReadLines("$root\data-wilayah\wilayah.sql")) {
  if ($line -match "^\('(33\.[0-9.]+)','(.*)'\)") {
    $code = $matches[1]; $nm = $matches[2].Replace("\'", "'"); $p = $code.Split('.')
    if ($p.Count -eq 2) { $kab[$code] = $nm }
    elseif ($p.Count -eq 3) { [void]$kec.Add(@($code, $nm)) }
    elseif ($p.Count -eq 4) { [void]$desa.Add(@($code, $nm)); $kk = $p[0] + '.' + $p[1] + '.' + $p[2]; if (-not $desaByKec.ContainsKey($kk)) { $desaByKec[$kk] = @{} }; $desaByKec[$kk][$nm.ToUpper()] = $code }
  }
}
$alok = Import-Csv "$root\laporan\data\rep_alokasi.csv"
$wil = @{}; $kor = @{}
foreach ($a in $alok) { $wil[$a.kab.ToUpper()] = $a.wilayah; $kor[$a.kab.ToUpper()] = ($a.catatan -match 'Koridor') }

L '-- ====================================================================='
L '-- Data referensi awal (dibuat otomatis oleh buat_data_referensi.ps1)'
L '-- Sumber kode wilayah: Kepmendagri No 300.2.2-2138 Tahun 2025 (wilayah.sql)'
L '-- ====================================================================='
L 'BEGIN;'
L ''
L '-- Kabupaten/kota'
foreach ($k in $kab.Keys) {
  $nm = $kab[$k]; $jenis = 'Kabupaten'; if ($nm -like 'Kota *') { $jenis = 'Kota' }
  $w = $wil[$nm.ToUpper()]; $kr = 'false'; if ($kor[$nm.ToUpper()]) { $kr = 'true' }
  L ("INSERT INTO ref.kabkota (kode, kode_bps, nama, jenis, wilayah_eks, koridor) VALUES ({0}, {1}, {2}, {3}, {4}, {5});" -f (Q $k), (Q ($k -replace '\.', '')), (Q $nm), (Q $jenis), (Q $w), $kr)
}
L ''
L '-- Kecamatan'
foreach ($c in $kec) { L ("INSERT INTO ref.kecamatan (kode, kode_kab, nama) VALUES ({0}, {1}, {2});" -f (Q $c[0]), (Q $c[0].Substring(0, 5)), (Q $c[1])) }
L ''
L '-- Desa/kelurahan'
foreach ($d in $desa) { L ("INSERT INTO ref.desa (kode, kode_kec, nama) VALUES ({0}, {1}, {2});" -f (Q $d[0]), (Q $d[0].Substring(0, 8)), (Q $d[1])) }

# ---------- surveyor dan klaster ----------
L ''
L '-- Surveyor'
for ($i = 1; $i -le 12; $i++) { L ("INSERT INTO survei.surveyor (kode) VALUES ('S{0:00}');" -f $i) }
$pen = @{}; foreach ($r in (Import-Csv "$root\laporan\data\rep_penugasan.csv" -Encoding UTF8)) { if ($r.id -and $r.id -ne '-') { $pen[$r.id] = $r } }
$und = Import-Csv "$root\data-wilayah\hasil_undian.csv" -Encoding UTF8
L ''
L '-- Klaster hasil pengundian (seed 2026)'
$miss = 0
foreach ($u in $und) {
  $id = $u.id; $k4 = $id.Split('-')[0]; $kode_kab = $k4.Substring(0, 2) + '.' + $k4.Substring(2, 2); $nomor = [int]$id.Split('-')[1]
  $kd = $u.kodeDesa; $kk = $kd.Substring(0, 8)
  $cadNm = ($u.cadangan -replace '^(Desa|Kel\.) ', '').ToUpper(); $cad = $null
  if ($desaByKec.ContainsKey($kk) -and $desaByKec[$kk].ContainsKey($cadNm)) { $cad = $desaByKec[$kk][$cadNm] } else { $miss++ }
  $p = $pen[$id]; $sv = 'NULL'; $ur = 'NULL'; $h1 = 'NULL'; $h2 = 'NULL'; $ot = 'NULL'; $kt = 'NULL'
  if ($p) { $sv = Q $p.sv; $ur = $p.urut; $h1 = $p.h1; $h2 = $p.h2; $ot = $p.outlet; $kt = $p.kons }
  $jenis = $u.jenis.ToLower()
  L ("INSERT INTO survei.klaster (id_klaster, kode_kab, nomor, jenis, kode_kec, kode_desa, kode_desa_cadangan, surveyor_kode, urutan_kerja, hari_mulai, hari_selesai, outlet_target, konsumen_target) VALUES ({0}, {1}, {2}, {3}, {4}, {5}, {6}, {7}, {8}, {9}, {10}, {11}, {12});" -f (Q $id), (Q $kode_kab), $nomor, (Q $jenis), (Q $kk), (Q $kd), (Q $cad), $sv, $ur, $h1, $h2, $ot, $kt)
}

# ---------- merek dan bobot skor ----------
L ''
L '-- Merek yang sering tercatat (alias dapat ditambah admin)'
foreach ($m in @('MARBOL', 'BONTE', 'ANGKER', 'SMITH', 'EVEREST', 'ESTE', 'MANCHESTER', 'LATO', 'SB', 'SURYAKU', 'ORIS')) { L ("INSERT INTO ref.merek (nama_baku) VALUES ({0});" -f (Q $m)) }
L "INSERT INTO ref.merek (nama_baku, alias) VALUES ('HUMER', ARRAY['HUMMER', 'HUMER MERAH']);"
L ''
L '-- Bobot awal skor prioritas (usulan; dapat diubah admin)'
foreach ($b in @(@('estimasi_kerugian', 30), @('proporsi_berindikasi', 25), @('hotspot', 20), @('tren_penindakan', 15), @('efek_pembinaan', 10))) { L ("INSERT INTO analisis.bobot_skor (komponen, bobot_persen) VALUES ({0}, {1});" -f (Q $b[0]), $b[1]) }

# ---------- batas kab/kota sementara dari TopoJSON ----------
$j = Get-Content "$root\data-peta\jawa-tengah-simplified-topo.json" -Raw -Encoding UTF8 | ConvertFrom-Json
$sx = [double]$j.transform.scale[0]; $sy = [double]$j.transform.scale[1]; $tx = [double]$j.transform.translate[0]; $ty = [double]$j.transform.translate[1]
$arcs = New-Object System.Collections.ArrayList
foreach ($arc in $j.arcs) { $ax = 0; $ay = 0; $pts = New-Object System.Collections.ArrayList; foreach ($pp in $arc) { $ax += [double]$pp[0]; $ay += [double]$pp[1]; [void]$pts.Add(@(($ax * $sx + $tx), ($ay * $sy + $ty))) }; [void]$arcs.Add($pts) }
function RingPts($ring) {
  $res = New-Object System.Collections.ArrayList
  foreach ($idx in $ring) { $i = [int]$idx; $pts = $null; if ($i -ge 0) { $pts = $arcs[$i] } else { $r = @($arcs[-bnot $i]); [array]::Reverse($r); $pts = $r }; $start = 0; if ($res.Count -gt 0) { $start = 1 }; for ($q = $start; $q -lt $pts.Count; $q++) { [void]$res.Add($pts[$q]) } }
  return , $res
}
$geoms = $j.objects.'jawa-tengah'.geometries
$kodeUrut = @($kab.Keys)   # 33.01..33.29 lalu 33.71..33.76, sama dengan urutan TopoJSON
L ''
L '-- Batas kabupaten/kota SEMENTARA (disederhanakan, sumber ghapsara/indonesia-atlas).'
L '-- Ganti dengan batas resmi dari BIG/BPS/Bappeda sebelum dipakai untuk analisis.'
for ($g = 0; $g -lt 35; $g++) {
  $geo = $geoms[$g]; $polys = @(); if ($geo.type -eq 'Polygon') { $polys = @(, $geo.arcs) } else { $polys = $geo.arcs }
  $pw = @()
  foreach ($poly in $polys) {
    $rw = @()
    foreach ($ring in $poly) {
      $rp = RingPts $ring
      $c = @(); foreach ($pt in $rp) { $c += ($pt[0].ToString('0.00000', $ci) + ' ' + $pt[1].ToString('0.00000', $ci)) }
      if ($c[0] -ne $c[$c.Count - 1]) { $c += $c[0] }
      if ($c.Count -ge 4) { $rw += ('(' + ($c -join ', ') + ')') }
    }
    if ($rw.Count -gt 0) { $pw += ('(' + ($rw -join ', ') + ')') }
  }
  $wkt = 'MULTIPOLYGON(' + ($pw -join ', ') + ')'
  L ("UPDATE ref.kabkota SET geom = ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_GeomFromText('{0}', 4326)), 3)) WHERE kode = '{1}';" -f $wkt, $kodeUrut[$g])
}
L ''
L 'COMMIT;'
[IO.File]::WriteAllText($out, $sb.ToString(), (New-Object Text.UTF8Encoding $false))
'kab ' + $kab.Count + ' kec ' + $kec.Count + ' desa ' + $desa.Count + ' klaster ' + $und.Count + ' cadangan tak ketemu ' + $miss
'ukuran file ' + (Get-Item $out).Length
