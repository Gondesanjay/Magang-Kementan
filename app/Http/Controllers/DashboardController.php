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

// ================= PERBAIKAN (L4 / KEPALA BIRO DISETUJUI OTOMATIS) =================
// Semua query yang memuat relasi 'approvalLogs' sekarang memakai
// 'approvalLogs.approver' (eager load). Tanpa ini, nama pejabat pada modal
// Detail Cuti (Dashboard.vue) bisa tampil "-" untuk log persetujuan otomatis
// Kepala Biro, karena Dashboard.vue membaca nama dari log.approver?.nama.
// ================= END PERBAIKAN =================
class DashboardController extends Controller
{
    /**
     * [BARU] SATU-SATUNYA tempat perhitungan saldo cuti untuk dashboard.
     * Rumus disamakan dengan RekapKuotaDetailController::hitungKuota()
     * (halaman admin):
     *   bawaan eligible = 12 jika sisa (tahun-2) & sisa (tahun-1) sama-sama 12,
     *                     selain itu min(6, sisa tahun-1)
     *   total tersedia  = (kuota + bawaan eligible) - terpakai
     * Dipakai oleh cabang karyawan, cabang atasan, dan attachSaldoInfoKePegawai().
     */
    private function hitungSaldoPegawai(int $pegawaiId, int $tahun, ?SaldoCuti $saldo = null): array
    {
        $saldo ??= SaldoCuti::where('pegawai_id', $pegawaiId)->where('tahun', $tahun)->first();
        $saldoTahunLalu = SaldoCuti::where('pegawai_id', $pegawaiId)->where('tahun', $tahun - 1)->first();

        $kuota            = (int) ($saldo->kuota_tahunan ?? 12);
        $sisaTahunLalu    = (int) ($saldo->carry_forward_normal ?? 0);
        $sisaDuaTahunLalu = (int) ($saldoTahunLalu->carry_forward_normal ?? 0);

        // Ikut koreksi_saldo Admin HR (NULL = ikut sistem).
        $terpakai = SaldoCuti::terpakaiEfektif($pegawaiId, $tahun, $saldo);

        $bawaanEligible = ($sisaDuaTahunLalu === 12 && $sisaTahunLalu === 12)
            ? 12
            : min(6, $sisaTahunLalu);

        $sisaKuotaTahunIni = max($kuota - $terpakai, 0);
        $totalTersedia     = max(($kuota + $bawaanEligible) - $terpakai, 0);

        return [
            'sisa_cuti'                => $totalTersedia,
            'kuota_tahunan'            => $kuota,
            'sisa_kuota_tahun_ini'     => $sisaKuotaTahunIni,
            'carry_forward_normal'     => $sisaTahunLalu,
            'sisa_cuti_dua_tahun_lalu' => $sisaDuaTahunLalu,
            'saldo_bawaan_eligible'    => $bawaanEligible,
            'cuti_terpakai'            => $terpakai,
            'total_cuti_tersedia'      => $totalTersedia,
            'saldo_tahunan'            => [
                ['tahun' => $tahun - 2, 'nilai' => $sisaDuaTahunLalu],
                ['tahun' => $tahun - 1, 'nilai' => $sisaTahunLalu],
                ['tahun' => $tahun,     'nilai' => $sisaKuotaTahunIni],
            ],
        ];
    }

    /**
     * Menempelkan info saldo cuti tahunan milik PEGAWAI PEMILIK PENGAJUAN
     * (bukan Auth::user()) ke setiap item collection, agar modal detail
     * menampilkan saldo yang benar.
     * [DIUBAH] Sekarang memakai hitungSaldoPegawai() agar rumus tidak menyimpang.
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
                $s = $this->hitungSaldoPegawai((int) $pegawai->id, $tahun);

                $cache[$pegawai->id] = [
                    'kuota_tahunan'            => $s['kuota_tahunan'],
                    'sisa_tahun_lalu'          => $s['carry_forward_normal'],
                    'sisa_dua_tahun_lalu'      => $s['sisa_cuti_dua_tahun_lalu'],
                    'sisa_hak_tahun_berjalan'  => $s['sisa_kuota_tahun_ini'],
                    'saldo_bawaan_eligible'    => $s['saldo_bawaan_eligible'],
                    'cuti_terpakai'            => $s['cuti_terpakai'],
                    'total_cuti_tersedia'      => $s['total_cuti_tersedia'],
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
        static $namaL3 = false;
        static $namaL4 = false;

        if ($namaL3 === false) {
            $namaL3 = $this->namaPejabatAktif(4) ?? 'Kasubag TU';
            $namaL4 = $this->namaPejabatAktif(6) ?? 'Kepala Biro Perencanaan';
        }

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
            $pegawai->nama_l3 = $namaL3;
            $pegawai->nama_l4 = $namaL4;
        }
    }

    private function namaPejabatAktif(int $roleId): ?string
    {
        return Pegawai::where('role_id', $roleId)
            ->where(fn($q) => $q->where('status_aktif', 'Aktif')->orWhereNull('status_aktif'))
            ->orderBy('id')
            ->value('nama');
    }

    public function index(Request $request)
    {
        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();
        $tahun = date('Y');

        // Tahun khusus CHART & DONUT (dipilih lewat dropdown di dashboard).
        // $tahun di atas TIDAK diubah karena dipakai kartu saldo/kuota/statistik
        // yang memang harus selalu tahun berjalan.
        $tahunChart = (int) $request->query('tahun', $tahun);
        if ($tahunChart < (int) $tahun - 2 || $tahunChart > (int) $tahun + 1) {
            $tahunChart = (int) $tahun;
        }

        // Diinisialisasi di luar if/elseif supaya bisa dipakai query chart
        // di bagian bawah (untuk role 1 nilainya tetap null).
        $wilayahField = null;
        $wilayahValue = null;

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
            $saldo = SaldoCuti::where('pegawai_id', $user->id)->where('tahun', $tahun)->first();

            // [DIUBAH] Seluruh perhitungan saldo lewat hitungSaldoPegawai()
            // (rumus sama dengan halaman admin).
            $saldoStats   = $this->hitungSaldoPegawai($user->id, (int) $tahun, $saldo);
            $cutiTerpakai = $saldoStats['cuti_terpakai'];

            // Info koreksi Admin HR, untuk label di kartu "Cuti Terpakai".
            $cutiTerpakaiSistem = (int) PengajuanCuti::where('pegawai_id', $user->id)
                ->where('jenis_cuti', 'Cuti Tahunan')
                ->where('status', 'disetujui')
                ->whereYear('tanggal_mulai', $tahun)
                ->sum('jumlah_hari');

            // Label hanya tampil kalau koreksi benar-benar BERBEDA dari hitungan sistem.
            $adaKoreksi = $saldo
                && $saldo->koreksi_saldo !== null
                && (int) $saldo->koreksi_saldo !== $cutiTerpakaiSistem;

            $stats = array_merge($saldoStats, [
                'ada_koreksi'          => $adaKoreksi,
                'cuti_terpakai_sistem' => $cutiTerpakaiSistem,
                'selisih_koreksi'      => $cutiTerpakai - $cutiTerpakaiSistem,
                'total_pengajuan' => PengajuanCuti::where('pegawai_id', $user->id)->count(),
                'menunggu'        => PengajuanCuti::where('pegawai_id', $user->id)->where('status', 'like', 'menunggu%')->count(),
                'disetujui'       => PengajuanCuti::where('pegawai_id', $user->id)->where('status', 'disetujui')->count(),
            ]);

            $recentCuti = PengajuanCuti::with(['atasanL1', 'atasanL2', 'atasanL3', 'atasanL4', 'approvalLogs.approver'])
                ->where('pegawai_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->take(5)
                ->get();

            $recentCutiPribadi = $recentCuti;

            $allCutiDisetujui = PengajuanCuti::with(['atasanL1', 'atasanL2', 'atasanL3', 'atasanL4', 'approvalLogs.approver'])
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

            // [DIUBAH] Perhitungan saldo atasan lewat method bersama, sehingga
            // field sisa_cuti_dua_tahun_lalu, saldo_bawaan_eligible, dan
            // saldo_tahunan ikut terkirim dan rumusnya sama dengan admin.
            $saldoStats = $this->hitungSaldoPegawai($user->id, (int) $tahun, $saldoAtasan);

            $stats = array_merge($saldoStats, [
                'total_antrean'      => $antreanQuery->count(),
                'cuti_tim_bulan_ini' => PengajuanCuti::when($wilayahField, function ($query) use ($wilayahField, $wilayahValue) {
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
                'total_anggota_tim'  => $anggotaTimQuery->count(),
            ]);

            // --- Recent Cuti (tabel atasan) ---
            $recentCuti = PengajuanCuti::with(['pegawai', 'atasanL1', 'atasanL2', 'atasanL3', 'atasanL4', 'approvalLogs.approver'])
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

            // --- Cuti Disetujui TIM (kartu, kalender, dll — tahun berjalan) ---
            // Exclude cuti milik atasan sendiri agar tidak dobel dengan data pribadi
            $allCutiDisetujui = PengajuanCuti::with(['pegawai', 'atasanL1', 'atasanL2', 'atasanL3', 'atasanL4', 'approvalLogs.approver'])
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
            $timCutiHariIni = PengajuanCuti::with(['pegawai', 'atasanL1', 'atasanL2', 'atasanL3', 'atasanL4', 'approvalLogs.approver'])
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
            // Chart dihitung di bagian "DATA CHART" di bawah (mengikuti tahun terpilih).
            if (in_array($user->role_id, [2, 3, 4, 6], true)) {
                $cutiPribadiDisetujui = PengajuanCuti::with(['atasanL1', 'atasanL2', 'atasanL3', 'atasanL4', 'approvalLogs.approver'])
                    ->where('pegawai_id', $user->id)
                    ->where('status', 'disetujui')
                    ->whereYear('tanggal_mulai', $tahun)
                    ->get();

                $recentCutiPribadi = PengajuanCuti::with(['atasanL1', 'atasanL2', 'atasanL3', 'atasanL4', 'approvalLogs.approver'])
                    ->where('pegawai_id', $user->id)
                    ->orderBy('created_at', 'desc')
                    ->take(5)
                    ->get();
            }
        }

        // =====================================================================
        // DATA CHART & DONUT — mengikuti tahun terpilih ($tahunChart)
        // Terpisah dari widget lain (kartu, kalender) yang tetap tahun berjalan.
        // =====================================================================
        if ($user->role_id === 1) {
            $cutiChartPribadi = PengajuanCuti::where('pegawai_id', $user->id)
                ->where('status', 'disetujui')
                ->whereYear('tanggal_mulai', $tahunChart)
                ->get();
            $cutiChartTim = $cutiChartPribadi;
        } else {
            $cutiChartTim = PengajuanCuti::with('pegawai')
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
                ->whereYear('tanggal_mulai', $tahunChart)
                ->get();

            $cutiChartPribadi = in_array($user->role_id, [2, 3, 4, 6], true)
                ? PengajuanCuti::where('pegawai_id', $user->id)
                ->where('status', 'disetujui')
                ->whereYear('tanggal_mulai', $tahunChart)
                ->get()
                : collect();
        }

        // Hitung total hari per bulan dari nol
        $chartDataBackend = array_fill(0, 12, 0);
        $chartDataPribadi = array_fill(0, 12, 0);

        foreach ($cutiChartTim as $cuti) {
            $i = (int) date('n', strtotime($cuti->tanggal_mulai)) - 1;
            if ($i >= 0 && $i <= 11) {
                $chartDataBackend[$i] += $cuti->jumlah_hari;
            }
        }
        foreach ($cutiChartPribadi as $cuti) {
            $i = (int) date('n', strtotime($cuti->tanggal_mulai)) - 1;
            if ($i >= 0 && $i <= 11) {
                $chartDataPribadi[$i] += $cuti->jumlah_hari;
            }
        }

        // Daftar opsi Filter Divisi
        $listKelompokSubstansi = Pegawai::whereNotNull('kelompok_substansi')
            ->where('kelompok_substansi', '!=', '')
            ->distinct()
            ->orderBy('kelompok_substansi')
            ->pluck('kelompok_substansi');

        // Tempel saldo + nama approver L1/L2 ke semua collection yang dikirim ke modal
        $this->attachSaldoInfoKePegawai($recentCuti, (int) $tahun);
        $this->attachSaldoInfoKePegawai($recentCutiPribadi, (int) $tahun);
        $this->attachSaldoInfoKePegawai($allCutiDisetujui, (int) $tahun);
        $this->attachSaldoInfoKePegawai($cutiPribadiDisetujui, (int) $tahun);
        $this->attachSaldoInfoKePegawai($timCutiHariIni, (int) $tahun);

        $this->attachNamaApproverL1L2KePegawai($recentCuti);
        $this->attachNamaApproverL1L2KePegawai($recentCutiPribadi);
        $this->attachNamaApproverL1L2KePegawai($allCutiDisetujui);
        $this->attachNamaApproverL1L2KePegawai($cutiPribadiDisetujui);
        $this->attachNamaApproverL1L2KePegawai($timCutiHariIni);

        return Inertia::render('Dashboard', [
            'stats'                     => $stats,
            'recentCuti'                => $recentCuti,
            'cutiDisetujuiData'         => $allCutiDisetujui,
            'cutiDisetujuiPribadi'      => $cutiPribadiDisetujui,
            'cutiDisetujuiChart'        => $cutiChartTim,
            'cutiDisetujuiPribadiChart' => $cutiChartPribadi,
            'chartDataBackend'          => $chartDataBackend,
            'chartDataPribadi'          => $chartDataPribadi,
            'hariLiburs'                => $hariLiburs,
            'anggotaTim'                => $anggotaTim,
            'timCutiHariIni'            => $timCutiHariIni,
            'isAdminHR'                 => $isAdminHR,
            'recentCutiPribadi'         => $recentCutiPribadi,
            'listKelompokSubstansi'     => $listKelompokSubstansi,
            // Tahun yang saldonya sudah dibuat Admin HR untuk user yang login.
            // Dipakai modal Revisi Tanggal Cuti untuk menolak Cuti Tahunan yang
            // menyentuh tahun tanpa saldo (mis. 2027 sebelum Generate Saldo).
            'tahun_saldo_tersedia'      => SaldoCuti::where('pegawai_id', $user->id)
                ->pluck('tahun')
                ->map(fn($t) => (int) $t)
                ->unique()
                ->values()
                ->all(),
            'filter'                    => [
                'kelompok_substansi' => $kelompokSubstansiFilter,
                'tahun'              => (string) $tahunChart,
            ],
        ]);
    }
}
