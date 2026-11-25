<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class BuatPengguna extends Command
{
    protected $signature = 'pengguna:buat
        {email : Email untuk login}
        {nama : Nama lengkap}
        {--peran=admin : admin, operator, atau penampil}
        {--kab= : Kode kab/kota (mis. 33.20), wajib untuk operator}
        {--sandi= : Kata sandi (jika kosong, akan ditanyakan)}';

    protected $description = 'Membuat akun pengguna aplikasi (akun admin pertama dibuat dengan perintah ini)';

    public function handle(): int
    {
        $peran = $this->option('peran');
        if (! in_array($peran, ['admin', 'operator', 'penampil'], true)) {
            $this->error('Peran harus admin, operator, atau penampil.');

            return self::FAILURE;
        }
        $kab = $this->option('kab') ?: null;
        if ($peran === 'operator' && ! $kab) {
            $this->error('Operator wajib punya --kab (kode kab/kota, mis. 33.20).');

            return self::FAILURE;
        }
        if ($kab && ! DB::table('ref.kabkota')->where('kode', $kab)->exists()) {
            $this->error("Kode kab/kota {$kab} tidak ada di ref.kabkota.");

            return self::FAILURE;
        }
        if (DB::table('app.pengguna')->where('email', $this->argument('email'))->exists()) {
            $this->error('Email itu sudah terdaftar.');

            return self::FAILURE;
        }

        $sandi = $this->option('sandi') ?: $this->secret('Kata sandi (minimal 10 karakter)');
        if (! is_string($sandi) || mb_strlen($sandi) < 10) {
            $this->error('Kata sandi minimal 10 karakter.');

            return self::FAILURE;
        }

        DB::table('app.pengguna')->insert([
            'nama' => $this->argument('nama'),
            'email' => $this->argument('email'),
            'kata_sandi_hash' => Hash::make($sandi),
            'peran' => $peran,
            'kode_kab' => $kab,
        ]);
        $this->info("Akun {$peran} untuk {$this->argument('email')} dibuat.");

        return self::SUCCESS;
    }
}
