<?php


namespace App\Http\Controllers;


use App\Models\ApprovalLog;
use App\Models\CutiDitangguhkan;
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


    // Menampilkan daftar cuti yang bisa ditangguhkan (di departemen yang sama)
    //
    // ---> PERBAIKAN <---
    // Sesuai aturan terbaru: L4 boleh menangguhkan cuti di SEMUA status
    // proses approval (menunggu_l1, menunggu_l2, menunggu_l3, menunggu_l4)
    // maupun yang sudah disetujui penuh ('disetujui'). Penangguhan HANYA
    // tidak diperbolehkan untuk cuti yang statusnya 'ditolak',
    // 'dibatalkan_reguler', atau 'dibatalkan_ditangguhkan' (sudah pernah
    // ditangguhkan sebelumnya, tidak boleh ditangguhkan dua kali).
    public function index(Request $request)
    {
        $search = $request->input('search');


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
            ->latest()
            ->get();


        return Inertia::render('Atasan/PembatalanCuti', [
            'daftarCuti' => $daftarCuti,
            'filters'    => $request->only(['search']),
        ]);
    }


    // Memproses Eksekusi Penangguhan oleh L4
    //
    // ---> PERBAIKAN <---
    // Ditambahkan validasi status di backend (bukan cuma mengandalkan
    // filter di index()), supaya permintaan penangguhan tetap DITOLAK
    // sekalipun ada yang mencoba mengirim request langsung ke endpoint ini
    // untuk pengajuan yang statusnya termasuk dalam
    // STATUS_TIDAK_BISA_DITANGGUHKAN (ditolak, dibatalkan_reguler, atau
    // sudah pernah ditangguhkan sebelumnya). Selain status-status itu,
    // termasuk menunggu_l1/l2/l3/l4 dan disetujui, penangguhan
    // diperbolehkan sesuai aturan terbaru.
    //
    // ---> PERBAIKAN LANJUTAN (menghapus batasan kelompok_substansi) <---
    // SEBELUMNYA method ini mewajibkan kelompok_substansi pegawai pemilik
    // pengajuan SAMA PERSIS dengan kelompok_substansi user yang login,
    // dengan asumsi itu meniru batasan departemen yang ada di index().
    // INI KELIRU: L4 (Kepala Biro, role_id 6) adalah jabatan yang
    // membawahi SELURUH kelompok substansi di dalam Biro, bukan anggota
    // dari satu kelompok substansi tertentu — persis seperti yang berlaku
    // di MonitoringCutiController::process(), di mana batasan
    // "harus satu departemen/kelompok" HANYA diterapkan untuk L1 (role_id
    // 2, dibatasi ke tim_kerja miliknya) dan L2 (role_id 3, dibatasi ke
    // kelompok_substansi miliknya) — L3 (role_id 4) dan L4 (role_id 6)
    // SENGAJA tidak dibatasi kelompok_substansi, karena wewenangnya lintas
    // unit. Karena kelompok_substansi milik Kepala Biro pada praktiknya
    // TIDAK SAMA dengan kelompok_substansi staf mana pun, pengecekan lama
    // ini membuat SETIAP permintaan penangguhan oleh L4 selalu gagal
    // (redirect balik dengan flash 'error', tanpa status yang benar-benar
    // berubah) — inilah sebab utama kenapa penangguhan tidak pernah
    // "nyambung" ke halaman Riwayat Pengajuan karyawan. Pengecekan
    // kelompok_substansi ini DIHAPUS; cukup abort_unless(role_id === 6) di
    // atas yang menjaga bahwa hanya Kepala Biro yang bisa mengeksekusi aksi
    // ini, sama seperti index() yang juga tidak membatasi kelompok
    // substansi untuk L4.
    public function process(Request $request, $id)
    {
        $user = auth()->user();


        abort_unless($user->role_id === 6, 403, 'Hanya L4 yang dapat menangguhkan cuti.');


        $request->validate([
            'alasan' => ['required', 'string', 'max:255']
        ]);


        $pengajuan = PengajuanCuti::with('pegawai')->findOrFail($id);


        // Jaga-jaga di level backend: status yang termasuk
        // STATUS_TIDAK_BISA_DITANGGUHKAN (ditolak, dibatalkan_reguler,
        // atau sudah pernah ditangguhkan sebelumnya) ditolak di sini.
        // Status lain — menunggu_l1/l2/l3/l4 maupun disetujui — tetap
        // diperbolehkan sesuai aturan terbaru.
        if (in_array($pengajuan->status, self::STATUS_TIDAK_BISA_DITANGGUHKAN, true)) {
            return back()->with('error', 'Cuti ini sudah ditolak atau sudah dibatalkan/ditangguhkan sebelumnya, sehingga tidak dapat ditangguhkan lagi.');
        }


        // Saldo cuti baru dipotong pada saat status mencapai 'disetujui'
        // (approval penuh L4). Jadi saldo HANYA dikembalikan jika status
        // pengajuan SEBELUM ditangguhkan adalah 'disetujui'. Untuk status
        // menunggu_l1/l2/l3/l4, saldo belum pernah dipotong sehingga tidak
        // perlu (dan tidak boleh) dikembalikan di sini.
        if ($pengajuan->status === 'disetujui') {
            $saldo = SaldoCuti::where('pegawai_id', $pengajuan->pegawai_id)
                ->where('tahun', date('Y', strtotime($pengajuan->tanggal_mulai)))
                ->first();


            if ($saldo) {
                $saldo->increment('sisa', $pengajuan->jumlah_hari);
            }
        }


        // Sisipkan alasan penangguhan ke dalam keterangan asli
        $keteranganBaru = $pengajuan->keterangan . ' | [DITANGGUHKAN/DIBATALKAN ATASAN: ' . $request->alasan . ']';


        // Ubah status pengajuan menjadi ditangguhkan
        $pengajuan->update([
            'status' => 'dibatalkan_ditangguhkan',
            'keterangan' => $keteranganBaru
        ]);


        // ====== catat aksi penangguhan ke approval_logs ======
        // level_approval memakai angka 5 sebagai PENANDA KHUSUS (bukan
        // bagian dari rantai L1-L4 biasa), supaya tidak bentrok dengan log
        // approval L4 yang sudah ada sebelumnya (kasus cuti yang sudah
        // disetujui penuh lalu baru ditangguhkan).
        ApprovalLog::create([
            'pengajuan_id' => $pengajuan->id,
            'approver_id' => $user->id,
            'level_approval' => 5,
            'keputusan' => 'tangguh',
            'catatan' => $request->alasan,
            'tanggal_keputusan' => now(),
        ]);


        // ================= [BARU] CATAT KE CUTI DITANGGUHKAN =================
        // Ini adalah SUMBER KEBENARAN untuk perhitungan carry-over saldo
        // cuti tahunan "Jalur Khusus (b)" (lihat GenerateSaldoCutiTahunBaru
        // & AdminController::hitungCarryForwardEligible()) — hari yang
        // tercatat di sini akan SELALU dibawa penuh ke tahun berikutnya,
        // tidak kena potongan maksimal 6 hari seperti sisa saldo biasa,
        // karena memang bukan "pegawai tidak sempat pakai" melainkan
        // "pegawai dilarang pakai oleh institusi" (ditangguhkan resmi
        // oleh Kepala Biro/L4).
        //
        // `updateOrCreate` dipakai (bukan `create`) supaya kalau ada
        // percobaan menangguhkan pengajuan yang sama dua kali (seharusnya
        // sudah dicegah oleh pengecekan status di atas, tapi ini jaga-jaga
        // tambahan), baris yang tercatat tetap satu, bukan dobel.
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
        // ================= [END BARU] =================


        return back()->with('success', 'Cuti berhasil ditangguhkan.');
    }
}
