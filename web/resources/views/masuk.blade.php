<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Masuk — {{ config('publik.nama') }}</title>
@include('partials.ikon')
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ substr(md5_file(public_path('css/app.css')), 0, 10) }}">
</head>
<body>
<div class="masuk">
  <form class="kotak" method="post" action="{{ route('masuk') }}">
    @csrf
    <h1>{{ config('publik.nama') }}</h1>
    <div style="font-weight:600;color:var(--navy);margin-top:2px">{{ config('publik.nama_panjang') }}</div>
    <p>Satpol PP Provinsi Jawa Tengah · Bidang Gakkumda. Masuk dengan akun yang diberikan admin.</p>
    @if($errors->any())<div class="notif bad">{{ $errors->first() }}</div>@endif
    <div style="margin-bottom:12px">
      <label for="email">Email</label>
      <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
    </div>
    <div style="margin-bottom:18px">
      <label for="sandi">Kata sandi</label>
      <input id="sandi" type="password" name="sandi" autocomplete="current-password" required>
    </div>
    <button class="btn" type="submit" style="width:100%">Masuk</button>
    <p style="margin:16px 0 0;text-align:center;font-size:13px"><a href="{{ route('beranda') }}">← Kembali ke beranda</a></p>
  </form>
</div>
</body>
</html>
