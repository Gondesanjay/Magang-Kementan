<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'status' => session('status'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'nama' => ['required', 'string', 'max:255'],
            'alamat_domisili' => ['nullable', 'string', 'max:500'],
            'no_telepon' => ['nullable', 'string', 'max:15'],
            'foto_profil' => ['nullable', 'image', 'mimes:jpeg,png,jpg', 'max:2048'],
        ]);

        $pegawai = $request->user();
        $pegawai->nama = $request->nama;
        $pegawai->alamat_domisili = $request->alamat_domisili;

        // Hanya ubah no_telepon jika field dikirim (hindari menimpa null).
        if ($request->filled('no_telepon')) {
            $pegawai->no_telepon = $request->no_telepon;
        }

        if ($request->hasFile('foto_profil')) {
            if ($pegawai->foto_profil) {
                Storage::disk('public')->delete($pegawai->foto_profil);
            }

            $file = $request->file('foto_profil');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('profile-photos', $filename, 'public');

            $pegawai->foto_profil = $path;
        }

        $pegawai->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Penghapusan akun mandiri dinonaktifkan.
     * Data pegawai dikelola Admin HR (soft delete di Kelola Pegawai).
     * Route DELETE /profile juga sebaiknya dihapus di web.php.
     */
    public function destroy(Request $request): RedirectResponse
    {
        abort(403, 'Penghapusan akun tidak diizinkan. Hubungi Admin HR bila perlu nonaktifkan akun.');
    }
}
