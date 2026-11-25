@extends('layouts.publik')
@section('judul', 'Kenali Rokok Ilegal')
@section('deskripsi', 'Jenis-jenis rokok ilegal, cara memeriksa pita cukai (3D), dampaknya, dasar hukum, dan tanya jawab.')
@section('isi')
<section class="judul-hal"><div class="wadah"><h1>Kenali rokok ilegal</h1><p>Jenis pelanggaran pita cukai, cara memeriksa keasliannya, dan apa yang bisa Anda lakukan.</p></div></section>
<div class="isi"><div class="wadah">
  <div class="teks" style="max-width:none">
    <h2>Lima jenis rokok ilegal</h2>
    <p>Rokok yang resmi dilekati pita cukai asli yang sesuai dengan jenis, golongan, dan pabriknya. Pelanggaran dikelompokkan sebagai berikut.</p>
  </div>
  <div class="tabel-wrap">
    <table class="tabel">
      <thead><tr><th>Jenis</th><th>Artinya</th><th>Hal yang perlu dicurigai</th></tr></thead>
      <tbody>
        <tr><td><b>Polos</b></td><td>Rokok tanpa pita cukai.</td><td>Bungkus tanpa pita; harga jauh di bawah rokok sejenis; merek yang tidak dikenal.</td></tr>
        <tr><td><b>Palsu</b></td><td>Pita cukai tiruan yang tidak dicetak secara resmi.</td><td>Warna dan cetakan pita kabur atau tidak sama dengan pita asli; unsur pengaman tidak tampak.</td></tr>
        <tr><td><b>Bekas</b></td><td>Pita cukai asli yang sudah pernah dipakai, lalu dipasang ulang.</td><td>Pita tampak sobek, bergeser, atau bekas lem; tidak menutup segel bungkus dengan rapi.</td></tr>
        <tr><td><b>Salah peruntukan</b></td><td>Pita asli, tetapi untuk jenis, golongan, atau wilayah lain.</td><td>Tulisan pada pita tidak sesuai jenis rokoknya (misalnya jenis atau tarif yang berbeda).</td></tr>
        <tr><td><b>Salah personalisasi</b></td><td>Pita asli milik pabrik atau merek lain.</td><td>Nama pabrik atau merek pada pita berbeda dengan nama pada bungkus.</td></tr>
      </tbody>
    </table>
  </div>

  <div class="teks" style="max-width:none">
    <h2>Periksa pita cukai dengan cara 3D</h2>
    <ol class="langkah">
      <li><div><b>Dilihat.</b> Pita menempel rapi pada bungkus, tidak sobek atau bergeser, cetakannya jelas, serta tulisan pada pita sesuai dengan jenis dan merek rokok.</div></li>
      <li><div><b>Diraba.</b> Pita asli dicetak dengan teknik khusus sehingga permukaannya terasa berbeda dari kertas biasa dan tidak licin seperti hasil cetak fotokopi atau cetak rumahan.</div></li>
      <li><div><b>Diterawang.</b> Bila pita diarahkan ke cahaya, tampak unsur pengaman pada kertas pita (misalnya tanda air dan benang pengaman).</div></li>
    </ol>
    <p class="muted kecil">Panduan lengkap beserta gambar contoh tersedia di situs resmi Direktorat Jenderal Bea dan Cukai (<a href="https://www.beacukai.go.id" rel="noopener noreferrer" target="_blank">beacukai.go.id</a>). Pemeriksaan visual tidak selalu menjamin keaslian; harga yang terlalu murah adalah tanda bahaya.</p>

    <h2>Dasar hukum</h2>
    <p>Pengaturan cukai berada pada Undang-Undang Nomor 11 Tahun 1995 tentang Cukai sebagaimana telah diubah dengan Undang-Undang Nomor 39 Tahun 2007. Undang-undang itu memuat ketentuan pidana, berupa pidana penjara dan/atau pidana denda yang dihitung berlipat dari nilai cukai, bagi pihak yang antara lain membuat, menawarkan, menjual, menyimpan, atau mengedarkan barang kena cukai yang tidak dilekati pita cukai, atau memalsukan dan menyalahgunakan pita cukai.</p>
    <div class="kotak"><p>Uraian di atas bersifat ringkasan edukatif. Besaran sanksi dan pasal yang berlaku harus dirujuk langsung ke naskah peraturan terbaru melalui Jaringan Dokumentasi dan Informasi Hukum.</p></div>

    <h2>Apa yang bisa dilakukan masyarakat</h2>
    <ul>
      <li>Biasakan memeriksa pita cukai sebelum membeli atau menjual rokok.</li>
      <li>Hindari rokok yang harganya jauh di bawah harga wajar atau tidak berpita.</li>
      <li>Pedagang: beli dari pemasok resmi dan simpan bukti pembelian.</li>
      <li>Bila menemukan peredaran atau gudang yang mencurigakan, laporkan lewat kanal resmi. Jangan bertindak sendiri.</li>
    </ul>
    <p><a class="tbl" href="{{ route('kontak') }}">Cara melapor</a></p>

    <h2>Tanya jawab</h2>
    <details class="tanya"><summary>Mengapa rokok ilegal lebih murah?</summary><p>Karena tidak membayar cukai (dan sering pula pajak lain), harganya dapat jauh lebih rendah. Selisih itulah yang menjadi kerugian penerimaan negara dan daerah.</p></details>
    <details class="tanya"><summary>Apa hubungannya dengan DBHCHT?</summary><p>Sebagian penerimaan cukai hasil tembakau dibagikan kepada daerah sebagai Dana Bagi Hasil Cukai Hasil Tembakau (DBHCHT). Dana ini digunakan antara lain untuk kesejahteraan masyarakat, kesehatan, dan penegakan ketentuan di bidang cukai. Cukai yang tidak dibayar berarti berkurangnya dana yang dapat kembali ke daerah.</p></details>
    <details class="tanya"><summary>Apakah pembeli akan dipidana?</summary><p>Ketentuan pidana dalam undang-undang cukai terutama menyasar pihak yang membuat, menawarkan, menjual, atau menyimpan untuk dijual. Meski demikian, membeli turut mendukung peredaran yang merugikan sehingga sebaiknya dihindari. Untuk kasus tertentu, rujuk peraturan dan petugas berwenang.</p></details>
    <details class="tanya"><summary>Rokok yang saya beli tampak berpita. Apakah pasti asli?</summary><p>Tidak selalu. Pita dapat palsu, bekas, atau salah peruntukan. Periksa dengan cara 3D, dan perhatikan kecocokan tulisan pada pita dengan jenis dan merek rokoknya.</p></details>
    <details class="tanya"><summary>Siapa yang melakukan penindakan?</summary><p>Penindakan dilakukan oleh aparat berwenang, termasuk Direktorat Jenderal Bea dan Cukai serta {{ config('publik.instansi') }} bersama instansi terkait dalam operasi gabungan di daerah.</p></details>
  </div>
</div></div>
@endsection
