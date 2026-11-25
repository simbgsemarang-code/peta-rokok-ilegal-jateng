<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('judul', config('publik.nama_panjang')) — {{ config('publik.nama') }}</title>
<meta name="description" content="@yield('deskripsi', 'Informasi publik tentang pemetaan dan penindakan peredaran rokok ilegal di 35 kabupaten/kota Jawa Tengah oleh '.config('publik.instansi').'.')">
<meta property="og:title" content="@yield('judul', config('publik.nama_panjang')) — {{ config('publik.nama') }}">
<meta property="og:site_name" content="{{ config('publik.nama') }}">
<meta property="og:type" content="website">
@include('partials.ikon')
<link rel="stylesheet" href="{{ asset('css/publik.css') }}">
@stack('kepala')
</head>
<body>
<a class="lompat" href="#isi">Lompat ke isi</a>
@php
  $namaApp = (string) config('publik.nama');
  $namaAwal = mb_substr($namaApp, 0, 2);
  $namaAksen = mb_substr($namaApp, 2);
@endphp
<header class="nav">
  <div class="nav-in">
    <a class="merek" href="{{ route('beranda') }}" aria-label="{{ $namaApp }}, beranda">
      <span class="logo"><img src="{{ asset('img/logo-jateng.png') }}" alt="Logo Provinsi Jawa Tengah"><img src="{{ asset('img/logo-satpolpp.png') }}" alt="Logo Satpol PP"></span>
      <span class="sekat" aria-hidden="true"></span>
      <span class="nama"><b>{{ $namaAwal }}<span class="aksen">{{ $namaAksen }}</span></b><small>{{ config('publik.nama_panjang') }}</small></span>
    </a>
    <input type="checkbox" id="menu" class="menu-cek" aria-hidden="true">
    <label for="menu" class="hamb" aria-label="Buka menu">☰</label>
    <nav class="menu" aria-label="Menu utama">
      <a href="{{ route('beranda') }}" class="tautan {{ request()->routeIs('beranda') ? 'aktif' : '' }}">Beranda</a>
      <a href="{{ route('tentang') }}" class="tautan {{ request()->routeIs('tentang') ? 'aktif' : '' }}">Tentang</a>
      <a href="{{ route('kenali') }}" class="tautan {{ request()->routeIs('kenali') ? 'aktif' : '' }}">Kenali Rokok Ilegal</a>
      @if(config('publik.tampilkan_data'))<a href="{{ route('peta.publik') }}" class="tautan {{ request()->routeIs('peta.publik') ? 'aktif' : '' }}">Peta</a>@endif
      @if(config('publik.tampilkan_data'))<a href="{{ route('data') }}" class="tautan {{ request()->routeIs('data') ? 'aktif' : '' }}">Data Penindakan</a>@endif
      <a href="{{ route('kontak') }}" class="tautan {{ request()->routeIs('kontak') ? 'aktif' : '' }}">Kontak &amp; Pengaduan</a>
      @auth
        <a href="{{ route('peta') }}" class="masuk"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3M14 4h5a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>Buka Aplikasi</a>
      @else
        <a href="{{ route('login') }}" class="masuk"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3M14 4h5a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>Masuk Petugas</a>
      @endauth
    </nav>
  </div>
</header>

<main id="isi">@yield('isi')</main>

@unless(View::hasSection('tanpa-footer'))
<footer class="kaki">
  <div class="wadah">
    <div class="grid">
      <div>
        <div class="logo"><img src="{{ asset('img/logo-jateng.png') }}" alt=""><img src="{{ asset('img/logo-satpolpp.png') }}" alt=""></div>
        <b style="color:#fff">{{ config('publik.instansi') }}</b><br>
        <span>{{ config('publik.unit') }}</span>
        <p style="margin-top:12px"><b style="color:#fff">{{ config('publik.nama') }}</b>: {{ config('publik.nama_panjang') }}, didukung Dana Bagi Hasil Cukai Hasil Tembakau (DBHCHT) Tahun Anggaran 2026.</p>
      </div>
      <div>
        <h4>Halaman</h4>
        <ul>
          <li><a href="{{ route('tentang') }}">Tentang pemetaan</a></li>
          <li><a href="{{ route('kenali') }}">Kenali rokok ilegal</a></li>
          @if(config('publik.tampilkan_data'))<li><a href="{{ route('data') }}">Data penindakan</a></li>@endif
          <li><a href="{{ route('kontak') }}">Kontak dan pengaduan</a></li>
          <li><a href="{{ route('login') }}">Masuk petugas</a></li>
        </ul>
      </div>
      <div>
        <h4>Catatan</h4>
        <p>Situs ini hanya menampilkan angka gabungan per kabupaten/kota. Nama pemilik, nama toko, alamat, dan titik lokasi tidak ditampilkan kepada publik.</p>
        <p>Informasi bersifat edukatif dan bukan nasihat hukum; rujuk peraturan yang berlaku.</p>
      </div>
    </div>
    <div class="bawah">© {{ date('Y') }} {{ config('publik.instansi') }}. Dikembangkan untuk kegiatan pemetaan peredaran rokok ilegal TA 2026.</div>
  </div>
</footer>
@endunless
@stack('skrip')
</body>
</html>
