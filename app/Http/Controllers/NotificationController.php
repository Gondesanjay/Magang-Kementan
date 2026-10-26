<?php

namespace App\Http\Controllers;

use App\Models\Notifikasi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    // Tandai semua notifikasi MILIK USER LOGIN sebagai sudah dibaca
    public function markAllAsRead(Request $request)
    {
        Notifikasi::where('pegawai_id', Auth::id())
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return back()->with('success', 'Semua notifikasi ditandai sudah dibaca.');
    }

    // Tandai satu notifikasi sebagai sudah dibaca.
    // Satu query atomik: filter pemilik ada DI DALAM query, jadi ID milik
    // orang lain tidak pernah tersentuh dan tidak ada yang bocor lewat
    // perbedaan respons (selalu redirect back()).
    public function markAsRead(string $id)
    {
        Notifikasi::where('pegawai_id', Auth::id())
            ->whereKey($id)
            ->update(['is_read' => true]);

        return back();
    }

    // Hapus satu notifikasi milik user login.
    // Tidak 404 kalau sudah terhapus otomatis oleh command.
    public function destroy(string $id)
    {
        Notifikasi::where('pegawai_id', Auth::id())
            ->whereKey($id)
            ->delete();

        return back();
    }

    // Hapus semua notifikasi milik user yang login
    public function destroyAll()
    {
        Notifikasi::where('pegawai_id', Auth::id())->delete();

        return back()->with('success', 'Semua notifikasi telah dihapus.');
    }
}
