<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        // 1. Validasi Manual yang ketat
        $request->validate([
            'nama' => ['required', 'string', 'max:255'],
            'foto_profil' => ['nullable', 'image', 'mimes:jpeg,png,jpg', 'max:2048'],
        ]);

        $pegawai = $request->user();
        $pegawai->nama = $request->nama;

        // 2. Jika ada file foto yang dikirim, simpan!
        if ($request->hasFile('foto_profil')) {
            // Hapus fisik foto lama agar storage tidak penuh
            if ($pegawai->foto_profil) {
                Storage::disk('public')->delete($pegawai->foto_profil);
            }

            // Simpan foto baru
            $file = $request->file('foto_profil');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('profile-photos', $filename, 'public');

            $pegawai->foto_profil = $path; // Masukkan nama file ke objek pegawai
        }

        // 3. Simpan permanen ke database
        $pegawai->save();

        return Redirect::route('profile.edit');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();
        Auth::logout();
        $user->delete();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
