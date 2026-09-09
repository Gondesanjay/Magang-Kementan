<?php

namespace App\Http\Controllers;

use App\Models\Pegawai;
use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use App\Models\HariLibur;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();
        $tahun = date('Y');
        $stats = [];
        $recentCuti = [];
        $recentCutiPribadi = []; // FIX: inisialisasi agar tidak undefined utk role selain 1,2,3,4,6
        $anggotaTim = [];
        $allCutiDisetujui = [];
        $timCutiHariIni = [];

        // ================================================================
        // TAMBAHAN: Filter Divisi (kolom DB: `departemen`, label UI: "Divisi/Departemen")
        // Catatan penamaan (penting, jangan tertukar dengan kolom `divisi`):
        //   - Kolom `pegawais.departemen` => berlabel "Divisi/Departemen" di form
        //     Kelola Pegawai, dan INI yang dipakai untuk filter "Filter Divisi"
        //     (dropdown drill-down manual di Dashboard).
        //   - Kolom `pegawais.divisi`     => berlabel "Tim Kerja" di form,
        //     dipakai untuk PEMBATASAN WILAYAH otomatis L1 (lihat blok
        //     "PERBAIKAN GRANULARITAS WILAYAH" di bawah).
        // Nilainya null jika user tidak memilih apa-apa di dropdown (=semua divisi).
        // ================================================================
        $departemenFilter = $request->query('departemen');

        // Flag Admin HR
        $isAdminHR = $user->role_id === 5;

        // 1. Data Hari Libur
        $hariLiburs = HariLibur::where('tanggal', '>=', Carbon::today()->toDateString())
            ->orderBy('tanggal', 'asc')
            ->get();

        // 2. Inisialisasi chart default (TIM)
        $chartDataBackend = array_fill(0, 12, 0);

        // === TAMBAHAN: Data cuti & chart Pribadi ===
        $cutiPribadiDisetujui = collect();
        $chartDataPribadi = array_fill(0, 12, 0);


        if ($user->role_id === 1) {
            // ==========================================
            // --- DASHBOARD KARYAWAN ---
            // ==========================================
            $saldo = SaldoCuti::where('pegawai_id', $user->id)->where('tahun', $tahun)->first();

            $kuotaTahunan = $saldo ? $saldo->kuota_tahunan : 0;

            // ---> PERBAIKAN (root cause "Sisa Tahun Kemarin" selalu 0) <---
            // Sebelumnya kode membaca kolom `sisa_cuti_tahun_lalu`, padahal
            // kolom itu TIDAK ADA di tabel `saldo_cutis` (dicek lewat
            // phpMyAdmin: kolom yang benar-benar ada adalah
            // `carry_forward_normal`). Karena Eloquent tidak melempar error
            // untuk kolom yang tidak ada di $attributes, nilainya diam-diam
            // selalu null/0, walau AdminController::updatePegawai() sudah
            // benar menyimpan input Admin HR ke `carry_forward_normal`.
            // Sekarang dashboard membaca kolom yang SAMA PERSIS dengan yang
            // ditulis oleh form Edit Pegawai di AdminController, supaya
            // kedua sisi selalu sinkron.
            $sisaTahunLalu = $saldo ? $saldo->carry_forward_normal : 0;
            $cutiTerpakai = PengajuanCuti::where('pegawai_id', $user->id)
                ->where('jenis_cuti', 'Cuti Tahunan')
                ->where('status', 'disetujui')
                ->whereYear('tanggal_mulai', $tahun)
                ->sum('jumlah_hari');

            $totalTersedia = ($kuotaTahunan + $sisaTahunLalu) - $cutiTerpakai;

            $stats = [
                'sisa_cuti' => $totalTersedia,
                'kuota_tahunan' => $kuotaTahunan,
                'sisa_cuti_tahun_lalu' => $sisaTahunLalu,
                'cuti_terpakai' => $cutiTerpakai,
                'total_cuti_tersedia' => $totalTersedia,
                'total_pengajuan' => PengajuanCuti::where('pegawai_id', $user->id)->count(),
                'menunggu' => PengajuanCuti::where('pegawai_id', $user->id)->where('status', 'like', 'menunggu%')->count(),
                'disetujui' => PengajuanCuti::where('pegawai_id', $user->id)->where('status', 'disetujui')->count(),
            ];

            // === FIX: Ambil 5 riwayat pengajuan cuti terbaru milik Karyawan ini ===
            // Sebelumnya variabel ini tidak pernah diisi untuk role_id === 1,
            // sehingga tabel "Riwayat Pengajuan Terbaru" di dashboard selalu kosong
            // meskipun data pengajuan cuti sudah ada di halaman Riwayat Pengajuan.
            $recentCuti = PengajuanCuti::with(['atasanL1', 'atasanL3', 'atasanL4', 'approvalLogs'])
                ->where('pegawai_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->take(5)
                ->get();

            // Untuk role 1, versi "pribadi" sama dengan versi utama
            $recentCutiPribadi = $recentCuti;

            if (in_array($user->role_id, [2, 3, 4, 6], true)) {
                // ... query cutiPribadiDisetujui & chartDataPribadi ...
                // (blok ini tidak pernah tereksekusi untuk role_id === 1,
                // dipertahankan apa adanya agar tidak mengubah struktur asli)
            }

            // Data pribadi (juga dipakai sebagai cutiDisetujuiData)
            $allCutiDisetujui = PengajuanCuti::with(['atasanL1', 'atasanL3', 'atasanL4', 'approvalLogs'])
                ->where('pegawai_id', $user->id)
                ->where('status', 'disetujui')
                ->whereYear('tanggal_mulai', $tahun)
                ->get();

            // Untuk role 1, data pribadi = data utama
            $cutiPribadiDisetujui = $allCutiDisetujui;
        } elseif (in_array($user->role_id, [2, 3, 4, 5, 6])) {
            // ==========================================
            // --- DASHBOARD ATASAN & ADMIN (L1, L2, L3, L4, HR) ---
            // ==========================================

            // ================================================================
            // PERBAIKAN GRANULARITAS WILAYAH (konsisten dengan MonitoringCutiController)
            // Struktur organisasi: satu Departemen/Kelompok (`departemen`)
            // bisa berisi BEBERAPA Tim Kerja (`divisi`), masing-masing
            // punya L1 sendiri; L2 membawahi SEMUA Tim Kerja dalam satu
            // Departemen/Kelompok.
            //
            // SEBELUMNYA: seluruh data tim (anggota tim, cuti tim, antrean,
            // dll) untuk L1 MAUPUN L2 sama-sama difilter pakai `departemen`
            // saja — ini salah granularitas untuk L1, karena artinya L1
            // bisa melihat staf dari Tim Kerja lain selama masih satu
            // Departemen/Kelompok yang sama (persis bug yang dilaporkan:
            // Maria Rosalin, L1 Tim Kerja "TIM KERJA KEBIJAKAN PERTANIAN",
            // melihat 12 orang dari seluruh Kelompok, padahal harusnya
            // hanya 5 orang di Tim Kerjanya sendiri).
            //
            // PERBAIKAN: tentukan $wilayahField & $wilayahValue berdasarkan
            // role sebelum membangun query manapun di bawah:
            //   - L1 (role_id 2): field = 'divisi'     (Tim Kerja sendiri)
            //   - L2 (role_id 3): field = 'departemen'  (Kelompok sendiri)
            //   - L3, L4 (role_id 4, 6): TIDAK dibatasi (null)
            //   - Admin HR (role_id 5): TIDAK dibatasi (null)
            // Lalu dipakai secara konsisten di SEMUA query tim di bawah ini
            // (anggotaTim, allCutiDisetujui, timCutiHariIni, recentCuti,
            // antrean/'total_antrean', 'cuti_tim_bulan_ini').
            // ================================================================
            $wilayahField = null;
            $wilayahValue = null;
            if ($user->role_id === 2) {
                $wilayahField = 'divisi';
                $wilayahValue = $user->divisi;
            } elseif ($user->role_id === 3) {
                $wilayahField = 'departemen';
                $wilayahValue = $user->departemen;
            }
            // role_id 4, 6, dan 5 (Admin HR) sengaja dibiarkan $wilayahField
            // tetap null -> tidak ada pembatasan wilayah (lintas departemen).

            $targetStatus = [
                2 => 'menunggu_l1',
                3 => 'menunggu_l2',
                4 => 'menunggu_l3',
                6 => 'menunggu_l4',
            ][$user->role_id] ?? null;

            $antreanQuery = $targetStatus
                ? PengajuanCuti::where('status', $targetStatus)
                : PengajuanCuti::where('status', 'like', 'menunggu%');

            // PERBAIKAN: dulu hanya role_id===2 yang dibatasi wilayah di
            // sini (L2 tidak dibatasi sama sekali untuk hitungan "Perlu
            // Persetujuan"). Sekarang memakai $wilayahField yang generik,
            // otomatis mencakup L1 (divisi) dan L2 (departemen).
            if ($wilayahField) {
                $antreanQuery->whereHas('pegawai', function ($q) use ($wilayahField, $wilayahValue) {
                    $q->where($wilayahField, $wilayahValue);
                });
            }

            // TAMBAHAN: drill-down Filter Divisi (dropdown manual, field
            // `departemen`) tetap berlaku di atas pembatasan wilayah
            // otomatis di atas — mempersempit lebih lanjut jika dipilih.
            if ($departemenFilter) {
                $antreanQuery->whereHas('pegawai', function ($q) use ($departemenFilter) {
                    $q->where('departemen', $departemenFilter);
                });
            }

            $anggotaTimQuery = Pegawai::where('role_id', 1);
            // PERBAIKAN: sebelumnya `if ($user->role_id !== 5) { where('departemen', ...) }`
            // menyamaratakan L1 & L2 dengan filter departemen yang sama.
            // Sekarang memakai $wilayahField/$wilayahValue yang sudah
            // disesuaikan per role di atas.
            if ($wilayahField) {
                $anggotaTimQuery->where($wilayahField, $wilayahValue);
            }
            // TAMBAHAN: Filter Divisi (dropdown manual)
            if ($departemenFilter) {
                $anggotaTimQuery->where('departemen', $departemenFilter);
            }

            $anggotaTim = $anggotaTimQuery->get();

            $saldoAtasan = null;
            if (in_array($user->role_id, [2, 3, 4, 6], true)) {
                $saldoAtasan = SaldoCuti::firstOrCreate(
                    ['pegawai_id' => $user->id, 'tahun' => $tahun],
                    ['kuota_tahunan' => 12, 'sisa' => 12]
                );
            }

            $kuotaTahunanAtasan = $saldoAtasan?->kuota_tahunan ?? 12;

            // ---> PERBAIKAN (sama seperti Dashboard Karyawan di atas) <---
            // Baca `carry_forward_normal`, bukan `sisa_cuti_tahun_lalu` yang
            // memang tidak ada kolomnya di tabel saldo_cutis, supaya
            // "Sisa Tahun Kemarin" Atasan juga ikut sinkron dengan input
            // Admin HR di form Edit Pegawai.
            $sisaTahunLaluAtasan = $saldoAtasan?->carry_forward_normal ?? 0;
            $cutiTerpakaiAtasan = $saldoAtasan
                ? PengajuanCuti::where('pegawai_id', $user->id)
                ->where('jenis_cuti', 'Cuti Tahunan')
                ->where('status', 'disetujui')
                ->whereYear('tanggal_mulai', $tahun)
                ->sum('jumlah_hari')
                : 0;

            $totalTersediaAtasan = ($kuotaTahunanAtasan + $sisaTahunLaluAtasan) - $cutiTerpakaiAtasan;

            $stats = [
                'kuota_tahunan' => $kuotaTahunanAtasan,
                'sisa_cuti_tahun_lalu' => $sisaTahunLaluAtasan,
                'cuti_terpakai' => $cutiTerpakaiAtasan,
                'total_cuti_tersedia' => $totalTersediaAtasan,
                'total_antrean' => $antreanQuery->count(),
                // PERBAIKAN: 'cuti_tim_bulan_ini' sebelumnya memakai
                // `departemen` untuk semua role !== 5. Sekarang memakai
                // $wilayahField generik (divisi untuk L1, departemen untuk L2).
                'cuti_tim_bulan_ini' => PengajuanCuti::when($wilayahField, function ($query) use ($wilayahField, $wilayahValue) {
                    $query->whereHas('pegawai', function ($q) use ($wilayahField, $wilayahValue) {
                        $q->where($wilayahField, $wilayahValue);
                    });
                })
                    // TAMBAHAN: Filter Divisi
                    ->when($departemenFilter, function ($query) use ($departemenFilter) {
                        $query->whereHas('pegawai', function ($q) use ($departemenFilter) {
                            $q->where('departemen', $departemenFilter);
                        });
                    })
                    ->where('status', 'disetujui')
                    ->whereMonth('tanggal_mulai', date('m'))
                    ->whereYear('tanggal_mulai', $tahun)
                    ->count(),
                'total_anggota_tim' => $anggotaTimQuery->count(),
            ];

            // Tabel Atasan
            // PERBAIKAN: sebelumnya `when($user->role_id !== 5, ...where('departemen', $user->departemen))`
            // menyamaratakan L1 & L2. Sekarang memakai $wilayahField generik.
            $recentCuti = PengajuanCuti::with(['pegawai', 'atasanL1', 'atasanL3', 'atasanL4', 'approvalLogs'])
                ->when($wilayahField, function ($query) use ($wilayahField, $wilayahValue) {
                    $query->whereHas('pegawai', function ($q) use ($wilayahField, $wilayahValue) {
                        $q->where($wilayahField, $wilayahValue);
                    });
                })
                // TAMBAHAN: Filter Divisi
                ->when($departemenFilter, function ($query) use ($departemenFilter) {
                    $query->whereHas('pegawai', function ($q) use ($departemenFilter) {
                        $q->where('departemen', $departemenFilter);
                    });
                })
                ->when($targetStatus, function ($query) use ($targetStatus) {
                    $query->orderByRaw("CASE WHEN status = '{$targetStatus}' THEN 1 ELSE 2 END");
                })
                ->orderBy('created_at', 'desc')
                ->take(5)
                ->get();

            // Data TIM (untuk grafik Tim & tabel)
            // PERBAIKAN: sama seperti di atas, memakai $wilayahField generik
            // (bukan hardcode 'departemen' untuk semua role !== 5). Karena
            // chart TIM (chartDataBackend) dihitung dari hasil
            // $allCutiDisetujui di bawah (lihat loop "Loop chart TIM"),
            // perbaikan di sini otomatis membuat chart bar & donut milik
            // L1 juga ikut ter-scope ke Tim Kerja-nya sendiri.
            $allCutiDisetujui = PengajuanCuti::with(['pegawai', 'atasanL1', 'atasanL3', 'atasanL4', 'approvalLogs'])
                ->when($wilayahField, function ($query) use ($wilayahField, $wilayahValue) {
                    $query->whereHas('pegawai', function ($q) use ($wilayahField, $wilayahValue) {
                        $q->where($wilayahField, $wilayahValue);
                    });
                })
                // TAMBAHAN: Filter Divisi
                ->when($departemenFilter, function ($query) use ($departemenFilter) {
                    $query->whereHas('pegawai', function ($q) use ($departemenFilter) {
                        $q->where('departemen', $departemenFilter);
                    });
                })
                ->where('status', 'disetujui')
                ->whereYear('tanggal_mulai', $tahun)
                ->get();

            // Tim cuti hari ini
            // PERBAIKAN: memakai $wilayahField generik, bukan hardcode
            // 'departemen'.
            $timCutiHariIni = PengajuanCuti::with(['pegawai', 'atasanL1', 'atasanL3', 'atasanL4', 'approvalLogs'])
                ->when($wilayahField, function ($query) use ($wilayahField, $wilayahValue) {
                    $query->whereHas('pegawai', function ($q) use ($wilayahField, $wilayahValue) {
                        $q->where($wilayahField, $wilayahValue);
                    });
                })
                // TAMBAHAN: Filter Divisi
                ->when($departemenFilter, function ($query) use ($departemenFilter) {
                    $query->whereHas('pegawai', function ($q) use ($departemenFilter) {
                        $q->where('departemen', $departemenFilter);
                    });
                })
                ->where('status', 'disetujui')
                ->whereDate('tanggal_mulai', '<=', Carbon::today())
                ->whereDate('tanggal_selesai', '>=', Carbon::today())
                ->get();

            // === TAMBAHAN: Data cuti Pribadi untuk Atasan (role 2,3,4,6) ===
            if (in_array($user->role_id, [2, 3, 4, 6], true)) {
                $cutiPribadiDisetujui = PengajuanCuti::with(['atasanL1', 'atasanL3', 'atasanL4', 'approvalLogs'])
                    ->where('pegawai_id', $user->id)
                    ->where('status', 'disetujui')
                    ->whereYear('tanggal_mulai', $tahun)
                    ->get();

                foreach ($cutiPribadiDisetujui as $cuti) {
                    $monthIndex = (int) date('n', strtotime($cuti->tanggal_mulai)) - 1;
                    if ($monthIndex >= 0 && $monthIndex <= 11) {
                        $chartDataPribadi[$monthIndex] += $cuti->jumlah_hari;
                    }
                }

                // === FIX: Ambil 5 riwayat pengajuan pribadi terbaru milik Atasan ini ===
                // Sebelumnya, hasil ini dihitung tapi tidak pernah dikirim ke frontend
                // karena di return Inertia::render() key 'recentCutiPribadi' di-hardcode
                // menjadi collect() kosong. Sekarang kita query & kirim datanya dengan benar.
                $recentCutiPribadi = PengajuanCuti::with(['atasanL1', 'atasanL3', 'atasanL4', 'approvalLogs'])
                    ->where('pegawai_id', $user->id)
                    ->orderBy('created_at', 'desc')
                    ->take(5)
                    ->get();
            }
        }

        // Loop chart TIM
        foreach ($allCutiDisetujui as $cuti) {
            $monthIndex = (int) date('n', strtotime($cuti->tanggal_mulai)) - 1;
            if ($monthIndex >= 0 && $monthIndex <= 11) {
                $chartDataBackend[$monthIndex] += $cuti->jumlah_hari;
            }
        }

        // Untuk role 1, chart pribadi = chart utama
        if ($user->role_id === 1) {
            $chartDataPribadi = $chartDataBackend;
        }

        // ================================================================
        // TAMBAHAN: Daftar opsi "Filter Divisi" untuk dropdown di dashboard.
        // Diambil dari kolom `pegawais.departemen` (BUKAN `pegawais.divisi`,
        // yang di form berlabel "Tim Kerja" dan dipakai untuk pembatasan
        // wilayah otomatis L1, bukan untuk dropdown drill-down ini).
        // ================================================================
        $listDivisi = Pegawai::whereNotNull('departemen')
            ->where('departemen', '!=', '')
            ->distinct()
            ->orderBy('departemen')
            ->pluck('departemen');

        return Inertia::render('Dashboard', [
            'stats'               => $stats,
            'recentCuti'          => $recentCuti,
            'cutiDisetujuiData'    => $allCutiDisetujui,        // Data TIM
            'cutiDisetujuiPribadi' => $cutiPribadiDisetujui,    // Data PRIBADI (baru)
            'chartDataBackend'    => $chartDataBackend,        // Chart TIM
            'chartDataPribadi'    => $chartDataPribadi,        // Chart PRIBADI (baru)
            'hariLiburs'          => $hariLiburs,
            'anggotaTim'          => $anggotaTim,
            'timCutiHariIni'      => $timCutiHariIni,
            'isAdminHR'           => $isAdminHR,
            // FIX: kirim variabel $recentCutiPribadi yang sudah benar-benar diisi,
            // bukan collect() kosong yang sebelumnya menimpa hasil query di atas.
            'recentCutiPribadi'   => $recentCutiPribadi,
            // TAMBAHAN: dukungan Filter Divisi
            'listDivisi'          => $listDivisi,
            'filter'              => [
                'departemen' => $departemenFilter,
            ],
        ]);
    }
}
