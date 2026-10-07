<?php

namespace App\Http\Controllers;

use App\Models\ApprovalLog;
use App\Models\CutiDitangguhkan;
use App\Models\Pegawai;
use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PembatalanController extends Controller
{
    // Status yang TIDAK BOLEH ditangguhkan sama sekali, baik di index()
    // maupun di process(). Selain status-status ini (termasuk
    // menunggu_l1/l2/l3/l4 dan disetujui), cuti boleh ditangguhkan L4.
    private const STATUS_TIDAK_BISA_DITANGGUHKAN = [
        'ditolak',
        'dibatalkan_reguler',
        'dibatalkan_ditangguhkan',
    ];

    /**
     * Menampilkan daftar cuti yang bisa ditangguhkan.
     *
     * Aturan status: L4 boleh menangguhkan cuti di SEMUA status proses
     * approval (menunggu_l1..l4) maupun yang sudah 'disetujui'. Penangguhan
     * HANYA tidak diperbolehkan untuk 'ditolak', 'dibatalkan_reguler', atau
     * 'dibatalkan_ditangguhkan'.
     *
     * Filter kelompok memakai kolom `kelompok_substansi` di tabel pegawai,
     * sama seperti halaman Monitoring Cuti (RekapKuotaDetail). Tidak ada
     * lagi daftar NIP di Vue maupun di repo. Penyaringan dilakukan di
     * database, dan daftar opsi dropdown dikirim dari sini sebagai array
     * string ('daftarKelompokSubstansi').
     */
    public function index(Request $request)
    {
        $search = $request->input('search');
        $kelompok = $request->input('kelompok_substansi');

        $daftarCuti = PengajuanCuti::with('pegawai')
            ->whereIn('status', [
                'menunggu_l1',
                'menunggu_l2',
                'menunggu_l3',
                'menunggu_l4',
                'disetujui',
            ])
            ->when($search, function ($query) use ($search) {
                $query->whereHas('pegawai', function ($q) use ($search) {
                    $q->where('nama', 'like', "%{$search}%")
                        ->orWhere('nip', 'like', "%{$search}%");
                });
            })
            ->when($kelompok, function ($query) use ($kelompok) {
                $query->whereHas('pegawai', function ($q) use ($kelompok) {
                    $q->where('kelompok_substansi', $kelompok);
                });
            })
            ->latest()
            ->get();

        // Isi dropdown: nama kelompok unik dari data pegawai (sama dengan
        // Monitoring Cuti). Nilai kosong / '-' (mis. Kepala Biro) dibuang.
        $daftarKelompokSubstansi = Pegawai::query()
            ->whereNotNull('kelompok_substansi')
            ->where('kelompok_substansi', '!=', '')
            ->where('kelompok_substansi', '!=', '-')
            ->distinct()
            ->orderBy('kelompok_substansi')
            ->pluck('kelompok_substansi');

        return Inertia::render('Atasan/PembatalanCuti', [
            'daftarCuti' => $daftarCuti,
            'daftarKelompokSubstansi' => $daftarKelompokSubstansi,
            'filters' => $request->only(['search', 'kelompok_substansi']),
        ]);
    }

    /**
     * Memproses eksekusi penangguhan oleh L4.
     *
     * Validasi status dilakukan di backend (bukan cuma di index()), supaya
     * request langsung ke endpoint ini untuk pengajuan berstatus
     * STATUS_TIDAK_BISA_DITANGGUHKAN tetap ditolak.
     *
     * Tidak ada pembatasan kelompok_substansi: L4 (Kepala Biro, role_id 6)
     * membawahi seluruh kelompok, sehingga cukup abort_unless(role_id === 6).
     */
    public function process(Request $request, $id)
    {
        $user = auth()->user();

        abort_unless($user->role_id === 6, 403, 'Hanya L4 yang dapat menangguhkan cuti.');

        $request->validate([
            'alasan' => ['required', 'string', 'max:255'],
        ]);

        $pengajuan = PengajuanCuti::with('pegawai')->findOrFail($id);

        if (in_array($pengajuan->status, self::STATUS_TIDAK_BISA_DITANGGUHKAN, true)) {
            return back()->with(
                'error',
                'Cuti ini sudah ditolak atau sudah dibatalkan/ditangguhkan sebelumnya, sehingga tidak dapat ditangguhkan lagi.'
            );
        }

        // Saldo cuti baru dipotong saat status mencapai 'disetujui'. Jadi
        // saldo HANYA dikembalikan jika status SEBELUM ditangguhkan adalah
        // 'disetujui'. Untuk menunggu_l1..l4, saldo belum pernah dipotong.
        if ($pengajuan->status === 'disetujui') {
            $saldo = SaldoCuti::where('pegawai_id', $pengajuan->pegawai_id)
                ->where('tahun', date('Y', strtotime($pengajuan->tanggal_mulai)))
                ->first();

            if ($saldo) {
                $saldo->increment('sisa', $pengajuan->jumlah_hari);
            }
        }

        // Sisipkan alasan penangguhan ke dalam keterangan asli
        $keteranganBaru = $pengajuan->keterangan
            . ' | [DITANGGUHKAN/DIBATALKAN ATASAN: '
            . $request->alasan
            . ']';

        // Ubah status pengajuan menjadi ditangguhkan
        $pengajuan->update([
            'status' => 'dibatalkan_ditangguhkan',
            'keterangan' => $keteranganBaru,
        ]);

        // Catat aksi penangguhan ke approval_logs.
        // level_approval = 5 adalah PENANDA KHUSUS (bukan bagian dari rantai
        // L1-L4), supaya tidak bentrok dengan log approval L4 sebelumnya.
        ApprovalLog::create([
            'pengajuan_id' => $pengajuan->id,
            'approver_id' => $user->id,
            'level_approval' => 5,
            'keputusan' => 'tangguh',
            'catatan' => $request->alasan,
            'tanggal_keputusan' => now(),
        ]);

        // Catat ke cuti_ditangguhkan: sumber kebenaran carry-over saldo
        // tahunan "Jalur Khusus (b)" (GenerateSaldoCutiTahunBaru &
        // AdminController::hitungCarryForwardEligible()). Hari yang tercatat
        // di sini SELALU dibawa penuh ke tahun berikutnya.
        // updateOrCreate dipakai supaya tidak ada baris ganda.
        CutiDitangguhkan::updateOrCreate(
            ['pengajuan_asal_id' => $pengajuan->id],
            [
                'pegawai_id' => $pengajuan->pegawai_id,
                'jumlah_hari' => $pengajuan->jumlah_hari,
                'tahun_asal' => (int) date('Y', strtotime($pengajuan->tanggal_mulai)),
                'tahun_penggunaan' => null,
                'status_pakai' => 'belum_dipakai',
                'alasan_penangguhan' => $request->alasan,
            ]
        );

        return back()->with('success', 'Cuti berhasil ditangguhkan.');
    }
}
