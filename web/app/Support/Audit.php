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
}
