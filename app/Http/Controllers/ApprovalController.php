<?php

namespace App\Http\Controllers;

use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use App\Models\Notifikasi;
use App\Models\Pegawai;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;

class ApprovalController extends Controller
{
    // 1. Menampilkan Daftar Antrean
    public function index()
    {
        $user = Auth::user();
        $query = PengajuanCuti::with('pegawai');

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

    private function isCutiTahunan($jenisCuti)
    {
        if ($jenisCuti === null || $jenisCuti === '') {
            return true;
        }

        return strtolower(trim((string) $jenisCuti)) === 'cuti tahunan';
    }

    // 2. Memproses Persetujuan atau Penolakan
    public function process(Request $request, $id)
    {
        $request->validate([
            'action' => ['required', 'in:approve,reject'],
        ]);

        $pengajuan = PengajuanCuti::with('pegawai')->findOrFail($id);
        $user = Auth::user();
        $targetUserId = $pengajuan->pegawai_id ?? $pengajuan->user_id;
        $infoCuti = $pengajuan->keterangan ?? 'Keperluan Cuti';

        // --- JIKA DITOLAK ---
        if ($request->action === 'reject') {
            $alasanPenolakan = $request->input('catatan', 'Ditolak oleh atasan');
            $keteranganLama = explode('|', $pengajuan->keterangan)[0];
            $keteranganBaru = trim($keteranganLama) . ' | ' . $alasanPenolakan;

            $pengajuan->update(['status' => 'ditolak', 'keterangan' => $keteranganBaru]);

            // Kirim notifikasi penolakan ke pegawai
            Notifikasi::create([
                'pegawai_id' => $targetUserId,
                'judul'      => 'Pengajuan Cuti Ditolak',
                'pesan'      => 'Pengajuan Anda ditolak oleh atasan. Alasan: ' . $alasanPenolakan,
                'is_read'    => false,
            ]);

            return back()->with('success', 'Pengajuan cuti berhasil ditolak.');
        }

        // --- JIKA DISETUJUI (LOGIKA BERJENJANG) ---
        $statusPesan = '';

        if ($pengajuan->status == 'menunggu_l1' && $user->role_id === 2) {
            $pengajuan->update(['status' => 'menunggu_l2', 'level_saat_ini' => 2, 'atasan_l1_id' => $user->id]);

            // Hapus notifikasi lama milik L1 agar tidak menumpuk
            Notifikasi::where('pegawai_id', $user->id)->where('judul', 'Pengajuan Cuti Baru')->delete();

            // Notifikasi ke L2 menggunakan format yang sama persis
            $atasanL2 = Pegawai::where('role_id', 3)->where('departemen', $pengajuan->pegawai->departemen)->first();
            if (!$atasanL2) {
                $atasanL2 = Pegawai::where('role_id', 3)->first();
            }

            if ($atasanL2) {
                Notifikasi::create([
                    'pegawai_id' => $atasanL2->id,
                    'judul'      => 'Pengajuan Cuti Baru',
                    'pesan'      => 'Ada pengajuan cuti dari ' . $pengajuan->pegawai->nama . ' yang butuh persetujuan Anda.',
                    'tautan'     => route('atasan.approval'),
                    'is_read'    => false,
                ]);
            }
            $statusPesan = 'Disetujui Atasan Langsung (L1) - Menunggu Kasubag TU (L2)';
        } elseif ($pengajuan->status == 'menunggu_l2' && $user->role_id === 3) {
            $pengajuan->update(['status' => 'menunggu_l3', 'level_saat_ini' => 3]);

            // Hapus notifikasi lama milik L2
            Notifikasi::where('pegawai_id', $user->id)->where('judul', 'Pengajuan Cuti Baru')->delete();

            // Notifikasi ke L3 menggunakan format yang sama persis
            $atasanL3 = Pegawai::where('role_id', 4)->where('departemen', $pengajuan->pegawai->departemen)->first();
            if (!$atasanL3) {
                $atasanL3 = Pegawai::where('role_id', 4)->first();
            }

            if ($atasanL3) {
                Notifikasi::create([
                    'pegawai_id' => $atasanL3->id,
                    'judul'      => 'Pengajuan Cuti Baru',
                    'pesan'      => 'Ada pengajuan cuti dari ' . $pengajuan->pegawai->nama . ' yang butuh persetujuan Anda.',
                    'tautan'     => route('atasan.approval'),
                    'is_read'    => false,
                ]);
            }
            $statusPesan = 'Disetujui Kasubag TU (L2) - Menunggu Kepala Biro Perencanaan (L3)';
        } elseif ($pengajuan->status == 'menunggu_l3' && $user->role_id === 4) {
            $pengajuan->update(['status' => 'disetujui', 'atasan_l3_id' => $user->id]);

            // Hapus notifikasi lama milik L3
            Notifikasi::where('pegawai_id', $user->id)->where('judul', 'Pengajuan Cuti Baru')->delete();

            // Potong saldo HANYA untuk jenis cuti tahunan
            if ($this->isCutiTahunan($pengajuan->jenis_cuti)) {
                $saldo = SaldoCuti::where('pegawai_id', $targetUserId)->where('tahun', date('Y', strtotime($pengajuan->tanggal_mulai)))->first();
                if ($saldo) {
                    $saldo->decrement('sisa', $pengajuan->jumlah_hari);
                }
            }

            $statusPesan = 'Disetujui Kepala Biro Perencanaan (L3 / Final)';
        }

        // Kirim Notifikasi ke Pegawai
        if (!empty($statusPesan)) {
            Notifikasi::create([
                'pegawai_id' => $targetUserId,
                'judul'      => 'Status Cuti Diperbarui',
                'pesan'      => $statusPesan,
                'is_read'    => false,
            ]);
        }

        return back()->with('success', 'Cuti berhasil disetujui.');
    }

    public function approve(Request $request, $id)
    {
        $request->merge(['action' => 'approve']);
        return $this->process($request, $id);
    }

    public function reject(Request $request, $id)
    {
        $request->merge(['action' => 'reject']);
        return $this->process($request, $id);
    }

    // 3. Menampilkan Riwayat Approval (Opsi B)
    public function history(Request $request)
    {
        $user = Auth::user();
        $query = PengajuanCuti::with('pegawai');

        // Logika filter riwayat: Tampilkan data yang SUDAH melewati tahap/level atasan tersebut
        if ($user->role_id === 2) {
            // Atasan L1: Menampilkan cuti staf di departemennya yang statusnya sudah bukan 'menunggu_l1'
            $query->whereHas('pegawai', function ($q) use ($user) {
                $q->where('departemen', $user->departemen);
            })->whereNotIn('status', ['menunggu_l1']);
        } elseif ($user->role_id === 3) {
            // L2: Menampilkan cuti yang sudah diproses L2 (sudah di tahap L3, disetujui, atau ditolak)
            $query->whereNotIn('status', ['menunggu_l1', 'menunggu_l2']);
        } elseif ($user->role_id === 4) {
            // L3: Hanya menampilkan cuti yang sudah final (disetujui atau ditolak)
            $query->whereIn('status', ['disetujui', 'ditolak']);
        } else {
            $query->where('id', 0);
        }

        // Fitur pencarian nama pegawai
        if ($request->filled('search')) {
            $query->whereHas('pegawai', function ($q) use ($request) {
                $q->where('nama', 'like', '%' . $request->search . '%');
            });
        }

        $riwayat = $query->orderBy('updated_at', 'desc')->paginate(10)->withQueryString();

        return Inertia::render('Atasan/RiwayatApproval', [
            'riwayat' => $riwayat,
            'filters' => $request->only(['search'])
        ]);
    }
}
