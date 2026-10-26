<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Membatasi akses route berdasarkan role_id pegawai.
 *
 * Pemakaian di route:
 *   Route::middleware('role:5')->group(...);        // hanya Admin HR
 *   Route::middleware('role:2,3,4,6')->group(...);  // hanya atasan (L1-L4)
 *
 * Role: 1 Staf, 2 Ketua Tim (L1), 3 Ketua Kelompok (L2),
 *       4 Kasubag TU (L3), 5 Admin HR, 6 Kepala Biro (L4).
 *
 * Dipasang pada GRUP route, jadi request GET maupun POST/PUT/DELETE
 * (reset password, ubah role, impor, dll) sama-sama tertutup. Pengecekan
 * di controller tetap dibiarkan sebagai lapis kedua.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        // Cadangan: route ini seharusnya sudah di belakang middleware 'auth'.
        if (!$user) {
            return redirect()->route('login');
        }

        $diizinkan = array_map('intval', $roles);

        if (!in_array((int) $user->role_id, $diizinkan, true)) {
            abort(403, 'Anda tidak memiliki hak akses ke halaman ini.');
        }

        return $next($request);
    }
}
