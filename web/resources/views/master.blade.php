@extends('layouts.app')
@section('judul', 'Data master')
@section('isi')
@use('App\Support\Format')
<div class="kepala"><div><h1>Data master</h1><p>Hanya admin. Setiap perubahan tercatat di log audit. Kab/kota, kecamatan, dan desa/kelurahan bersifat tetap dan tidak dapat diubah di sini.</p></div></div>
<div class="tabs">
  @foreach($daftarTab as $k => $n)
    <a href="{{ route('master', ['tab' => $k]) }}" class="{{ $tab === $k ? 'aktif' : '' }}">{{ $n }}</a>
  @endforeach
</div>

@if($tab === 'operasi')
  @php
    $qs = fn (array $tambah = []) => array_filter(array_merge(['tab' => 'operasi', 'tahun' => $tahunFilter, 'kab' => $kabFilter, 'hasil' => $hasilFilter, 'q' => $q], $tambah), fn ($v) => $v !== null && $v !== '');
    $jumlahHal = max(1, (int) ceil($total / $perHalaman));
  @endphp
  <div class="card">
    <div class="aksi" style="justify-content:space-between;margin-bottom:10px">
      <div><h2 style="margin:0">Data gabungan operasi cukai</h2><div class="help">Setiap baris adalah satu operasi (sheet DATA). Tahun mengikuti data yang ada, sehingga tahun baru muncul otomatis begitu operasinya dimasukkan.</div></div>
      <a class="btn" href="{{ route('master.operasi.baru') }}">+ Tambah operasi</a>
    </div>
    <label id="lblTh">Tahun</label>
    <div class="seg" role="group" aria-labelledby="lblTh" style="margin-bottom:10px">
      <a href="{{ route('master', $qs(['tahun' => null])) }}" aria-current="{{ $tahunFilter ? 'false' : 'true' }}">Semua</a>
      @foreach($daftarTahun as $t)<a href="{{ route('master', $qs(['tahun' => $t])) }}" aria-current="{{ $tahunFilter === $t ? 'true' : 'false' }}">{{ $t }}</a>@endforeach
    </div>
    <form method="get" action="{{ route('master') }}" class="baris" style="grid-template-columns:minmax(150px,1fr) minmax(150px,1fr) minmax(200px,2fr) auto;align-items:end">
      <input type="hidden" name="tab" value="operasi">
      @if($tahunFilter)<input type="hidden" name="tahun" value="{{ $tahunFilter }}">@endif
      <div><label for="fk">Kab/Kota</label><select id="fk" name="kab"><option value="">Semua</option>@foreach($kabkota as $k)<option value="{{ $k->kode }}" @selected($kabFilter === $k->kode)>{{ $k->nama }}</option>@endforeach</select></div>
      <div><label for="fh">Hasil</label><select id="fh" name="hasil"><option value="">Semua</option>@foreach($hasil as $h)<option value="{{ $h }}" @selected($hasilFilter === $h)>{{ $h }}</option>@endforeach</select></div>
      <div><label for="fq">Cari</label><input id="fq" type="search" name="q" value="{{ $q }}" placeholder="tim, tanggal, nama, toko, alamat"></div>
      <div class="aksi"><button class="btn" type="submit">Terapkan</button><a class="btn abu" href="{{ route('master', ['tab' => 'operasi']) }}">Atur ulang</a></div>
    </form>
  </div>
  <div class="card tabel">
    @if(empty($baris))<div class="kosong">Tidak ada operasi yang cocok dengan filter.</div>@else
    <table>
      <thead><tr><th>#</th><th>Tahun</th><th>Kab/Kota</th><th>Tanggal</th><th>Tim</th><th>Hasil</th><th class="n">Pelanggar</th><th class="n">Batang</th><th>Sumber</th><th>Tindakan</th></tr></thead>
      <tbody>
      @foreach($baris as $o)
        <tr>
          <td>{{ $o->id }}</td><td>{{ $o->tahun }}</td><td>{{ $o->kab }}</td>
          <td>{{ $o->tanggal_asli ?: ($o->tanggal_mulai ? Format::tanggal($o->tanggal_mulai) : '–') }}</td>
          <td>{{ $o->tim ?: '–' }}</td>
          <td><span class="badge {{ $o->hasil === 'TEMUAN' ? 'warn' : 'info' }}">{{ $o->hasil }}</span>@if(! $o->aktif) <span class="badge bad">nonaktif</span>@endif</td>
          <td class="n">{{ Format::angka($o->pelanggar) }}</td><td class="n">{{ Format::angka($o->batang) }}</td>
          <td><small class="muted">{{ $o->nama_file ?: '–' }}</small></td>
          <td class="aksi"><a class="btn kecil" href="{{ route('master.operasi.tampil', $o->id) }}">Ubah</a></td>
        </tr>
      @endforeach
      </tbody>
    </table>
    <div class="aksi" style="margin-top:12px;justify-content:space-between">
      <span class="muted">{{ Format::angka($total) }} operasi · halaman {{ $halaman }} dari {{ $jumlahHal }}</span>
      <span class="aksi">
        @if($halaman > 1)<a class="btn kecil abu" href="{{ route('master', $qs(['hal' => $halaman - 1])) }}">← Sebelumnya</a>@endif
        @if($halaman < $jumlahHal)<a class="btn kecil abu" href="{{ route('master', $qs(['hal' => $halaman + 1])) }}">Berikutnya →</a>@endif
      </span>
    </div>
    @endif
  </div>

@elseif($tab === 'merek')
  <div class="grid g2">
    <div class="card tabel">
      <h2>Daftar merek baku</h2>
      @if(empty($merek))<div class="kosong">Belum ada merek.</div>@else
      <table><thead><tr><th>Merek baku</th><th>Alias</th><th class="n">Dipakai</th><th>Tindakan</th></tr></thead>
      <tbody>@foreach($merek as $m)
        <tr><td><b>{{ $m->nama_baku }}</b>@if($m->catatan)<br><small class="muted">{{ $m->catatan }}</small>@endif</td><td>{{ $m->alias ?: '–' }}</td><td class="n">{{ Format::angka($m->pemakaian) }}</td>
          <td class="aksi"><a class="btn kecil" href="{{ route('master', ['tab' => 'merek', 'ubah' => $m->id]) }}">Ubah</a>
            <form method="post" action="{{ route('master.hapus', ['entitas' => 'merek', 'id' => $m->id]) }}" onsubmit="return confirm('Hapus merek {{ e($m->nama_baku) }}? Temuan yang memakainya menjadi belum dinormalkan.')">@csrf<button class="btn kecil bahaya" type="submit">Hapus</button></form></td></tr>
      @endforeach</tbody></table>
      @endif
    </div>
    <div>
      <div class="card">
        <h2>{{ $edit ? 'Ubah merek' : 'Tambah merek' }}</h2>
        <form method="post" action="{{ route('master.simpan', 'merek') }}">
          @csrf
          @if($edit)<input type="hidden" name="id" value="{{ $edit->id }}">@endif
          <div style="margin-bottom:10px"><label for="nama_baku">Nama baku</label><input id="nama_baku" type="text" name="nama_baku" maxlength="80" value="{{ old('nama_baku', $edit->nama_baku ?? '') }}" required></div>
          <div style="margin-bottom:10px"><label for="alias">Alias, pisahkan dengan koma</label><textarea id="alias" name="alias" maxlength="500" placeholder="HUMMER, HUMER MERAH">{{ old('alias', $edit->alias ?? '') }}</textarea><div class="help">Temuan lama yang cocok dinormalkan ulang setiap kali disimpan.</div></div>
          <div style="margin-bottom:10px"><label for="catatan">Catatan</label><input id="catatan" type="text" name="catatan" maxlength="300" value="{{ old('catatan', $edit->catatan ?? '') }}"></div>
          <div class="aksi"><button class="btn" type="submit">Simpan merek</button>@if($edit)<a class="btn abu" href="{{ route('master', ['tab' => 'merek']) }}">Batal</a>@endif</div>
        </form>
      </div>
      <div class="card tabel">
        <h2>Merek di data yang belum dinormalkan</h2>
        @if(empty($belum))<div class="kosong">Semua merek sudah terpetakan.</div>@else
        <table><thead><tr><th>Penulisan di data</th><th class="n">Batang</th><th class="n">Baris</th></tr></thead>
        <tbody>@foreach($belum as $b)<tr><td>{{ $b->merek }}</td><td class="n">{{ Format::angka($b->batang) }}</td><td class="n">{{ Format::angka($b->baris) }}</td></tr>@endforeach</tbody></table>
        <p class="muted">40 teratas menurut jumlah batang. Yang belum dinormalkan tetap dihitung apa adanya di rekap.</p>
        @endif
      </div>
    </div>
  </div>

@elseif($tab === 'tarif')
  <div class="card tabel">
    <h2>Tarif cukai untuk estimasi kerugian</h2>
    <p class="muted" style="margin-top:0">Isi sesuai peraturan yang berlaku. Aplikasi tidak membawa angka tarif bawaan agar tidak ada angka yang salah terpakai.</p>
    @if(empty($tarif))<div class="kosong">Belum ada tarif.</div>@else
    <table><thead><tr><th>Berlaku mulai</th><th>Produk</th><th>Golongan</th><th class="n">Cukai/batang</th><th class="n">HJE minimum</th><th class="n">Pajak rokok %</th><th class="n">PPN HT %</th><th>Dasar regulasi</th><th>Tindakan</th></tr></thead>
    <tbody>@foreach($tarif as $t)
      <tr><td>{{ $t->berlaku_mulai }}</td><td>{{ $t->jenis_produk }}</td><td>{{ $t->golongan }}</td><td class="n">{{ Format::angka($t->tarif_cukai_per_batang, 2) }}</td><td class="n">{{ Format::angka($t->hje_minimum_per_batang, 2) }}</td><td class="n">{{ Format::angka($t->pajak_rokok_persen, 2) }}</td><td class="n">{{ Format::angka($t->ppn_ht_persen, 2) }}</td><td>{{ $t->dasar_regulasi }}</td>
        <td class="aksi"><a class="btn kecil" href="{{ route('master', ['tab' => 'tarif', 'ubah' => $t->id]) }}">Ubah</a>
          <form method="post" action="{{ route('master.hapus', ['entitas' => 'tarif', 'id' => $t->id]) }}" onsubmit="return confirm('Hapus tarif ini?')">@csrf<button class="btn kecil bahaya" type="submit">Hapus</button></form></td></tr>
    @endforeach</tbody></table>
    @endif
  </div>
  <div class="card">
    <h2>{{ $edit ? 'Ubah tarif' : 'Tambah tarif' }}</h2>
    <form method="post" action="{{ route('master.simpan', 'tarif') }}">
      @csrf
      @if($edit)<input type="hidden" name="id" value="{{ $edit->id }}">@endif
      <div class="baris">
        <div><label for="berlaku_mulai">Berlaku mulai</label><input id="berlaku_mulai" type="date" name="berlaku_mulai" value="{{ old('berlaku_mulai', $edit->berlaku_mulai ?? '') }}" required></div>
        <div><label for="jenis_produk">Jenis produk</label><input id="jenis_produk" type="text" name="jenis_produk" placeholder="SKM / SPM / SKT" value="{{ old('jenis_produk', $edit->jenis_produk ?? '') }}" required></div>
        <div><label for="golongan">Golongan</label><input id="golongan" type="text" name="golongan" placeholder="mis. I" value="{{ old('golongan', $edit->golongan ?? '') }}" required></div>
        <div><label for="tarif_cukai_per_batang">Tarif cukai per batang (Rp)</label><input id="tarif_cukai_per_batang" type="number" step="any" min="0" name="tarif_cukai_per_batang" value="{{ old('tarif_cukai_per_batang', $edit->tarif_cukai_per_batang ?? '') }}" required></div>
      </div>
      <div class="baris">
        <div><label for="hje_minimum_per_batang">HJE minimum per batang (Rp)</label><input id="hje_minimum_per_batang" type="number" step="any" min="0" name="hje_minimum_per_batang" value="{{ old('hje_minimum_per_batang', $edit->hje_minimum_per_batang ?? '') }}"><div class="help">Kosongkan bila hanya menghitung cukai.</div></div>
        <div><label for="pajak_rokok_persen">Pajak rokok (%)</label><input id="pajak_rokok_persen" type="number" step="any" min="0" max="100" name="pajak_rokok_persen" value="{{ old('pajak_rokok_persen', $edit->pajak_rokok_persen ?? '') }}"></div>
        <div><label for="ppn_ht_persen">PPN hasil tembakau (%)</label><input id="ppn_ht_persen" type="number" step="any" min="0" max="100" name="ppn_ht_persen" value="{{ old('ppn_ht_persen', $edit->ppn_ht_persen ?? '') }}"></div>
        <div><label for="dasar_regulasi">Dasar regulasi</label><input id="dasar_regulasi" type="text" name="dasar_regulasi" maxlength="300" value="{{ old('dasar_regulasi', $edit->dasar_regulasi ?? '') }}"><div class="help">Nomor dan tahun PMK/peraturan.</div></div>
      </div>
      <div class="aksi"><button class="btn" type="submit">Simpan tarif</button>@if($edit)<a class="btn abu" href="{{ route('master', ['tab' => 'tarif']) }}">Batal</a>@endif</div>
    </form>
  </div>

@elseif($tab === 'surveyor')
  <div class="grid g2">
    <div class="card tabel">
      <h2>Daftar surveyor</h2>
      @if(empty($surveyor))<div class="kosong">Belum ada surveyor.</div>@else
      <table><thead><tr><th>Kode</th><th>Nama</th><th>Pendamping</th><th class="n">Klaster</th><th>Tindakan</th></tr></thead>
      <tbody>@foreach($surveyor as $s)
        <tr><td><b>{{ $s->kode }}</b></td><td>{{ $s->nama ?: '–' }}</td><td>{{ $s->pendamping ?: '–' }}</td><td class="n">{{ Format::angka($s->klaster) }}</td>
          <td class="aksi"><a class="btn kecil" href="{{ route('master', ['tab' => 'surveyor', 'ubah' => $s->kode]) }}">Ubah</a>
            <form method="post" action="{{ route('master.hapus', ['entitas' => 'surveyor', 'id' => $s->kode]) }}" onsubmit="return confirm('Hapus surveyor {{ e($s->kode) }}?')">@csrf<button class="btn kecil bahaya" type="submit">Hapus</button></form></td></tr>
      @endforeach</tbody></table>
      @endif
    </div>
    <div class="card">
      <h2>{{ $edit ? 'Ubah surveyor '.$edit->kode : 'Tambah surveyor' }}</h2>
      <form method="post" action="{{ route('master.simpan', 'surveyor') }}">
        @csrf
        @if($edit)<input type="hidden" name="kode_asal" value="{{ $edit->kode }}">@endif
        <div style="margin-bottom:10px"><label for="kode">Kode (3 karakter)</label><input id="kode" type="text" name="kode" maxlength="3" placeholder="S01" value="{{ old('kode', $edit->kode ?? '') }}" {{ $edit ? 'readonly' : '' }} required><div class="help">Kode tidak dapat diubah setelah dibuat.</div></div>
        <div style="margin-bottom:10px"><label for="nama">Nama</label><input id="nama" type="text" name="nama" maxlength="120" value="{{ old('nama', $edit->nama ?? '') }}"></div>
        <div style="margin-bottom:10px"><label for="pendamping">Tenaga ahli pendamping</label><input id="pendamping" type="text" name="pendamping" maxlength="120" value="{{ old('pendamping', $edit->pendamping ?? '') }}"></div>
        <div class="aksi"><button class="btn" type="submit">Simpan surveyor</button>@if($edit)<a class="btn abu" href="{{ route('master', ['tab' => 'surveyor']) }}">Batal</a>@endif</div>
      </form>
    </div>
  </div>

@elseif($tab === 'poi')
  <div class="grid g2">
    <div class="card tabel">
      <h2>Titik acuan</h2>
      <p class="muted" style="margin-top:0">Pasar, terminal, SPBU, pabrik, pelabuhan, dan lokasi lain yang menjadi acuan analisis.</p>
      @if(empty($poi))<div class="kosong">Belum ada titik acuan.</div>@else
      <table><thead><tr><th>Jenis</th><th>Nama</th><th>Koordinat</th><th>Sumber</th><th>Tindakan</th></tr></thead>
      <tbody>@foreach($poi as $p)
        <tr><td>{{ $p->jenis }}</td><td>{{ $p->nama ?: '–' }}</td><td>{{ number_format($p->lat, 5, ',', '') }}, {{ number_format($p->lon, 5, ',', '') }}</td><td>{{ $p->sumber ?: '–' }}</td>
          <td class="aksi"><a class="btn kecil" href="{{ route('master', ['tab' => 'poi', 'ubah' => $p->id]) }}">Ubah</a>
            <form method="post" action="{{ route('master.hapus', ['entitas' => 'poi', 'id' => $p->id]) }}" onsubmit="return confirm('Hapus titik acuan ini?')">@csrf<button class="btn kecil bahaya" type="submit">Hapus</button></form></td></tr>
      @endforeach</tbody></table>
      @endif
    </div>
    <div class="card">
      <h2>{{ $edit ? 'Ubah titik acuan' : 'Tambah titik acuan' }}</h2>
      <form method="post" action="{{ route('master.simpan', 'poi') }}">
        @csrf
        @if($edit)<input type="hidden" name="id" value="{{ $edit->id }}">@endif
        <div style="margin-bottom:10px"><label for="jenis">Jenis</label><input id="jenis" type="text" name="jenis" list="jenisPoi" maxlength="40" value="{{ old('jenis', $edit->jenis ?? '') }}" required><datalist id="jenisPoi"><option>Pasar</option><option>Terminal</option><option>SPBU</option><option>Pabrik</option><option>Pelabuhan</option><option>Stasiun</option></datalist></div>
        <div style="margin-bottom:10px"><label for="nama_poi">Nama</label><input id="nama_poi" type="text" name="nama" maxlength="160" value="{{ old('nama', $edit->nama ?? '') }}"></div>
        <div class="baris" style="grid-template-columns:1fr 1fr">
          <div><label for="lat">Lintang</label><input id="lat" type="number" step="any" name="lat" placeholder="-7.15" value="{{ old('lat', isset($edit) && $edit ? $edit->lat : '') }}" required></div>
          <div><label for="lon">Bujur</label><input id="lon" type="number" step="any" name="lon" placeholder="110.20" value="{{ old('lon', isset($edit) && $edit ? $edit->lon : '') }}" required></div>
        </div>
        <div style="margin-bottom:10px"><label for="sumber">Sumber</label><input id="sumber" type="text" name="sumber" maxlength="200" value="{{ old('sumber', $edit->sumber ?? '') }}"></div>
        <div class="aksi"><button class="btn" type="submit">Simpan titik</button>@if($edit)<a class="btn abu" href="{{ route('master', ['tab' => 'poi']) }}">Batal</a>@endif</div>
      </form>
    </div>
  </div>
@endif
@endsection
