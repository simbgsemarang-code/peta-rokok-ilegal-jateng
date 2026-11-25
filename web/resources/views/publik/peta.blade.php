@extends('layouts.publik')
@section('judul', 'Peta')
@section('deskripsi', 'Peta hasil penindakan rokok ilegal di Jawa Tengah per kabupaten/kota dan sebaran per kecamatan.')
@section('tanpa-footer', '1')
@push('kepala')
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<style>
  body { overflow-x: hidden; }
  .peta-penuh { position: relative; width: 100%; background: #dbe7f0; }
  #petaFull { position: absolute; inset: 0; }
  .panel-peta { position: absolute; top: 12px; right: 12px; z-index: 500; width: 310px; max-height: calc(100% - 24px); overflow-y: auto; background: #fff; border-radius: 14px; box-shadow: 0 10px 34px rgba(20,39,74,.25); font-size: .92rem; }
  .panel-peta > summary { cursor: pointer; list-style: none; padding: 12px 16px; font-weight: 700; color: var(--navy); display: flex; justify-content: space-between; align-items: center; }
  .panel-peta > summary::after { content: "▾"; color: var(--teal); }
  .panel-peta:not([open]) > summary::after { content: "▸"; }
  .panel-peta .isi-panel { padding: 0 16px 14px; }
  .panel-peta label.f { display: block; font-size: .78rem; font-weight: 700; color: var(--navy); margin: 8px 0 3px; }
  .panel-peta select { width: 100%; padding: 8px 10px; border-radius: 9px; border: 1px solid #c9d0dc; font: inherit; background: #fff; }
  .seg { display: flex; flex-wrap: wrap; gap: 6px; }
  .seg button { flex: 1 1 auto; min-width: 54px; padding: 8px 10px; border-radius: 9px; border: 1px solid #c9d0dc; background: #fff; color: var(--navy); font: inherit; font-size: .88rem; font-weight: 600; cursor: pointer; transition: background .15s, color .15s, border-color .15s; }
  .seg button:hover { border-color: var(--navy); }
  .seg button[aria-pressed="true"] { background: var(--navy); border-color: var(--navy); color: #fff; }
  .seg button:focus-visible { outline: 2px solid var(--teal); outline-offset: 2px; }
  .seg.dua button { flex: 1 1 0; }
  .panel-peta .cek { display: flex; gap: 8px; align-items: flex-start; margin-top: 10px; font-size: .88rem; }
  .panel-peta .mini { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin: 12px 0 4px; }
  .panel-peta .mini div { background: var(--bg); border-radius: 10px; padding: 8px 10px; }
  .panel-peta .mini b { display: block; font-size: 1.1rem; color: var(--navy); font-variant-numeric: tabular-nums; }
  .panel-peta .mini span { font-size: .76rem; color: var(--muted); }
  .panel-peta .catatan-p { font-size: .8rem; color: var(--muted); line-height: 1.5; margin-top: 8px; }
  @media (max-width: 700px) { .panel-peta { left: 62px; right: 10px; width: auto; top: 10px; } }
</style>
@endpush
@section('isi')
@use('App\Support\Format')
<div class="peta-penuh" id="penuh">
  <div id="petaFull" role="application" aria-label="Peta hasil penindakan rokok ilegal Jawa Tengah"></div>
  <details class="panel-peta" id="panelPeta" open>
    <summary>Peta penindakan</summary>
    <div class="isi-panel">
      <div class="mini">
        <div><b id="statPel">{{ $ringkas['pelanggar'] ? Format::angka($ringkas['pelanggar']) : '–' }}</b><span>pelaku ditemukan melanggar</span></div>
        <div><b id="statBtg">{{ $ringkas['batang'] ? Format::angka($ringkas['batang']) : '–' }}</b><span>batang disita</span></div>
      </div>
      @php $daftarTahun = collect($tahun)->map(fn ($t) => (int) $t)->unique()->sort()->values(); @endphp
      <span class="f" id="lblTahun" style="display:block;font-size:.78rem;font-weight:700;color:var(--navy);margin:8px 0 5px">Tahun</span>
      <div class="seg" id="tahun" role="group" aria-labelledby="lblTahun">
        <button type="button" data-tahun="" aria-pressed="true">Semua</button>
        @foreach($daftarTahun as $t)<button type="button" data-tahun="{{ $t }}" aria-pressed="false">{{ $t }}</button>@endforeach
      </div>
      <span class="f" id="lblDasar" style="display:block;font-size:.78rem;font-weight:700;color:var(--navy);margin:12px 0 5px">Peta dasar</span>
      <div class="seg dua" id="dasar" role="group" aria-labelledby="lblDasar">
        <button type="button" data-dasar="osm" aria-pressed="true">OSM</button>
        <button type="button" data-dasar="satelit" aria-pressed="false">Satelit</button>
      </div>
      <label class="f" for="ukur" style="margin-top:12px">Warna wilayah menurut</label>
      <select id="ukur"><option value="batang">Jumlah batang disita</option><option value="pelanggar">Jumlah pelaku</option><option value="operasi">Jumlah operasi</option></select>
      <label class="cek"><input type="checkbox" id="sebaran" checked> <span>Gelembung sebaran per kecamatan</span></label>
      <p class="catatan-p">
        Gelembung berada di <b>titik tengah kecamatan</b>, bukan lokasi usaha. Kecamatan dengan kurang dari 3 pelaku diberi label "1–2" dan jumlah batangnya tidak ditampilkan. Nama, alamat, dan titik lokasi usaha tidak ditampilkan kepada publik.
        @auth <br>Anda login sebagai petugas: <a href="{{ route('peta') }}"><b>buka peta petugas</b></a> untuk batas desa/kelurahan dan titik perkiraan lokasi.
        @else <br>Petugas dengan akses dapat <a href="{{ route('login') }}">masuk</a> untuk peta sampai desa/kelurahan.@endauth
      </p>
      <p class="catatan-p" id="infoPeta"></p>
    </div>
  </details>
</div>
@endsection

@push('skrip')
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script>
(function () {
  const nf = new Intl.NumberFormat('id-ID');
  const WARNA = ['#d9efec', '#8fd0c9', '#35a99f', '#0f766e', '#0a4b46'];
  const NAMA = { batang: 'batang', pelanggar: 'pelaku', operasi: 'operasi' };
  const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const $ = id => document.getElementById(id);

  // peta mengisi sisa tinggi layar di bawah navbar
  function ukurTinggi() { const nav = document.querySelector('.nav'); $('penuh').style.height = Math.max(480, innerHeight - nav.offsetHeight) + 'px'; if (peta) peta.invalidateSize(); }
  let peta = null;
  ukurTinggi();
  peta = L.map('petaFull', { zoomControl: true }).setView([-7.15, 110.2], 8);
  window.addEventListener('resize', ukurTinggi);
  if (innerWidth <= 700) $('panelPeta').removeAttribute('open');   // di ponsel panel dilipat agar peta terlihat

  // peta dasar: OSM atau citra satelit (Esri World Imagery + label tempat)
  const osm = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 16, attribution: '&copy; OpenStreetMap' }).addTo(peta);
  const satelit = L.layerGroup([
    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 16, attribution: 'Citra &copy; Esri, Maxar, Earthstar Geographics' }),
    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}', { maxZoom: 16 })
  ]);
  const DASAR = { osm, satelit };
  let dasarAktif = 'osm';
  function pilihDasar(k) {
    if (k === dasarAktif) return;
    DASAR[dasarAktif].remove(); DASAR[k].addTo(peta); dasarAktif = k;
    document.querySelectorAll('#dasar button').forEach(b => b.setAttribute('aria-pressed', b.dataset.dasar === k ? 'true' : 'false'));
    if (lapisan) lapisan.bringToFront(); lapSebaran.eachLayer(l => l.bringToFront && l.bringToFront());
  }
  const PER = @json($ringkas['per_tahun']);
  let tahunDipilih = '';
  function isiStat() {
    const baris = tahunDipilih ? PER.filter(p => String(p.tahun) === tahunDipilih) : PER;
    const p = baris.reduce((a, r) => a + r.pelanggar, 0), b = baris.reduce((a, r) => a + r.batang, 0);
    $('statPel').textContent = p ? nf.format(p) : '–'; $('statBtg').textContent = b ? nf.format(b) : '–';
  }
  const DARATAN = L.latLngBounds([-8.35, 108.5], [-6.4, 111.75]);
  let lapisan = null, lapSebaran = L.layerGroup().addTo(peta), data = null, sebaran = [], batas = [], sudahPas = false;
  const leg = L.control({ position: 'bottomleft' }); leg.onAdd = () => { const d = L.DomUtil.create('div', 'legenda-p'); d.id = 'legP'; return d; }; leg.addTo(peta);

  function hitungBatas(v) { v = v.filter(x => x > 0).sort((a, b) => a - b); if (!v.length) return []; const b = []; for (let i = 1; i <= 4; i++) b.push(v[Math.min(v.length - 1, Math.floor(i * v.length / 5))]); return [...new Set(b)]; }
  function warna(x) { if (!x) return '#eef1f6'; let k = 0; while (k < batas.length && x > batas[k]) k++; return WARNA[Math.min(k, 4)]; }

  function gambar() {
    const ukur = $('ukur').value;
    batas = hitungBatas(data.features.map(f => f.properties[ukur]));
    if (lapisan) lapisan.remove();
    lapisan = L.geoJSON({ type: 'FeatureCollection', features: data.features.filter(f => f.geometry) }, {
      style: f => ({ fillColor: warna(f.properties[ukur]), fillOpacity: .72, color: '#ffffff', weight: 1.2 }),
      onEachFeature: (f, l) => {
        const p = f.properties;
        l.bindTooltip(esc(p.nama) + ': ' + nf.format(p[ukur]) + ' ' + NAMA[ukur], { sticky: true });
        l.bindPopup('<b>' + esc(p.nama) + '</b><br>' + nf.format(p.operasi) + ' operasi · ' + nf.format(p.pelanggar) + ' pelaku<br>' + nf.format(p.batang) + ' batang');
        l.on({ mouseover: e => e.target.setStyle({ weight: 2.6, color: '#14274a' }), mouseout: e => lapisan.resetStyle(e.target) });
      }
    }).addTo(peta);
    lapisan.bringToBack();
    if (!sudahPas) { peta.fitBounds(DARATAN, { padding: [10, 10] }); sudahPas = true; }
    let h = '<b>' + NAMA[ukur] + ' per kab/kota</b><br><i style="background:#eef1f6"></i>0<br>', awal = 1;
    batas.forEach((b, k) => { h += '<i style="background:' + WARNA[k] + '"></i>' + nf.format(awal) + '–' + nf.format(b) + '<br>'; awal = b + 1; });
    h += '<i style="background:' + WARNA[Math.min(batas.length, 4)] + '"></i>≥ ' + nf.format(awal);
    $('legP').innerHTML = h;
  }

  function gambarSebaran() {
    lapSebaran.clearLayers();
    if (!$('sebaran').checked) return;
    sebaran.forEach(s => {
      const n = s.pelanggar === null ? 1.5 : s.pelanggar, kecil = innerWidth <= 700;   // gelembung lebih kecil di ponsel
      const m = L.circleMarker([s.lat, s.lon], { radius: Math.min(kecil ? 16 : 26, (kecil ? 3.5 : 5) + (kecil ? 2.1 : 3.2) * Math.sqrt(n)), color: '#fff', weight: kecil ? 1.2 : 1.6, fillColor: '#b45309', fillOpacity: .82 });
      m.bindTooltip('Kec. ' + esc(s.kecamatan) + ': ' + esc(s.label) + ' pelaku', { sticky: true });
      m.bindPopup('<b>Kec. ' + esc(s.kecamatan) + '</b><br>' + esc(s.kab) + '<br>' + esc(s.label) + ' pelaku ditemukan melanggar' +
        (s.batang === null ? '' : '<br>' + nf.format(s.batang) + ' batang') +
        '<br><small>Gelembung di titik tengah kecamatan, bukan lokasi usaha.</small>');
      m.addTo(lapSebaran);
    });
    $('infoPeta').textContent = sebaran.length ? sebaran.length + ' kecamatan dengan temuan yang dapat dipetakan.' : 'Sebaran per kecamatan belum tersedia.';
  }

  async function muat() {
    const t = tahunDipilih, q = t ? '?tahun=' + encodeURIComponent(t) : '';
    try {
      const [a, b] = await Promise.all([
        fetch('{{ route('publik.kabkota') }}' + q, { headers: { Accept: 'application/json' } }).then(r => r.json()),
        fetch('{{ route('publik.sebaran') }}' + q, { headers: { Accept: 'application/json' } }).then(r => r.json())
      ]);
      data = a; sebaran = Array.isArray(b) ? b : [];
      if (!data.features || !data.features.length) throw new Error('kosong');
      gambar(); gambarSebaran();
    } catch (e) { $('infoPeta').textContent = 'Data peta belum tersedia.'; }
  }
  document.querySelectorAll('#tahun button').forEach(b => b.addEventListener('click', () => {
    tahunDipilih = b.dataset.tahun;
    document.querySelectorAll('#tahun button').forEach(x => x.setAttribute('aria-pressed', x === b ? 'true' : 'false'));
    isiStat(); muat();
  }));
  document.querySelectorAll('#dasar button').forEach(b => b.addEventListener('click', () => pilihDasar(b.dataset.dasar)));
  $('ukur').addEventListener('change', () => data && gambar());
  $('sebaran').addEventListener('change', gambarSebaran);
  muat();
})();
</script>
@endpush
