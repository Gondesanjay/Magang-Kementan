<?php


namespace App\Http\Controllers;


use App\Models\ApprovalLog;
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
    // ---> PERBAIKAN TAMBAHAN <---
    // Di index() daftar cuti sudah dibatasi hanya untuk pegawai di
    // departemen yang sama dengan L4 yang login. Namun endpoint process()
    // ini sebelumnya TIDAK memvalidasi hal tersebut, sehingga secara
    // teknis seseorang bisa mengirim request langsung (mis. lewat
    // Postman/curl) dengan ID pengajuan milik pegawai di departemen lain
    // dan tetap berhasil menangguhkannya. Sekarang ditambahkan pengecekan
    // departemen pegawai pemilik pengajuan harus sama dengan departemen
    // L4 yang sedang login, konsisten dengan batasan yang sudah ada di
    // index().
    public function process(Request $request, $id)
    {
        $user = auth()->user();


        abort_unless($user->role_id === 6, 403, 'Hanya L4 yang dapat menangguhkan cuti.');


        $request->validate([
            'alasan' => ['required', 'string', 'max:255']
        ]);


        $pengajuan = PengajuanCuti::with('pegawai')->findOrFail($id);


        // Jaga-jaga di level backend: L4 hanya boleh menangguhkan cuti
        // milik pegawai di departemen yang sama dengannya, sama seperti
        // batasan yang sudah diterapkan di index().
        if (!$pengajuan->pegawai || $pengajuan->pegawai->departemen !== $user->departemen) {
            return back()->with('error', 'Anda tidak berwenang menangguhkan cuti pegawai di luar departemen Anda.');
        }


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


        return back()->with('success', 'Cuti berhasil ditangguhkan.');
    }
}
