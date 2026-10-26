<?php

namespace App\Http\Controllers;

use App\Models\PengajuanCuti;
use App\Models\Pegawai;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unduh lampiran pengajuan cuti dari disk private.
 * Hak akses: pemilik, atasan berwenang (L1/L2 scoped), L3, L4, Admin HR.
 * File dicari di disk local dulu, lalu public (lampiran lama sebelum migrasi).
 */
class LampiranController extends Controller
{
    public function unduh(int $id): StreamedResponse
    {
        /** @var Pegawai $user */
        $user = Auth::user();

        $pengajuan = PengajuanCuti::with('pegawai')->findOrFail($id);

        if (!$this->bolehAkses($user, $pengajuan)) {
            abort(403, 'Anda tidak diizinkan membuka lampiran ini.');
        }

        $path = $pengajuan->getRawOriginal('lampiran');
        if (empty($path)) {
            abort(404, 'Lampiran tidak ditemukan.');
        }

        foreach (['local', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return Storage::disk($disk)->response(
                    $path,
                    basename($path),
                    ['Content-Disposition' => 'inline; filename="' . basename($path) . '"']
                );
            }
        }

        abort(404, 'File lampiran tidak ditemukan di penyimpanan.');
    }

    /**
     * Aturan selaras dengan Monitoring Cuti:
     * - pemilik pengajuan
     * - role 5 (Admin HR), 4 (Kasubag TU), 6 (Kepala Biro): semua
     * - role 2 (L1): tim_kerja sama
     * - role 3 (L2): kelompok_substansi sama
     */
    private function bolehAkses(Pegawai $user, PengajuanCuti $pengajuan): bool
    {
        if ((int) $pengajuan->pegawai_id === (int) $user->id) {
            return true;
        }

        $role = (int) $user->role_id;

        if (in_array($role, [4, 5, 6], true)) {
            return true;
        }

        $pemohon = $pengajuan->pegawai;
        if (!$pemohon) {
            return false;
        }

        if ($role === 2) {
            $tim = trim((string) ($user->tim_kerja ?? ''));
            return $tim !== '' && $tim !== '-'
                && $tim === trim((string) ($pemohon->tim_kerja ?? ''));
        }

        if ($role === 3) {
            $kel = trim((string) ($user->kelompok_substansi ?? ''));
            return $kel !== ''
                && $kel === trim((string) ($pemohon->kelompok_substansi ?? ''));
        }

        return false;
    }
}
