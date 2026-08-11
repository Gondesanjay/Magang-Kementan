<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceChangePassword
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Cek apakah user sudah login dan kolom is_first_login bernilai true
        if (auth()->check() && auth()->user()->is_first_login) {

            // Jangan redirect jika user sudah berada di rute halaman ganti password
            // (untuk mencegah infinite redirect loop)
            if (!$request->routeIs('password.change')) {
                return redirect()->route('password.change');
            }
        }

        return $next($request);
    }
}
