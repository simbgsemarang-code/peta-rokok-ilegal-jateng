<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ImporController;
use App\Http\Controllers\KerugianController;
use App\Http\Controllers\MasterController;
use App\Http\Controllers\PengaturanController;
use App\Http\Controllers\PetaController;
use App\Http\Controllers\PublikController;
use App\Http\Controllers\RekapController;
use App\Http\Controllers\RekomendasiController;
use Illuminate\Support\Facades\Route;

// ---- Situs publik (tanpa login): hanya informasi dan angka teragregat per kab/kota ----
Route::get('/', [PublikController::class, 'beranda'])->name('beranda');
Route::get('/tentang', [PublikController::class, 'tentang'])->name('tentang');
Route::get('/kenali-rokok-ilegal', [PublikController::class, 'kenali'])->name('kenali');
Route::get('/data', [PublikController::class, 'data'])->name('data');
Route::get('/peta-publik', [PublikController::class, 'peta'])->name('peta.publik');
Route::get('/kontak', [PublikController::class, 'kontak'])->name('kontak');
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/data-publik/kabkota', [PublikController::class, 'kabkota'])->name('publik.kabkota');
    Route::get('/data-publik/sebaran', [PublikController::class, 'sebaran'])->name('publik.sebaran');
    Route::get('/data-publik/penindakan.csv', [PublikController::class, 'csv'])->name('publik.csv');
});

// ---- Masuk petugas ----
Route::middleware('guest')->group(function () {
    Route::get('/masuk', [AuthController::class, 'form'])->name('login');
    Route::post('/masuk', [AuthController::class, 'masuk'])->name('masuk');
});

// ---- Aplikasi (perlu login) ----
Route::middleware(['auth', 'aktif'])->group(function () {
    Route::post('/keluar', [AuthController::class, 'keluar'])->name('keluar');

    Route::get('/peta', [PetaController::class, 'index'])->name('peta');
    Route::get('/api/kabkota', [PetaController::class, 'kabkota'])->name('api.kabkota');
    Route::get('/api/titik', [PetaController::class, 'titik'])->name('api.titik');
    Route::get('/api/wilayah', [PetaController::class, 'wilayah'])->name('api.wilayah');
    Route::get('/rekap', [RekapController::class, 'index'])->name('rekap');
    Route::get('/kerugian', [KerugianController::class, 'index'])->name('kerugian');
    Route::get('/rekomendasi', [RekomendasiController::class, 'index'])->name('rekomendasi');
    Route::get('/rekomendasi/unduh', [RekomendasiController::class, 'unduh'])->name('rekomendasi.unduh');

    Route::middleware('peran:admin')->group(function () {
        Route::get('/impor', [ImporController::class, 'index'])->name('impor');
        Route::post('/impor', [ImporController::class, 'unggah'])->name('impor.unggah');
        Route::get('/impor/{id}', [ImporController::class, 'tampil'])->whereNumber('id')->name('impor.tampil');
        Route::post('/impor/{id}/simpan', [ImporController::class, 'simpan'])->whereNumber('id')->name('impor.simpan');
        Route::post('/impor/{id}/batal', [ImporController::class, 'batal'])->whereNumber('id')->name('impor.batal');

        Route::post('/kerugian/hitung', [KerugianController::class, 'hitung'])->name('kerugian.hitung');
        Route::post('/kerugian/{versi}/final', [KerugianController::class, 'final'])->whereNumber('versi')->name('kerugian.final');

        Route::post('/rekomendasi/{id}', [RekomendasiController::class, 'sunting'])->whereNumber('id')->name('rekomendasi.sunting');
        Route::post('/rekomendasi/{id}/status', [RekomendasiController::class, 'status'])->whereNumber('id')->name('rekomendasi.status');

        // Data Master: data operasi (sheet DATA gabungan) dan master dinamis (merek, tarif, surveyor, titik acuan)
        Route::get('/master', [MasterController::class, 'index'])->name('master');
        Route::get('/master/operasi/baru', [MasterController::class, 'operasiBaru'])->name('master.operasi.baru');
        Route::post('/master/operasi', [MasterController::class, 'operasiSimpan'])->name('master.operasi.simpan');
        Route::get('/master/operasi/{id}', [MasterController::class, 'operasiTampil'])->whereNumber('id')->name('master.operasi.tampil');
        Route::post('/master/operasi/{id}', [MasterController::class, 'operasiUbah'])->whereNumber('id')->name('master.operasi.ubah');
        Route::post('/master/operasi/{id}/aktif', [MasterController::class, 'operasiAktif'])->whereNumber('id')->name('master.operasi.aktif');
        Route::post('/master/operasi/{id}/hapus', [MasterController::class, 'operasiHapus'])->whereNumber('id')->name('master.operasi.hapus');
        Route::post('/master/operasi/{id}/pelanggar', [MasterController::class, 'pelanggarTambah'])->whereNumber('id')->name('master.pelanggar.tambah');
        Route::post('/master/pelanggar/{pid}', [MasterController::class, 'pelanggarUbah'])->whereNumber('pid')->name('master.pelanggar.ubah');
        Route::post('/master/pelanggar/{pid}/hapus', [MasterController::class, 'pelanggarHapus'])->whereNumber('pid')->name('master.pelanggar.hapus');
        Route::post('/master/{entitas}', [MasterController::class, 'simpan'])->where('entitas', 'merek|tarif|surveyor|poi')->name('master.simpan');
        Route::post('/master/{entitas}/{id}/hapus', [MasterController::class, 'hapus'])->where('entitas', 'merek|tarif|surveyor|poi')->name('master.hapus');

        Route::get('/pengaturan', [PengaturanController::class, 'index'])->name('pengaturan');
        Route::post('/pengaturan/bobot', [PengaturanController::class, 'simpanBobot'])->name('pengaturan.bobot');
        Route::post('/pengaturan/pendukung', [PengaturanController::class, 'simpanPendukung'])->name('pengaturan.pendukung');
        Route::post('/pengaturan/pengguna', [PengaturanController::class, 'simpanPengguna'])->name('pengaturan.pengguna');
        Route::post('/pengaturan/pengguna/{id}', [PengaturanController::class, 'ubahPengguna'])->whereNumber('id')->name('pengaturan.pengguna.ubah');
    });
});
