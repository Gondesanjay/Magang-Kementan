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
    /**
     * Menempelkan info saldo cuti tahunan milik PEGAWAI PEMILIK PENGAJUAN
     * (bukan Auth::user()) ke setiap item collection, agar modal detail
     * menampilkan saldo yang benar.
     */
    private function attachSaldoInfoKePegawai($collection, int $tahun): void
    {
        static $cache = [];

        foreach ($collection as $item) {
            $pegawai = $item->pegawai ?? null;
            if (!$pegawai) {
                continue;
            }

            if (!isset($cache[$pegawai->id])) {
                $saldo = SaldoCuti::where('pegawai_id', $pegawai->id)
                    ->where('tahun', $tahun)
                    ->first();
                $saldoTahunLalu = SaldoCuti::where('pegawai_id', $pegawai->id)
                    ->where('tahun', $tahun - 1)
                    ->first();

                $kuotaTahunan = $saldo->kuota_tahunan ?? ($pegawai->jatah_cuti ?? 12);
                $sisaTahunLalu = $saldo->carry_forward_normal ?? 0;
                $sisaDuaTahunLalu = $saldoTahunLalu?->carry_forward_normal ?? 0;

                $cutiTerpakai = PengajuanCuti::where('pegawai_id', $pegawai->id)
                    ->where('jenis_cuti', 'Cuti Tahunan')
                    ->where('status', 'disetujui')
                    ->whereYear('tanggal_mulai', $tahun)
                    ->sum('jumlah_hari');

                $sisaHakTahunBerjalan = max($kuotaTahunan - $cutiTerpakai, 0);
                $saldoBawaanEligible = ($sisaDuaTahunLalu === 12 && $sisaTahunLalu === 12)
                    ? 12
                    : min(6, $sisaTahunLalu);

                $cache[$pegawai->id] = [
                    'kuota_tahunan'            => $kuotaTahunan,
                    'sisa_tahun_lalu'          => $sisaTahunLalu,
                    'sisa_dua_tahun_lalu'      => $sisaDuaTahunLalu,
                    'sisa_hak_tahun_berjalan'  => $sisaHakTahunBerjalan,
                    'saldo_bawaan_eligible'    => $saldoBawaanEligible,
                    'cuti_terpakai'            => $cutiTerpakai,
                    'total_cuti_tersedia'      => $sisaHakTahunBerjalan + $saldoBawaanEligible,
                ];
            }

            $info = $cache[$pegawai->id];
            $pegawai->kuota_tahunan           = $info['kuota_tahunan'];
            $pegawai->sisa_tahun_lalu         = $info['sisa_tahun_lalu'];
            $pegawai->cuti_terpakai           = $info['cuti_terpakai'];
            $pegawai->sisa_dua_tahun_lalu     = $info['sisa_dua_tahun_lalu'];
            $pegawai->sisa_hak_tahun_berjalan = $info['sisa_hak_tahun_berjalan'];
            $pegawai->saldo_bawaan_eligible   = $info['saldo_bawaan_eligible'];
            $pegawai->total_cuti_tersedia     = $info['total_cuti_tersedia'];
            $pegawai->sisa_cuti_tersedia      = $info['total_cuti_tersedia'];
        }
    }

    /**
     * Menempelkan nama atasan L1 (Ketua Tim Kerja) & L2 (Ketua Kelompok
     * Substansi) milik PEGAWAI PEMILIK PENGAJUAN ke $item->pegawai.
     * Diperlukan karena L1/L2 berbeda-beda per Tim Kerja / Kelompok.
     */
    private function attachNamaApproverL1L2KePegawai($collection): void
    {
        static $cache = [];

        foreach ($collection as $item) {
            $pegawai = $item->pegawai ?? null;
            if (!$pegawai) {
                continue;
            }

            if (!isset($cache[$pegawai->id])) {
                $cache[$pegawai->id] = [
                    'nama_l1' => optional($pegawai->ketua_tim_kerja)->nama,
                    'nama_l2' => optional($pegawai->ketua_kelompok)->nama,
                ];
            }

            $pegawai->nama_l1 = $cache[$pegawai->id]['nama_l1'];
            $pegawai->nama_l2 = $cache[$pegawai->id]['nama_l2'];
        }
    }

    public function index(Request $request)
    {
        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();
        $tahun = date('Y');

        $stats               = [];
        $recentCuti          = [];
        $recentCutiPribadi   = [];
        $anggotaTim          = [];
        $allCutiDisetujui    = [];
        $timCutiHariIni      = [];
        $cutiPribadiDisetujui = collect();
        $chartDataBackend    = array_fill(0, 12, 0);
        $chartDataPribadi    = array_fill(0, 12, 0);

        // Filter Divisi hanya untuk role 4, 5, 6 (L3, Admin HR, L4).
        // Role 1, 2, 3 sudah punya pembatasan wilayah otomatis.
        $kelompokSubstansiFilter = in_array($user->role_id, [1, 2, 3], true)
            ? null
            : $request->query('kelompok_substansi');

        $isAdminHR = $user->role_id === 5;

        $hariLiburs = HariLibur::where('tanggal', '>=', Carbon::today()->toDateString())
            ->orderBy('tanggal', 'asc')
            ->get();

        // =====================================================================
        // DASHBOARD KARYAWAN (role 1)
        // =====================================================================
        if ($user->role_id === 1) {
            $saldo            = SaldoCuti::where('pegawai_id', $user->id)->where('tahun', $tahun)->first();
            $saldoTahunLalu   = SaldoCuti::where('pegawai_id', $user->id)->where('tahun', $tahun - 1)->first();
            $saldoDuaTahunLalu = SaldoCuti::where('pegawai_id', $user->id)->where('tahun', $tahun - 2)->first();

            $kuotaTahunan     = $saldo ? $saldo->kuota_tahunan : 0;
            $sisaTahunLalu    = $saldo ? $saldo->carry_forward_normal : 0;
            $sisaDuaTahunLalu = $saldoTahunLalu?->carry_forward_normal
                ?? $saldoDuaTahunLalu?->carry_forward_normal
                ?? 0;

            $cutiTerpakai = PengajuanCuti::where('pegawai_id', $user->id)
                ->where('jenis_cuti', 'Cuti Tahunan')
                ->where('status', 'disetujui')
                ->whereYear('tanggal_mulai', $tahun)
                ->sum('jumlah_hari');

            $saldoBawaanEligible = ($sisaDuaTahunLalu === 12 && $sisaTahunLalu === 12)
                ? 12
                : min(6, $sisaTahunLalu);

            $sisaKuotaTahunIni = max($kuotaTahunan - $cutiTerpakai, 0);
            $totalTersedia     = $sisaKuotaTahunIni + $saldoBawaanEligible;

            $stats = [
                'sisa_cuti'                 => $totalTersedia,
                'kuota_tahunan'             => $kuotaTahunan,
                'sisa_kuota_tahun_ini'      => $sisaKuotaTahunIni,
                'carry_forward_normal'      => $sisaTahunLalu,
                'sisa_cuti_dua_tahun_lalu'  => $sisaDuaTahunLalu,
                'saldo_bawaan_eligible'     => $saldoBawaanEligible,
                'cuti_terpakai'             => $cutiTerpakai,
                'total_cuti_tersedia'       => $totalTersedia,
                'saldo_tahunan'             => [
                    ['tahun' => $tahun - 2, 'nilai' => $sisaDuaTahunLalu],
                    ['tahun' => $tahun - 1, 'nilai' => $sisaTahunLalu],
                    ['tahun' => $tahun,     'nilai' => $sisaKuotaTahunIni],
                ],
                'total_pengajuan' => PengajuanCuti::where('pegawai_id', $user->id)->count(),
                'menunggu'        => PengajuanCuti::where('pegawai_id', $user->id)->where('status', 'like', 'menunggu%')->count(),
                'disetujui'       => PengajuanCuti::where('pegawai_id', $user->id)->where('status', 'disetujui')->count(),
            ];

            $recentCuti = PengajuanCuti::with(['atasanL1', 'atasanL2', 'atasanL3', 'atasanL4', 'approvalLogs'])
                ->where('pegawai_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->take(5)
                ->get();

            $recentCutiPribadi = $recentCuti;

            $allCutiDisetujui = PengajuanCuti::with(['atasanL1', 'atasanL2', 'atasanL3', 'atasanL4', 'approvalLogs'])
                ->where('pegawai_id', $user->id)
                ->where('status', 'disetujui')
                ->whereYear('tanggal_mulai', $tahun)
                ->get();

            $cutiPribadiDisetujui = $allCutiDisetujui;

            // =====================================================================
            // DASHBOARD ATASAN & ADMIN (L1, L2, L3, L4, HR)
            // =====================================================================
        } elseif (in_array($user->role_id, [2, 3, 4, 5, 6])) {

            // Granularitas wilayah:
            //   L1 (role 2) → tim_kerja
            //   L2 (role 3) → kelompok_substansi
            //   L3/L4/HR    → tidak dibatasi
            $wilayahField = null;
            $wilayahValue = null;
            if ($user->role_id === 2) {
                $wilayahField = 'tim_kerja';
                $wilayahValue = $user->tim_kerja;
            } elseif ($user->role_id === 3) {
                $wilayahField = 'kelompok_substansi';
                $wilayahValue = $user->kelompok_substansi;
            }

            $targetStatus = [
                2 => 'menunggu_l1',
                3 => 'menunggu_l2',
                4 => 'menunggu_l3',
                6 => 'menunggu_l4',
            ][$user->role_id] ?? null;

            // --- Antrean ---
            $antreanQuery = $targetStatus
                ? PengajuanCuti::where('status', $targetStatus)
                : PengajuanCuti::where('status', 'like', 'menunggu%');

            if ($wilayahField) {
                $antreanQuery->whereHas('pegawai', function ($q) use ($wilayahField, $wilayahValue) {
                    $q->where($wilayahField, $wilayahValue);
                });
            }
            if ($kelompokSubstansiFilter) {
                $antreanQuery->whereHas('pegawai', function ($q) use ($kelompokSubstansiFilter) {
                    $q->where('kelompok_substansi', $kelompokSubstansiFilter);
                });
            }

            // --- Anggota Tim ---
            $anggotaTimQuery = in_array($user->role_id, [3, 4, 5, 6], true)
                ? Pegawai::where('role_id', '!=', 5)
                : Pegawai::where('role_id', 1);

            if ($wilayahField) {
                $anggotaTimQuery->where($wilayahField, $wilayahValue);
            }
            if ($kelompokSubstansiFilter) {
                $anggotaTimQuery->where('kelompok_substansi', $kelompokSubstansiFilter);
            }
            $anggotaTimQuery->where('id', '!=', $user->id);
            $anggotaTim = $anggotaTimQuery->get();

            // --- Saldo Atasan (role 2,3,4,6) ---
            $saldoAtasan = null;
            if (in_array($user->role_id, [2, 3, 4, 6], true)) {
                $saldoAtasan = SaldoCuti::firstOrCreate(
                    ['pegawai_id' => $user->id, 'tahun' => $tahun],
                    ['kuota_tahunan' => 12, 'sisa' => 12]
                );
            }

            $kuotaTahunanAtasan     = $saldoAtasan?->kuota_tahunan ?? 12;
            $sisaTahunLaluAtasan    = $saldoAtasan?->carry_forward_normal ?? 0;
            $cutiTerpakaiAtasan     = $saldoAtasan
                ? PengajuanCuti::where('pegawai_id', $user->id)
                ->where('jenis_cuti', 'Cuti Tahunan')
                ->where('status', 'disetujui')
                ->whereYear('tanggal_mulai', $tahun)
                ->sum('jumlah_hari')
                : 0;

            $totalTersediaAtasan    = ($kuotaTahunanAtasan + $sisaTahunLaluAtasan) - $cutiTerpakaiAtasan;
            $sisaKuotaTahunIniAtasan = max($kuotaTahunanAtasan - $cutiTerpakaiAtasan, 0);

            $stats = [
                'kuota_tahunan'        => $kuotaTahunanAtasan,
                'sisa_kuota_tahun_ini' => $sisaKuotaTahunIniAtasan,
                'carry_forward_normal' => $sisaTahunLaluAtasan,
                'cuti_terpakai'        => $cutiTerpakaiAtasan,
                'total_cuti_tersedia'  => $totalTersediaAtasan,
                'total_antrean'        => $antreanQuery->count(),
                'cuti_tim_bulan_ini'   => PengajuanCuti::when($wilayahField, function ($query) use ($wilayahField, $wilayahValue) {
                    $query->whereHas('pegawai', function ($q) use ($wilayahField, $wilayahValue) {
                        $q->where($wilayahField, $wilayahValue);
                    });
                })
                    ->when($kelompokSubstansiFilter, function ($query) use ($kelompokSubstansiFilter) {
                        $query->whereHas('pegawai', function ($q) use ($kelompokSubstansiFilter) {
                            $q->where('kelompok_substansi', $kelompokSubstansiFilter);
                        });
                    })
                    ->where('status', 'disetujui')
                    ->whereMonth('tanggal_mulai', date('m'))
                    ->whereYear('tanggal_mulai', $tahun)
                    ->count(),
                'total_anggota_tim' => $anggotaTimQuery->count(),
            ];

            // --- Recent Cuti (tabel atasan) ---
            $recentCuti = PengajuanCuti::with(['pegawai', 'atasanL1', 'atasanL2', 'atasanL3', 'atasanL4', 'approvalLogs'])
                ->when($wilayahField, function ($query) use ($wilayahField, $wilayahValue) {
                    $query->whereHas('pegawai', function ($q) use ($wilayahField, $wilayahValue) {
                        $q->where($wilayahField, $wilayahValue);
                    });
                })
                ->when($kelompokSubstansiFilter, function ($query) use ($kelompokSubstansiFilter) {
                    $query->whereHas('pegawai', function ($q) use ($kelompokSubstansiFilter) {
                        $q->where('kelompok_substansi', $kelompokSubstansiFilter);
                    });
                })
                ->when($targetStatus, function ($query) use ($targetStatus) {
                    $query->orderByRaw("CASE WHEN status = '{$targetStatus}' THEN 1 ELSE 2 END");
                })
                ->orderBy('created_at', 'desc')
                ->take(5)
                ->get();

            // --- Cuti Disetujui TIM (chart + tabel) ---
            // Exclude cuti milik atasan sendiri agar tidak dobel dengan data pribadi
            $allCutiDisetujui = PengajuanCuti::with(['pegawai', 'atasanL1', 'atasanL2', 'atasanL3', 'atasanL4', 'approvalLogs'])
                ->when($wilayahField, function ($query) use ($wilayahField, $wilayahValue, $user) {
                    $query->whereHas('pegawai', function ($q) use ($wilayahField, $wilayahValue, $user) {
                        $q->where($wilayahField, $wilayahValue)
                            ->where('id', '!=', $user->id);
                    });
                })
                ->when($kelompokSubstansiFilter, function ($query) use ($kelompokSubstansiFilter) {
                    $query->whereHas('pegawai', function ($q) use ($kelompokSubstansiFilter) {
                        $q->where('kelompok_substansi', $kelompokSubstansiFilter);
                    });
                })
                ->where('status', 'disetujui')
                ->whereYear('tanggal_mulai', $tahun)
                ->get();

            // --- Tim cuti hari ini ---
            $timCutiHariIni = PengajuanCuti::with(['pegawai', 'atasanL1', 'atasanL2', 'atasanL3', 'atasanL4', 'approvalLogs'])
                ->when($wilayahField, function ($query) use ($wilayahField, $wilayahValue) {
                    $query->whereHas('pegawai', function ($q) use ($wilayahField, $wilayahValue) {
                        $q->where($wilayahField, $wilayahValue);
                    });
                })
                ->when($kelompokSubstansiFilter, function ($query) use ($kelompokSubstansiFilter) {
                    $query->whereHas('pegawai', function ($q) use ($kelompokSubstansiFilter) {
                        $q->where('kelompok_substansi', $kelompokSubstansiFilter);
                    });
                })
                ->where('status', 'disetujui')
                ->whereDate('tanggal_mulai', '<=', Carbon::today())
                ->whereDate('tanggal_selesai', '>=', Carbon::today())
                ->get();

            // --- Data cuti Pribadi untuk Atasan (role 2,3,4,6) ---
            if (in_array($user->role_id, [2, 3, 4, 6], true)) {
                $cutiPribadiDisetujui = PengajuanCuti::with(['atasanL1', 'atasanL2', 'atasanL3', 'atasanL4', 'approvalLogs'])
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

                $recentCutiPribadi = PengajuanCuti::with(['atasanL1', 'atasanL2', 'atasanL3', 'atasanL4', 'approvalLogs'])
                    ->where('pegawai_id', $user->id)
                    ->orderBy('created_at', 'desc')
                    ->take(5)
                    ->get();
            }
        }

        // Chart TIM
        foreach ($allCutiDisetujui as $cuti) {
            $monthIndex = (int) date('n', strtotime($cuti->tanggal_mulai)) - 1;
            if ($monthIndex >= 0 && $monthIndex <= 11) {
                $chartDataBackend[$monthIndex] += $cuti->jumlah_hari;
            }
        }

        // Role 1: chart pribadi = chart utama
        if ($user->role_id === 1) {
            $chartDataPribadi = $chartDataBackend;
        }

        // Daftar opsi Filter Divisi
        $listKelompokSubstansi = Pegawai::whereNotNull('kelompok_substansi')
            ->where('kelompok_substansi', '!=', '')
            ->distinct()
            ->orderBy('kelompok_substansi')
            ->pluck('kelompok_substansi');

        // Tempel saldo + nama approver L1/L2 ke semua collection yang dikirim ke modal
        $this->attachSaldoInfoKePegawai($recentCuti, $tahun);
        $this->attachSaldoInfoKePegawai($recentCutiPribadi, $tahun);
        $this->attachSaldoInfoKePegawai($allCutiDisetujui, $tahun);
        $this->attachSaldoInfoKePegawai($cutiPribadiDisetujui, $tahun);
        $this->attachSaldoInfoKePegawai($timCutiHariIni, $tahun);

        $this->attachNamaApproverL1L2KePegawai($recentCuti);
        $this->attachNamaApproverL1L2KePegawai($recentCutiPribadi);
        $this->attachNamaApproverL1L2KePegawai($allCutiDisetujui);
        $this->attachNamaApproverL1L2KePegawai($cutiPribadiDisetujui);
        $this->attachNamaApproverL1L2KePegawai($timCutiHariIni);

        return Inertia::render('Dashboard', [
            'stats'                 => $stats,
            'recentCuti'            => $recentCuti,
            'cutiDisetujuiData'      => $allCutiDisetujui,
            'cutiDisetujuiPribadi'   => $cutiPribadiDisetujui,
            'chartDataBackend'      => $chartDataBackend,
            'chartDataPribadi'      => $chartDataPribadi,
            'hariLiburs'            => $hariLiburs,
            'anggotaTim'            => $anggotaTim,
            'timCutiHariIni'        => $timCutiHariIni,
            'isAdminHR'             => $isAdminHR,
            'recentCutiPribadi'     => $recentCutiPribadi,
            'listKelompokSubstansi' => $listKelompokSubstansi,
            'filter'                => [
                'kelompok_substansi' => $kelompokSubstansiFilter,
            ],
        ]);
    }
}
