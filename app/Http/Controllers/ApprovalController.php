<?php

namespace App\Http\Controllers;

use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use App\Models\Notifikasi;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ApprovalController extends Controller
{
    // 1. Menampilkan Daftar Antrean
    public function index()
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        $query = PengajuanCuti::with('pegawai');

        // Filter data berdasarkan tingkatan Role Atasan
        if ($user->role_id === 2) {
            $query->where('status', 'menunggu_l1')
                ->whereHas('pegawai', function ($q) use ($user) {
                    $q->where('departemen', $user->departemen);
                });
        } elseif ($user->role_id === 3) {
            $query->where('status', 'menunggu_l2');
        } elseif ($user->role_id === 4) {
            $query->where('status', 'menunggu_l3');
        } else {
            $query->where('id', 0);
        }

        $antrean = $query->orderBy('created_at', 'asc')->get();

        return Inertia::render('Atasan/AntreanApproval', [
            'antrean' => $antrean
        ]);
    }

    // 2. Memproses Persetujuan atau Penolakan (Fungsi Utama)
    public function process(Request $request, $id)
    {
        $request->validate([
            'action' => ['required', 'in:approve,reject'],
        ]);

        $pengajuan = PengajuanCuti::with('pegawai')->findOrFail($id);
        $user = \Illuminate\Support\Facades\Auth::user();

        $targetUserId = $pengajuan->pegawai_id ?? $pengajuan->user_id;

        // Ambil keterangan cuti yang benar dari database
        $infoCuti = $pengajuan->keterangan ?? 'Keperluan Cuti';

        // Jika Ditolak
        if ($request->action === 'reject') {
            // Ambil catatan alasan yang diinput atasan lewat prompt (default jika kosong)
            $alasanPenolakan = $request->input('catatan', 'Ditolak oleh atasan');

            // Gabungkan alasan penolakan ke dalam kolom keterangan dengan pemisah '|'
            $keteranganLama = explode('|', $pengajuan->keterangan)[0];
            $keteranganBaru = trim($keteranganLama) . ' | ' . $alasanPenolakan;

            $pengajuan->update([
                'status' => 'ditolak',
                'keterangan' => $keteranganBaru
            ]);

            $namaAtasanDitolak = 'Atasan';
            if ($user->role_id === 2) {
                $namaAtasanDitolak = 'Atasan Langsung (L1)';
            } elseif ($user->role_id === 3) {
                $namaAtasanDitolak = 'Kasubag TU (L2)';
            } elseif ($user->role_id === 4) {
                $namaAtasanDitolak = 'Kepala Biro Perencanaan (L3)';
            }

            Notifikasi::create([
                'pegawai_id' => $targetUserId,
                'judul'      => 'Pengajuan Cuti: ' . $infoCuti,
                'pesan'      => 'Ditolak oleh ' . $namaAtasanDitolak . '. Alasan: ' . $alasanPenolakan,
                'is_read'    => false,
            ]);

            return back()->with('success', 'Pengajuan cuti berhasil ditolak.');
        }
        // Logika Persetujuan (Approve) dengan Format Sentence Case yang Elegan
        $statusPesan = '';

        if ($user->role_id === 2 && $pengajuan->status === 'menunggu_l1') {
            $pengajuan->update([
                'status' => 'menunggu_l2',
                'level_saat_ini' => 2,
                'atasan_l1_id' => $user->id
            ]);
            $statusPesan = 'Disetujui Atasan Langsung (L1) - Menunggu Kasubag TU (L2)';
        } elseif ($user->role_id === 3 && $pengajuan->status === 'menunggu_l2') {
            $pengajuan->update([
                'status' => 'menunggu_l3',
                'level_saat_ini' => 3
            ]);
            $statusPesan = 'Disetujui Kasubag TU (L2) - Menunggu Kepala Biro Perencanaan (L3)';
        } elseif ($user->role_id === 4 && $pengajuan->status === 'menunggu_l3') {
            $pengajuan->update([
                'status' => 'disetujui',
                'atasan_l3_id' => $user->id
            ]);
            $statusPesan = 'Disetujui Kepala Biro Perencanaan (L3 / Final)';

            // Pemotongan Saldo Cuti
            $saldo = SaldoCuti::where('pegawai_id', $targetUserId)
                ->where('tahun', date('Y', strtotime($pengajuan->tanggal_mulai)))
                ->first();
            if ($saldo) {
                $saldo->decrement('sisa', $pengajuan->jumlah_hari);
            }
        }

        // Kirim Notifikasi Disetujui
        if (!empty($statusPesan)) {
            Notifikasi::create([
                'pegawai_id' => $targetUserId,
                'judul'      => 'Pengajuan Cuti: ' . $infoCuti,
                'pesan'      => $statusPesan,
                'is_read'    => false,
            ]);
        }

        return back()->with('success', 'Cuti berhasil disetujui.');
    }

    // 3. Jembatan untuk Tombol "SETUJUI" di Dashboard (Quick Action)
    public function approve(Request $request, $id)
    {
        // Menyisipkan request 'action' => 'approve' lalu melemparkannya ke fungsi process()
        $request->merge(['action' => 'approve']);
        return $this->process($request, $id);
    }

    // 4. Jembatan untuk Tombol "TOLAK" di Dashboard (Quick Action)
    public function reject(Request $request, $id)
    {
        // Menyisipkan request 'action' => 'reject' lalu melemparkannya ke fungsi process()
        $request->merge(['action' => 'reject']);
        return $this->process($request, $id);
    }
}
