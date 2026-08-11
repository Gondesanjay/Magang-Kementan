<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Notifikasi; // PERUBAHAN 1: Memanggil model yang benar
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    // 1. Fungsi untuk menandai SEMUA notifikasi milik user yang login sebagai 'read' (dibaca)
    public function markAllAsRead(Request $request)
    {
        $pegawaiId = Auth::id();

        Notifikasi::where('pegawai_id', $pegawaiId) // PERUBAHAN 2: user_id -> pegawai_id
            ->where('is_read', false)               // PERUBAHAN 3: read -> is_read
            ->update(['is_read' => true]);

        return back()->with('success', 'Semua notifikasi ditandai sudah dibaca.');
    }

    // 2. Fungsi untuk menandai SATU notifikasi tertentu saat diklik
    public function markAsRead(string $id)
    {
        $notifikasi = Notifikasi::findOrFail($id);

        // Pastikan yang mengubah adalah pemilik notifikasi tersebut
        if ($notifikasi->pegawai_id === Auth::id()) {
            $notifikasi->update(['is_read' => true]);
        }

        return back();
    }

    // 3. Fungsi untuk MENGHAPUS notifikasi (Sesuai dengan fungsi tombol silang/ceklis di UI)
    public function destroy(string $id)
    {
        $notifikasi = Notifikasi::findOrFail($id);

        if ($notifikasi->pegawai_id === Auth::id()) {
            $notifikasi->delete();
        }

        return back();
    }
}
