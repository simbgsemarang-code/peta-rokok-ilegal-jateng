@extends('layouts.publik')
@section('judul', 'Tentang Pemetaan')
@section('deskripsi', 'Latar belakang, tujuan, dan cara kerja pemetaan peredaran rokok ilegal di Jawa Tengah oleh Satpol PP Provinsi Jawa Tengah.')
@section('isi')
<section class="judul-hal"><div class="wadah"><h1>Tentang pemetaan peredaran rokok ilegal</h1><p>Mengapa pemetaan ini dibuat, apa tujuannya, dan bagaimana datanya dikumpulkan dan dijaga.</p></div></section>
<div class="isi"><div class="wadah"><div class="teks">
  <h2>Latar belakang</h2>
  <p>Rokok polos, berpita cukai palsu, dan berpita bekas masih beredar di Jawa Tengah. Peredarannya mengurangi penerimaan cukai dan DBHCHT serta menekan industri hasil tembakau yang taat aturan. Penindakan selama ini cenderung bersifat sebagian-sebagian karena belum ada gambaran sistematis tentang wilayah rawan, jalur distribusi, dan besarnya kerugian.</p>
  <p>Karena itu {{ config('publik.instansi') }}, melalui {{ config('publik.unit') }}, melaksanakan kegiatan <b>Pembuatan Peta Peredaran Rokok Ilegal di Jawa Tengah</b> pada Tahun Anggaran 2026 dengan pendanaan DBHCHT.</p>

  <h2>Tujuan</h2>
  <ol>
    <li>Menganalisis pola, rantai pasok, dan model penjualan rokok ilegal.</li>
    <li>Memetakan zona rawan (<i>hotspot</i>) dan titik potensi peredaran di 35 kabupaten/kota.</li>
    <li>Memperkirakan potensi kerugian penerimaan negara dan daerah.</li>
    <li>Mengembangkan aplikasi peta berbasis web (SIG) bagi aparat penegak hukum.</li>
    <li>Menganalisis pengaruh kebijakan daerah terhadap penurunan peredaran.</li>
  </ol>

  <h2>Bagaimana pemetaan dilakukan</h2>
  <ul>
    <li><b>Data hasil operasi cukai 2023–2026.</b> Seluruh hasil operasi gabungan dihimpun dan dirapikan: kabupaten/kota, tanggal, merek, dan jumlah batang.</li>
    <li><b>Survei lapangan.</b> Dirancang secara berjenjang di 35 kabupaten/kota, mencakup pengamatan di tempat penjualan, wawancara pedagang, dan survei konsumen dewasa, agar gambaran peredaran tidak hanya bergantung pada tempat petugas menemukan pelanggaran.</li>
    <li><b>Analisis dan aplikasi.</b> Data dipetakan sampai tingkat desa/kelurahan, dianalisis untuk menemukan wilayah rawan, dan dipakai menyusun perkiraan kerugian serta rekomendasi penindakan bagi petugas.</li>
  </ul>
  <div class="kotak emas">
    <p><b>Cara membaca peta.</b> Peta penindakan menunjukkan <i>tempat petugas menemukan pelanggaran</i>, bukan seluruh peredaran. Wilayah dengan banyak temuan dapat berarti peredaran tinggi, atau karena operasi lebih sering dilakukan di sana. Angka kerugian bersifat perkiraan dan akan diperbarui setelah survei lapangan selesai.</p>
  </div>

  <h2>Data apa yang tampil untuk publik</h2>
  <p>Halaman publik hanya memuat <b>angka gabungan per kabupaten/kota</b>: jumlah operasi, jumlah pelaku yang ditemukan melanggar, dan jumlah batang yang disita. Nama pemilik, nama toko, alamat, dan titik lokasi <b>tidak</b> ditampilkan kepada publik. Peta rinci sampai desa/kelurahan hanya tersedia bagi petugas yang diberi akses.</p>

  <h2>Siapa yang terlibat</h2>
  <p>Kegiatan diselenggarakan oleh {{ config('publik.instansi') }} bersama konsultan pelaksana. Aplikasi dikelola dengan peran berbeda: admin yang memperbarui data, operator kabupaten/kota, dan penampil untuk aparat penegak hukum.</p>
  <p>
    @if(config('publik.tampilkan_data'))<a class="tbl" href="{{ route('data') }}">Lihat data penindakan</a> @endif
    <a class="tbl alt" href="{{ route('kontak') }}">Kontak &amp; pengaduan</a>
  </p>
</div></div></div>
@endsection
