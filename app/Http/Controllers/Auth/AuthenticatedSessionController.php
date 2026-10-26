<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\FcmToken; // BARU
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     *
     * Inertia::location() memaksa browser melakukan hard-reload ke halaman
     * login (untuk request Inertia dikirim respons 409 + X-Inertia-Location,
     * untuk request biasa menjadi redirect normal). Ini membuang cache
     * halaman SPA di memori JavaScript, sehingga tombol "Back" setelah logout
     * tidak lagi menampilkan halaman lama.
     *
     * Return type diubah dari RedirectResponse ke SymfonyResponse karena
     * Inertia::location() bukan RedirectResponse.
     */
    public function destroy(Request $request): SymfonyResponse
    {
        // BARU: hapus token push milik perangkat INI sebelum sesi ditutup,
        // supaya notifikasi akun ini tidak lagi muncul di browser yang sudah
        // logout (penting untuk komputer bersama). Token perangkat lain
        // milik pegawai yang sama tidak disentuh.
        // Gagal menghapus token tidak boleh menggagalkan logout.
        try {
            $token = $request->input('fcm_token');
            $pegawai = $request->user();

            if (is_string($token) && $token !== '' && $pegawai) {
                FcmToken::where('token', $token)
                    ->where('user_id', $pegawai->id)
                    ->delete();
            }
        } catch (\Throwable $e) {
            report($e);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return Inertia::location(route('login'));
    }
}
