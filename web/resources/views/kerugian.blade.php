@extends('layouts.app')
@section('judul', 'Estimasi kerugian')
@section('isi')
@use('App\Support\Format')
@php
  $admin = auth()->user()->peran === 'admin';
  $dbhcht = (float) ($param['dbhcht_persen'] ?? 3);
  $totRugi = 0; $totLo = 0; $totHi = 0; $totDb = 0; $totVol = 0; $nHitung = 0;
  foreach ($rows as $r) {
    if ($r->kerugian_rp !== null) { $nHitung++; $totRugi += $r->kerugian_rp; $totLo += $r->kerugian_rp_bawah; $totHi += $r->kerugian_rp_atas; $totVol += $r->volume_batang_tahun; if ($cukaiPerBatang !== null) $totDb += $r->volume_batang_tahun * $cukaiPerBatang * $dbhcht / 100; }
  }
@endphp
<div class="kepala">
  <div>
    <h1>Estimasi potensi kerugian cukai dan DBHCHT</h1>
    <p>Perkiraan penerimaan yang hilang per tahun akibat rokok ilegal, per kab/kota. Hasilnya <b>perkiraan awal</b>, bukan angka final.</p>
  </div>
  @if(count($versi))
  <form method="get" action="{{ route('kerugian') }}" class="aksi">
    <label for="versi" style="margin:0">Versi analisis</label>
    <select id="versi" name="versi" onchange="this.form.submit()" style="width:auto">
      @foreach($versi as $v)<option value="{{ $v->id }}" @selected($aktif && $aktif->id == $v->id)>#{{ $v->id }} · {{ Format::tanggal($v->dibuat_pada) }} · {{ $v->status }}</option>@endforeach
    </select>
  </form>
  @endif
</div>

@if(! $aktif)
  <div class="notif warn">Belum ada perhitungan. @if($admin)Isi parameter di bawah lalu tekan <b>Hitung</b>.@else Minta admin menjalankan perhitungan.@endif</div>
@else
  @if($jumlahPendukung < 35)
    <div class="notif warn"><b>Data jumlah outlet rokok baru ada untuk {{ $jumlahPendukung }} dari 35 kab/kota.</b> Kab/kota tanpa data itu belum dapat dihitung kerugiannya. @if($admin)Isi di <a href="{{ route('pengaturan', ['tab' => 'pendukung']) }}">Pengaturan → Data pendukung</a>.@endif</div>
  @endif
  @if(! ($info['tarif'] ?? null))
    <div class="notif warn"><b>Tarif cukai belum dipilih</b> pada versi ini, sehingga nilai rupiah belum dapat dihitung. @if($admin)Isi tarif di <a href="{{ route('pengaturan', ['tab' => 'tarif']) }}">Pengaturan → Tarif</a>, lalu hitung ulang.@endif</div>
  @endif

  <div class="grid g4" style="margin-bottom:14px">
    <div class="angka"><b>{{ Format::rupiah($nHitung ? $totRugi : null) }}</b><span>perkiraan kerugian/tahun ({{ $nHitung }} kab/kota)</span></div>
    <div class="angka"><b>{{ $nHitung ? Format::rupiah($totLo).' – '.Format::rupiah($totHi) : '–' }}</b><span>rentang skenario rendah–tinggi</span></div>
    <div class="angka"><b>{{ ($nHitung && $cukaiPerBatang !== null) ? Format::rupiah($totDb) : '–' }}</b><span>perkiraan bagian DBHCHT ({{ Format::angka($dbhcht, 1) }}% dari cukai)</span></div>
    <div class="angka"><b>{{ $nHitung ? Format::angka($totVol / 1e6, 1).' juta' : '–' }}</b><span>batang rokok ilegal/tahun</span></div>
  </div>

  <div class="card tabel">
    <table>
      <thead><tr>
        <th>Kab/Kota</th><th class="n">Outlet rokok</th><th class="n">Proporsi berindikasi</th><th>Dasar</th>
        <th class="n">Volume (batang/th)</th><th class="n">Kerugian/th</th><th class="n">Rentang</th><th class="n">Bagian DBHCHT</th>
      </tr></thead>
      <tbody>
        @foreach($rows as $r)
        <tr>
          <td>{{ $r->nama }}</td>
          <td class="n">{{ Format::angka($r->outlet) }}</td>
          <td class="n">{{ Format::persen($r->proporsi) }}<br><small>{{ Format::persen($r->ci_bawah, 0) }}–{{ Format::persen($r->ci_atas, 0) }}</small></td>
          <td>@if($r->metode === 'langsung')<span class="badge ok">survei</span>@elseif($r->metode)<span class="badge warn">skenario</span>@else–@endif</td>
          <td class="n">{{ Format::angka($r->volume_batang_tahun) }}</td>
          <td class="n"><b>{{ Format::rupiah($r->kerugian_rp) }}</b></td>
          <td class="n"><small>{{ $r->kerugian_rp !== null ? Format::rupiah($r->kerugian_rp_bawah).' – '.Format::rupiah($r->kerugian_rp_atas) : '–' }}</small></td>
          <td class="n">{{ ($r->kerugian_rp !== null && $cukaiPerBatang !== null) ? Format::rupiah($r->volume_batang_tahun * $cukaiPerBatang * $dbhcht / 100) : '–' }}</td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  <div class="card">
    <div class="aksi" style="justify-content:space-between">
      <div>Status versi #{{ $aktif->id }}: <span class="badge {{ $aktif->status === 'final' ? 'ok' : 'warn' }}">{{ $aktif->status }}</span>
        <span class="muted"> · dibuat {{ Format::tanggal($aktif->dibuat_pada) }} oleh {{ $aktif->pembuat ?? '–' }}</span></div>
      @if($admin && $aktif->status !== 'final')
        <form method="post" action="{{ route('kerugian.final', $aktif->id) }}" onsubmit="return confirm('Tetapkan versi #{{ $aktif->id }} sebagai final?')">@csrf<button class="btn alt" type="submit">Tetapkan sebagai final</button></form>
      @endif
    </div>
    <details style="margin-top:10px"><summary>Cara menghitung dan batasannya</summary>
      <ol class="muted">
        <li><b>Proporsi berindikasi</b> = outlet berindikasi ÷ outlet yang menjual rokok pada survei kab/kota itu (minimal {{ $param['min_outlet_survei'] }} outlet, belum berbobot desain; rentang = Wilson 95%). Bila data survei belum cukup, dipakai <b>skenario</b> rendah/tengah/tinggi ({{ $param['skenario_rendah'] }}% / {{ $param['skenario_tengah'] }}% / {{ $param['skenario_tinggi'] }}%) yang ditetapkan admin.</li>
        <li><b>Volume</b> = jumlah outlet rokok × proporsi × {{ Format::angka($param['vol_per_outlet']) }} batang per outlet berindikasi per tahun (asumsi awal; ganti dengan hasil kuesioner pedagang).</li>
        <li><b>Kerugian</b> = volume × (tarif cukai per batang + HJE × (pajak rokok% + PPN%)) sesuai tarif yang dipilih. <b>Bagian DBHCHT</b> = volume × tarif cukai × {{ Format::angka($dbhcht, 1) }}%.</li>
        <li>Angka ini batas bawah kasar: hanya rokok polos/pita tidak sah di outlet yang tercatat. Estimasi berbobot (Hájek) dan estimasi area kecil (Fay-Herriot) menyusul setelah survei selesai.</li>
      </ol>
    </details>
  </div>
@endif

@if($admin)
<div class="card">
  <h2>Hitung ulang</h2>
  <p class="muted" style="margin-top:0">Setiap perhitungan membuat versi analisis baru berstatus draf dan menyusun ulang skor serta rekomendasi penindakan. Versi lama tetap tersimpan.</p>
  <form method="post" action="{{ route('kerugian.hitung') }}">
    @csrf
    <div class="baris">
      <div>
        <label for="tarif_id">Tarif yang dipakai</label>
        <select id="tarif_id" name="tarif_id">
          <option value="">— belum dipilih —</option>
          @foreach($tarif as $t)<option value="{{ $t->id }}" @selected(old('tarif_id', $param['tarif_id']) == $t->id)>{{ $t->jenis_produk }} {{ $t->golongan }} · Rp {{ Format::angka($t->tarif_cukai_per_batang, 2) }}/batang · berlaku {{ $t->berlaku_mulai }}</option>@endforeach
        </select>
        <div class="help">Isi tarif di Pengaturan → Tarif.</div>
      </div>
      <div><label for="vol_per_outlet">Batang per outlet berindikasi per tahun</label><input id="vol_per_outlet" type="number" step="any" min="0" name="vol_per_outlet" value="{{ old('vol_per_outlet', $param['vol_per_outlet']) }}" required><div class="help">Asumsi awal 7.300 (1 bungkus isi 20 per hari).</div></div>
      <div><label for="dbhcht_persen">Bagian DBHCHT (% dari cukai)</label><input id="dbhcht_persen" type="number" step="any" min="0" max="100" name="dbhcht_persen" value="{{ old('dbhcht_persen', $param['dbhcht_persen']) }}" required><div class="help">Periksa ketentuan yang berlaku.</div></div>
    </div>
    <div class="baris">
      <div><label for="skenario_rendah">Skenario rendah (% outlet berindikasi)</label><input id="skenario_rendah" type="number" step="any" min="0" max="100" name="skenario_rendah" value="{{ old('skenario_rendah', $param['skenario_rendah']) }}" required></div>
      <div><label for="skenario_tengah">Skenario tengah (%)</label><input id="skenario_tengah" type="number" step="any" min="0" max="100" name="skenario_tengah" value="{{ old('skenario_tengah', $param['skenario_tengah']) }}" required></div>
      <div><label for="skenario_tinggi">Skenario tinggi (%)</label><input id="skenario_tinggi" type="number" step="any" min="0" max="100" name="skenario_tinggi" value="{{ old('skenario_tinggi', $param['skenario_tinggi']) }}" required></div>
      <div><label for="deskripsi">Catatan versi (opsional)</label><input id="deskripsi" type="text" name="deskripsi" maxlength="200" value="{{ old('deskripsi') }}"></div>
    </div>
    <button class="btn" type="submit">Hitung dan buat versi baru</button>
  </form>
</div>
@endif
@endsection
