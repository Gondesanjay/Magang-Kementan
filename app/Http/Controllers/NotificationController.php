<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Notifikasi;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    // Tandai semua notifikasi sebagai sudah dibaca (titik hijau hilang, data tetap ada)
    public function markAllAsRead(Request $request)
    {
        Notifikasi::where('pegawai_id', Auth::id())
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return back()->with('success', 'Semua notifikasi ditandai sudah dibaca.');
    }

    // Tandai satu notifikasi sebagai sudah dibaca
    public function markAsRead(string $id)
    {
        $notifikasi = Notifikasi::find($id);

        if ($notifikasi && (int) $notifikasi->pegawai_id === (int) Auth::id()) {
            $notifikasi->update(['is_read' => true]);
        }

        return back();
    }

    // Hapus satu notifikasi
    // Pakai find() agar tidak 404 jika notifikasi sudah dihapus otomatis oleh command
    public function destroy(string $id)
    {
        $notifikasi = Notifikasi::find($id);

        if ($notifikasi && (int) $notifikasi->pegawai_id === (int) Auth::id()) {
            $notifikasi->delete();
        }

        return back();
    }

    // Hapus semua notifikasi milik user yang login
    public function destroyAll()
    {
        Notifikasi::where('pegawai_id', Auth::id())->delete();

        return back()->with('success', 'Semua notifikasi telah dihapus.');
    }
}
