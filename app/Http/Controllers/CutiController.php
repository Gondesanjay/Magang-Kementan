<?php

namespace App\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use App\Models\Notifikasi;
use App\Models\Pegawai;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;

class CutiController extends Controller
{
    private function normalizeJenisCuti($value)
    {
        if ($value === null) {
            return 'Cuti Tahunan';
        }

        $normalized = trim((string) $value);

        $map = [
            'cuti tahunan' => 'Cuti Tahunan',
            'cuti melahirkan' => 'Cuti Melahirkan',
            'cuti besar' => 'Cuti Besar',
            'cuti alasan penting' => 'Cuti Alasan Penting',
            'cuti_alasan_penting' => 'Cuti Alasan Penting',
        ];

        $lower = strtolower($normalized);

        return $map[$lower] ?? $normalized;
    }

    // 1. Menampilkan Halaman Form Pengajuan
    public function create()
    {
        $saldo = SaldoCuti::where('pegawai_id', auth()->id())
            ->where('tahun', date('Y'))
            ->first();

        return Inertia::render('Karyawan/AjukanCuti', [
            'sisa_cuti' => $saldo ? $saldo->sisa : 0
        ]);
    }

    // 2. Memproses Simpan Data
    public function store(Request $request)
    {
        $request->validate([
            'jenis_cuti' => ['required', 'string', 'in:Cuti Tahunan,Cuti Melahirkan,Cuti Besar,Cuti Alasan Penting'],
            'tanggal_mulai' => ['required', 'date', 'after_or_equal:today'],
            'tanggal_selesai' => ['required', 'date', 'after_or_equal:tanggal_mulai'],
            'keterangan' => ['required', 'string'],
            'alamat_cuti' => ['required', 'string'],
            'no_telp' => ['required', 'string', 'max:20'],
        ]);

        $user = auth()->user();

        // Normalisasi jenis cuti
        $jenisCuti = $this->normalizeJenisCuti($request->jenis_cuti);

        // Hitung jumlah hari (sementara tetap menggunakan selisih hari kalender sesuai aslinya)
        $jumlah_hari = Carbon::parse($request->tanggal_mulai)->diffInDays(Carbon::parse($request->tanggal_selesai)) + 1;

        // =========================================================
        // MENENTUKAN STATUS AWAL & TARGET NOTIFIKASI (HIERARKI)
        // =========================================================
        $statusAwal = 'menunggu_l1'; // Default untuk staf
        $levelSaatIni = 1;
        $targetRoleNotifikasi = 2; // Default notif ke atasan L1

        if ($user->role_id === 2) { // Jika L1 yang mengajukan
            $statusAwal = 'menunggu_l2';
            $levelSaatIni = 2;
            $targetRoleNotifikasi = 3; // Kirim notif ke L2
        } elseif ($user->role_id === 3) { // Jika L2 yang mengajukan
            $statusAwal = 'menunggu_l3';
            $levelSaatIni = 3;
            $targetRoleNotifikasi = 4; // Kirim notif ke L3
        } elseif ($user->role_id === 4) { // Jika L3 yang mengajukan
            $statusAwal = 'menunggu_l4';
            $levelSaatIni = 4;
            $targetRoleNotifikasi = 6; // Kabiro/L4
        }

        // Simpan ke database
        $pengajuan = PengajuanCuti::create([
            'pegawai_id' => $user->id,
            'jenis_cuti' => $jenisCuti,
            'tanggal_mulai' => $request->tanggal_mulai,
            'tanggal_selesai' => $request->tanggal_selesai,
            'jumlah_hari' => $jumlah_hari,
            'keterangan' => $request->keterangan,
            'alamat_cuti' => $request->alamat_cuti,
            'no_telp' => $request->no_telp,
            'status' => $statusAwal, // Menggunakan status dinamis
            'level_saat_ini' => $levelSaatIni,
        ]);

        // =========================================================
        // PROSES TRIGGER NOTIFIKASI KE ATASAN YANG TEPAT
        // =========================================================
        // Cari Atasan target yang memiliki departemen yang SAMA dengan pegawai
        $atasanTarget = Pegawai::where('role_id', $targetRoleNotifikasi)
            ->where('departemen', $user->departemen)
            ->first();

        // Jika tidak ketemu berdasarkan departemen, ambil target role mana saja sebagai cadangan
        if (!$atasanTarget) {
            $atasanTarget = Pegawai::where('role_id', $targetRoleNotifikasi)->first();
        }

        // Kirim notifikasi jika Atasan target ditemukan
        if ($atasanTarget) {
            Notifikasi::create([
                'pegawai_id' => $atasanTarget->id,
                'judul'      => 'Pengajuan Cuti Baru',
                'pesan'      => 'Ada pengajuan cuti baru dari ' . $user->nama . ' yang butuh persetujuan Anda.',
                'tautan'     => route('atasan.approval'),
                'is_read'    => false,
            ]);
        }

        return redirect()->route('karyawan.riwayat')->with('success', 'Pengajuan cuti berhasil dikirim.');
    }

    // 3. Menampilkan Halaman Riwayat Pengajuan (Dengan Filter & Search Global)
    public function history(Request $request)
    {
        $query = PengajuanCuti::with(['atasanL1', 'atasanL3', 'atasanL4', 'approvalLogs'])
            ->where('pegawai_id', auth()->id())
            ->orderBy('created_at', 'desc');

        // Filter berdasarkan pencarian keterangan
        if ($request->filled('search')) {
            $query->where('keterangan', 'like', '%' . $request->search . '%');
        }

        // Filter berdasarkan status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Ambil data dengan pagination 5 per halaman
        $riwayat = $query->paginate(5)->withQueryString();

        return Inertia::render('Karyawan/RiwayatPengajuan', [
            'riwayat' => $riwayat,
            'filters' => $request->only(['search', 'status'])
        ]);
    }

    // 4. Menampilkan Kalender Tim
    public function teamCalendar()
    {
        $user = auth()->user();

        $cutiTim = PengajuanCuti::with('pegawai:id,nama,departemen,jabatan')
            ->whereHas('pegawai', function ($query) use ($user) {
                $query->where('departemen', $user->departemen);
            })
            ->where('status', 'disetujui')
            ->where('tanggal_selesai', '>=', Carbon::today()->toDateString())
            ->orderBy('tanggal_mulai', 'asc')
            ->get();

        // Ambil data hari libur yang sudah diinput oleh Admin HR
        $hariLiburs = \App\Models\HariLibur::all();

        return Inertia::render('Karyawan/KalenderTim', [
            'cutiTim' => $cutiTim,
            'departemen' => $user->departemen,
            'hariLiburs' => $hariLiburs
        ]);
    }

    // 5. Unduh PDF Bukti Cuti
    public function downloadPdf($id)
    {
        $pengajuan = PengajuanCuti::with(['pegawai', 'atasanL1', 'atasanL3', 'atasanL4', 'approvalLogs'])->findOrFail($id);

        if ($pengajuan->pegawai_id !== auth()->id() || $pengajuan->status !== 'disetujui') {
            abort(403, 'Anda tidak memiliki akses, atau cuti belum disetujui sepenuhnya.');
        }

        $tanggalMasuk = Carbon::parse($pengajuan->pegawai->tanggal_masuk);
        $masaKerja = $tanggalMasuk->diff(Carbon::now())->format('%y Tahun / %m Bulan');

        $saldo = SaldoCuti::where('pegawai_id', $pengajuan->pegawai_id)
            ->where('tahun', date('Y'))
            ->first();
        $sisaCuti = $saldo ? $saldo->sisa : 0;

        $data = [
            'pengajuan' => $pengajuan,
            'pegawai' => $pengajuan->pegawai,
            'masaKerja' => $masaKerja,
            'sisaCuti' => $sisaCuti,
        ];

        $pdf = Pdf::loadView('pdf.surat-cuti', $data)->setPaper('A4', 'portrait');
        $namaFile = 'Surat_Izin_Cuti_' . $pengajuan->pegawai->nama . '_' . $pengajuan->tanggal_mulai . '.pdf';

        return $pdf->download($namaFile);
    }

    // 6. Karyawan Membatalkan Pengajuan Cuti Sendiri (Yang Masih Antrean)
    public function cancel($id)
    {
        $pengajuan = PengajuanCuti::findOrFail($id);

        // Validasi kepemilikan data
        if ($pengajuan->pegawai_id !== auth()->id()) {
            abort(403, 'Anda tidak diizinkan membatalkan pengajuan ini.');
        }

        // Hanya bisa dibatalkan jika statusnya masih dalam antrean (menunggu persetujuan)
        if (!in_array($pengajuan->status, ['menunggu_l1', 'menunggu_l2', 'menunggu_l3', 'menunggu_l4'])) {
            return back()->with('error', 'Cuti ini sudah tidak dapat dibatalkan.');
        }

        // Update status menjadi dibatalkan reguler (inisiatif sendiri)
        $pengajuan->update([
            'status' => 'dibatalkan_reguler'
        ]);

        return back()->with('success', 'Pengajuan cuti berhasil dibatalkan.');
    }

    // 7. Karyawan Membatalkan Cuti Mandiri (Yang Sudah Disetujui)
    public function batalkanMandiri(Request $request, $id)
    {
        // 1. Validasi alasan pembatalan wajib diisi
        $request->validate([
            'alasan_pembatalan' => 'required|string|max:255',
        ], [
            'alasan_pembatalan.required' => 'Alasan pembatalan wajib diisi.',
        ]);

        $cuti = PengajuanCuti::findOrFail($id);

        // 2. Keamanan: Pastikan yang membatalkan adalah pemilik cuti & statusnya 'disetujui'
        if ($cuti->pegawai_id !== auth()->id() || $cuti->status !== 'disetujui') {
            return redirect()->back()->with('error', 'Aksi tidak diizinkan atau cuti tidak dapat dibatalkan.');
        }

        // 3. Kembalikan saldo cuti secara otomatis HANYA JIKA sebelumnya Cuti Tahunan
        if (strtolower($cuti->jenis_cuti) === 'cuti tahunan') {
            $tahunCuti = date('Y', strtotime($cuti->tanggal_mulai));
            $saldo = SaldoCuti::where('pegawai_id', $cuti->pegawai_id)
                ->where('tahun', $tahunCuti)
                ->first();

            if ($saldo) {
                $saldo->sisa += $cuti->jumlah_hari;
                $saldo->save();
            }
        }

        // 4. Ubah status menjadi dibatalkan dan simpan alasannya
        $cuti->status = 'dibatalkan_reguler';
        // Tambahkan alasan ke keterangan yang sudah ada
        $cuti->keterangan = $cuti->keterangan . ' | Batal Mandiri: ' . $request->alasan_pembatalan;
        $cuti->save();

        return redirect()->back()->with('success', 'Cuti berhasil dibatalkan dan saldo telah dikembalikan.');
    }

    // 8. Karyawan Merevisi Cuti (Khusus Status Ditangguhkan)
    public function revisi(Request $request, $id)
    {
        $request->validate([
            'tanggal_mulai' => ['required', 'date', 'after_or_equal:today'],
            'tanggal_selesai' => ['required', 'date', 'after_or_equal:tanggal_mulai'],
        ]);

        $cuti = PengajuanCuti::findOrFail($id);

        if ($cuti->pegawai_id !== auth()->id() || $cuti->status !== 'dibatalkan_ditangguhkan') {
            return redirect()->back()->with('error', 'Cuti ini tidak dapat direvisi.');
        }

        // --- LOGIKA BARU: MENGHITUNG HARI KERJA (SKIP SABTU & MINGGU) ---
        $startDate = Carbon::parse($request->tanggal_mulai);
        $endDate = Carbon::parse($request->tanggal_selesai);
        $jumlah_hari = 0;

        $currentDate = $startDate->copy();
        while ($currentDate->lte($endDate)) {
            // Jika bukan hari Sabtu (6) dan bukan hari Minggu (0)
            if (!$currentDate->isWeekend()) {
                $jumlah_hari++;
            }
            $currentDate->addDay();
        }

        // Cegah pengajuan jika jumlah harinya 0 (berarti dia cuma ngajuin di pas hari libur)
        if ($jumlah_hari === 0) {
            return redirect()->back()->with('error', 'Tanggal yang dipilih jatuh pada hari libur sepenuhnya.');
        }

        // Update data cuti
        $cuti->update([
            'tanggal_mulai' => $request->tanggal_mulai,
            'tanggal_selesai' => $request->tanggal_selesai,
            'jumlah_hari' => $jumlah_hari,
            'status' => 'menunggu_l1', // Ini bisa disesuaikan lagi jika atasan yang merevisi
            'level_saat_ini' => 1,
            'keterangan' => $cuti->keterangan . ' | [DIREVISI]',
        ]);

        // Kirim Notifikasi Ulang ke Atasan L1
        $userLogin = auth()->user();
        $atasanL1 = Pegawai::where('role_id', 2)->where('departemen', $userLogin->departemen)->first()
            ?? Pegawai::where('role_id', 2)->first();

        if ($atasanL1) {
            Notifikasi::create([
                'pegawai_id' => $atasanL1->id,
                'judul'      => 'Revisi Pengajuan Cuti',
                'pesan'      => 'Ada revisi tanggal cuti dari ' . $userLogin->nama . ' yang butuh persetujuan Anda.',
                'tautan'     => route('atasan.approval'),
                'is_read'    => false,
            ]);
        }

        return redirect()->back()->with('success', 'Tanggal cuti berhasil direvisi dan diajukan ulang.');
    }
}
