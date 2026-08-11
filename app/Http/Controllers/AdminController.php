<?php

namespace App\Http\Controllers;

use App\Models\Pegawai;
use App\Models\SaldoCuti;
use App\Models\HariLibur;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AdminController extends Controller
{
    // 1. Menampilkan Daftar Pegawai
    public function kelolaPegawai()
    {
        $pegawai = Pegawai::orderBy('nama', 'asc')->get();
        return Inertia::render('Admin/KelolaPegawai', [
            'pegawai' => $pegawai
        ]);
    }

    // 2. Menampilkan Daftar Saldo Cuti Pegawai Tahun Ini
    public function kelolaSaldo()
    {
        $tahun = date('Y');

        // Ambil data pegawai beserta data saldo cutinya khusus tahun ini
        $pegawai = Pegawai::with(['saldoCuti' => function ($query) use ($tahun) {
            $query->where('tahun', $tahun);
        }])->orderBy('nama', 'asc')->get();

        return Inertia::render('Admin/KelolaSaldo', [
            'pegawai' => $pegawai,
            'tahun' => $tahun
        ]);
    }

    // 3. Memperbarui atau Membuat Saldo Cuti Baru
    public function updateSaldo(Request $request, $id)
    {
        $request->validate([
            'kuota_tahunan' => ['required', 'numeric', 'min:0'],
            'sisa' => ['required', 'numeric', 'min:0'],
        ]);

        $tahun = date('Y');

        // Update data jika sudah ada, atau buat baru jika belum punya saldo tahun ini
        SaldoCuti::updateOrCreate(
            ['pegawai_id' => $id, 'tahun' => $tahun],
            ['kuota_tahunan' => $request->kuota_tahunan, 'sisa' => $request->sisa]
        );

        return back();
    }

    // 4. Menampilkan Rekap Laporan Cuti Seluruh Pegawai
    public function rekapLaporan()
    {
        // Ambil semua data pengajuan cuti beserta data pegawainya, urutkan dari yang terbaru
        $laporan = \App\Models\PengajuanCuti::with('pegawai')
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Admin/RekapLaporan', [
            'laporan' => $laporan
        ]);
    }

    // 5. Export Data Rekap Cuti ke CSV/Excel
    public function exportExcel()
    {
        $laporan = \App\Models\PengajuanCuti::with('pegawai')->orderBy('created_at', 'desc')->get();

        $filename = "Rekap_Cuti_Instansi_" . date('Y-m-d') . ".csv";
        $headers = [
            "Content-type"        => "text/csv",
            "Content-Disposition" => "attachment; filename=$filename",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];

        $columns = ['ID', 'NIP', 'Nama Pegawai', 'Departemen', 'Tanggal Mulai', 'Tanggal Selesai', 'Durasi (Hari)', 'Keterangan', 'Status'];

        $callback = function () use ($laporan, $columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);

            foreach ($laporan as $row) {
                fputcsv($file, [
                    $row->id,
                    $row->pegawai->nip ?? '-',
                    $row->pegawai->nama ?? '-',
                    $row->pegawai->departemen ?? '-',
                    $row->tanggal_mulai,
                    $row->tanggal_selesai,
                    $row->jumlah_hari,
                    $row->keterangan,
                    $row->status,
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    // 6. Fungsi Menangguhkan Cuti (Oleh Admin/L3)
    public function suspendCuti(Request $request, $id)
    {
        $request->validate([
            'alasan' => ['required', 'string', 'max:255']
        ]);

        $pengajuan = \App\Models\PengajuanCuti::findOrFail($id);

        // Hanya cuti yang sudah disetujui yang bisa ditangguhkan
        if ($pengajuan->status !== 'disetujui') {
            return back()->with('error', 'Hanya cuti yang telah disetujui yang dapat ditangguhkan.');
        }

        // Kembalikan Saldo Cuti Pegawai (Berdasarkan tahun cuti tersebut)
        $tahunCuti = date('Y', strtotime($pengajuan->tanggal_mulai));
        $saldo = \App\Models\SaldoCuti::where('pegawai_id', $pengajuan->pegawai_id)
            ->where('tahun', $tahunCuti)
            ->first();

        if ($saldo) {
            $saldo->update([
                'sisa' => $saldo->sisa + $pengajuan->jumlah_hari
            ]);
        }

        // Sisipkan alasan penangguhan ke kolom keterangan yang sudah ada
        $keteranganBaru = $pengajuan->keterangan . ' | [DITANGGUHKAN: ' . $request->alasan . ']';

        // Update status menjadi dibatalkan_ditangguhkan
        $pengajuan->update([
            'status' => 'dibatalkan_ditangguhkan',
            'keterangan' => $keteranganBaru
        ]);

        return back()->with('success', 'Cuti berhasil ditangguhkan dan saldo telah dikembalikan.');
    }

    // 7. Menampilkan Halaman Kelola Hari Libur
    public function kelolaLibur()
    {
        $libur = HariLibur::orderBy('tanggal', 'desc')->get();
        return Inertia::render('Admin/KelolaLibur', [
            'libur' => $libur
        ]);
    }

    // 8. Menyimpan Data Hari Libur Baru
    public function storeLibur(Request $request)
    {
        $request->validate([
            'tanggal' => ['required', 'date', 'unique:hari_liburs,tanggal'],
            'keterangan' => ['required', 'string', 'max:255'],
            'is_cuti_bersama' => ['required', 'boolean'],
        ], [
            'tanggal.unique' => 'Tanggal ini sudah didaftarkan sebagai hari libur.'
        ]);

        HariLibur::create([
            'tanggal' => $request->tanggal,
            'keterangan' => $request->keterangan,
            'is_cuti_bersama' => $request->is_cuti_bersama,
        ]);

        return back();
    }

    // 9. Menghapus Data Hari Libur
    public function destroyLibur($id)
    {
        HariLibur::findOrFail($id)->delete();
        return back();
    }
}
