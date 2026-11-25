<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function form()
    {
        return view('masuk');
    }

    public function masuk(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'sandi' => ['required', 'string'],
        ], [
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak benar.',
            'sandi.required' => 'Kata sandi wajib diisi.',
        ]);

        $kunci = Str::lower($data['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($kunci, 5)) {
            $detik = RateLimiter::availableIn($kunci);

            return back()->withErrors(['email' => "Terlalu banyak percobaan. Coba lagi dalam {$detik} detik."])->onlyInput('email');
        }

        $berhasil = Auth::attempt([
            'email' => $data['email'],
            'password' => $data['sandi'],
            'aktif' => true,
        ]);

        if (! $berhasil) {
            RateLimiter::hit($kunci, 60);

            return back()->withErrors(['email' => 'Email atau kata sandi salah, atau akun tidak aktif.'])->onlyInput('email');
        }

        RateLimiter::clear($kunci);
        $request->session()->regenerate();

        return redirect()->intended(route('peta'));
    }

    public function keluar(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('beranda');
    }
}
