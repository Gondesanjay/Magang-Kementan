<?php


namespace App\Http\Middleware;


use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;


/**
 * Mengeluarkan (logout) pegawai yang statusnya diubah Admin HR menjadi
 * non-Aktif (Pensiun, Mutasi Keluar, Resign / Berhenti, Meninggal Dunia)
 * saat sesinya MASIH berjalan.
 *
 * Tanpa middleware ini, pegawai yang sudah login tetap bisa memakai aplikasi
 * sampai sesinya habis, walaupun statusnya sudah bukan Aktif. Pengecekan saat
 * login sendiri ada di LoginRequest::authenticate().
 *
 * Auth::user() dimuat ulang dari database di setiap request, jadi perubahan
 * status oleh admin langsung berlaku pada klik berikutnya si pegawai.
 * Status kosong (NULL) dianggap 'Aktif' agar pegawai lama tidak ikut terkunci.
 */
class CekStatusAktif
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();


        if ($user) {
            $status = $user->status_aktif ?: 'Aktif';


            if ($status !== 'Aktif') {
                Auth::guard('web')->logout();


                $request->session()->invalidate();
                $request->session()->regenerateToken();


                return redirect()
                    ->route('login')
                    ->withErrors([
                        'nip' => "Akun Anda berstatus {$status} dan tidak dapat digunakan lagi. Silakan hubungi Admin HR.",
                    ]);
            }
        }


        return $next($request);
    }
}
