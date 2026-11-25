@extends('layouts.app')
@section('judul', 'Rekap penindakan')
@section('isi')
@use('App\Support\Format')
<div class="kepala">
  <div>
    <h1>Rekap penindakan</h1>
    <p>Dihitung otomatis dari data operasi yang sudah disimpan{{ $batasKab ? ' (kab/kota Anda: '.$batasKab.')' : '' }}.</p>
  </div>
</div>
<div class="tabs">
  <a href="{{ route('rekap') }}" class="{{ $tab === 'kabkota' ? 'aktif' : '' }}">Per kab/kota</a>
  <a href="{{ route('rekap', ['tab' => 'bulanan']) }}" class="{{ $tab === 'bulanan' ? 'aktif' : '' }}">Per bulan</a>
  <a href="{{ route('rekap', ['tab' => 'merek']) }}" class="{{ $tab === 'merek' ? 'aktif' : '' }}">Per merek (40 teratas)</a>
</div>

@if(empty($baris))
  <div class="card kosong">Belum ada data tersimpan. @if(auth()->user()->peran === 'admin')<a href="{{ route('impor') }}">Impor data operasi</a> lebih dulu.@endif</div>
@else
<div class="card tabel">
  <table>
    @if($tab === 'kabkota')
      <thead>
        <tr><th rowspan="2">Kab/Kota</th><th class="n" colspan="{{ count($tahun) }}">Pelanggar</th><th class="n" colspan="{{ count($tahun) }}">Jumlah batang</th><th class="n" rowspan="2">Operasi</th></tr>
        <tr>@foreach([1,2] as $_)@foreach($tahun as $t)<th class="n">{{ $t }}</th>@endforeach @endforeach</tr>
      </thead>
      <tbody>
        @php $tp = array_fill_keys($tahun, 0); $tb = array_fill_keys($tahun, 0); $to = 0; @endphp
        @foreach($baris as $b)
          @php $ops = 0; @endphp
          <tr>
            <td>{{ $b['nama'] }}</td>
            @foreach($tahun as $t)@php $v = $b['t'][$t] ?? null; $tp[$t] += $v['pelanggar'] ?? 0; $ops += $v['operasi'] ?? 0; @endphp<td class="n">{{ $v ? Format::angka($v['pelanggar']) : '–' }}</td>@endforeach
            @foreach($tahun as $t)@php $v = $b['t'][$t] ?? null; $tb[$t] += $v['batang'] ?? 0; @endphp<td class="n">{{ $v ? Format::angka($v['batang']) : '–' }}</td>@endforeach
            @php $to += $ops; @endphp
            <td class="n">{{ Format::angka($ops) }}</td>
          </tr>
        @endforeach
      </tbody>
      <tfoot><tr><td>Jawa Tengah</td>@foreach($tahun as $t)<td class="n">{{ Format::angka($tp[$t]) }}</td>@endforeach @foreach($tahun as $t)<td class="n">{{ Format::angka($tb[$t]) }}</td>@endforeach<td class="n">{{ Format::angka($to) }}</td></tr></tfoot>
    @elseif($tab === 'bulanan')
      <thead>
        <tr><th rowspan="2">Bulan</th><th class="n" colspan="{{ count($tahun) }}">Pelanggar</th><th class="n" colspan="{{ count($tahun) }}">Jumlah batang</th></tr>
        <tr>@foreach([1,2] as $_)@foreach($tahun as $t)<th class="n">{{ $t }}</th>@endforeach @endforeach</tr>
      </thead>
      <tbody>
        @foreach($baris as $bln => $b)
          <tr><td>{{ $namaBulan[$bln] ?? $bln }}</td>
            @foreach($tahun as $t)<td class="n">{{ isset($b[$t]) ? Format::angka($b[$t]['pelanggar']) : '–' }}</td>@endforeach
            @foreach($tahun as $t)<td class="n">{{ isset($b[$t]) ? Format::angka($b[$t]['batang']) : '–' }}</td>@endforeach
          </tr>
        @endforeach
      </tbody>
    @else
      <thead>
        <tr><th rowspan="2">Merek</th><th class="n" colspan="{{ count($tahun) }}">Jumlah batang</th><th class="n" rowspan="2">Total batang</th><th class="n" rowspan="2">Pelanggar</th></tr>
        <tr>@foreach($tahun as $t)<th class="n">{{ $t }}</th>@endforeach</tr>
      </thead>
      <tbody>
        @foreach($baris as $nama => $b)
          <tr><td>{{ $nama }}</td>
            @foreach($tahun as $t)<td class="n">{{ isset($b['t'][$t]) ? Format::angka($b['t'][$t]['batang']) : '–' }}</td>@endforeach
            <td class="n"><b>{{ Format::angka($b['total']) }}</b></td>
            <td class="n">{{ Format::angka(array_sum(array_column($b['t'], 'pelanggar'))) }}</td>
          </tr>
        @endforeach
      </tbody>
    @endif
  </table>
</div>
@if($tab === 'bulanan')<p class="muted">Bulan diambil dari tanggal mulai operasi; operasi tanpa tanggal tidak ikut dihitung di tabel ini.</p>@endif
@if($tab === 'merek')<p class="muted">Penulisan merek diseragamkan lewat daftar merek dan alias (Data master → Merek). Jumlah pelanggar per merek dapat tumpang tindih karena satu pelanggar bisa membawa beberapa merek.</p>@endif
@endif
@endsection
