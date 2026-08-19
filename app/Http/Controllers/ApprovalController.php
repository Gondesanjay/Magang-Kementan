<?php

namespace App\Http\Controllers;

use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use App\Models\Notifikasi;
use App\Models\Pegawai;
use App\Models\ApprovalLog;
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

        if ($user->role_id === 2) { // L1 (Ketua Tim)
            $query->where('status', 'menunggu_l1')
                ->whereHas('pegawai', function ($q) use ($user) {
                    $q->where('departemen', $user->departemen);
                });
        } elseif ($user->role_id === 3) { // L2 (Ketua Kelompok)
            $query->where('status', 'menunggu_l2');
        } elseif ($user->role_id === 4) { // L3 (Kasubag TU)
            $query->where('status', 'menunggu_l3');
        } elseif ($user->role_id === 6) { // L4 (Kepala Biro Perencanaan - ROLE 6)
            $query->where('status', 'menunggu_l4');
        } else {
            $query->where('id', 0); // Cegah role lain melihat antrean
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
        $statusSaatIni = $pengajuan->status;
        $roleYangDibutuhkan = [
            'menunggu_l1' => 2,
            'menunggu_l2' => 3,
            'menunggu_l3' => 4,
            'menunggu_l4' => 6,
        ][$statusSaatIni] ?? null;

        if ($roleYangDibutuhkan === null || $user->role_id !== $roleYangDibutuhkan) {
            abort(403, 'Anda tidak berwenang memproses pengajuan pada tahap ini.');
        }

        if ($user->role_id === 2 && $pengajuan->pegawai->departemen !== $user->departemen) {
            abort(403, 'Pengajuan berada di luar departemen Anda.');
        }

        $levelApproval = [
            'menunggu_l1' => 1,
            'menunggu_l2' => 2,
            'menunggu_l3' => 3,
            'menunggu_l4' => 4,
        ][$statusSaatIni];

        // --- JIKA DITOLAK ---
        if ($request->action === 'reject') {
            $alasanPenolakan = $request->input('catatan', 'Ditolak oleh atasan');
            $keteranganLama = explode('|', $pengajuan->keterangan)[0];
            $keteranganBaru = trim($keteranganLama) . ' | ' . $alasanPenolakan;

            $pengajuan->update(['status' => 'ditolak', 'keterangan' => $keteranganBaru]);

            ApprovalLog::create([
                'pengajuan_id' => $pengajuan->id,
                'approver_id' => $user->id,
                'level_approval' => $levelApproval,
                'keputusan' => 'tolak',
                'catatan' => $alasanPenolakan,
                'tanggal_keputusan' => now(),
            ]);

            // Kirim notifikasi penolakan ke pegawai
            Notifikasi::create([
                'pegawai_id' => $targetUserId,
                'judul'      => 'Pengajuan Cuti Ditolak',
                'pesan'      => 'Pengajuan Anda ditolak oleh atasan. Alasan: ' . $alasanPenolakan,
                'is_read'    => false,
            ]);

            return back()->with('success', 'Pengajuan cuti berhasil ditolak.');
        }

        // --- JIKA DISETUJUI (LOGIKA ALUR BARU KEMENTAN) ---
        $statusPesan = '';

        // TAHAP 1: L1 MENGAPPROVE (Otomatis lompat L2, langsung ke L3)
        if ($statusSaatIni === 'menunggu_l1' && $user->role_id === 2) {
            // Hapus notifikasi lama milik L1 agar tidak menumpuk
            Notifikasi::where('pegawai_id', $user->id)->where('judul', 'Pengajuan Cuti Baru')->delete();

            // ALUR STAF: Langsung melompat ke L3 (Kasubag TU)
            $pengajuan->update(['status' => 'menunggu_l3', 'level_saat_ini' => 3, 'atasan_l1_id' => $user->id]);

            // Kirim Notifikasi ke L3 (Role 4)
            $atasanL3 = Pegawai::where('role_id', 4)->where('departemen', $pengajuan->pegawai->departemen)->first()
                ?? Pegawai::where('role_id', 4)->first();

            if ($atasanL3) {
                Notifikasi::create([
                    'pegawai_id' => $atasanL3->id,
                    'judul'      => 'Pengajuan Cuti Baru',
                    'pesan'      => 'Ada pengajuan cuti dari ' . $pengajuan->pegawai->nama . ' yang butuh persetujuan Anda.',
                    'tautan'     => route('atasan.approval'),
                    'is_read'    => false,
                ]);
            }
            $statusPesan = 'Disetujui Ketua Tim Kerja (L1) - Menunggu Kasubag TU (L3)';
        }

        // TAHAP 2: L2 MENGAPPROVE (Hanya terjadi jika L1 yang mengajukan cuti)
        elseif ($statusSaatIni === 'menunggu_l2' && $user->role_id === 3) {
            // Hapus notifikasi lama milik L2
            Notifikasi::where('pegawai_id', $user->id)->where('judul', 'Pengajuan Cuti Baru')->delete();

            // L2 Setuju -> Lanjut ke L3
            $pengajuan->update(['status' => 'menunggu_l3', 'level_saat_ini' => 3]);

            // Notifikasi ke L3 (Role 4)
            $atasanL3 = Pegawai::where('role_id', 4)->where('departemen', $pengajuan->pegawai->departemen)->first()
                ?? Pegawai::where('role_id', 4)->first();

            if ($atasanL3) {
                Notifikasi::create([
                    'pegawai_id' => $atasanL3->id,
                    'judul'      => 'Pengajuan Cuti Baru',
                    'pesan'      => 'Ada pengajuan cuti dari ' . $pengajuan->pegawai->nama . ' yang butuh persetujuan Anda.',
                    'tautan'     => route('atasan.approval'),
                    'is_read'    => false,
                ]);
            }
            $statusPesan = 'Disetujui Ketua Kelompok Substansi (L2) - Menunggu Kasubag TU (L3)';
        }

        // TAHAP 3: L3 MENGAPPROVE (Diteruskan ke L4)
        elseif ($statusSaatIni === 'menunggu_l3' && $user->role_id === 4) {
            // Hapus notifikasi lama milik L3
            Notifikasi::where('pegawai_id', $user->id)->where('judul', 'Pengajuan Cuti Baru')->delete();

            // L3 Setuju -> Lanjut ke L4
            $pengajuan->update(['status' => 'menunggu_l4', 'level_saat_ini' => 4, 'atasan_l3_id' => $user->id]);

            // Notifikasi ke L4 (KABIRO - ROLE 6)
            $atasanL4 = Pegawai::where('role_id', 6)->where('departemen', $pengajuan->pegawai->departemen)->first()
                ?? Pegawai::where('role_id', 6)->first();

            if ($atasanL4) {
                Notifikasi::create([
                    'pegawai_id' => $atasanL4->id,
                    'judul'      => 'Pengajuan Cuti Baru',
                    'pesan'      => 'Ada pengajuan cuti dari ' . $pengajuan->pegawai->nama . ' yang butuh persetujuan Anda.',
                    'tautan'     => route('atasan.approval'),
                    'is_read'    => false,
                ]);
            }
            $statusPesan = 'Disetujui Kasubag TU (L3) - Menunggu Kepala Biro Perencanaan (L4)';
        }

        // TAHAP 4: L4 MENGAPPROVE (FINAL)
        elseif ($statusSaatIni === 'menunggu_l4' && $user->role_id === 6) { // KABIRO = ROLE 6
            // Hapus notifikasi lama milik L4
            Notifikasi::where('pegawai_id', $user->id)->where('judul', 'Pengajuan Cuti Baru')->delete();

            // L4 Setuju -> Status FINAL (Disetujui)
            $pengajuan->update([
                'status' => 'disetujui',
                'level_saat_ini' => 6,
                'atasan_l4_id' => $user->id,
            ]);

            // Potong saldo HANYA untuk jenis cuti tahunan (Dieksekusi di akhir/final)
            if ($this->isCutiTahunan($pengajuan->jenis_cuti)) {
                $saldo = SaldoCuti::where('pegawai_id', $targetUserId)->where('tahun', date('Y', strtotime($pengajuan->tanggal_mulai)))->first();
                if ($saldo) {
                    $saldo->decrement('sisa', $pengajuan->jumlah_hari);
                }
            }

            $statusPesan = 'Disetujui Kepala Biro Perencanaan (L4)';
        }

        // Kirim Notifikasi Update Status ke Pegawai Pemohon
        if (!empty($statusPesan)) {
            ApprovalLog::create([
                'pengajuan_id' => $pengajuan->id,
                'approver_id' => $user->id,
                'level_approval' => $levelApproval,
                'keputusan' => 'setuju',
                'catatan' => null,
                'tanggal_keputusan' => now(),
            ]);

            Notifikasi::create([
                'pegawai_id' => $targetUserId,
                'judul'      => 'Status Cuti Diperbarui',
                'pesan'      => $statusPesan,
                'is_read'    => false,
            ]);
        }

        return back()->with('success', 'Pengajuan cuti berhasil diproses dan diteruskan.');
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

    // 3. Menampilkan Riwayat Approval
    public function history(Request $request)
    {
        $user = Auth::user();
        $query = PengajuanCuti::with('pegawai');

        // Logika filter riwayat: Tampilkan data yang SUDAH melewati tahap/level atasan tersebut
        if ($user->role_id === 2) {
            // Atasan L1: Menampilkan cuti di departemennya yang statusnya sudah bukan 'menunggu_l1'
            $query->whereHas('pegawai', function ($q) use ($user) {
                $q->where('departemen', $user->departemen);
            })->whereNotIn('status', ['menunggu_l1']);
        } elseif ($user->role_id === 3) {
            // L2: Menampilkan cuti yang sudah diproses L2
            $query->whereNotIn('status', ['menunggu_l1', 'menunggu_l2']);
        } elseif ($user->role_id === 4) {
            // L3: Menampilkan cuti yang sudah diproses L3
            $query->whereNotIn('status', ['menunggu_l1', 'menunggu_l2', 'menunggu_l3']);
        } elseif ($user->role_id === 6) { // L4 (ROLE 6)
            // L4: Hanya menampilkan cuti yang sudah final
            $query->whereIn('status', ['disetujui', 'ditolak', 'dibatalkan_reguler', 'dibatalkan_ditangguhkan']);
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
