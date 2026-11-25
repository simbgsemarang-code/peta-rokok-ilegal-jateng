@extends('layouts.app')
@section('judul', 'Impor data operasi')
@section('isi')
@use('App\Support\Format')
<div class="kepala">
  <div>
    <h1>Impor data operasi cukai</h1>
    <p>Unggah berkas Excel hasil operasi. Datanya baru tampil di peta dan rekap setelah Anda menyimpan batch.</p>
  </div>
</div>

<div class="card">
  <h2>Unggah berkas baru</h2>
  <form method="post" action="{{ route('impor.unggah') }}" enctype="multipart/form-data">
    @csrf
    <div class="baris" style="grid-template-columns:minmax(260px,1fr) auto;align-items:end">
      <div>
        <label for="berkas">Berkas Excel (.xlsx / .xls, maks. 30 MB)</label>
        <input id="berkas" type="file" name="berkas" accept=".xlsx,.xls" required>
      </div>
      <button class="btn" type="submit">Baca dan tampilkan pratinjau</button>
    </div>
  </form>
  <details style="margin-top:6px">
    <summary>Format berkas yang dikenali</summary>
    <ul class="muted">
      <li><b>Gabungan</b> — sheet <code>DATA</code> seperti pada <i>DATA GABUNGAN OPERASI CUKAI 2023-2026.xlsx</i>: kolom Tahun, Kab/Kota, Tim, Tanggal, Tgl Mulai, Bulan, Pemilik, Toko, Alamat, Merk, Jumlah, Jenis, Operasi, Pelanggar, Sumber, Kecamatan.</li>
      <li><b>Rincian</b> — sheet <code>RINCIAN HASIL OPS</code> seperti pada rekap tahunan: No, Kab/Kota, Tanggal Pelaksanaan, Nama Pemilik, Nama Toko, Alamat, Merk, Jumlah (sel kosong berarti sama dengan baris di atasnya).</li>
      <li>Baris pembinaan/sosialisasi dicatat sebagai operasi, bukan pelanggar, sama seperti rekap Excel.</li>
      <li>Mengunggah ulang berkas yang sama akan menggandakan data. Sistem memberi peringatan bila operasi tampak sudah ada; batalkan salah satu batch bila terjadi.</li>
    </ul>
  </details>
</div>

<div class="card tabel">
  <h2>Riwayat impor</h2>
  @if(empty($batch))
    <div class="kosong">Belum ada impor.</div>
  @else
  <table>
    <thead><tr><th>#</th><th>Berkas</th><th>Diunggah</th><th class="n">Operasi</th><th class="n">Pelanggar</th><th class="n">Ditolak</th><th>Status</th><th></th></tr></thead>
    <tbody>
      @foreach($batch as $b)
      <tr>
        <td>{{ $b->id }}</td>
        <td>{{ $b->nama_file }}</td>
        <td>{{ Format::tanggal($b->diunggah_pada) }}<br><small>{{ $b->pengunggah }}</small></td>
        <td class="n">{{ Format::angka($b->operasi) }}</td>
        <td class="n">{{ Format::angka($b->pelanggar) }}</td>
        <td class="n">{{ Format::angka($b->jumlah_ditolak) }}</td>
        <td><span class="badge {{ ['disimpan' => 'ok', 'pratinjau' => 'warn', 'dibatalkan' => 'bad'][$b->status] ?? '' }}">{{ $b->status }}</span></td>
        <td class="aksi">
          <a class="btn kecil abu" href="{{ route('impor.tampil', $b->id) }}">Lihat</a>
          @if($b->status !== 'dibatalkan')
          <form method="post" action="{{ route('impor.batal', $b->id) }}" onsubmit="return confirm('Batalkan batch #{{ $b->id }}? Datanya tidak lagi dihitung.')">@csrf<button class="btn kecil bahaya" type="submit">Batalkan</button></form>
          @endif
        </td>
      </tr>
      @endforeach
    </tbody>
  </table>
  @endif
</div>
@endsection
