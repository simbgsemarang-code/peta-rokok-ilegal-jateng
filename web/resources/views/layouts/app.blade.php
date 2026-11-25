<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('judul', 'Peta') — {{ config('publik.nama') }}</title>
@include('partials.ikon')
<link rel="stylesheet" href="{{ asset('css/app.css') }}">
@stack('kepala')
</head>
<body>
@php $p = auth()->user(); @endphp
<div class="app">
  <aside class="side">
    <div class="brand"><b style="font-size:19px;letter-spacing:.04em">{{ config('publik.nama') }}</b><span>Peta Peredaran Rokok Ilegal<br>Jawa Tengah · Satpol PP</span></div>
    <nav class="nav">
      <a href="{{ route('peta') }}" class="{{ request()->routeIs('peta') ? 'aktif' : '' }}">Peta penindakan</a>
      <a href="{{ route('rekap') }}" class="{{ request()->routeIs('rekap') ? 'aktif' : '' }}">Rekap penindakan</a>
      <div class="grup">Analisis</div>
      <a href="{{ route('kerugian') }}" class="{{ request()->routeIs('kerugian*') ? 'aktif' : '' }}">Estimasi kerugian</a>
      <a href="{{ route('rekomendasi') }}" class="{{ request()->routeIs('rekomendasi*') ? 'aktif' : '' }}">Rekomendasi penindakan</a>
      <div class="grup">Situs</div>
      <a href="{{ route('beranda') }}">Beranda publik</a>
      @if($p->peran === 'admin')
        <div class="grup">Admin</div>
        <a href="{{ route('impor') }}" class="{{ request()->routeIs('impor*') ? 'aktif' : '' }}">Impor data operasi</a>
        <a href="{{ route('pengaturan') }}" class="{{ request()->routeIs('pengaturan*') ? 'aktif' : '' }}">Pengaturan</a>
      @endif
    </nav>
    <div class="user">
      <b>{{ $p->nama }}</b>
      {{ $p->labelPeran() }}@if($p->kode_kab) · {{ $p->kode_kab }}@endif
      <form method="post" action="{{ route('keluar') }}">@csrf<button class="btn kecil abu" type="submit">Keluar</button></form>
    </div>
  </aside>
  <main class="main @yield('kelas')">
    @if(session('info'))<div class="notif info">{{ session('info') }}</div>@endif
    @if($errors->any())<div class="notif bad"><ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
    @yield('isi')
  </main>
</div>
@stack('skrip')
</body>
</html>
