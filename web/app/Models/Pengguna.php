<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Pengguna aplikasi, disimpan di tabel app.pengguna (bukan tabel "users" bawaan Laravel).
 * Peran: admin, operator (terbatas pada satu kab/kota), penampil.
 */
class Pengguna extends Authenticatable
{
    protected $table = 'app.pengguna';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['kata_sandi_hash', 'totp_rahasia'];

    protected function casts(): array
    {
        return ['aktif' => 'boolean'];
    }

    public function getAuthPassword(): string
    {
        return $this->kata_sandi_hash;
    }

    // Tabel tidak punya kolom remember_token; nama kosong mematikan fitur "ingat saya".
    public function getRememberTokenName(): string
    {
        return '';
    }

    public function adalahAdmin(): bool
    {
        return $this->peran === 'admin';
    }

    public function labelPeran(): string
    {
        return match ($this->peran) {
            'admin' => 'Admin',
            'operator' => 'Operator kab/kota',
            default => 'Penampil',
        };
    }
}
