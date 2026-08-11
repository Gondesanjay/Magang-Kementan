<?php

namespace App\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use App\Models\Notifikasi; // <-- Tambahan Import Model Notifikasi
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;

class CutiController extends Controller
{
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
            'tanggal_mulai' => ['required', 'date', 'after_or_equal:today'],
            'tanggal_selesai' => ['required', 'date', 'after_or_equal:tanggal_mulai'],
            'keterangan' => ['required', 'string'],
            'alamat_cuti' => ['required', 'string'],
            'no_telp' => ['required', 'string', 'max:20'],
        ]);

        $jumlah_hari = Carbon::parse($request->tanggal_mulai)->diffInDays(Carbon::parse($request->tanggal_selesai)) + 1;

        $pengajuan = PengajuanCuti::create([
            'pegawai_id' => auth()->id(),
            'tanggal_mulai' => $request->tanggal_mulai,
            'tanggal_selesai' => $request->tanggal_selesai,
            'jumlah_hari' => $jumlah_hari,
            'keterangan' => $request->keterangan,
            'alamat_cuti' => $request->alamat_cuti,
            'no_telp' => $request->no_telp,
            'status' => 'menunggu_l1',
            'level_saat_ini' => 1,
        ]);

        // <-- OTOMATIS TAMBAHKAN NOTIFIKASI BARU SAAT PENGAJUAN SUKSES -->
        Notifikasi::create([
            'pegawai_id' => auth()->id(),
            'judul' => 'Pengajuan Cuti Berhasil',
            'pesan' => 'Permohonan cuti Anda berhasil diajukan dan sedang menunggu persetujuan Atasan L1.',
            'tautan' => route('karyawan.riwayat'),
            'is_read' => false,
        ]);

        return redirect()->route('karyawan.riwayat')->with('success', 'Pengajuan cuti berhasil dikirim.');
    }

    // 3. Menampilkan Halaman Riwayat Pengajuan (Dengan Filter & Search Global)
    public function history(Request $request)
    {
        $query = PengajuanCuti::where('pegawai_id', auth()->id())
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
        $pengajuan = PengajuanCuti::with(['pegawai', 'atasanL1', 'atasanL3'])->findOrFail($id);

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

    // 6. Karyawan Membatalkan Pengajuan Cuti Sendiri
    public function cancel($id)
    {
        $pengajuan = PengajuanCuti::findOrFail($id);

        // Validasi kepemilikan data
        if ($pengajuan->pegawai_id !== auth()->id()) {
            abort(403, 'Anda tidak diizinkan membatalkan pengajuan ini.');
        }

        // Hanya bisa dibatalkan jika statusnya masih dalam antrean (menunggu persetujuan)
        if (!in_array($pengajuan->status, ['menunggu_l1', 'menunggu_l2', 'menunggu_l3'])) {
            return back()->with('error', 'Cuti ini sudah tidak dapat dibatalkan.');
        }

        // Update status menjadi dibatalkan reguler (inisiatif sendiri)
        $pengajuan->update([
            'status' => 'dibatalkan_reguler'
        ]);

        // <-- TAMBAHKAN NOTIFIKASI PEMBATALAN -->
        Notifikasi::create([
            'pegawai_id' => auth()->id(),
            'judul' => 'Pengajuan Cuti Dibatalkan',
            'pesan' => 'Anda telah membatalkan permohonan cuti secara mandiri.',
            'tautan' => route('karyawan.riwayat'),
            'is_read' => false,
        ]);

        return back()->with('success', 'Pengajuan cuti berhasil dibatalkan.');
    }
}
