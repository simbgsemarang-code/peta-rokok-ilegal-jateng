<?php

namespace App\Support;

/** Format angka gaya Indonesia: titik sebagai pemisah ribuan, koma sebagai desimal. */
class Format
{
    public static function angka(null|int|float|string $v, int $desimal = 0): string
    {
        return $v === null || $v === '' ? '–' : number_format((float) $v, $desimal, ',', '.');
    }

    public static function persen(null|int|float|string $v, int $desimal = 1): string
    {
        return $v === null || $v === '' ? '–' : number_format((float) $v * 100, $desimal, ',', '.').'%';
    }

    public static function rupiah(null|int|float|string $v): string
    {
        if ($v === null || $v === '') {
            return '–';
        }
        $n = (float) $v;
        $abs = abs($n);
        if ($abs >= 1e12) {
            return 'Rp '.number_format($n / 1e12, 2, ',', '.').' triliun';
        }
        if ($abs >= 1e9) {
            return 'Rp '.number_format($n / 1e9, 2, ',', '.').' miliar';
        }
        if ($abs >= 1e6) {
            return 'Rp '.number_format($n / 1e6, 1, ',', '.').' juta';
        }

        return 'Rp '.number_format($n, 0, ',', '.');
    }

    public static function tanggal(null|string $v): string
    {
        return $v ? \Illuminate\Support\Carbon::parse($v)->timezone('Asia/Jakarta')->translatedFormat('j M Y H:i') : '–';
    }
}
