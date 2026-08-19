<?php

namespace App\Http\Controllers;

use App\Models\Pegawai;
use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use App\Models\HariLibur;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $tahun = date('Y');
        $stats = [];
        $recentCuti = [];
        $anggotaTim = [];
        $allCutiDisetujui = [];
        $timCutiHariIni = []; // <--- VARIABEL BARU

        // 1. Tarik Data Hari Libur Secara Global
        $hariLiburs = HariLibur::where('tanggal', '>=', Carbon::today()->toDateString())
            ->orderBy('tanggal', 'asc')
            ->get();

        // 2. Inisialisasi default array 12 bulan (berisi angka 0) untuk grafik
        $chartDataBackend = array_fill(0, 12, 0);

        if ($user->role_id === 1) {
            // ==========================================
            // --- DASHBOARD KARYAWAN ---
            // ==========================================
            $saldo = SaldoCuti::where('pegawai_id', $user->id)->where('tahun', $tahun)->first();

            $stats = [
                'sisa_cuti' => $saldo ? $saldo->sisa : 0,
                'kuota_tahunan' => $saldo ? $saldo->kuota_tahunan : 0,
                'sisa_cuti_tahun_lalu' => $saldo ? $saldo->sisa_cuti_tahun_lalu : 0,
                'total_pengajuan' => PengajuanCuti::where('pegawai_id', $user->id)->count(),
                'menunggu' => PengajuanCuti::where('pegawai_id', $user->id)->where('status', 'like', 'menunggu%')->count(),
                'disetujui' => PengajuanCuti::where('pegawai_id', $user->id)->where('status', 'disetujui')->count(),
            ];

            // Tabel Karyawan
            $recentCuti = PengajuanCuti::where('pegawai_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->take(5)
                ->get();

            // Data UTUH untuk Grafik, Modal, dan Kalender
            $allCutiDisetujui = PengajuanCuti::where('pegawai_id', $user->id)
                ->where('status', 'disetujui')
                ->whereYear('tanggal_mulai', $tahun)
                ->get();
        } elseif (in_array($user->role_id, [2, 3, 4])) {
            // ==========================================
            // --- DASHBOARD ATASAN (L1, L2, L3) ---
            // ==========================================
            $targetStatus = 'menunggu_l' . ($user->role_id - 1);

            $antreanQuery = PengajuanCuti::where('status', $targetStatus);
            if ($user->role_id === 2) {
                $antreanQuery->whereHas('pegawai', function ($q) use ($user) {
                    $q->where('departemen', $user->departemen);
                });
            }

            $anggotaTimQuery = Pegawai::where('departemen', $user->departemen)
                ->where('role_id', 1);

            $anggotaTim = $anggotaTimQuery->get();

            $stats = [
                'total_antrean' => $antreanQuery->count(),
                'cuti_tim_bulan_ini' => PengajuanCuti::whereHas('pegawai', function ($q) use ($user) {
                    $q->where('departemen', $user->departemen);
                })->where('status', 'disetujui')
                    ->whereMonth('tanggal_mulai', date('m'))
                    ->whereYear('tanggal_mulai', $tahun)
                    ->count(),
                'total_anggota_tim' => $anggotaTimQuery->count(),
            ];

            // Tabel Atasan: Smart Sorting (Maks 5)
            $recentCuti = PengajuanCuti::with('pegawai')
                ->whereHas('pegawai', function ($q) use ($user) {
                    $q->where('departemen', $user->departemen);
                })
                ->orderByRaw("CASE WHEN status = '{$targetStatus}' THEN 1 ELSE 2 END")
                ->orderBy('created_at', 'desc')
                ->take(5)
                ->get();

            // Data UTUH untuk Grafik, Modal, dan Kalender (Tidak dibatasi 5)
            $allCutiDisetujui = PengajuanCuti::with('pegawai')->whereHas('pegawai', function ($q) use ($user) {
                $q->where('departemen', $user->departemen);
            })
                ->where('status', 'disetujui')
                ->whereYear('tanggal_mulai', $tahun)
                ->get();

            // <--- QUERY TIM CUTI HARI INI (BARU) --->
            $timCutiHariIni = PengajuanCuti::with('pegawai')
                ->whereHas('pegawai', function ($q) use ($user) {
                    $q->where('departemen', $user->departemen);
                })
                ->where('status', 'disetujui')
                ->whereDate('tanggal_mulai', '<=', Carbon::today())
                ->whereDate('tanggal_selesai', '>=', Carbon::today())
                ->get();
        } elseif ($user->role_id === 5) {
            // ==========================================
            // --- DASHBOARD ADMIN HR ---
            // ==========================================
            $anggotaTim = Pegawai::where('role_id', 1)->limit(50)->get();

            $stats = [
                'total_pegawai' => Pegawai::where('role_id', 1)->count(),
                'total_pengajuan' => PengajuanCuti::whereYear('created_at', $tahun)->count(),
                'pengajuan_menunggu' => PengajuanCuti::where('status', 'like', 'menunggu%')->count(),
                'pengajuan_disetujui' => PengajuanCuti::where('status', 'disetujui')->count(),
            ];

            // Tabel Admin HR
            $recentCuti = PengajuanCuti::with('pegawai')
                ->orderBy('created_at', 'desc')
                ->take(10)
                ->get();

            // Data UTUH untuk Grafik, Modal, dan Kalender
            $allCutiDisetujui = PengajuanCuti::with('pegawai')->where('status', 'disetujui')
                ->whereYear('tanggal_mulai', $tahun)
                ->get();

            // <--- QUERY TIM CUTI HARI INI ADMIN HR (BARU) --->
            $timCutiHariIni = PengajuanCuti::with('pegawai')
                ->where('status', 'disetujui')
                ->whereDate('tanggal_mulai', '<=', Carbon::today())
                ->whereDate('tanggal_selesai', '>=', Carbon::today())
                ->get();
        }

        // Loop untuk memetakan data grafik menggunakan data utuh
        foreach ($allCutiDisetujui as $cuti) {
            $monthIndex = (int) date('n', strtotime($cuti->tanggal_mulai)) - 1;
            $chartDataBackend[$monthIndex] += $cuti->jumlah_hari;
        }

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'recentCuti' => $recentCuti,
            'cutiDisetujuiData' => $allCutiDisetujui,
            'chartDataBackend' => $chartDataBackend,
            'hariLiburs' => $hariLiburs,
            'anggotaTim' => $anggotaTim,
            'timCutiHariIni' => $timCutiHariIni 
        ]);
    }
}
