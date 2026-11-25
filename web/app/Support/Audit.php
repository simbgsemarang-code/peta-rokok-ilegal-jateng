<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class Audit
{
    /**
     * Menjalankan perubahan data dalam satu transaksi dan menyetel app.pengguna_id,
     * sehingga trigger app.fn_audit() di basis data mencatat siapa pelakunya.
     */
    public static function jalankan(callable $kerja): mixed
    {
        return DB::transaction(function () use ($kerja) {
            $id = Auth::id();
            if ($id) {
                DB::statement("SELECT set_config('app.pengguna_id', ?, true)", [(string) $id]);
            }

            return $kerja();
        });
    }

    /**
     * Mencatat perubahan data operasi secara manual ke log audit. Dipakai untuk tabel penindakan yang tidak
     * memakai trigger (impor Excel memasukkan ribuan baris). Isi $lama/$baru JANGAN memuat data pribadi.
     */
    public static function catat(string $aksi, string $tabel, string|int|null $idBaris, ?array $lama = null, ?array $baru = null): void
    {
        DB::table('app.log_audit')->insert([
            'pengguna_id' => Auth::id(),
            'aksi' => $aksi,
            'tabel' => $tabel,
            'id_baris' => $idBaris === null ? null : (string) $idBaris,
            'data_lama' => $lama === null ? null : json_encode($lama, JSON_UNESCAPED_UNICODE),
            'data_baru' => $baru === null ? null : json_encode($baru, JSON_UNESCAPED_UNICODE),
        ]);
    }
}
