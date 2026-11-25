@extends('layouts.app')
@section('judul', 'Pratinjau impor #'.$batch->id)
@section('isi')
@use('App\Support\Format')
<div class="kepala">
  <div>
    <h1>Impor #{{ $batch->id }} · {{ $batch->nama_file }}</h1>
    <p>Diunggah {{ Format::tanggal($batch->diunggah_pada) }} oleh {{ $batch->pengunggah ?? '–' }} · status
      <span class="badge {{ ['disimpan' => 'ok', 'pratinjau' => 'warn', 'dibatalkan' => 'bad'][$batch->status] ?? '' }}">{{ $batch->status }}</span></p>
  </div>
  <div class="aksi">
    <a class="btn alt" href="{{ route('impor') }}">Kembali</a>
    @if($batch->status === 'pratinjau')
      <form method="post" action="{{ route('impor.simpan', $batch->id) }}">@csrf<button class="btn" type="submit">Simpan data ini</button></form>
    @endif
    @if($batch->status !== 'dibatalkan')
      <form method="post" action="{{ route('impor.batal', $batch->id) }}" onsubmit="return confirm('Batalkan batch ini?')">@csrf<button class="btn bahaya" type="submit">Batalkan</button></form>
    @endif
  </div>
</div>

@if($dobel > 0)
  <div class="notif warn"><b>{{ Format::angka($dobel) }} operasi tampaknya sudah ada</b> pada batch tersimpan lain (kab/kota, tahun, dan tanggal sama). Menyimpan batch ini dapat menggandakan angka. Periksa dulu, atau batalkan batch lama yang tumpang tindih.</div>
@endif
@if($kecTak->n > 0)
  <div class="notif info">{{ Format::angka($kecTak->n - $kecTak->tanpa_kec) }} dari {{ Format::angka($kecTak->n) }} pelanggar berhasil dicocokkan ke kecamatan; sisanya ({{ Format::angka($kecTak->tanpa_kec) }}) hanya diketahui sampai tingkat kab/kota.</div>
@endif
@if(count($catatan) > 1 || $batch->jumlah_ditolak > 0)
  <div class="notif {{ $batch->jumlah_ditolak > 0 ? 'bad' : 'warn' }}">
    <b>{{ $batch->jumlah_ditolak }} baris ditolak.</b> Catatan pembacaan:
    <ul>@foreach($catatan as $c)<li>{{ $c }}</li>@endforeach</ul>
  </div>
@else
  <p class="muted">{{ $catatan[0] ?? '' }} · {{ Format::angka($batch->jumlah_baris) }} baris dibaca, tidak ada yang ditolak.</p>
@endif

<div class="grid g2">
  <div class="card tabel">
    <h2>Ringkasan per tahun</h2>
    <table>
      <thead><tr><th>Tahun</th><th class="n">Operasi</th><th class="n">Pelanggar</th><th class="n">Batang</th></tr></thead>
      <tbody>
        @forelse($ringkas as $r)
          <tr><td>{{ $r->tahun }}</td><td class="n">{{ Format::angka($r->operasi) }}</td><td class="n">{{ Format::angka($r->pelanggar) }}</td><td class="n">{{ Format::angka($r->batang) }}</td></tr>
        @empty
          <tr><td colspan="4" class="kosong">Tidak ada data.</td></tr>
        @endforelse
      </tbody>
      @if(count($ringkas) > 1)
      <tfoot><tr><td>Total</td><td class="n">{{ Format::angka(array_sum(array_column($ringkas, 'operasi'))) }}</td><td class="n">{{ Format::angka(array_sum(array_column($ringkas, 'pelanggar'))) }}</td><td class="n">{{ Format::angka(array_sum(array_column($ringkas, 'batang'))) }}</td></tr></tfoot>
      @endif
    </table>
  </div>
  <div class="card tabel">
    <h2>10 kab/kota dengan batang terbanyak</h2>
    <table>
      <thead><tr><th>Kab/Kota</th><th class="n">Operasi</th><th class="n">Pelanggar</th><th class="n">Batang</th></tr></thead>
      <tbody>
        @foreach($perKab as $r)
          <tr><td>{{ $r->nama }}</td><td class="n">{{ Format::angka($r->operasi) }}</td><td class="n">{{ Format::angka($r->pelanggar) }}</td><td class="n">{{ Format::angka($r->batang) }}</td></tr>
        @endforeach
      </tbody>
    </table>
  </div>
</div>
@if($batch->status === 'pratinjau')
  <p class="muted">Bandingkan angka di atas dengan baris total pada berkas asli sebelum menyimpan. Data pratinjau belum tampil di peta dan rekap.</p>
@endif
@endsection
