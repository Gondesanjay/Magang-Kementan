<?php

namespace App\Http\Controllers;

use App\Models\FcmToken;
use Illuminate\Http\Request;

class FcmTokenController extends Controller
{
    // Simpan token perangkat. user_id SELALU dari sesi login, bukan dari request.
    // updateOrCreate berdasarkan token: kalau perangkat dipakai bergantian
    // (komputer bersama), token otomatis pindah ke user yang sedang login.
    public function store(Request $request)
    {
        $request->validate(['token' => ['required', 'string', 'max:500']]);

        FcmToken::updateOrCreate(
            ['token' => $request->token],
            ['user_id' => $request->user()->id]
        );

        return response()->json(['ok' => true]);
    }

    // Hapus token perangkat ini. Dipanggil frontend SEBELUM logout supaya
    // browser yang sudah logout tidak lagi menerima push milik user tersebut.
    // Hanya token milik user login yang bisa dihapus.
    public function destroy(Request $request)
    {
        $request->validate(['token' => ['required', 'string', 'max:500']]);

        FcmToken::where('token', $request->token)
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['ok' => true]);
    }
}
