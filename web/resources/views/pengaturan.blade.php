@extends('layouts.app')
@section('judul', 'Pengaturan')
@section('isi')
@use('App\Support\Format')
<div class="kepala"><div><h1>Pengaturan</h1><p>Hanya admin. Setiap perubahan tercatat di log audit.</p></div></div>
<div class="tabs">
  @foreach(['bobot' => 'Bobot skor', 'pendukung' => 'Data pendukung', 'pengguna' => 'Pengguna', 'audit' => 'Log audit'] as $k => $n)
    <a href="{{ route('pengaturan', ['tab' => $k]) }}" class="{{ $tab === $k ? 'aktif' : '' }}">{{ $n }}</a>
  @endforeach
  <a href="{{ route('master', ['tab' => 'tarif']) }}">Tarif ↗</a>
  <a href="{{ route('master', ['tab' => 'merek']) }}">Merek ↗</a>
</div>

@if($tab === 'bobot')
  <div class="card">
    <h2>Bobot skor prioritas</h2>
    <p class="muted" style="margin-top:0">Jumlah harus 100%. Komponen yang belum tersedia datanya dilewati dan bobotnya dibagi ke komponen lain saat menghitung.</p>
    <form method="post" action="{{ route('pengaturan.bobot') }}">
      @csrf
      <div class="baris">
        @foreach($komponen as $k => $nama)
          <div><label for="b_{{ $k }}">{{ $nama }} (%)</label><input id="b_{{ $k }}" type="number" step="any" min="0" max="100" name="{{ $k }}" value="{{ old($k, $bobot[$k] ?? 0) }}" required></div>
        @endforeach
      </div>
      <button class="btn" type="submit">Simpan bobot</button>
    </form>
  </div>

@elseif($tab === 'pendukung')
  <div class="card">
    <h2>Data pendukung per kab/kota</h2>
    <p class="muted" style="margin-top:0">Jumlah outlet rokok (toko, warung, kios) dan perokok dewasa dari Podes, Dinas Perdagangan, atau Susenas. Dipakai untuk menghitung volume dan kerugian.</p>
    <form method="get" action="{{ route('pengaturan') }}" class="aksi" style="margin-bottom:10px">
      <input type="hidden" name="tab" value="pendukung">
      <label for="th" style="margin:0">Tahun data</label>
      <input id="th" type="number" name="tahun" value="{{ $tahun }}" min="2000" max="2100" style="width:100px">
      <button class="btn kecil abu" type="submit">Tampilkan</button>
    </form>
    <form method="post" action="{{ route('pengaturan.pendukung') }}">
      @csrf
      <input type="hidden" name="tahun" value="{{ $tahun }}">
      <div class="baris" style="grid-template-columns:minmax(260px,420px)"><div><label for="sumber">Sumber data</label><input id="sumber" type="text" name="sumber" maxlength="200" placeholder="mis. Podes 2024" value="{{ old('sumber', collect($nilai)->first()->sumber ?? '') }}"></div></div>
      <div class="tabel"><table>
        <thead><tr><th>Kab/Kota</th><th>Jumlah outlet rokok</th><th>Jumlah perokok dewasa</th></tr></thead>
        <tbody>
          @foreach($kabkota as $k)
          <tr><td>{{ $k->nama }}</td>
            <td><input type="number" min="0" name="outlet[{{ $k->kode }}]" value="{{ old('outlet.'.$k->kode, $nilai[$k->kode]->jumlah_outlet_rokok ?? '') }}" style="max-width:160px"></td>
            <td><input type="number" min="0" name="perokok[{{ $k->kode }}]" value="{{ old('perokok.'.$k->kode, $nilai[$k->kode]->jumlah_perokok_dewasa ?? '') }}" style="max-width:160px"></td></tr>
          @endforeach
        </tbody>
      </table></div>
      <button class="btn" type="submit" style="margin-top:12px">Simpan data tahun {{ $tahun }}</button>
    </form>
  </div>

@elseif($tab === 'pengguna')
  <div class="card tabel">
    <h2>Akun pengguna</h2>
    <table><thead><tr><th>Nama</th><th>Email</th><th>Peran</th><th>Status</th><th>Tindakan</th></tr></thead>
    <tbody>
      @foreach($pengguna as $u)
      <tr>
        <td>{{ $u->nama }}</td><td>{{ $u->email }}</td>
        <td>{{ $u->peran }}@if($u->kab) · {{ $u->kab }}@endif</td>
        <td><span class="badge {{ $u->aktif ? 'ok' : 'bad' }}">{{ $u->aktif ? 'aktif' : 'nonaktif' }}</span></td>
        <td class="aksi">
          @if($u->id != auth()->id())
          <form method="post" action="{{ route('pengaturan.pengguna.ubah', $u->id) }}">@csrf<input type="hidden" name="aksi" value="{{ $u->aktif ? 'nonaktif' : 'aktif' }}"><button class="btn kecil {{ $u->aktif ? 'bahaya' : 'alt' }}" type="submit">{{ $u->aktif ? 'Nonaktifkan' : 'Aktifkan' }}</button></form>
          @endif
          <form method="post" action="{{ route('pengaturan.pengguna.ubah', $u->id) }}" class="aksi">@csrf<input type="hidden" name="aksi" value="sandi"><input type="password" name="sandi" placeholder="Sandi baru (≥10)" autocomplete="new-password" style="width:150px"><button class="btn kecil abu" type="submit">Ganti sandi</button></form>
        </td>
      </tr>
      @endforeach
    </tbody></table>
  </div>
  <div class="card">
    <h2>Tambah pengguna</h2>
    <form method="post" action="{{ route('pengaturan.pengguna') }}">
      @csrf
      <div class="baris">
        <div><label for="u_nama">Nama</label><input id="u_nama" type="text" name="nama" value="{{ old('nama') }}" required></div>
        <div><label for="u_email">Email</label><input id="u_email" type="email" name="email" value="{{ old('email') }}" required></div>
        <div><label for="u_peran">Peran</label><select id="u_peran" name="peran"><option value="penampil">Penampil (hanya melihat)</option><option value="operator">Operator kab/kota</option><option value="admin">Admin</option></select></div>
        <div><label for="u_kab">Kab/kota (khusus operator)</label><select id="u_kab" name="kode_kab"><option value="">—</option>@foreach($kabkota as $k)<option value="{{ $k->kode }}">{{ $k->nama }}</option>@endforeach</select></div>
        <div><label for="u_sandi">Kata sandi awal</label><input id="u_sandi" type="password" name="sandi" autocomplete="new-password" minlength="10" required><div class="help">Minimal 10 karakter.</div></div>
      </div>
      <button class="btn" type="submit">Tambah pengguna</button>
    </form>
  </div>

@else
  <div class="card tabel">
    <h2>Log audit (150 terbaru)</h2>
    <table><thead><tr><th>#</th><th>Waktu</th><th>Pelaku</th><th>Aksi</th><th>Tabel</th><th>Baris</th></tr></thead>
    <tbody>@foreach($log as $l)<tr><td>{{ $l->id }}</td><td>{{ Format::tanggal($l->waktu) }}</td><td>{{ $l->pelaku ?? 'sistem' }}</td><td>{{ $l->aksi }}</td><td>{{ $l->tabel }}</td><td>{{ $l->id_baris }}</td></tr>@endforeach</tbody></table>
    <p class="muted">Kata sandi dan rahasia 2FA tidak pernah disalin ke log.</p>
  </div>
@endif
@endsection
