@extends('layouts.app')
@section('judul', 'Peta penindakan')
@section('kelas', 'penuh')
@push('kepala')
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
@endpush
@section('isi')
<div class="peta-wrap">
  <div id="peta"></div>
  <aside class="panel">
    <h2 id="judulPanel">Penindakan per kabupaten/kota</h2>
    <p class="muted" style="margin:0 0 12px">Data hasil operasi cukai yang sudah disimpan admin. Peta menunjukkan <b>tempat petugas menemukan pelanggaran</b>, bukan seluruh peredaran.</p>
    <div class="baris" style="grid-template-columns:1fr 1fr;margin-bottom:8px">
      <div>
        <label for="tingkat">Tingkat wilayah</label>
        <select id="tingkat">
          <option value="kab">Kabupaten/Kota</option>
          <option value="kecamatan">Kecamatan</option>
          <option value="desa">Desa/Kelurahan</option>
        </select>
      </div>
      <div>
        <label for="kabPilih">Kab/Kota</label>
        <select id="kabPilih">
          <option value="">Semua</option>
          @foreach($kabkota as $k)<option value="{{ $k->kode }}">{{ $k->nama }}</option>@endforeach
        </select>
      </div>
    </div>
    <div class="baris" style="grid-template-columns:1fr 1fr">
      <div>
        <label for="tahun">Tahun</label>
        <select id="tahun">
          <option value="">Semua tahun</option>
          @foreach($tahun as $t)<option value="{{ $t }}">{{ $t }}</option>@endforeach
        </select>
      </div>
      <div>
        <label for="ukur">Warna menurut</label>
        <select id="ukur">
          <option value="pelanggar">Jumlah pelanggar</option>
          <option value="jumlah_batang">Jumlah batang</option>
          <option value="operasi">Jumlah operasi</option>
        </select>
      </div>
    </div>
    <label style="font-weight:400"><input type="checkbox" id="dasar" checked> Tampilkan peta dasar (butuh internet)</label>
    @if($bolehTitik)
    <label style="font-weight:400;margin-top:4px"><input type="checkbox" id="titik" checked> Titik perkiraan lokasi pelanggar</label>
    <label style="font-weight:400;margin-left:20px"><input type="checkbox" id="titikKab"> sertakan yang hanya setingkat kab/kota</label>
    <div id="legTitik" class="muted" style="font-size:12px;margin:6px 0 0 20px;line-height:1.7">
      <span style="color:#0f766e">●</span> alamat/jalan &nbsp;<span style="color:#2563eb">●</span> desa/dusun &nbsp;<span style="color:#7c3aed">●</span> kecamatan &nbsp;<span style="color:#6b7280">●</span> kab/kota<br>
      Ukuran lingkaran menurut jumlah pelanggar di titik itu.
    </div>
    <div class="muted" style="font-size:12px;margin:6px 0 0 20px">Titik berasal dari alamat, bukan GPS; sebagian besar hanya setingkat kecamatan. Untuk admin dan operator, tanpa nama atau alamat.</div>
    @endif
    <div id="galat" class="notif bad" style="display:none;margin-top:12px"></div>
    <div id="info" class="notif info" style="display:none;margin-top:12px"></div>
    <div class="grid g2" style="margin:14px 0">
      <div class="angka"><b id="s-pelanggar">–</b><span>pelanggar</span></div>
      <div class="angka"><b id="s-batang">–</b><span>batang</span></div>
      <div class="angka"><b id="s-operasi">–</b><span id="s-operasi-l">operasi</span></div>
      <div class="angka"><b id="s-kab">–</b><span id="s-kab-l">dari 35 kab/kota ada temuan</span></div>
    </div>
    <div id="catatan" class="muted" style="font-size:12.5px;margin:-4px 0 10px"></div>
    <h3>10 teratas</h3>
    <ul class="daftar" id="teratas"></ul>
  </aside>
</div>
@endsection
@push('skrip')
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script>
(function () {
  const nf = new Intl.NumberFormat('id-ID');
  const NAMA = { pelanggar: 'pelanggar', jumlah_batang: 'batang', operasi: 'operasi' };
  const WARNA = ['#fef3c7', '#fcd34d', '#f59e0b', '#dc2626', '#7f1d1d'];
  const JUDUL = { kab: 'Penindakan per kabupaten/kota', kecamatan: 'Penindakan per kecamatan', desa: 'Penindakan per desa/kelurahan' };
  const SATUAN = { kab: 'kab/kota', kecamatan: 'kecamatan', desa: 'desa/kelurahan' };
  const $ = id => document.getElementById(id);
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  const peta = L.map('peta', { zoomControl: true }).setView([-7.15, 110.2], 8);
  const dasar = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 18, attribution: '&copy; OpenStreetMap' });
  dasar.addTo(peta);
  const DARATAN_JATENG = L.latLngBounds([-8.35, 108.5], [-6.4, 111.75]);
  let lapisan = null, lapKab = null, batas = [], data = null, kabGeo = null, urutMuat = 0;

  const legenda = L.control({ position: 'bottomleft' });
  legenda.onAdd = () => { const d = L.DomUtil.create('div', 'legenda'); d.id = 'legenda'; return d; };
  legenda.addTo(peta);

  function hitungBatas(nilai) {
    const v = nilai.filter(x => x > 0).sort((a, b) => a - b);
    if (!v.length) return [];
    const b = [];
    for (let i = 1; i <= 4; i++) b.push(v[Math.min(v.length - 1, Math.floor(i * v.length / 5))]);
    return [...new Set(b)];
  }
  function warna(x) {
    if (!x) return '#e5e7eb';
    let k = 0; while (k < batas.length && x > batas[k]) k++;
    return WARNA[Math.min(k, WARNA.length - 1)];
  }
  function gambarLegenda(ukur, tingkat) {
    let h = '<b>' + esc(NAMA[ukur]) + '</b><br><i style="background:#e5e7eb"></i>0<br>';
    let awal = 1;
    batas.forEach((b, k) => { h += '<i style="background:' + WARNA[k] + '"></i>' + nf.format(awal) + '–' + nf.format(b) + '<br>'; awal = b + 1; });
    h += '<i style="background:' + WARNA[Math.min(batas.length, 4)] + '"></i>≥ ' + nf.format(awal);
    if (tingkat === 'desa') h += '<br><i style="background:#fff;border:2px solid #0f766e"></i>desa sampel survei';
    $('legenda').innerHTML = h;
  }

  function popup(p, tingkat) {
    let h = '<b>' + esc(p.nama) + '</b>';
    if (tingkat === 'kab') {
      h += (p.koridor ? ' <small>(wilayah koridor)</small>' : '') + '<br>Eks-karesidenan ' + esc(p.wilayah_eks) +
        '<br>' + nf.format(p.pelanggar) + ' pelanggar · ' + nf.format(p.operasi) + ' operasi<br>' + nf.format(p.jumlah_batang) + ' batang' +
        '<div style="margin-top:8px"><button class="btn kecil" data-lv="kecamatan" data-kab="' + esc(p.kode) + '">Kecamatan</button> ' +
        '<button class="btn kecil alt" data-lv="desa" data-kab="' + esc(p.kode) + '">Desa/kelurahan</button></div>';
    } else if (tingkat === 'kecamatan') {
      h += '<br>' + esc(p.kab) + '<br>' + nf.format(p.pelanggar) + ' pelanggar · ' + nf.format(p.jumlah_batang) + ' batang' +
        '<div style="margin-top:8px"><button class="btn kecil" data-lv="desa" data-kab="' + esc(p.kode.slice(0, 5)) + '">Lihat desa/kelurahan</button></div>';
    } else {
      h += ' <small>(' + esc(p.jenis) + ')</small><br>Kec. ' + esc(p.kecamatan) + ', ' + esc(p.kab) +
        '<br>' + nf.format(p.pelanggar) + ' pelanggar · ' + nf.format(p.jumlah_batang) + ' batang';
      if (p.klaster) h += '<br><small>Sampel survei: klaster <b>' + esc(p.klaster) + '</b> (' + esc(p.jenis_klaster) + ')</small>';
    }
    return h;
  }

  // Batas daratan utama: kab/kota berpulau (mis. Jepara dengan Karimunjawa) tidak ditarik ke pulau terjauh.
  function batasUtamaFitur(f) {
    const g = f.geometry;
    if (g.type !== 'MultiPolygon') return L.geoJSON(f).getBounds();
    let terbaik = null, luas = -1;
    g.coordinates.forEach(c => {
      const b = L.geoJSON({ type: 'Polygon', coordinates: c }).getBounds(), a = (b.getNorth() - b.getSouth()) * (b.getEast() - b.getWest());
      if (a > luas) { luas = a; terbaik = b; }
    });
    return terbaik;
  }
  function batasUtamaLapisan(lap) {
    const bs = []; lap.eachLayer(l => { if (l.getBounds) bs.push(l.getBounds()); });
    if (!bs.length) return lap.getBounds();
    const pusat = bs.map(b => b.getCenter()), med = a => a.slice().sort((x, y) => x - y)[Math.floor(a.length / 2)];
    const ml = med(pusat.map(c => c.lat)), mg = med(pusat.map(c => c.lng));
    let out = null;
    bs.forEach((b, i) => { if (Math.abs(pusat[i].lat - ml) < 0.5 && Math.abs(pusat[i].lng - mg) < 0.5) out = out ? out.extend(b) : L.latLngBounds(b.getSouthWest(), b.getNorthEast()); });
    return out || lap.getBounds();
  }

  function gambar(fokus) {
    const tingkat = data.tingkat || 'kab';
    const ukur = $('ukur').value;
    batas = hitungBatas(data.features.map(f => f.properties[ukur] || 0));
    if (lapisan) lapisan.remove();
    const berGeom = data.features.filter(f => f.geometry);
    lapisan = L.geoJSON({ type: 'FeatureCollection', features: berGeom }, {
      style: f => {
        const p = f.properties, sampel = tingkat === 'desa' && p.klaster;
        return { fillColor: warna(p[ukur] || 0), fillOpacity: .6, color: sampel ? '#0f766e' : (tingkat === 'kab' ? '#475467' : '#8a94a6'), weight: sampel ? 2.6 : (tingkat === 'kab' ? 1 : tingkat === 'kecamatan' ? .6 : .5) };
      },
      onEachFeature: (f, l) => {
        const p = f.properties;
        l.bindTooltip(esc(p.nama) + ': ' + nf.format(p[ukur] || 0) + ' ' + NAMA[ukur], { sticky: true });
        l.bindPopup(popup(p, tingkat));
        l.on({ mouseover: e => e.target.setStyle({ weight: 2.5, color: '#14274a' }), mouseout: e => lapisan.resetStyle(e.target) });
      }
    }).addTo(peta);
    if (lapTitik) lapTitik.eachLayer(m => m.bringToFront && m.bringToFront());   // titik tetap di atas poligon

    // garis batas kab/kota sebagai pembatas bila menampilkan tingkat yang lebih rinci
    if (lapKab) { lapKab.remove(); lapKab = null; }
    if (tingkat !== 'kab' && kabGeo) {
      lapKab = L.geoJSON({ type: 'FeatureCollection', features: kabGeo.filter(f => f.geometry) }, { interactive: false, style: { fill: false, color: '#14274a', weight: 1.8, opacity: .85 } }).addTo(peta);
    }
    if (berGeom.length && fokus) {
      peta.invalidateSize();
      let batasPeta = DARATAN_JATENG;   // tampilan se-provinsi: daratan utama, tanpa kepulauan terjauh
      const kb = $('kabPilih').value;
      if (kb) {
        const f = tingkat === 'kab' ? berGeom.find(x => x.properties.kode === kb) : null;
        batasPeta = f ? batasUtamaFitur(f) : batasUtamaLapisan(lapisan);
      }
      peta.fitBounds(batasPeta, { padding: [20, 20] });
    }
    gambarLegenda(ukur, tingkat);

    const r = data.ringkas;
    $('judulPanel').textContent = JUDUL[tingkat];
    $('s-pelanggar').textContent = nf.format(r.pelanggar);
    $('s-batang').textContent = nf.format(r.jumlah_batang);
    if (tingkat === 'kab') {
      $('s-operasi').textContent = nf.format(r.operasi);
      $('s-operasi-l').textContent = 'operasi';
      $('s-kab').textContent = r.kab_bertemuan;
      $('s-kab-l').textContent = 'dari 35 kab/kota ada temuan';
      $('catatan').textContent = '';
    } else {
      $('s-operasi').textContent = nf.format(r.lingkup_pelanggar);
      $('s-operasi-l').textContent = 'pelanggar di cakupan ini';
      $('s-kab').textContent = r.unit_bertemuan;
      $('s-kab-l').textContent = 'dari ' + nf.format(r.unit) + ' ' + SATUAN[tingkat] + ' ada temuan';
      const sisa = r.lingkup_pelanggar - r.pelanggar;
      $('catatan').innerHTML = '<b>' + nf.format(r.pelanggar) + '</b> dari ' + nf.format(r.lingkup_pelanggar) + ' pelanggar dapat ditempatkan sampai tingkat ' + SATUAN[tingkat] +
        '; ' + nf.format(sisa) + ' lainnya hanya diketahui sampai tingkat yang lebih luas karena alamatnya belum cukup rinci.' +
        (tingkat === 'desa' ? '<br>' + nf.format(r.klaster) + ' desa/kelurahan di sini adalah sampel survei (garis hijau tebal).' : '');
    }

    const top = [...data.features].sort((a, b) => (b.properties[ukur] || 0) - (a.properties[ukur] || 0)).filter(f => (f.properties[ukur] || 0) > 0).slice(0, 10);
    const maks = top.length ? top[0].properties[ukur] : 1;
    const ul = $('teratas');
    ul.innerHTML = top.length ? '' : '<li class="muted">Belum ada temuan pada tingkat ini' + (tingkat === 'kab' ? '. Impor data operasi lebih dulu (menu Admin).' : '.') + '</li>';
    top.forEach(f => {
      const p = f.properties, li = document.createElement('li');
      li.innerHTML = '<div style="flex:1"><div>' + esc(p.nama) + (tingkat === 'desa' ? ' <small class="muted">Kec. ' + esc(p.kecamatan) + '</small>' : tingkat === 'kecamatan' ? ' <small class="muted">' + esc(p.kab) + '</small>' : '') +
        '</div><div class="bar" style="width:' + Math.max(4, 100 * p[ukur] / maks) + '%"></div></div><b>' + nf.format(p[ukur]) + '</b>';
      li.onclick = () => lapisan.eachLayer(l => { if (l.feature.properties.kode === p.kode) { peta.fitBounds(l.getBounds(), { maxZoom: tingkat === 'desa' ? 14 : 11 }); l.openPopup(); } });
      ul.appendChild(li);
    });
  }

  // ---- titik perkiraan lokasi (hanya admin/operator) ----
  const BOLEH_TITIK = @json($bolehTitik);
  const WARNA_TITIK = { titik: '#0f766e', desa: '#2563eb', kecamatan: '#7c3aed', kabkota: '#6b7280' };
  const LABEL_TITIK = { titik: 'alamat/jalan', desa: 'desa/dusun', kecamatan: 'kecamatan', kabkota: 'kab/kota' };
  let titikData = [], lapTitik = L.layerGroup().addTo(peta);
  function gambarTitik() {
    lapTitik.clearLayers();
    if (!BOLEH_TITIK || !$('titik').checked) return;
    const semua = $('titikKab').checked, kb = $('kabPilih').value;
    titikData.filter(t => (semua || t.ketelitian !== 'kabkota') && (!kb || t.kode_kab === kb)).forEach(t => {
      const m = L.circleMarker([t.lat, t.lon], {
        radius: Math.min(22, 4 + 3 * Math.sqrt(t.pelanggar)), color: '#ffffff', weight: 1.8,
        fillColor: WARNA_TITIK[t.ketelitian] || '#6b7280', fillOpacity: .88
      });
      m.bindTooltip(esc(t.kab) + ': ' + nf.format(t.pelanggar) + ' pelanggar (perkiraan)', { sticky: true });
      m.bindPopup('<b>' + esc(t.kab) + '</b><br>' + nf.format(t.pelanggar) + ' pelanggar · ' + nf.format(t.operasi) + ' operasi<br>' +
        nf.format(t.batang) + ' batang<br><small>Perkiraan setingkat <b>' + esc(LABEL_TITIK[t.ketelitian] || t.ketelitian) +
        '</b> dari alamat; bukan titik GPS.</small>');
      m.addTo(lapTitik);
    });
  }
  async function muatTitik(t) {
    if (!BOLEH_TITIK) return;
    try {
      const res = await fetch('{{ route('api.titik') }}' + (t ? '?tahun=' + encodeURIComponent(t) : ''), { headers: { Accept: 'application/json' } });
      titikData = res.ok ? await res.json() : [];
    } catch (e) { titikData = []; }
    gambarTitik();
  }

  async function ambil(url) {
    const res = await fetch(url, { headers: { Accept: 'application/json' } });
    const j = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(j.galat || 'Gagal memuat data peta.');
    return j;
  }
  function atur() {   // menyesuaikan pilihan menurut tingkat
    const tingkat = $('tingkat').value;
    const opsiOperasi = $('ukur').querySelector('option[value=operasi]');
    opsiOperasi.disabled = tingkat !== 'kab';
    if (tingkat !== 'kab' && $('ukur').value === 'operasi') $('ukur').value = 'pelanggar';
  }

  async function muat(fokus) {
    atur();
    const tingkat = $('tingkat').value, kb = $('kabPilih').value, t = $('tahun').value, saya = ++urutMuat;
    $('galat').style.display = 'none'; $('info').style.display = 'none';
    muatTitik(t);
    try {
      if (!kabGeo) kabGeo = (await ambil('{{ route('api.kabkota') }}')).features;
      if (tingkat === 'desa' && !kb) {
        if (lapisan) { lapisan.remove(); lapisan = null; }
        if (lapKab) { lapKab.remove(); lapKab = null; }
        data = null; $('teratas').innerHTML = ''; $('catatan').textContent = '';
        ['s-pelanggar', 's-batang', 's-operasi', 's-kab'].forEach(i => $(i).textContent = '–');
        $('info').textContent = 'Pilih satu kab/kota untuk melihat desa/kelurahan (satu provinsi sekaligus terlalu berat untuk peta).';
        $('info').style.display = 'block';
        return;
      }
      const q = new URLSearchParams();
      if (t) q.set('tahun', t);
      let j;
      if (tingkat === 'kab') { j = await ambil('{{ route('api.kabkota') }}' + (q.toString() ? '?' + q : '')); j.tingkat = 'kab'; }
      else { q.set('tingkat', tingkat); if (kb) q.set('kab', kb); j = await ambil('{{ route('api.wilayah') }}?' + q); }
      if (saya !== urutMuat) return;   // ada permintaan yang lebih baru
      data = j; gambar(fokus);
    } catch (e) { $('galat').textContent = e.message; $('galat').style.display = 'block'; }
  }

  if (BOLEH_TITIK) { $('titik').addEventListener('change', gambarTitik); $('titikKab').addEventListener('change', gambarTitik); }
  $('tahun').addEventListener('change', () => muat(false));
  $('tingkat').addEventListener('change', () => muat(true));
  $('kabPilih').addEventListener('change', () => { gambarTitik(); muat(true); });
  $('ukur').addEventListener('change', () => data && gambar(false));
  $('dasar').addEventListener('change', e => e.target.checked ? dasar.addTo(peta) : dasar.remove());
  peta.on('popupopen', e => {   // tombol penelusuran di dalam popup
    e.popup.getElement().querySelectorAll('button[data-lv]').forEach(b => b.addEventListener('click', () => {
      $('tingkat').value = b.dataset.lv; $('kabPilih').value = b.dataset.kab; peta.closePopup(); gambarTitik(); muat(true);
    }));
  });
  muat(true);
})();
</script>
@endpush
