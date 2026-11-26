<?php

namespace App\Http\Controllers;

use App\Models\CutiDitangguhkan;
use App\Models\Pegawai;
use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use App\Models\HariLibur;
use App\Support\RincianSaldo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Carbon\Carbon;

/**
 * Dashboard semua role.
 *
 * Ringkasan aturan:
 * - Saldo: data diambil di hitungSaldoPegawai(); rumus aritmetikanya dari
 *   RincianSaldo::hitungDenganTgh(), satu sumber dengan Rekap Kuota Detail
 *   (Tgh mengikuti flag SaldoCuti: Tgh tahun ini hanya catatan, Tgh tahun lalu dikembalikan).
 * - Chart, kalender, dan KPI memakai cuti yang beririsan dengan tahun/bulan
 *   terpilih; cuti lintas tahun dipotong lewat potongCutiKeTahun().
 * - Anggota tim: lihat MODE_ATASAN_TERPISAH. Hanya pegawai aktif yang sudah
 *   terdaftar pada/sebelum akhir tahun berjalan.
 * - "Disetujui bulan ini" untuk atasan (role 2, 3, 4, 6) hanya cuti yang ia
 *   setujui sendiri; Admin HR melihat semua.
 * - Antrean (total_antrean) tidak difilter tanggal.
 * - Kolom relasi approver dibatasi agar remember_token/hash tidak bocor.
 *
 * Perbaikan N+1 (hasil tampilan sama):
 * - Relasi 'pegawai' ikut di-eager load pada $withAtasanPribadi.
 * - Koleksi yang sama (mis. $recentCuti = $recentCutiPribadi untuk role 1)
 *   hanya diproses sekali.
 * - Semua daftar cuti digabung lalu diproses sekali oleh
 *   RincianSaldo::lengkapi() (4 query tetap) dan attachNamaApprover...()
 *   (maksimal 2 query lewat Pegawai::petaKetua()).
 * - Tiga count() statistik pengajuan role 1 digabung jadi satu query.
 */
class DashboardController extends Controller
{
    // true : 'anggotaTim' hanya level di bawah pengguna (tanpa akun sendiri);
    //        pejabat L3/L4 dikirim terpisah lewat 'atasanPejabat'.
    // false: atasan sendiri & L3 (untuk L1/L2) ikut di 'anggotaTim', anggota
    //        tambahan aktif, 'atasanPejabat' kosong.
    private const MODE_ATASAN_TERPISAH = true;

    // Kolom & nilai yang menandai pegawai pensiun.
    private const KOLOM_STATUS_PEGAWAI = 'status_aktif';
    private const STATUS_PENSIUN = 'Pensiun';

    // Status AKTIF (NULL juga dianggap aktif).
    private const STATUS_AKTIF = 'Aktif';

    // Kolom tanggal pegawai mulai terdaftar.
    private const KOLOM_TANGGAL_MASUK = 'created_at';

    // role_id pejabat L3 (Kasubag TU); ikut di tim L1 & L2 (mode false).
    private const ROLE_L3 = 4;

    // Pegawai yang selalu ikut sebagai anggota tim (LIKE pada nama);
    // tidak dipakai pada MODE_ATASAN_TERPISAH = true.
    private const ANGGOTA_TAMBAHAN_NAMA = [];

    /**
     * Jumlah hari cuti ditangguhkan (Tgh) dengan tahun asal $tahun.
     * Sumber sama dengan RekapKuotaDetailController::hitungKuota().
     */
    private function hitungTangguh(int $pegawaiId, int $tahun): int
    {
        return (int) CutiDitangguhkan::where('pegawai_id', $pegawaiId)
            ->where('tahun_asal', $tahun)
            ->sum('jumlah_hari');
    }

    /**
     * Pengambilan data saldo dashboard (sama dengan Rekap Kuota Detail).
     * Bawaan = 12 jika sisa N-2 & N-1 sama-sama 12, selain itu min(6, sisa N-1).
     * Rumus aritmetika ada di RincianSaldo::hitungTotal().
     */
    private function hitungSaldoPegawai(int $pegawaiId, int $tahun, ?SaldoCuti $saldo = null): array
    {
        $saldo ??= SaldoCuti::where('pegawai_id', $pegawaiId)->where('tahun', $tahun)->first();
        $saldoTahunLalu = SaldoCuti::where('pegawai_id', $pegawaiId)->where('tahun', $tahun - 1)->first();

        $kuota            = (int) ($saldo->kuota_tahunan ?? 12);
        $sisaTahunLalu    = (int) ($saldo->carry_forward_normal ?? 0);
        $sisaDuaTahunLalu = (int) ($saldoTahunLalu->carry_forward_normal ?? 0);

        // Ikut koreksi Admin HR (NULL = ikut sistem).
        $terpakai = SaldoCuti::terpakaiEfektif($pegawaiId, $tahun, $saldo);

        // Tgh tahun berjalan: catatan, dibawa ke tahun depan (lihat flag SaldoCuti).
        $tangguh = $this->hitungTangguh($pegawaiId, $tahun);

        // Tgh tahun lalu: dikembalikan ke saldo tahun ini.
        $dikembalikan = $this->hitungTangguh($pegawaiId, $tahun - 1);

        $bawaanEligible = SaldoCuti::hitungBawaan(
            $sisaDuaTahunLalu,
            $sisaTahunLalu,
            $kuota
        );

        $hasil = RincianSaldo::hitungDenganTgh(
            (int) $kuota,
            (int) $bawaanEligible,
            (int) $dikembalikan, // Tgh tahun lalu
            (int) $terpakai,
            (int) $tangguh       // Tgh tahun ini
        );

        $terpakaiTotal     = $hasil['cuti_terpakai_total'];
        $sisaKuotaTahunIni = $hasil['sisa_hak_tahun_berjalan'];
        $totalTersedia     = $hasil['total_cuti_tersedia'];

        return [
            'sisa_cuti'                     => $totalTersedia,
            'tahun_saldo'                   => $tahun,
            'kuota_tahunan'                 => $kuota,
            'sisa_kuota_tahun_ini'          => $sisaKuotaTahunIni,
            'carry_forward_normal'          => $sisaTahunLalu,
            'sisa_cuti_dua_tahun_lalu'      => $sisaDuaTahunLalu,
            'saldo_bawaan_eligible'         => $bawaanEligible,
            // Hanya cuti yang benar-benar digunakan (termasuk koreksi Admin HR).
            'cuti_terpakai'                 => $terpakai,
            'cuti_digunakan'                => $terpakai,
            // Digunakan + ditangguhkan: angka utama kartu "Cuti Terpakai".
            'cuti_terpakai_total'           => $terpakaiTotal,
            'cuti_ditangguhkan'             => $tangguh,
            // Dari penangguhan tahun lalu, masuk ke total tahun ini.
            'cuti_dikembalikan'             => $dikembalikan,
            // Informasi: akan dikembalikan ke saldo tahun depan.
            'akan_dikembalikan_tahun_depan' => $tangguh,
            'total_cuti_tersedia'           => $totalTersedia,
            'saldo_tahunan'                 => [
                ['tahun' => $tahun - 2, 'nilai' => $sisaDuaTahunLalu],
                ['tahun' => $tahun - 1, 'nilai' => $sisaTahunLalu],
                ['tahun' => $tahun,     'nilai' => $sisaKuotaTahunIni],
            ],
        ];
    }

    /**
     * Menempelkan info saldo milik pegawai pemilik pengajuan (bukan
     * Auth::user()) ke setiap item, agar modal detail menampilkan saldo yang benar.
     *
     * @param  iterable<int, mixed>  $collection
     */
    private function attachSaldoInfoKePegawai(iterable $collection, int $tahun): void
    {
        RincianSaldo::lengkapi($collection, $tahun);
    }

    /**
     * Menempelkan nama atasan L1-L4 milik pegawai pemilik pengajuan ke
     * $item->pegawai.
     *
     * [N+1] L1 & L2 semua pegawai dimuat sekaligus lewat Pegawai::petaKetua()
     * (maksimal 2 query); L3 & L4 dicari sekali.
     *
     * @param  iterable<int, mixed>  $collection
     */
    private function attachNamaApproverL1L2KePegawai(iterable $collection): void
    {
        $items = collect($collection);

        $namaL3 = $this->namaPejabatAktif(4) ?? 'Kasubag TU';
        $namaL4 = $this->namaPejabatAktif(6) ?? 'Kepala Biro Perencanaan';

        $peta = Pegawai::petaKetua(
            $items->map(fn($item) => $item->pegawai ?? null)->filter()
        );

        foreach ($items as $item) {
            $pegawai = $item->pegawai ?? null;
            if (!$pegawai) {
                continue;
            }

            $pegawai->nama_l1 = $peta[(int) $pegawai->id]['nama_l1'] ?? null;
            $pegawai->nama_l2 = $peta[(int) $pegawai->id]['nama_l2'] ?? null;
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

    /**
     * Query "Anggota Tim". Mode terpisah: hanya role di bawah pengguna, tanpa
     * akun sendiri. Mode lain: akun sendiri, L3 (untuk L1/L2), dan anggota
     * tambahan ikut. Selalu: pegawai aktif & terdaftar sebelum akhir $tahun.
     */
    private function queryAnggotaTim(
        Pegawai $user,
        ?string $wilayahField,
        ?string $wilayahValue,
        ?string $kelompokSubstansiFilter,
        int $tahun
    ) {
        $kolomStatus = self::KOLOM_STATUS_PEGAWAI;
        $kolomMasuk  = self::KOLOM_TANGGAL_MASUK;
        $akhirTahun  = Carbon::create($tahun, 12, 31)->endOfDay();
        $terpisah    = self::MODE_ATASAN_TERPISAH;

        // Role "anggota tim" pada mode hierarki.
        //   L4 (6): L3 ke bawah | L3 (4): L2 ke bawah | L2 (3): L1 & L2
        //   Admin HR (5): L1-L3 (L3 & L4 tampil di bagian "Atasan")
        //   L1 (2) & lainnya: pegawai biasa
        $roleBawahan = match ($user->role_id) {
            6       => [1, 2, 3, 4],
            4       => [1, 2, 3],
            3       => [1, 2],
            5       => [1, 2, 3],
            default => [1],
        };

        return Pegawai::query()
            ->where(function ($q) use ($user, $roleBawahan, $terpisah, $wilayahField, $wilayahValue, $kelompokSubstansiFilter) {
                $q->where(function ($scope) use ($user, $roleBawahan, $terpisah, $wilayahField, $wilayahValue, $kelompokSubstansiFilter) {
                    if ($terpisah) {
                        $scope->whereIn('role_id', $roleBawahan);
                    } elseif (in_array($user->role_id, [3, 4, 5, 6], true)) {
                        $scope->where('role_id', '!=', 5);
                    } else {
                        $scope->where('role_id', 1);
                    }

                    if ($wilayahField) {
                        $scope->where($wilayahField, $wilayahValue);
                    }
                    if ($kelompokSubstansiFilter) {
                        $scope->where('kelompok_substansi', $kelompokSubstansiFilter);
                    }
                });

                // Akun atasan sendiri ikut di daftar (mode false).
                if (!$terpisah && !$kelompokSubstansiFilter && in_array($user->role_id, [2, 3, 4, 6], true)) {
                    $q->orWhere('id', $user->id);
                }

                // Pejabat L3 ikut di tim L1 & L2 (mode false).
                if (!$terpisah && !$kelompokSubstansiFilter && in_array($user->role_id, [2, 3], true)) {
                    $q->orWhere('role_id', self::ROLE_L3);
                }

                // Anggota tambahan (mode false, tanpa filter Divisi).
                if (!$terpisah && !$kelompokSubstansiFilter) {
                    foreach (self::ANGGOTA_TAMBAHAN_NAMA as $nama) {
                        $q->orWhere('nama', 'LIKE', '%' . $nama . '%');
                    }
                }
            })
            // Mode hierarki: akun sendiri tidak ikut.
            ->when($terpisah, fn($q) => $q->where('id', '!=', $user->id))
            // Hanya pegawai aktif (NULL dianggap aktif).
            ->where(function ($q) use ($kolomStatus) {
                $q->whereNull($kolomStatus)
                    ->orWhere($kolomStatus, self::STATUS_AKTIF);
            })
            // Terdaftar pada/sebelum akhir tahun berjalan.
            ->where(function ($q) use ($kolomMasuk, $akhirTahun) {
                $q->whereNull($kolomMasuk)
                    ->orWhere($kolomMasuk, '<=', $akhirTahun);
            });
    }

    /**
     * Pejabat L3 & L4 untuk bagian "Atasan" di popup tim (hanya mode terpisah).
     * L1, L2, HR melihat L3 & L4; L3 melihat L4; lainnya kosong.
     */
    private function dataAtasanPejabat(Pegawai $user)
    {
        if (!self::MODE_ATASAN_TERPISAH) {
            return collect();
        }

        $rolePejabat = match ($user->role_id) {
            2, 3, 5 => [4, 6],
            4       => [6],
            default => [],
        };

        if (empty($rolePejabat)) {
            return collect();
        }

        $pejabat = Pegawai::whereIn('role_id', $rolePejabat)
            ->where('id', '!=', $user->id)
            ->where(function ($q) {
                $q->whereNull(self::KOLOM_STATUS_PEGAWAI)
                    ->orWhere(self::KOLOM_STATUS_PEGAWAI, '!=', self::STATUS_PENSIUN);
            })
            ->select('id', 'nama', 'nip', 'jabatan', 'foto_profil', 'role_id')
            ->get()
            ->sortByDesc('role_id'); // L4 dulu, lalu L3

        $idSedangCuti = PengajuanCuti::whereIn('pegawai_id', $pejabat->pluck('id'))
            ->where('status', 'disetujui')
            ->whereDate('tanggal_mulai', '<=', Carbon::today())
            ->whereDate('tanggal_selesai', '>=', Carbon::today())
            ->pluck('pegawai_id')
            ->all();

        return $pejabat->map(fn($p) => [
            'id'          => $p->id,
            'nama'        => $p->nama,
            'nip'         => $p->nip,
            'foto_profil' => $p->foto_profil,
            'label_level' => $p->role_id === 6 ? 'Kepala Biro Perencanaan' : 'Kepala Subbagian Tata Usaha',
            'sedang_cuti' => in_array($p->id, $idSedangCuti),
        ])->values();
    }

    /**
     * Memotong cuti lintas tahun ke bagian yang jatuh di $tahun (salinan untuk
     * chart, database tidak berubah). Dihitung hari kerja, atau hari kalender
     * bila cuti aslinya dihitung kalender. Hasil 0 hari dibuang.
     *
     * @param  iterable<int, PengajuanCuti>  $daftarCuti
     */
    private function potongCutiKeTahun($daftarCuti, int $tahun)
    {
        $awalTahun  = Carbon::create($tahun, 1, 1)->startOfDay();
        $akhirTahun = Carbon::create($tahun, 12, 31)->startOfDay();

        $libur = HariLibur::whereBetween('tanggal', [
            Carbon::create($tahun - 1, 1, 1)->toDateString(),
            Carbon::create($tahun + 1, 12, 31)->toDateString(),
        ])
            ->pluck('tanggal')
            ->map(fn($t) => Carbon::parse($t)->toDateString())
            ->flip()
            ->all();

        $hitung = function (Carbon $dari, Carbon $sampai, bool $kalender) use ($libur): int {
            $n = 0;
            for ($d = $dari->copy(); $d->lte($sampai); $d->addDay()) {
                if ($kalender || (!$d->isWeekend() && !isset($libur[$d->toDateString()]))) {
                    $n++;
                }
            }
            return $n;
        };

        return collect($daftarCuti)
            ->map(function ($cuti) use ($awalTahun, $akhirTahun, $hitung) {
                $mulai   = Carbon::parse($cuti->tanggal_mulai)->startOfDay();
                $selesai = Carbon::parse($cuti->tanggal_selesai)->startOfDay();

                // Tidak melewati batas tahun: biarkan seperti semula.
                if ($mulai->gte($awalTahun) && $selesai->lte($akhirTahun)) {
                    return $cuti;
                }

                // Deteksi cuti yang dihitung per hari kalender.
                $totalKalender = (int) abs($mulai->diffInDays($selesai)) + 1;
                $totalKerja    = $hitung($mulai, $selesai, false);
                $modeKalender  = ((int) $cuti->jumlah_hari === $totalKalender)
                    && ($totalKalender !== $totalKerja);

                $mulaiPotong   = $mulai->lt($awalTahun) ? $awalTahun->copy() : $mulai->copy();
                $selesaiPotong = $selesai->gt($akhirTahun) ? $akhirTahun->copy() : $selesai->copy();

                if ($mulaiPotong->gt($selesaiPotong)) {
                    return null;
                }

                $hari = $hitung($mulaiPotong, $selesaiPotong, $modeKalender);
                if ($hari <= 0) {
                    return null;
                }

                // Salinan khusus chart (model asli & database tidak disentuh).
                $salinan = clone $cuti;
                $salinan->setAttribute('tanggal_mulai', $mulaiPotong->toDateString());
                $salinan->setAttribute('tanggal_selesai', $selesaiPotong->toDateString());
                $salinan->setAttribute('jumlah_hari', $hari);

                return $salinan;
            })
            ->filter()
            ->values();
    }

    public function index(Request $request)
    {
        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();
        $tahun = (int) date('Y');

        // Rentang tahun & bulan berjalan untuk cuti yang beririsan
        // (cuti lintas tahun/bulan tetap terbaca di periode berikutnya).
        $awalTahunIni  = Carbon::create($tahun, 1, 1)->toDateString();
        $akhirTahunIni = Carbon::create($tahun, 12, 31)->toDateString();
        $awalBulanIni  = Carbon::create($tahun, (int) date('n'), 1)->startOfMonth()->toDateString();
        $akhirBulanIni = Carbon::create($tahun, (int) date('n'), 1)->endOfMonth()->toDateString();

        // Tahun khusus chart & donut (dropdown dashboard). $tahun tidak diubah
        // karena dipakai kartu saldo/kuota/statistik (selalu tahun berjalan).
        $tahunChart = (int) $request->query('tahun', $tahun);
        if ($tahunChart < $tahun - 2 || $tahunChart > $tahun + 1) {
            $tahunChart = $tahun;
        }

        // Diinisialisasi di luar if/elseif agar bisa dipakai query chart di
        // bawah (untuk role 1 tetap null).
        $wilayahField = null;
        $wilayahValue = null;

        $stats                 = [];
        $recentCuti            = [];
        $recentCutiPribadi     = [];
        $anggotaTim            = [];
        $allCutiDisetujui      = [];
        $timCutiHariIni        = [];
        $cutiDisetujuiBulanIni = collect();
        $cutiPribadiDisetujui  = collect();
        $chartDataBackend      = array_fill(0, 12, 0);
        $chartDataPribadi      = array_fill(0, 12, 0);

        // Filter Divisi hanya untuk role 4, 5, 6 (L3, Admin HR, L4).
        $kelompokSubstansiFilter = in_array($user->role_id, [1, 2, 3], true)
            ? null
            : $request->query('kelompok_substansi');

        $isAdminHR = $user->role_id === 5;

        $hariLiburs = HariLibur::where('tanggal', '>=', Carbon::today()->toDateString())
            ->orderBy('tanggal', 'asc')
            ->get();

        // Relasi atasan & approver: hanya kolom yang dibutuhkan UI.
        // [N+1] 'pegawai' ikut di-eager load (dibaca RincianSaldo::lengkapi()
        // dan attachNamaApproverL1L2KePegawai()).
        $withAtasanPribadi = [
            'pegawai',
            'atasanL1:id,nama',
            'atasanL2:id,nama',
            'atasanL3:id,nama',
            'atasanL4:id,nama',
            'approvalLogs.approver:id,nama,jabatan',
        ];
        $withAtasanTim = [
            'pegawai',
            'atasanL1:id,nama',
            'atasanL2:id,nama',
            'atasanL3:id,nama',
            'atasanL4:id,nama',
            'approvalLogs.approver:id,nama,jabatan',
        ];

        // =====================================================================
        // DASHBOARD KARYAWAN (role 1)
        // =====================================================================
        if ($user->role_id === 1) {
            $saldo = SaldoCuti::where('pegawai_id', $user->id)->where('tahun', $tahun)->first();

            $saldoStats   = $this->hitungSaldoPegawai($user->id, $tahun, $saldo);
            $cutiTerpakai = $saldoStats['cuti_terpakai'];

            $cutiTerpakaiSistem = SaldoCuti::terpakaiMurni((int) $user->id, $tahun);

            $adaKoreksi = $saldo
                && $saldo->koreksi_saldo !== null
                && (int) $saldo->koreksi_saldo !== $cutiTerpakaiSistem;

            // [N+1] Tiga count() digabung menjadi satu query.
            $ringkasPengajuan = PengajuanCuti::where('pegawai_id', $user->id)
                ->selectRaw("COUNT(*) AS total, SUM(CASE WHEN status LIKE 'menunggu%' THEN 1 ELSE 0 END) AS menunggu, SUM(CASE WHEN status = 'disetujui' THEN 1 ELSE 0 END) AS disetujui")
                ->first();

            $stats = array_merge($saldoStats, [
                'ada_koreksi'          => $adaKoreksi,
                'cuti_terpakai_sistem' => $cutiTerpakaiSistem,
                'selisih_koreksi'      => $cutiTerpakai - $cutiTerpakaiSistem,
                'total_pengajuan'      => (int) ($ringkasPengajuan->total ?? 0),
                'menunggu'             => (int) ($ringkasPengajuan->menunggu ?? 0),
                'disetujui'            => (int) ($ringkasPengajuan->disetujui ?? 0),
            ]);

            $recentCuti = PengajuanCuti::with($withAtasanPribadi)
                ->where('pegawai_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->take(5)
                ->get();

            $recentCutiPribadi = $recentCuti;

            // Beririsan dengan tahun berjalan (cuti lintas tahun ikut terbaca).
            $allCutiDisetujui = PengajuanCuti::with($withAtasanPribadi)
                ->where('pegawai_id', $user->id)
                ->where('status', 'disetujui')
                ->whereDate('tanggal_mulai', '<=', $akhirTahunIni)
                ->whereDate('tanggal_selesai', '>=', $awalTahunIni)
                ->get();

            $cutiPribadiDisetujui = $allCutiDisetujui;

            // =====================================================================
            // DASHBOARD ATASAN & ADMIN (L1, L2, L3, L4, HR)
            // =====================================================================
        } elseif (in_array($user->role_id, [2, 3, 4, 5, 6])) {

            // Wilayah: L1 (role 2) tim_kerja, L2 (role 3) kelompok_substansi,
            // L3/L4/HR tidak dibatasi.
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
            // Tidak difilter tanggal: pengajuan boleh dibuat setelah tanggal cuti.
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
            $anggotaTim = $this->queryAnggotaTim(
                $user,
                $wilayahField,
                $wilayahValue,
                $kelompokSubstansiFilter,
                $tahun
            )->get();

            // Total dari daftar yang sama, jadi pasti cocok.
            $totalAnggota = $anggotaTim->count();

            // ID anggota tim: KPI, kalender & daftar cuti selalu sama dengan
            // popup Daftar Anggota Tim.
            $idAnggota = $anggotaTim->pluck('id')->all();

            // --- Saldo Atasan (role 2,3,4,6) ---
            $saldoAtasan = null;
            if (in_array($user->role_id, [2, 3, 4, 6], true)) {
                $saldoAtasan = SaldoCuti::firstOrCreate(
                    ['pegawai_id' => $user->id, 'tahun' => $tahun],
                    ['kuota_tahunan' => 12, 'sisa' => 12]
                );
            }

            $saldoStats = $this->hitungSaldoPegawai($user->id, $tahun, $saldoAtasan);

            // --- Cuti Disetujui BULAN INI (beririsan dengan bulan berjalan) ---
            // Atasan role 2, 3, 4, 6: hanya cuti yang ia setujui sendiri.
            // Admin HR (role 5) melihat semua.
            $cutiDisetujuiBulanIni = PengajuanCuti::with($withAtasanTim)
                ->whereIn('pegawai_id', $idAnggota)
                ->when($user->role_id !== 5, function ($query) use ($user) {
                    $query->whereHas('approvalLogs', function ($q) use ($user) {
                        $q->where('approver_id', $user->id)
                            ->where('keputusan', 'setuju');
                    });
                })
                ->where('status', 'disetujui')
                ->whereDate('tanggal_mulai', '<=', $akhirBulanIni)
                ->whereDate('tanggal_selesai', '>=', $awalBulanIni)
                ->orderBy('tanggal_mulai')
                ->get();

            $stats = array_merge($saldoStats, [
                'total_antrean'      => $antreanQuery->count(),
                'cuti_tim_bulan_ini' => $cutiDisetujuiBulanIni->count(),
                'total_anggota_tim'  => $totalAnggota,
            ]);

            // --- Recent Cuti (tabel atasan) ---
            $recentCuti = PengajuanCuti::with($withAtasanTim)
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

            // --- Cuti Disetujui TIM (tahun berjalan, beririsan) ---
            // Untuk kalender "Ketersediaan Tim"; daftar anggota sama dengan popup.
            $allCutiDisetujui = PengajuanCuti::with($withAtasanTim)
                ->whereIn('pegawai_id', $idAnggota)
                ->where('status', 'disetujui')
                ->whereDate('tanggal_mulai', '<=', $akhirTahunIni)
                ->whereDate('tanggal_selesai', '>=', $awalTahunIni)
                ->get();

            // --- Tim cuti hari ini ---
            $timCutiHariIni = PengajuanCuti::with($withAtasanTim)
                ->whereIn('pegawai_id', $idAnggota)
                ->where('status', 'disetujui')
                ->whereDate('tanggal_mulai', '<=', Carbon::today())
                ->whereDate('tanggal_selesai', '>=', Carbon::today())
                ->get();

            // --- Data cuti pribadi untuk atasan (role 2,3,4,6) ---
            if (in_array($user->role_id, [2, 3, 4, 6], true)) {
                $cutiPribadiDisetujui = PengajuanCuti::with($withAtasanPribadi)
                    ->where('pegawai_id', $user->id)
                    ->where('status', 'disetujui')
                    ->whereDate('tanggal_mulai', '<=', $akhirTahunIni)
                    ->whereDate('tanggal_selesai', '>=', $awalTahunIni)
                    ->get();

                $recentCutiPribadi = PengajuanCuti::with($withAtasanPribadi)
                    ->where('pegawai_id', $user->id)
                    ->orderBy('created_at', 'desc')
                    ->take(5)
                    ->get();
            }
        }

        // =====================================================================
        // DATA CHART & DONUT: mengikuti tahun terpilih ($tahunChart)
        // Cuti beririsan dengan tahun terpilih, dipotong lewat
        // potongCutiKeTahun() agar cuti lintas tahun terbagi benar.
        // =====================================================================
        $awalTahunChart  = $tahunChart . '-01-01';
        $akhirTahunChart = $tahunChart . '-12-31';

        if ($user->role_id === 1) {
            $cutiChartPribadi = $this->potongCutiKeTahun(
                PengajuanCuti::where('pegawai_id', $user->id)
                    ->where('status', 'disetujui')
                    ->whereDate('tanggal_mulai', '<=', $akhirTahunChart)
                    ->whereDate('tanggal_selesai', '>=', $awalTahunChart)
                    ->get(),
                $tahunChart
            );
            $cutiChartTim = $cutiChartPribadi;
        } else {
            // Akun sendiri dikecualikan dari chart TIM (semua role); cuti
            // pribadinya tampil di chart "Pribadi".
            $cutiChartTim = $this->potongCutiKeTahun(
                PengajuanCuti::with('pegawai')
                    ->where('pegawai_id', '!=', $user->id)
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
                    ->whereDate('tanggal_mulai', '<=', $akhirTahunChart)
                    ->whereDate('tanggal_selesai', '>=', $awalTahunChart)
                    ->get(),
                $tahunChart
            );

            $cutiChartPribadi = in_array($user->role_id, [2, 3, 4, 6], true)
                ? $this->potongCutiKeTahun(
                    PengajuanCuti::where('pegawai_id', $user->id)
                        ->where('status', 'disetujui')
                        ->whereDate('tanggal_mulai', '<=', $akhirTahunChart)
                        ->whereDate('tanggal_selesai', '>=', $awalTahunChart)
                        ->get(),
                    $tahunChart
                )
                : collect();
        }

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

        $listKelompokSubstansi = Pegawai::whereNotNull('kelompok_substansi')
            ->where('kelompok_substansi', '!=', '')
            ->distinct()
            ->orderBy('kelompok_substansi')
            ->pluck('kelompok_substansi');

        // [N+1] Kumpulkan daftar unik. Untuk role 1, $recentCuti dan
        // $recentCutiPribadi adalah objek yang sama (juga $allCutiDisetujui
        // dan $cutiPribadiDisetujui). Array kosong dilewati.
        $daftarUnik = [];
        foreach ([$recentCuti, $recentCutiPribadi, $allCutiDisetujui, $cutiPribadiDisetujui, $timCutiHariIni, $cutiDisetujuiBulanIni] as $daftar) {
            if (is_object($daftar)) {
                $daftarUnik[spl_object_id($daftar)] = $daftar;
            }
        }

        // [N+1] Semua daftar digabung lalu diproses sekali, agar pegawai yang
        // sama tidak dihitung ulang.
        $semuaItem = collect($daftarUnik)->flatMap(fn($d) => $d->all())->values();

        $this->attachSaldoInfoKePegawai($semuaItem, $tahun);
        $this->attachNamaApproverL1L2KePegawai($semuaItem);

        // Pejabat L3 & L4 untuk bagian "Atasan" di modal Daftar Anggota Tim
        // (kosong bila MODE_ATASAN_TERPISAH = false).
        $atasanPejabat = $this->dataAtasanPejabat($user);

        return Inertia::render('Dashboard', [
            'stats'                     => $stats,
            'recentCuti'                => $recentCuti,
            'cutiDisetujuiData'         => $allCutiDisetujui,
            'cutiDisetujuiBulanIni'     => $cutiDisetujuiBulanIni,
            'cutiDisetujuiPribadi'      => $cutiPribadiDisetujui,
            'cutiDisetujuiChart'        => $cutiChartTim,
            'cutiDisetujuiPribadiChart' => $cutiChartPribadi,
            'chartDataBackend'          => $chartDataBackend,
            'chartDataPribadi'          => $chartDataPribadi,
            'hariLiburs'                => $hariLiburs,
            'anggotaTim'                => $anggotaTim,
            'atasanPejabat'             => $atasanPejabat,
            'timCutiHariIni'            => $timCutiHariIni,
            'isAdminHR'                 => $isAdminHR,
            'recentCutiPribadi'         => $recentCutiPribadi,
            'listKelompokSubstansi'     => $listKelompokSubstansi,
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
