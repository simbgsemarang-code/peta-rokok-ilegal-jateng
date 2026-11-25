@extends('layouts.app')
@section('judul', 'Rekomendasi penindakan')
@section('isi')
@use('App\Support\Format')
@php
  $admin = auth()->user()->peran === 'admin';
  $kat = ['tinggi' => 'bad', 'sedang' => 'warn', 'rendah' => 'ok'];
  $sts = ['draf' => 'warn', 'disetujui' => 'ok', 'ditolak' => 'bad'];
  $tersedia = $info['komponen_tersedia'] ?? [];
@endphp
<div class="kepala">
  <div>
    <h1>Rekomendasi kebijakan penindakan</h1>
    <p>Draf per kab/kota, diurutkan menurut skor prioritas. <b>Keputusan akhir tetap pada Satpol PP</b>; admin dapat menyunting dan menyetujui.</p>
  </div>
  <div class="aksi">
    @if(count($versi))
    <form method="get" action="{{ route('rekomendasi') }}" class="aksi">
      <label for="versi" style="margin:0">Versi</label>
      <select id="versi" name="versi" onchange="this.form.submit()" style="width:auto">
        @foreach($versi as $v)<option value="{{ $v->id }}" @selected($pilih == $v->id)>#{{ $v->id }} · {{ Format::tanggal($v->dibuat_pada) }} · {{ $v->status }}</option>@endforeach
      </select>
    </form>
    @if($pilih)<a class="btn alt" href="{{ route('rekomendasi.unduh', ['versi' => $pilih]) }}">Unduh Excel</a>@endif
    @endif
  </div>
</div>

@if(! $pilih || empty($rows))
  <div class="card kosong">Belum ada rekomendasi. @if($admin)Jalankan perhitungan di <a href="{{ route('kerugian') }}">Estimasi kerugian</a>; rekomendasi disusun bersamaan.@else Minta admin menjalankan perhitungan.@endif</div>
@else
  <div class="notif info">
    Skor prioritas memakai komponen yang tersedia:
    <b>{{ $tersedia ? implode(', ', array_map(fn ($k) => str_replace('_', ' ', $k), $tersedia)) : 'belum ada' }}</b>.
    Sebuah komponen dipakai hanya bila datanya ada untuk semua kab/kota, agar skor sebanding; bobot komponen yang belum dipakai (misalnya hotspot, efek pembinaan, atau kerugian bila data outlet belum lengkap) dibagi ke komponen yang dipakai.
    Lokasi, waktu, dan sasaran disusun dari data penindakan {{ $info['tahun_terakhir'] ?? '' ? 'sampai '.$info['tahun_terakhir'] : '' }}.
  </div>
  <div class="card tabel">
    <table>
      <thead><tr><th class="n">#</th><th>Kab/Kota</th><th class="n">Skor</th><th>Rekomendasi</th><th>Status</th>@if($admin)<th></th>@endif</tr></thead>
      <tbody>
        @foreach($rows as $r)
        <tr>
          <td class="n">{{ $r->peringkat }}</td>
          <td><b>{{ $r->nama }}</b><br><span class="badge {{ $kat[$r->kategori] ?? '' }}">{{ $r->kategori ?? '–' }}</span>
            @if($r->kerugian_rp !== null)<br><small>≈ {{ Format::rupiah($r->kerugian_rp) }}/th</small>@endif</td>
          <td class="n">{{ Format::angka($r->skor, 1) }}</td>
          <td style="min-width:380px">
            <div><b>Lokasi:</b> {{ $r->lokasi_prioritas }}</div>
            <div><b>Waktu:</b> {{ $r->waktu_operasi }}</div>
            <div><b>Sasaran:</b> {{ $r->sasaran }}</div>
            <div><b>Pendekatan:</b> {{ $r->pendekatan }}</div>
            @if($admin)
            <details style="margin-top:6px"><summary>Sunting</summary>
              <form method="post" action="{{ route('rekomendasi.sunting', $r->id) }}">
                @csrf
                <label>Lokasi prioritas</label><textarea name="lokasi_prioritas">{{ $r->lokasi_prioritas }}</textarea>
                <label>Waktu operasi</label><textarea name="waktu_operasi">{{ $r->waktu_operasi }}</textarea>
                <label>Sasaran</label><textarea name="sasaran">{{ $r->sasaran }}</textarea>
                <label>Pendekatan</label><textarea name="pendekatan">{{ $r->pendekatan }}</textarea>
                <button class="btn kecil" type="submit" style="margin-top:6px">Simpan (kembali ke draf)</button>
              </form>
            </details>
            @endif
          </td>
          <td><span class="badge {{ $sts[$r->status] ?? '' }}">{{ $r->status }}</span>
            @if($r->status === 'disetujui')<br><small>{{ $r->penyetuju }}<br>{{ Format::tanggal($r->disetujui_pada) }}</small>@endif</td>
          @if($admin)
          <td>
            <div class="aksi" style="flex-direction:column;align-items:stretch">
              @if($r->status !== 'disetujui')<form method="post" action="{{ route('rekomendasi.status', $r->id) }}">@csrf<input type="hidden" name="aksi" value="setujui"><button class="btn kecil" type="submit" style="width:100%">Setujui</button></form>@endif
              @if($r->status !== 'ditolak')<form method="post" action="{{ route('rekomendasi.status', $r->id) }}">@csrf<input type="hidden" name="aksi" value="tolak"><button class="btn kecil bahaya" type="submit" style="width:100%">Tolak</button></form>@endif
              @if($r->status !== 'draf')<form method="post" action="{{ route('rekomendasi.status', $r->id) }}">@csrf<input type="hidden" name="aksi" value="draf"><button class="btn kecil abu" type="submit" style="width:100%">Jadikan draf</button></form>@endif
            </div>
          </td>
          @endif
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>
@endif
@endsection
