@extends('layouts.app')
@section('judul', $op ? 'Operasi #'.$op->id : 'Tambah operasi')
@section('isi')
@use('App\Support\Format')
@php
  // nilai isian: pakai input lama hanya bila formulir yang gagal adalah formulir ini
  $nilai = fn (string $kunci, string $kolom, $asal = '') => old('_form') === $kunci ? old($kolom, $asal) : $asal;
  $barisMerek = function (string $kunci, array $temuan) {
      if (old('_form') === $kunci && is_array(old('temuan'))) {
          return array_values(array_map(fn ($t) => (object) ['merek_asli' => $t['merek'] ?? '', 'jumlah_batang' => $t['batang'] ?? ''], old('temuan')));
      }
      return $temuan ?: [(object) ['merek_asli' => '', 'jumlah_batang' => '']];
  };
@endphp
<div class="kepala">
  <div>
    <h1>{{ $op ? 'Operasi #'.$op->id : 'Tambah operasi' }}</h1>
    <p>{{ $op ? $op->kab.' · '.$op->tahun.' · sumber: '.($op->nama_file ?: '–') : 'Satu baris operasi pada sheet DATA. Pelanggar dan rincian merek ditambahkan setelah operasi disimpan.' }}</p>
  </div>
  <a class="btn abu" href="{{ route('master', ['tab' => 'operasi']) }}">← Kembali ke daftar</a>
</div>

<div class="card">
  <h2>Data operasi</h2>
  <form method="post" action="{{ $op ? route('master.operasi.ubah', $op->id) : route('master.operasi.simpan') }}">
    @csrf
    <input type="hidden" name="_form" value="operasi">
    <div class="baris">
      <div><label for="tahun">Tahun</label><input id="tahun" type="number" name="tahun" min="2000" max="2100" value="{{ $nilai('operasi', 'tahun', $op->tahun ?? $tahunIni) }}" required><div class="help">Tahun baru (mis. {{ $tahunIni + 1 }}) otomatis muncul di semua filter dan peta.</div></div>
      <div><label for="kode_kab">Kab/Kota</label><select id="kode_kab" name="kode_kab" required><option value="">— pilih —</option>@foreach($kabkota as $k)<option value="{{ $k->kode }}" @selected($nilai('operasi', 'kode_kab', $op->kode_kab ?? '') === $k->kode)>{{ $k->nama }}</option>@endforeach</select></div>
      <div><label for="hasil">Hasil</label><select id="hasil" name="hasil" required>@foreach($hasil as $h)<option value="{{ $h }}" @selected($nilai('operasi', 'hasil', $op->hasil ?? 'TEMUAN') === $h)>{{ $h }}</option>@endforeach</select></div>
      <div><label for="tim">Tim</label><input id="tim" type="text" name="tim" maxlength="200" value="{{ $nilai('operasi', 'tim', $op->tim ?? '') }}"></div>
    </div>
    <div class="baris">
      <div><label for="tanggal_asli">Tanggal (teks asli)</label><input id="tanggal_asli" type="text" name="tanggal_asli" maxlength="100" placeholder="1 - 2 APRIL 2026" value="{{ $nilai('operasi', 'tanggal_asli', $op->tanggal_asli ?? '') }}"></div>
      <div><label for="tanggal_mulai">Tanggal mulai</label><input id="tanggal_mulai" type="date" name="tanggal_mulai" value="{{ $nilai('operasi', 'tanggal_mulai', $op->tanggal_mulai ?? '') }}"><div class="help">Dipakai untuk rekap bulanan.</div></div>
      <div><label for="bulan">Bulan (bila tanggal kosong)</label><input id="bulan" type="number" name="bulan" min="1" max="12" value="{{ $nilai('operasi', 'bulan', $op->bulan ?? '') }}"></div>
    </div>
    <div class="aksi">
      <button class="btn" type="submit">{{ $op ? 'Simpan perubahan' : 'Simpan operasi' }}</button>
    </div>
  </form>
  @if($op)
  <div class="aksi" style="margin-top:14px;padding-top:12px;border-top:1px solid var(--line)">
    <form method="post" action="{{ route('master.operasi.aktif', $op->id) }}">@csrf
      <button class="btn abu" type="submit">{{ $op->aktif ? 'Nonaktifkan operasi' : 'Aktifkan kembali' }}</button></form>
    <form method="post" action="{{ route('master.operasi.hapus', $op->id) }}" onsubmit="return confirm('Hapus permanen operasi #{{ $op->id }} beserta semua pelanggar dan rincian mereknya? Tindakan ini tidak dapat dibatalkan.')">@csrf
      <button class="btn bahaya" type="submit">Hapus permanen</button></form>
    <span class="help">Nonaktifkan bila hanya ingin menyembunyikan dari rekap dan peta tanpa kehilangan data. @if(! $op->aktif)<span class="badge bad">sedang nonaktif</span>@endif</span>
  </div>
  @endif
</div>

@if($op)
<datalist id="daftarMerek">@foreach($daftarMerek as $m)<option value="{{ $m->nama_baku }}">@endforeach</datalist>
<template id="tplBaris">
  <div class="baris-merek" style="display:grid;grid-template-columns:1fr 150px auto;gap:8px;margin-bottom:6px">
    <input type="text" list="daftarMerek" name="temuan[__I__][merek]" placeholder="Merek (mis. HUMMER)" maxlength="120">
    <input type="number" min="0" name="temuan[__I__][batang]" placeholder="Batang">
    <button type="button" class="btn kecil abu" data-hapus-baris aria-label="Hapus baris merek">✕</button>
  </div>
</template>

@if($op->hasil !== 'TEMUAN')
  <div class="notif info">Operasi berhasil <b>{{ $op->hasil }}</b> tidak memiliki pelanggar. Ubah hasil menjadi TEMUAN bila ingin menambahkan pelanggar.</div>
@else
  <h2 style="margin:18px 0 8px">Pelanggar ({{ count($pelanggar) }})</h2>
  <p class="muted" style="margin-top:0">Nama, toko, dan alamat adalah data pribadi: hanya tampil untuk admin dan tidak pernah dipublikasikan. Perubahan dicatat di log audit tanpa data pribadi.</p>
  @foreach($pelanggar as $p)
    @php $kunci = 'p'.$p->id; @endphp
    <details class="card" @if(old('_form') === $kunci) open @endif>
      <summary style="cursor:pointer;font-weight:600">#{{ $p->id }} · {{ $p->nama_pemilik ?: '(tanpa nama)' }}@if($p->nama_toko_kendaraan) — {{ $p->nama_toko_kendaraan }}@endif · {{ Format::angka($p->batang) }} batang
        @if($p->lat !== null)<span class="badge info">titik {{ $p->cara_penentuan }}</span>@endif</summary>
      <form method="post" action="{{ route('master.pelanggar.ubah', $p->id) }}" style="margin-top:12px">
        @csrf
        <input type="hidden" name="_form" value="{{ $kunci }}">
        <div class="baris">
          <div><label>Nama pemilik</label><input type="text" name="nama_pemilik" maxlength="200" value="{{ $nilai($kunci, 'nama_pemilik', $p->nama_pemilik) }}"></div>
          <div><label>Nama toko / kendaraan</label><input type="text" name="nama_toko_kendaraan" maxlength="200" value="{{ $nilai($kunci, 'nama_toko_kendaraan', $p->nama_toko_kendaraan) }}"></div>
          <div><label>Kecamatan (teks)</label><input type="text" name="kecamatan_teks" maxlength="100" value="{{ $nilai($kunci, 'kecamatan_teks', $p->kecamatan_teks) }}"></div>
        </div>
        <div style="margin-bottom:12px"><label>Alamat</label><input type="text" name="alamat" maxlength="500" value="{{ $nilai($kunci, 'alamat', $p->alamat) }}"></div>
        @php $latAsal = $p->lat !== null ? rtrim(rtrim(number_format($p->lat, 6, '.', ''), '0'), '.') : ''; $lonAsal = $p->lon !== null ? rtrim(rtrim(number_format($p->lon, 6, '.', ''), '0'), '.') : ''; @endphp
        <input type="hidden" name="lat_asal" value="{{ $latAsal }}"><input type="hidden" name="lon_asal" value="{{ $lonAsal }}">
        <div class="baris" style="grid-template-columns:1fr 1fr 2fr">
          <div><label>Lintang</label><input type="number" step="any" name="lat" value="{{ $nilai($kunci, 'lat', $latAsal) }}" placeholder="-7.15"></div>
          <div><label>Bujur</label><input type="number" step="any" name="lon" value="{{ $nilai($kunci, 'lon', $lonAsal) }}" placeholder="110.20"></div>
          <div class="help" style="align-self:end">Kosongkan keduanya bila lokasi hanya diketahui sampai kab/kota. Mengubah koordinat menandainya sebagai titik manual; ketelitian saat ini: <b>{{ $p->ketelitian_lokasi }}</b>.</div>
        </div>
        <label>Rincian merek yang disita</label>
        <div class="daftar-merek">
          @foreach($barisMerek($kunci, $p->temuan) as $i => $t)
            <div class="baris-merek" style="display:grid;grid-template-columns:1fr 150px auto;gap:8px;margin-bottom:6px">
              <input type="text" list="daftarMerek" name="temuan[{{ $i }}][merek]" maxlength="120" value="{{ $t->merek_asli }}" placeholder="Merek">
              <input type="number" min="0" name="temuan[{{ $i }}][batang]" value="{{ $t->jumlah_batang }}" placeholder="Batang">
              <button type="button" class="btn kecil abu" data-hapus-baris aria-label="Hapus baris merek">✕</button>
            </div>
          @endforeach
        </div>
        <div class="aksi" style="margin-top:6px">
          <button type="button" class="btn kecil abu" data-tambah-baris>+ Tambah merek</button>
          <button class="btn" type="submit">Simpan pelanggar</button>
        </div>
      </form>
      <form method="post" action="{{ route('master.pelanggar.hapus', $p->id) }}" style="margin-top:10px" onsubmit="return confirm('Hapus pelanggar #{{ $p->id }} beserta rincian mereknya?')">@csrf
        <button class="btn kecil bahaya" type="submit">Hapus pelanggar ini</button></form>
    </details>
  @endforeach

  <details class="card" @if(old('_form') === 'baru' || empty($pelanggar)) open @endif>
    <summary style="cursor:pointer;font-weight:600">+ Tambah pelanggar</summary>
    <form method="post" action="{{ route('master.pelanggar.tambah', $op->id) }}" style="margin-top:12px">
      @csrf
      <input type="hidden" name="_form" value="baru">
      <div class="baris">
        <div><label>Nama pemilik</label><input type="text" name="nama_pemilik" maxlength="200" value="{{ $nilai('baru', 'nama_pemilik') }}"></div>
        <div><label>Nama toko / kendaraan</label><input type="text" name="nama_toko_kendaraan" maxlength="200" value="{{ $nilai('baru', 'nama_toko_kendaraan') }}"></div>
        <div><label>Kecamatan (teks)</label><input type="text" name="kecamatan_teks" maxlength="100" value="{{ $nilai('baru', 'kecamatan_teks') }}"></div>
      </div>
      <div style="margin-bottom:12px"><label>Alamat</label><input type="text" name="alamat" maxlength="500" value="{{ $nilai('baru', 'alamat') }}"></div>
      <div class="baris" style="grid-template-columns:1fr 1fr 2fr">
        <div><label>Lintang</label><input type="number" step="any" name="lat" value="{{ $nilai('baru', 'lat') }}" placeholder="-7.15"></div>
        <div><label>Bujur</label><input type="number" step="any" name="lon" value="{{ $nilai('baru', 'lon') }}" placeholder="110.20"></div>
        <div class="help" style="align-self:end">Opsional. Bila kosong, lokasi dianggap setingkat kab/kota.</div>
      </div>
      <label>Rincian merek yang disita</label>
      <div class="daftar-merek">
        @foreach($barisMerek('baru', []) as $i => $t)
          <div class="baris-merek" style="display:grid;grid-template-columns:1fr 150px auto;gap:8px;margin-bottom:6px">
            <input type="text" list="daftarMerek" name="temuan[{{ $i }}][merek]" maxlength="120" value="{{ $t->merek_asli }}" placeholder="Merek (mis. HUMMER)">
            <input type="number" min="0" name="temuan[{{ $i }}][batang]" value="{{ $t->jumlah_batang }}" placeholder="Batang">
            <button type="button" class="btn kecil abu" data-hapus-baris aria-label="Hapus baris merek">✕</button>
          </div>
        @endforeach
      </div>
      <div class="aksi" style="margin-top:6px">
        <button type="button" class="btn kecil abu" data-tambah-baris>+ Tambah merek</button>
        <button class="btn" type="submit">Tambah pelanggar</button>
      </div>
    </form>
  </details>
@endif
@endif
@endsection

@push('skrip')
<script>
// baris merek dinamis: tambah dan hapus tanpa memuat ulang halaman
(function () {
  const tpl = document.getElementById('tplBaris');
  if (!tpl) return;
  document.addEventListener('click', e => {
    const tambah = e.target.closest('[data-tambah-baris]'), hapus = e.target.closest('[data-hapus-baris]');
    if (tambah) {
      const wadah = tambah.closest('form').querySelector('.daftar-merek');
      const idx = Date.now() + Math.floor(Math.random() * 1000);   // indeks unik, urutan tidak penting
      const el = tpl.content.firstElementChild.cloneNode(true);
      el.querySelectorAll('input').forEach(i => { i.name = i.name.replace('__I__', idx); });
      wadah.appendChild(el);
      el.querySelector('input').focus();
    } else if (hapus) {
      const wadah = hapus.closest('.daftar-merek');
      if (wadah.children.length > 1) hapus.closest('.baris-merek').remove();
      else hapus.closest('.baris-merek').querySelectorAll('input').forEach(i => { i.value = ''; });
    }
  });
})();
</script>
@endpush
