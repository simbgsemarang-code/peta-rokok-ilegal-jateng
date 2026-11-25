@extends('layouts.publik')
@section('judul', 'Data Penindakan')
@section('deskripsi', 'Angka gabungan hasil operasi cukai di 35 kabupaten/kota Jawa Tengah: jumlah operasi, pelaku, dan batang rokok ilegal yang disita.')
@push('kepala')
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
@endpush
@section('isi')
@use('App\Support\Format')
@php $maks = $ringkas['per_tahun'] ? max(array_column($ringkas['per_tahun'], 'batang')) : 1; @endphp
<section class="judul-hal"><div class="wadah"><h1>Data penindakan</h1><p>Hasil operasi cukai {{ $ringkas['tahun_awal'] ? $ringkas['tahun_awal'].'–'.$ringkas['tahun_akhir'] : '' }} di Jawa Tengah, per kabupaten/kota. @if($ringkas['mutakhir'])Diperbarui {{ Format::tanggal($ringkas['mutakhir']) }}.@endif</p></div></section>
<div class="isi"><div class="wadah">
  @if(! $ringkas['per_tahun'])
    <div class="kotak emas"><p>Data penindakan belum tersedia.</p></div>
  @else
  <div class="angka-grid" style="margin-bottom:34px">
    <div class="angka"><b>{{ Format::angka($ringkas['operasi']) }}</b><span>operasi</span></div>
    <div class="angka"><b>{{ Format::angka($ringkas['pelanggar']) }}</b><span>pelaku ditemukan melanggar</span></div>
    <div class="angka"><b>{{ Format::angka($ringkas['batang']) }}</b><span>batang disita</span></div>
    <div class="angka"><b>{{ $ringkas['kab_bertemuan'] }} <small style="font-size:1rem;color:var(--muted)">dari 35</small></b><span>kabupaten/kota dengan temuan</span></div>
  </div>

  <div class="grid2" style="margin-bottom:34px">
    <div>
      <h2>Per tahun</h2>
      <div class="batang-tahun" role="list">
        @foreach(array_reverse($ringkas['per_tahun']) as $t)
          <div class="bt" role="listitem"><span>{{ $t['tahun'] }}</span><div class="bar" title="{{ Format::angka($t['batang']) }} batang"><i style="width:{{ max(2, round(100 * $t['batang'] / $maks)) }}%"></i></div><b>{{ Format::angka($t['batang']) }}</b></div>
        @endforeach
      </div>
      <p class="muted kecil" style="margin-top:10px">Jumlah batang disita per tahun.</p>
    </div>
    <div class="tabel-wrap">
      <table class="tabel">
        <thead><tr><th>Tahun</th><th class="n">Operasi</th><th class="n">Pelaku</th><th class="n">Batang</th></tr></thead>
        <tbody>@foreach($ringkas['per_tahun'] as $t)<tr><td>{{ $t['tahun'] }}</td><td class="n">{{ Format::angka($t['operasi']) }}</td><td class="n">{{ Format::angka($t['pelanggar']) }}</td><td class="n">{{ Format::angka($t['batang']) }}</td></tr>@endforeach</tbody>
      </table>
    </div>
  </div>

  <h2>Per kabupaten/kota</h2>
  <div class="kontrol">
    <div><label for="tahun">Tahun</label><select id="tahun"><option value="">Semua tahun</option>@foreach($tahun as $t)<option value="{{ $t }}">{{ $t }}</option>@endforeach</select></div>
    <div><label for="ukur">Warna peta menurut</label><select id="ukur"><option value="batang">Jumlah batang disita</option><option value="pelanggar">Jumlah pelaku</option><option value="operasi">Jumlah operasi</option></select></div>
    <a class="tbl alt kecil" href="{{ route('publik.csv') }}" download>Unduh data (CSV)</a>
  </div>
  <div class="data-peta">
    <div id="petaPublik" role="application" aria-label="Peta hasil penindakan per kabupaten/kota"></div>
    <div class="tabel-wrap" style="max-height:520px;overflow:auto">
      <table class="tabel" id="tabelKab">
        <thead><tr><th>Kabupaten/Kota</th><th class="n">Operasi</th><th class="n">Pelaku</th><th class="n">Batang</th></tr></thead>
        <tbody><tr><td colspan="4" class="muted">Memuat…</td></tr></tbody>
      </table>
    </div>
  </div>
  <noscript><p class="muted">Peta dan tabel per kabupaten/kota membutuhkan JavaScript. Data lengkap tersedia pada berkas CSV di atas.</p></noscript>

  <div class="kotak emas">
    <p><b>Cara membaca angka.</b></p>
    <ul>
      <li>Angka adalah <b>hasil penindakan</b> yang tercatat, bukan seluruh peredaran rokok ilegal. Wilayah yang lebih sering dioperasi cenderung memiliki lebih banyak temuan.</li>
      <li>Jumlah batang dapat sangat besar bila ada satu kali penyitaan dalam jumlah besar, sehingga tidak selalu sejalan dengan jumlah pelaku.</li>
      <li>Operasi pembinaan dan sosialisasi dihitung sebagai operasi, tetapi pelakunya tidak dihitung sebagai pelaku yang melanggar.</li>
      <li>Tahun terbaru dapat belum lengkap. Data dihimpun dari rekap operasi gabungan dan diperbarui secara berkala.</li>
    </ul>
  </div>
  @endif
</div></div>
@endsection

@if($ringkas['per_tahun'])
@push('skrip')
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script>
(function () {
  const nf = new Intl.NumberFormat('id-ID');
  const WARNA = ['#d9efec', '#8fd0c9', '#35a99f', '#0f766e', '#0a4b46'];
  const NAMA = { batang: 'batang', pelanggar: 'pelaku', operasi: 'operasi' };
  const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const peta = L.map('petaPublik', { scrollWheelZoom: false }).setView([-7.15, 110.2], 8);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 14, attribution: '&copy; OpenStreetMap' }).addTo(peta);
  const DARATAN = L.latLngBounds([-8.35, 108.5], [-6.4, 111.75]);
  let lapisan = null, data = null, batas = [], sudahPas = false;
  const leg = L.control({ position: 'bottomleft' }); leg.onAdd = () => { const d = L.DomUtil.create('div', 'legenda-p'); d.id = 'legP'; return d; }; leg.addTo(peta);

  function hitungBatas(v) { v = v.filter(x => x > 0).sort((a, b) => a - b); if (!v.length) return []; const b = []; for (let i = 1; i <= 4; i++) b.push(v[Math.min(v.length - 1, Math.floor(i * v.length / 5))]); return [...new Set(b)]; }
  function warna(x) { if (!x) return '#eef1f6'; let k = 0; while (k < batas.length && x > batas[k]) k++; return WARNA[Math.min(k, 4)]; }

  function gambar() {
    const ukur = document.getElementById('ukur').value;
    batas = hitungBatas(data.features.map(f => f.properties[ukur]));
    if (lapisan) lapisan.remove();
    lapisan = L.geoJSON({ type: 'FeatureCollection', features: data.features.filter(f => f.geometry) }, {
      style: f => ({ fillColor: warna(f.properties[ukur]), fillOpacity: .78, color: '#ffffff', weight: 1 }),
      onEachFeature: (f, l) => {
        const p = f.properties;
        l.bindTooltip(esc(p.nama) + ': ' + nf.format(p[ukur]) + ' ' + NAMA[ukur], { sticky: true });
        l.bindPopup('<b>' + esc(p.nama) + '</b><br>' + nf.format(p.operasi) + ' operasi · ' + nf.format(p.pelanggar) + ' pelaku<br>' + nf.format(p.batang) + ' batang');
        l.on({ mouseover: e => e.target.setStyle({ weight: 2.5, color: '#14274a' }), mouseout: e => lapisan.resetStyle(e.target) });
      }
    }).addTo(peta);
    if (!sudahPas) { peta.fitBounds(DARATAN, { padding: [10, 10] }); sudahPas = true; }
    let h = '<b>' + NAMA[ukur] + '</b><br><i style="background:#eef1f6"></i>0<br>', awal = 1;
    batas.forEach((b, k) => { h += '<i style="background:' + WARNA[k] + '"></i>' + nf.format(awal) + '–' + nf.format(b) + '<br>'; awal = b + 1; });
    h += '<i style="background:' + WARNA[Math.min(batas.length, 4)] + '"></i>≥ ' + nf.format(awal);
    document.getElementById('legP').innerHTML = h;

    const urut = [...data.features].sort((a, b) => b.properties[ukur] - a.properties[ukur] || a.properties.nama.localeCompare(b.properties.nama));
    const tb = document.querySelector('#tabelKab tbody');
    tb.innerHTML = urut.map(f => '<tr><td>' + esc(f.properties.nama) + '</td><td class="n">' + nf.format(f.properties.operasi) + '</td><td class="n">' + nf.format(f.properties.pelanggar) + '</td><td class="n">' + nf.format(f.properties.batang) + '</td></tr>').join('');
  }
  async function muat() {
    const t = document.getElementById('tahun').value;
    try {
      const res = await fetch('{{ route('publik.kabkota') }}' + (t ? '?tahun=' + encodeURIComponent(t) : ''), { headers: { Accept: 'application/json' } });
      data = await res.json(); if (!data.features || !data.features.length) throw new Error('kosong'); gambar();
    } catch (e) { document.querySelector('#tabelKab tbody').innerHTML = '<tr><td colspan="4" class="muted">Data peta belum tersedia.</td></tr>'; }
  }
  document.getElementById('tahun').addEventListener('change', muat);
  document.getElementById('ukur').addEventListener('change', () => data && gambar());
  peta.on('focus', () => peta.scrollWheelZoom.enable()); peta.on('blur', () => peta.scrollWheelZoom.disable());
  muat();
})();
</script>
@endpush
@endif
