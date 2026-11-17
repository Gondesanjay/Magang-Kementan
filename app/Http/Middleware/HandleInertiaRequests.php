<?php

namespace App\Http\Middleware;

use App\Models\Notifikasi;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id'                 => $user->id,
                    'nip'                => $user->nip,
                    'nama'               => $user->nama,
                    'role_id'            => $user->role_id,
                    'foto_profil'        => $user->foto_profil,
                    'jabatan'            => $user->jabatan,
                    'kelompok_substansi' => $user->kelompok_substansi,
                    'tim_kerja'          => $user->tim_kerja,
                    'status_kepegawaian' => $user->status_kepegawaian,
                    'alamat_domisili'    => $user->alamat_domisili,
                    'no_telepon'         => $user->no_telepon,
                ] : null,
            ],
            // Flash message untuk pop-up session (sukses/error)
            'flash' => [
                'success' => fn() => $request->session()->get('success'),
                'error'   => fn() => $request->session()->get('error'),
            ],
            // Notifikasi: HANYA milik user login, 5 terbaru, dan HANYA kolom
            // yang dipakai frontend (pegawai_id & kolom internal tidak ikut).
            // Closure = baru dihitung saat dibutuhkan.
            // Kalau MainLayout.vue memakai kolom lain, tambahkan di get([...]).
            'notifikasis' => fn() => $user
                ? Notifikasi::where('pegawai_id', $user->id)
                ->latest()
                ->take(5)
                ->get(['id', 'judul', 'pesan', 'tautan', 'is_read', 'created_at'])
                : [],
        ];
    }
}
