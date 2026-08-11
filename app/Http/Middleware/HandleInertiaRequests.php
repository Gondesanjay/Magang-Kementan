<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
// PASTIKAN MENGGUNAKAN 'Notifikasi' BUKAN 'Notification'
use App\Models\Notifikasi;

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

        // Ambil data notifikasi jika user sudah login menggunakan model Notifikasi
        $notifikasis = $user
            ? Notifikasi::where('pegawai_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get()
            : [];

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id'          => $user->id,
                    'nip'         => $user->nip,
                    'nama'        => $user->nama,
                    'role_id'     => $user->role_id,
                    'foto_profil' => $user->foto_profil,
                    'jabatan'     => $user->jabatan,
                    'departemen'  => $user->departemen,
                ] : null,
            ],
            // Bagikan props notifikasi ke seluruh frontend Vue
            'notifikasis' => $notifikasis,
        ];
    }
}
