<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Pemakaian: ->middleware('peran:admin') atau 'peran:admin,operator'. */
class PeranMiddleware
{
    public function handle(Request $request, Closure $next, string ...$peran): Response
    {
        $pengguna = $request->user();
        if (! $pengguna || ! in_array($pengguna->peran, $peran, true)) {
            abort(403, 'Anda tidak memiliki hak untuk membuka halaman ini.');
        }

        return $next($request);
    }
}
