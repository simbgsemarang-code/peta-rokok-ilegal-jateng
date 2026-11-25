<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Mengeluarkan pengguna yang akunnya dinonaktifkan walau sesinya masih terbuka. */
class PastikanAktif
{
    public function handle(Request $request, Closure $next): Response
    {
        $pengguna = $request->user();
        if ($pengguna && ! $pengguna->aktif) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Akun Anda dinonaktifkan. Hubungi admin.']);
        }

        return $next($request);
    }
}
