@extends('layouts.publik')
@section('judul', 'Beranda')
@section('isi')
@use('App\Support\Format')
<section class="hero">
  <div class="wadah grid">
    <div>
      <span class="lencana">{{ config('publik.nama') }} · Jawa Tengah · 35 kabupaten/kota · DBHCHT TA 2026</span>
      <h1>Bersama menjaga Jawa Tengah dari peredaran rokok ilegal</h1>
      <p class="lead">Informasi publik tentang upaya {{ config('publik.instansi') }} memetakan dan menindak peredaran rokok tanpa pita cukai, berpita palsu, atau berpita bekas, demi penerimaan daerah dan perlindungan pelaku usaha yang taat aturan.</p>
      <div class="aksi">
        @if(config('publik.tampilkan_data'))<a class="tbl terang" href="{{ route('data') }}">Lihat data penindakan</a>@endif
        <a class="tbl garis" href="{{ route('kenali') }}">Kenali rokok ilegal</a>
      </div>
    </div>
    @if(config('publik.tampilkan_data'))
    <div class="kartu-peta" id="kartuPeta">
      <h3>Hasil penindakan per kabupaten/kota</h3>
      <div class="muted kecil">Jumlah batang rokok ilegal yang disita{{ $ringkas['tahun_awal'] ? ', '.$ringkas['tahun_awal'].'–'.$ringkas['tahun_akhir'] : '' }}</div>
      <svg id="petaMini" viewBox="0 0 1000 640" role="img" aria-label="Peta Jawa Tengah menurut jumlah batang rokok ilegal yang disita per kabupaten/kota"></svg>
      <div class="tip" id="tipMini" aria-live="polite">Arahkan kursor ke wilayah.</div>
      <div class="skala"><span>sedikit</span><i style="background:#d9efec"></i><i style="background:#8fd0c9"></i><i style="background:#35a99f"></i><i style="background:#0f766e"></i><i style="background:#0a4b46"></i><span>banyak</span></div>
    </div>
    @endif
  </div>
</section>

<div class="wadah angka-wrap">
  <div class="angka-grid">
    <div class="angka"><b>{{ $ringkas['operasi'] ? Format::angka($ringkas['operasi']) : '–' }}</b><span>operasi penindakan dan pembinaan</span></div>
    <div class="angka"><b>{{ $ringkas['pelanggar'] ? Format::angka($ringkas['pelanggar']) : '–' }}</b><span>pelaku ditemukan melanggar</span></div>
    <div class="angka"><b>{{ $ringkas['batang'] ? Format::angka($ringkas['batang']) : '–' }}</b><span>batang rokok ilegal disita</span></div>
    <div class="angka"><b>{{ $ringkas['kab_bertemuan'] ?: '–' }} <small style="font-size:1rem;color:var(--muted)">dari 35</small></b><span>kabupaten/kota dengan temuan</span></div>
  </div>
  <p class="catatan-angka">
    @if($ringkas['mutakhir'])Data operasi {{ $ringkas['tahun_awal'] }}–{{ $ringkas['tahun_akhir'] }}, diperbarui {{ Format::tanggal($ringkas['mutakhir']) }}.@else Data penindakan belum tersedia.@endif
    Angka menunjukkan hasil penindakan, bukan seluruh peredaran rokok ilegal.
  </p>
</div>

<section class="bagian">
  <div class="wadah">
    <div class="kepala">
      <h2>Mengapa rokok ilegal merugikan kita semua</h2>
      <p>Rokok yang beredar tanpa melunasi cukai bukan hanya persoalan hukum. Dampaknya terasa pada pembangunan daerah, pelaku usaha, dan konsumen.</p>
    </div>
    <div class="grid3">
      <div class="kartu"><div class="ikon">₪</div><h3>Menggerus penerimaan negara dan daerah</h3><p>Cukai hasil tembakau yang tidak dibayar berarti berkurangnya penerimaan negara, termasuk bagian yang dibagikan ke daerah sebagai Dana Bagi Hasil Cukai Hasil Tembakau (DBHCHT).</p></div>
      <div class="kartu"><div class="ikon">⚖</div><h3>Merugikan pelaku usaha yang taat aturan</h3><p>Produsen dan pedagang yang membayar cukai bersaing dengan barang berharga lebih murah karena tidak membayar kewajiban. Persaingan yang tidak adil mengancam lapangan kerja.</p></div>
      <div class="kartu"><div class="ikon">✚</div><h3>Lolos dari pengawasan</h3><p>Rokok ilegal tidak melalui pengawasan pita cukai, sehingga konsumen tidak memiliki kepastian asal dan peredarannya sulit ditelusuri.</p></div>
    </div>
  </div>
</section>

<section class="bagian abu">
  <div class="wadah">
    <div class="kepala">
      <h2>Kenali lima jenis pelanggaran pita cukai</h2>
      <p>Rokok dikategorikan ilegal bila tidak dilekati pita cukai atau pita cukainya bermasalah.</p>
    </div>
    <div class="jenis">
      <div class="item"><span class="no">1</span><b>Polos</b><span>Tidak dilekati pita cukai sama sekali</span></div>
      <div class="item"><span class="no">2</span><b>Palsu</b><span>Pita cukai tiruan yang bukan buatan resmi</span></div>
      <div class="item"><span class="no">3</span><b>Bekas</b><span>Pita cukai bekas pakai yang dipakai ulang</span></div>
      <div class="item"><span class="no">4</span><b>Salah peruntukan</b><span>Pita untuk jenis, golongan, atau wilayah lain</span></div>
      <div class="item"><span class="no">5</span><b>Salah personalisasi</b><span>Pita untuk pabrik atau merek lain</span></div>
    </div>
    <p style="margin-top:22px"><a class="tbl alt kecil" href="{{ route('kenali') }}">Pelajari cara mengenalinya</a></p>
  </div>
</section>

<section class="bagian">
  <div class="wadah grid2">
    <div>
      <h2>Pemetaan berbasis data dan survei lapangan</h2>
      <p>{{ config('publik.unit') }} bersama konsultan pelaksana menyusun peta peredaran rokok ilegal di 35 kabupaten/kota. Pemetaan memadukan data hasil operasi cukai 2023–2026 dengan survei lapangan, untuk menemukan wilayah rawan, memperkirakan potensi kerugian DBHCHT, dan menyusun rekomendasi penindakan.</p>
      <p><a class="tbl" href="{{ route('tentang') }}">Tentang pemetaan ini</a></p>
    </div>
    <div class="kartu">
      <h3>Peran masyarakat</h3>
      <ol class="langkah" style="margin-top:14px">
        <li><div><b>Kenali.</b> Periksa pita cukai dengan cara 3D: dilihat, diraba, diterawang.</div></li>
        <li><div><b>Hindari.</b> Jangan membeli, menyimpan, atau menjual rokok yang mencurigakan.</div></li>
        <li><div><b>Laporkan.</b> Sampaikan informasi lewat kanal resmi di halaman <a href="{{ route('kontak') }}">Kontak &amp; Pengaduan</a>.</div></li>
      </ol>
    </div>
  </div>
</section>

<section class="bagian" style="padding-top:0">
  <div class="wadah">
    <div class="petugas">
      <div>
        <h2>Untuk petugas dan pengelola data</h2>
        <p>Masuk untuk melihat peta sampai tingkat desa/kelurahan, rekap penindakan, estimasi kerugian, dan rekomendasi penindakan. Akses diberikan oleh admin sesuai peran.</p>
      </div>
      @auth
        <a class="tbl terang" href="{{ route('peta') }}">Buka aplikasi</a>
      @else
        <a class="tbl terang" href="{{ route('login') }}">Masuk petugas</a>
      @endauth
    </div>
  </div>
</section>
@endsection

@if(config('publik.tampilkan_data'))
@push('skrip')
<script>
(function () {
  const nf = new Intl.NumberFormat('id-ID');
  const svg = document.getElementById('petaMini'), tip = document.getElementById('tipMini');
  const WARNA = ['#d9efec', '#8fd0c9', '#35a99f', '#0f766e', '#0a4b46'];
  fetch('{{ route('publik.kabkota') }}', { headers: { Accept: 'application/json' } }).then(r => r.json()).then(j => {
    const fit = j.features.filter(f => f.geometry);
    if (!fit.length) { document.getElementById('kartuPeta').style.display = 'none'; return; }
    // hanya daratan utama (tanpa Kepulauan Karimunjawa) agar peta mini proporsional
    const bagian = [];
    fit.forEach(f => {
      const g = f.geometry, polis = g.type === 'Polygon' ? [g.coordinates] : g.coordinates;
      polis.forEach(p => { const lats = p[0].map(c => c[1]); if (Math.max(...lats) < -6.2) bagian.push({ f, p }); });
    });
    let minX = 999, maxX = -999, minY = 999, maxY = -999;
    bagian.forEach(({ p }) => p[0].forEach(c => { minX = Math.min(minX, c[0]); maxX = Math.max(maxX, c[0]); minY = Math.min(minY, c[1]); maxY = Math.max(maxY, c[1]); }));
    const k = Math.cos(((minY + maxY) / 2) * Math.PI / 180), w = (maxX - minX) * k, h = maxY - minY, s = Math.min(1000 / w, 640 / h);
    const ox = (1000 - w * s) / 2, oy = (640 - h * s) / 2;
    const xy = c => (ox + (c[0] - minX) * k * s).toFixed(1) + ',' + (oy + (maxY - c[1]) * s).toFixed(1);
    const nilai = fit.map(f => f.properties.batang).filter(v => v > 0).sort((a, b) => a - b);
    const batas = [1, 2, 3, 4].map(i => nilai[Math.min(nilai.length - 1, Math.floor(i * nilai.length / 5))] || 0);
    const warna = v => { if (!v) return '#eef1f6'; let i = 0; while (i < 4 && v > batas[i]) i++; return WARNA[i]; };
    const perKab = {};
    bagian.forEach(({ f, p }) => { (perKab[f.properties.kode] = perKab[f.properties.kode] || { f, d: '' }).d += p.map(r => 'M' + r.map(xy).join('L') + 'Z').join(''); });
    Object.values(perKab).forEach(({ f, d }) => {
      const el = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      el.setAttribute('d', d); el.setAttribute('fill', warna(f.properties.batang)); el.setAttribute('fill-rule', 'evenodd');
      const teks = f.properties.nama + ': ' + nf.format(f.properties.operasi) + ' operasi · ' + nf.format(f.properties.batang) + ' batang';
      el.addEventListener('mouseenter', () => tip.textContent = teks); el.addEventListener('focus', () => tip.textContent = teks);
      el.setAttribute('tabindex', '0'); el.setAttribute('aria-label', teks);
      svg.appendChild(el);
    });
  }).catch(() => { document.getElementById('kartuPeta').style.display = 'none'; });
})();
</script>
@endpush
@endif
