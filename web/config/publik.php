<?php

/**
 * Pengaturan situs publik (beranda, informasi, dan data penindakan teragregat).
 * Nilai dapat diubah lewat berkas .env tanpa mengubah kode.
 */
return [
    'instansi' => env('PUBLIK_INSTANSI', 'Satpol PP Provinsi Jawa Tengah'),
    'unit' => env('PUBLIK_UNIT', 'Bidang Penegakan Peraturan Perundang-undangan Daerah (Gakkumda)'),

    // Data penindakan teragregat per kab/kota (jumlah operasi, pelaku, batang). Tanpa nama, alamat, dan titik lokasi.
    // Setel PUBLIK_TAMPILKAN_DATA=false untuk menyembunyikan halaman Data dan API-nya dari publik.
    'tampilkan_data' => filter_var(env('PUBLIK_TAMPILKAN_DATA', true), FILTER_VALIDATE_BOOLEAN),

    // Kanal layanan dan pengaduan resmi: isi dari .env. Bila kosong, halaman Kontak menampilkan penjelasan
    // bahwa kanal akan diumumkan instansi; aplikasi tidak mengarang nomor atau alamat.
    'kontak' => [
        'alamat' => env('PUBLIK_ALAMAT'),
        'telepon' => env('PUBLIK_TELEPON'),
        'whatsapp' => env('PUBLIK_WHATSAPP'),
        'email' => env('PUBLIK_EMAIL'),
        'situs' => env('PUBLIK_SITUS'),
        'jam_layanan' => env('PUBLIK_JAM_LAYANAN'),
        'tautan_pengaduan' => env('PUBLIK_TAUTAN_PENGADUAN'),
    ],
];
