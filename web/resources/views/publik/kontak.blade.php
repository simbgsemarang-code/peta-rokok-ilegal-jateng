@extends('layouts.publik')
@section('judul', 'Kontak & Pengaduan')
@section('deskripsi', 'Cara menghubungi dan melaporkan dugaan peredaran rokok ilegal di Jawa Tengah.')
@section('isi')
@php
  $k = config('publik.kontak');
  $ada = array_filter($k);
  $wa = $k['whatsapp'] ? preg_replace('/\D+/', '', $k['whatsapp']) : null;
@endphp
<section class="judul-hal"><div class="wadah"><h1>Kontak &amp; pengaduan</h1><p>Sampaikan informasi dugaan peredaran rokok ilegal kepada pihak berwenang lewat kanal resmi.</p></div></section>
<div class="isi"><div class="wadah">
  @if(! $ada)
    <div class="kotak emas"><p><b>Kanal layanan dan pengaduan resmi belum dicantumkan di situs ini.</b> {{ config('publik.instansi') }} akan mengumumkan kanalnya di halaman ini. Sementara itu, laporan dapat disampaikan langsung ke kantor {{ config('publik.instansi') }} atau satuan Satpol PP kabupaten/kota setempat, atau ke kantor Bea Cukai terdekat.</p></div>
  @else
  <div class="kontak-grid">
    @if($k['alamat'])<div class="kontak-item"><div class="ikon">⌖</div><div><b>Alamat</b>{{ $k['alamat'] }}</div></div>@endif
    @if($k['telepon'])<div class="kontak-item"><div class="ikon">☎</div><div><b>Telepon</b><a href="tel:{{ preg_replace('/[^\d+]/', '', $k['telepon']) }}">{{ $k['telepon'] }}</a></div></div>@endif
    @if($wa)<div class="kontak-item"><div class="ikon">✆</div><div><b>WhatsApp</b><a href="https://wa.me/{{ $wa }}" rel="noopener noreferrer" target="_blank">{{ $k['whatsapp'] }}</a></div></div>@endif
    @if($k['email'])<div class="kontak-item"><div class="ikon">✉</div><div><b>Email</b><a href="mailto:{{ $k['email'] }}">{{ $k['email'] }}</a></div></div>@endif
    @if($k['situs'])<div class="kontak-item"><div class="ikon">◎</div><div><b>Situs resmi</b><a href="{{ $k['situs'] }}" rel="noopener noreferrer" target="_blank">{{ $k['situs'] }}</a></div></div>@endif
    @if($k['jam_layanan'])<div class="kontak-item"><div class="ikon">◷</div><div><b>Jam layanan</b>{{ $k['jam_layanan'] }}</div></div>@endif
  </div>
  @if($k['tautan_pengaduan'])<p style="margin-top:22px"><a class="tbl" href="{{ $k['tautan_pengaduan'] }}" rel="noopener noreferrer" target="_blank">Buka formulir pengaduan</a></p>@endif
  @endif

  <div class="teks" style="margin-top:34px">
    <h2>Informasi yang membantu petugas</h2>
    <ul>
      <li>Lokasi sedekat mungkin: nama tempat atau toko, desa/kelurahan, kecamatan, kabupaten/kota, dan patokan sekitarnya.</li>
      <li>Apa yang dilihat: jenis dan merek rokok, ciri bungkus dan pita cukai, perkiraan jumlah.</li>
      <li>Waktu kejadian atau kapan biasanya terjadi pengiriman atau penjualan.</li>
      <li>Foto bila dapat diambil dengan aman, tanpa membahayakan diri.</li>
    </ul>
    <div class="kotak">
      <p><b>Demi keselamatan Anda,</b> jangan menghadapi pelaku atau menyita barang sendiri. Serahkan penanganan kepada petugas berwenang. Perlindungan identitas pelapor mengikuti kebijakan instansi penerima laporan.</p>
    </div>
    <p class="muted kecil">Situs ini tidak menerima laporan langsung dan tidak menyimpan data pelapor.</p>
  </div>
</div></div>
@endsection
