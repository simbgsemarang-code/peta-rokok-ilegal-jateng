# Menambah kolom "Estimasi Koordinat" dan "Ketelitian Estimasi" di sheet DATA.
# Pemakaian: powershell -File tulis_koordinat.ps1 -Excel <berkas.xlsx> -Alamat <alamat_unik.json> -Hasil <geo_hasil.json>
param([Parameter(Mandatory)][string]$Excel, [Parameter(Mandatory)][string]$Alamat, [Parameter(Mandatory)][string]$Hasil)
$ErrorActionPreference = 'Stop'
$ci = [Globalization.CultureInfo]::InvariantCulture
$a = Get-Content -LiteralPath $Alamat -Raw -Encoding UTF8 | ConvertFrom-Json
$h = Get-Content -LiteralPath $Hasil -Raw -Encoding UTF8 | ConvertFrom-Json
$tabel = @{}; foreach ($p in $h.PSObject.Properties) { $tabel[$p.Name] = $p.Value }

$xl = New-Object -ComObject Excel.Application
$xl.Visible = $false; $xl.DisplayAlerts = $false
try {
  $wb = $xl.Workbooks.Open($Excel)
  $ws = $wb.Worksheets.Item('DATA')
  $akhir = $ws.Cells($ws.Rows.Count, 1).End(-4162).Row     # xlUp
  if ($ws.Range('Q1').Value2 -or $ws.Range('R1').Value2) { throw 'Kolom Q/R sudah terisi; dibatalkan agar tidak menimpa.' }
  $n = $akhir - 1
  $arr = New-Object 'object[,]' $n, 2
  $kosong = 0
  for ($i = 0; $i -lt $n; $i++) {
    $baris = $i + 2
    $kunci = $a.baris."$baris"
    $r = if ($kunci) { $tabel[$kunci] } else { $null }
    if ($r -and $null -ne $r.lat) {
      $arr[$i, 0] = ([double]$r.lat).ToString('0.000000', $ci) + ', ' + ([double]$r.lon).ToString('0.000000', $ci)
      $arr[$i, 1] = [string]$r.ketelitian
    } else { $kosong++ }
  }
  $ws.Range('Q1').Value2 = 'Estimasi Koordinat (lat, lon)'
  $ws.Range('R1').Value2 = 'Ketelitian Estimasi'
  $ws.Range('Q2:Q' + $akhir).NumberFormat = '@'
  $ws.Range('R2:R' + $akhir).NumberFormat = '@'
  $ws.Range('Q2:R' + $akhir).Value2 = $arr
  # samakan gaya dengan kolom P
  $null = $ws.Range('P1').Copy(); $null = $ws.Range('Q1:R1').PasteSpecial(-4122)
  $null = $ws.Range('P2').Copy(); $null = $ws.Range('Q2:R' + $akhir).PasteSpecial(-4122)
  $ws.Range('Q2:R' + $akhir).NumberFormat = '@'
  $ws.Columns('Q').ColumnWidth = 27; $ws.Columns('R').ColumnWidth = 20
  $ws.Range('Q1').AddComment('Perkiraan dari geocoding alamat (Nominatim/OpenStreetMap), bukan titik GPS. Ketelitian: titik alamat/jalan = paling baik; desa/dusun; kecamatan = titik tengah kecamatan; kab/kota = titik tengah kab/kota (alamat tidak ditemukan). Jangan dipakai sebagai lokasi toko tanpa verifikasi.') | Out-Null
  if ($ws.AutoFilterMode) { $ws.AutoFilterMode = $false; $null = $ws.Range('A1:R1').AutoFilter() }
  $wb.Save()
  "Selesai: $n baris data, $kosong tanpa koordinat. Disimpan."
  $wb.Close($false)
} catch {
  "GALAT pada baris skrip " + $_.InvocationInfo.ScriptLineNumber + ': ' + $_.Exception.Message
  throw
} finally {
  $xl.Quit()
  [void][Runtime.InteropServices.Marshal]::ReleaseComObject($xl)
}
