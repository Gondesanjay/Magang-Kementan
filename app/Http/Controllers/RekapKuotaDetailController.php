<?php

namespace App\Http\Controllers;

use App\Models\CutiDitangguhkan;
use App\Models\KoreksiCutiBulanan;
use App\Models\LogPerubahanSaldo;
use App\Models\Notifikasi;
use App\Models\Pegawai;
use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use App\Support\RincianSaldo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// ================= CATATAN PERUBAHAN =================
// - Cuti Terpakai di halaman ini READ-ONLY. Koreksinya diedit per bulan di
//   Rekap Laporan (tabel koreksi_cuti_bulanan). hitungKuota() memakai koreksi
//   bulanan bila ada; bila tidak, aturan lama (koreksi_saldo tahunan / hitungan sistem).
// - updateSaldo() tidak lagi menerima koreksi_saldo / pakai_sistem. Hak, Sisa N-1,
//   Sisa N-2, dan Tgh tetap bisa diedit. Penulisan data dibungkus DB::transaction;
//   notifikasi dikirim SETELAH transaksi berhasil.
// - Field baru ke Vue: 'ada_koreksi_bulanan', 'koreksi_bulanan', 'terpakai_dikunci',
//   'dompet_tahun_lalu_asli'.
// - kirimNotifikasiPerubahanSaldo() & setTotalDitangguhkan() bersifat public
//   (dipakai AdminController).
// - Anti N+1: relasi di buildQuery() dibatasi, log perubahan dimuat sekali per
//   halaman (muatLogPerubahan), koreksi bulanan di-cache per tahun, pejabat aktif
//   dimuat sekali (pejabatAktif).
// - Rumus saldo satu sumber lewat RincianSaldo::hitungTotal(). 'total_hak_cuti' &
//   'total_cuti_tersedia' tetap berarti Total Hak Tersedia (SEBELUM dikurangi Terpakai).
// ================= END CATATAN PERUBAHAN =================

class RekapKuotaDetailController extends Controller
{
    // Alasan perubahan WAJIB diisi bila ada angka berubah. Biarkan false sampai
    // form di RekapKuotaDetail.vue punya kolom alasan (kalau true lebih dulu,
    // tombol Simpan ditolak).
    private const WAJIB_ALASAN_KOREKSI = false;

    // Pengisi kolom cuti_ditangguhkans.alasan_penangguhan (WAJIB, tanpa default di DB)
    // untuk baris hasil edit manual Tgh oleh Admin HR.
    private const ALASAN_TGH_MANUAL = 'Koreksi manual Admin HR';

    // true : Total Tersedia tahun N = Hak N + Bawaan Eligible N + Tgh tahun asal N-1.
    // false: Tgh hanya informasi. Tgh N-2 selalu hanya catatan.
    private const TGH_MASUK_TOTAL = true;

    // true : Tgh tahun N mengurangi Sisa N dan Saldo Akhir N.
    // false: Tgh N hanya catatan.
    private const TGH_TAHUN_INI_MENGURANGI_SISA = false;

    // Urutan tampil per role_id (angka kecil = lebih atas). Sama dengan
    // $urutanJabatan di AdminController.
    // 6 = Kepala Biro (L4), 4 = Kasubag TU (L3), 3 = Ketua Kelompok (L2),
    // 2 = Ketua Tim (L1), 1 = Pegawai/Staf, lainnya = 99.
    private array $urutanJabatan = [
        6 => 1,
        4 => 2,
        3 => 3,
        2 => 4,
        1 => 5,
    ];

    // Level approval (1-4) -> role_id. Sama dengan $roleYangDibutuhkan di ApprovalController.
    private array $levelToRoleId = [
        1 => 2,
        2 => 3,
        3 => 4,
        4 => 6,
    ];

    private array $levelLabels = [
        1 => 'Ketua Tim Kerja',
        2 => 'Ketua Kelompok Substansi',
        3 => 'Kasubag TU',
        4 => 'Kepala Biro Perencanaan',
    ];

    // Cache nama approver supaya tidak query berulang
    private array $approverNameCache = [];

    // Pejabat aktif role 2, 3, 4, 6 (dimuat sekali), dikelompokkan per role_id
    private ?Collection $pejabatAktifPerRole = null;

    // [tahun => [pegawai_id => [bulan => nilai]]], dimuat sekali per tahun
    private array $cacheKoreksiBulanan = [];

    // [tahun => [pegawai_id => SUM(nilai - nilai_sistem)]], diisi bersama cache di atas
    private array $cacheSelisihKoreksi = [];

    // [tahun => [pegawai_id => [baris log]]], diisi sekali per halaman
    private array $cacheLogPerubahan = [];

    // ================= HELPER: LOG PERUBAHAN SALDO =================

    // Format baris log yang dikirim ke Vue (dipakai jalur cache dan fallback).
    private function formatLogPerubahan(LogPerubahanSaldo $log): array
    {
        return [
            'id' => $log->id,
            'kolom' => $log->kolom,
            'nilai_lama' => $log->nilai_lama,
            'nilai_baru' => $log->nilai_baru,
            'alasan' => $log->alasan,
            'oleh' => $log->pengubah->nama ?? '-',
            'waktu' => $log->created_at?->format('d-m-Y H:i'),
        ];
    }

    /**
     * Muat log perubahan saldo semua pegawai di satu halaman dalam SATU query
     * (20 log terbaru per pegawai) ke $cacheLogPerubahan[tahun][pegawai_id].
     *
     * @param  array<int, int>  $pegawaiIds
     */
    private function muatLogPerubahan(array $pegawaiIds, int $tahun): void
    {
        if (empty($pegawaiIds)) {
            return;
        }

        $semua = LogPerubahanSaldo::with('pengubah:id,nama')
            ->whereIn('pegawai_id', $pegawaiIds)
            ->where('tahun', $tahun)
            ->latest('id')
            ->get()
            ->groupBy('pegawai_id');

        foreach ($pegawaiIds as $id) {
            $this->cacheLogPerubahan[$tahun][$id] = ($semua->get($id) ?? collect())
                ->take(20)
                ->map(fn($log) => $this->formatLogPerubahan($log))
                ->values()
                ->all();
        }
    }
    // ================= END HELPER LOG PERUBAHAN SALDO =================

    // ================= HELPER: FILTER TAHUN =================

    // Satu-satunya penentu "tahun rekap": dari ?tahun=, fallback ke tahun berjalan.
    private function resolveTahun(Request $request): int
    {
        $tahun = (int) $request->input('tahun', date('Y'));

        return ($tahun >= 2000 && $tahun <= 2100)
            ? $tahun
            : (int) date('Y');
    }

    // Opsi dropdown Filter Tahun: tahun sekarang +1, 0, -1, -2 (plus tahun terpilih).
    private function buildDaftarTahun(int $tahunTerpilih): array
    {
        $tahunSekarang = (int) date('Y');

        $daftar = [
            $tahunSekarang + 1,
            $tahunSekarang,
            $tahunSekarang - 1,
            $tahunSekarang - 2,
        ];

        if (!in_array($tahunTerpilih, $daftar, true)) {
            $daftar[] = $tahunTerpilih;
        }

        rsort($daftar);

        return array_values(array_unique($daftar));
    }
    // ================= END HELPER FILTER TAHUN =================

    // ================= HELPER: FILTER STATUS KEPEGAWAIAN =================
    // Nilai status_aktif sama dengan STATUS_PEGAWAI di AdminController
    // ('Aktif', 'Pensiun', 'Mutasi Keluar', 'Resign / Berhenti', 'Meninggal Dunia').
    // status_aktif NULL dianggap 'Aktif'.

    // Hanya pegawai aktif (atau NULL). Dipakai untuk mencari nama approver.
    private function hanyaAktif(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->where('status_aktif', 'Aktif')
                ->orWhereNull('status_aktif');
        });
    }

    /**
     * Daftar Rekap tahun tertentu: pegawai AKTIF selalu tampil; NON-AKTIF hanya
     * bila di tahun itu punya Cuti Tahunan disetujui / ditangguhkan setelah
     * disetujui (arsip tahun lalu tetap utuh).
     *
     * Dasarnya riwayat cuti, BUKAN baris saldo_cutis, supaya saldo "siluman" hasil
     * Generate Saldo untuk pegawai pensiun tidak memunculkannya di tahun baru.
     */
    private function hanyaAktifAtauPunyaCutiDiTahun(Builder $query, int $tahun): Builder
    {
        return $query->where(function ($q) use ($tahun) {
            $q->where('status_aktif', 'Aktif')
                ->orWhereNull('status_aktif')
                ->orWhereHas('pengajuanCuti', function ($p) use ($tahun) {
                    $p->where('jenis_cuti', 'Cuti Tahunan')
                        ->whereYear('tanggal_mulai', $tahun)
                        ->where(function ($status) {
                            $status->where('status', 'disetujui')
                                ->orWhere(function ($ditangguhkan) {
                                    $ditangguhkan->where('status', 'dibatalkan_ditangguhkan')
                                        ->whereHas('cutiDitangguhkan');
                                });
                        });
                });
        });
    }
    // ================= END HELPER FILTER STATUS KEPEGAWAIAN =================

    // ================= HELPER: GEMBOK DOMPET ASLI =================
    // Dompet "ASLI" = kuota_tahunan > 0. Baris bayangan (kuota_tahunan = 0) hanya
    // menyimpan carry-forward dan tidak memunculkan pegawai di Rekap tahun itu.
    private function dompetTahunAsli(Builder $query, int $tahun): Builder
    {
        return $query->where('tahun', $tahun)
            ->where('kuota_tahunan', '>', 0);
    }
    // ================= END HELPER GEMBOK DOMPET ASLI =================

    // ================= HELPER BERSAMA: QUERY DASAR =================
    // Dipakai index(), indexAtasan(), exportExcel(), hitungKpi().
    //   1) Gembok dompet: hanya pegawai dengan saldo_cutis ASLI di tahun terpilih.
    //   2) Status: non-aktif hanya tampil bila punya cuti di tahun itu (arsip).
    //   3) Admin HR (role 5) dikecualikan.
    //   4) Eager-load saldo (tahun & tahun-1), pengajuan, cuti ditangguhkan.
    //   5) Urutan mengikuti hierarki jabatan (CASE WHEN).
    //   6) wilayahField/wilayahValue (opsional) untuk jalur Atasan.
    //
    // $muatApprovalLogs = false (KPI & Export): pengajuan yang dimuat hanya
    // Cuti Tahunan yang disetujui.
    private function buildQuery(
        Request $request,
        ?string $wilayahField = null,
        ?string $wilayahValue = null,
        bool $muatApprovalLogs = true
    ): Builder {
        $tahunIni = $this->resolveTahun($request);
        $search = $request->input('search');

        $query = Pegawai::query()
            ->select('id', 'nama', 'nip', 'jabatan', 'kelompok_substansi', 'tim_kerja', 'role_id', 'status_aktif')
            ->where('role_id', '!=', 5)
            ->with([
                'saldoCuti' => function ($q) use ($tahunIni) {
                    $q->whereIn('tahun', [$tahunIni, $tahunIni - 1]);
                },
                'pengajuanCuti' => function ($q) use ($muatApprovalLogs, $tahunIni) {
                    if ($muatApprovalLogs) {
                        $q->with('approvalLogs.approver:id,nama,jabatan');
                    } else {
                        // KPI & Export hanya butuh Cuti Tahunan yang disetujui
                        // (lihat hitungCutiTerpakaiMurniDariRelasi()).
                        $q->where('jenis_cuti', 'Cuti Tahunan')
                            ->where('status', 'disetujui');
                    }
                    // Hanya yang bersinggungan dengan tahun dilihat (cuti lintas
                    // tahun tampil di kedua tahunnya).
                    $q->whereDate('tanggal_mulai', '<=', "{$tahunIni}-12-31")
                        ->where(function ($w) use ($tahunIni) {
                            $w->whereDate('tanggal_selesai', '>=', "{$tahunIni}-01-01")
                                ->orWhereNull('tanggal_selesai');
                        })
                        ->latest();
                },
                'cutiDitangguhkan' => function ($q) use ($tahunIni) {
                    $q->whereIn('tahun_asal', [$tahunIni - 2, $tahunIni - 1, $tahunIni]);
                },
            ]);

        // Gembok utama: dompet saldo ASLI tahun terpilih
        $query->whereHas('saldoCuti', function ($q) use ($tahunIni) {
            $this->dompetTahunAsli($q, $tahunIni);
        });

        // Non-aktif: hanya bila punya cuti di tahun itu (arsip)
        $this->hanyaAktifAtauPunyaCutiDiTahun($query, $tahunIni);

        if ($wilayahField) {
            $query->where($wilayahField, $wilayahValue);
        }

        if ($kelompokSubstansiPilihan = $request->input('kelompok_substansi')) {
            $query->where('kelompok_substansi', $kelompokSubstansiPilihan);
        }

        if ($timKerjaPilihan = $request->input('tim_kerja')) {
            $query->where('tim_kerja', $timKerjaPilihan);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('nama', 'like', "%{$search}%")
                    ->orWhere('nip', 'like', "%{$search}%");
            });
        }

        $caseSql = 'CASE';
        foreach ($this->urutanJabatan as $roleId => $urutan) {
            $caseSql .= " WHEN role_id = {$roleId} THEN {$urutan}";
        }
        $caseSql .= ' ELSE 99 END';

        return $query->orderByRaw($caseSql)->orderBy('nama');
    }
    // ================= END HELPER BERSAMA =================

    // ================= HELPER RANTAI APPROVAL =================

    /**
     * Urutan level approval (1/2/3/4) yang WAJIB dilalui.
     * Selaras dengan getApprovalRoute() di MonitoringCuti.vue.
     *
     *   - Staf (1) di Subbagian Tata Usaha -> [3, 4]
     *   - Ketua Tim Kerja (2)              -> [2, 3, 4]
     *   - Ketua Kelompok Substansi (3)     -> [3, 4]
     *   - Kasubag TU (4)                   -> [4]
     *   - Kepala Biro (6)                  -> [] (disetujui otomatis)
     *   - Staf (1) lainnya: tanpa Tim Kerja ('-'/kosong) -> [2, 3, 4]; dengan Tim Kerja -> [1, 3, 4]
     */
    private function getRequiredLevels(
        int $roleIdPemohon,
        ?string $kelompokSubstansi = null,
        ?string $timKerja = null
    ): array {
        if (
            $roleIdPemohon === 1 &&
            $kelompokSubstansi &&
            str_contains(strtolower($kelompokSubstansi), 'subbagian tata usaha')
        ) {
            return [3, 4];
        }

        return match ($roleIdPemohon) {
            6 => [],
            2 => [2, 3, 4],
            3 => [3, 4],
            4 => [4],
            default => (function () use ($timKerja) {
                $tim = trim((string) $timKerja);
                $tanpaTimKerja = $tim === '' || $tim === '-';

                if ($tanpaTimKerja) {
                    return [2, 3, 4];
                }

                return [1, 3, 4];
            })(),
        };
    }

    // Samakan teks seperti perbandingan MySQL (tak peka huruf besar/kecil, spasi tepi diabaikan).
    private function normalisasiTeks(?string $teks): string
    {
        return mb_strtolower(trim((string) $teks));
    }

    // Semua pejabat AKTIF role 2, 3, 4, 6 dalam SATU query per request,
    // dikelompokkan per role_id dan diurutkan id terkecil.
    private function pejabatAktif(): Collection
    {
        if ($this->pejabatAktifPerRole === null) {
            $this->pejabatAktifPerRole = $this->hanyaAktif(
                Pegawai::query()->whereIn('role_id', [2, 3, 4, 6])
            )
                ->orderBy('id')
                ->get(['id', 'nama', 'role_id', 'kelompok_substansi', 'tim_kerja'])
                ->groupBy('role_id');
        }

        return $this->pejabatAktifPerRole;
    }

    /**
     * Nama pegawai yang menjabat di role_id tertentu (hanya yang masih aktif).
     *   - L1 (role 2): dicari per tim_kerja pemohon
     *   - Role lain: prioritas satu kelompok_substansi, fallback ke role itu mana pun
     * Pencocokan dilakukan di memori dari pejabatAktif() (satu query).
     */
    private function getApproverName(int $roleId, ?string $kelompokSubstansi, ?string $timKerja = null): string
    {
        $kandidat = $this->pejabatAktif()->get($roleId, collect());

        // L1: scope per tim_kerja
        if ($roleId === 2) {
            $timKerjaBersih = trim((string) $timKerja);

            if ($timKerjaBersih === '' || $timKerjaBersih === '-') {
                return 'Belum ditentukan';
            }

            $key = '2-tim-' . $timKerjaBersih;

            if (isset($this->approverNameCache[$key])) {
                return $this->approverNameCache[$key];
            }

            $target = $this->normalisasiTeks($timKerjaBersih);
            $pegawaiL1 = $kandidat->first(
                fn($p) => $this->normalisasiTeks($p->tim_kerja) === $target
            );

            return $this->approverNameCache[$key] = $pegawaiL1->nama ?? 'Belum ditentukan';
        }

        // Role lain
        $key = $roleId . '-' . ($kelompokSubstansi ?? '');

        if (isset($this->approverNameCache[$key])) {
            return $this->approverNameCache[$key];
        }

        $pegawai = null;

        if ($kelompokSubstansi) {
            $target = $this->normalisasiTeks($kelompokSubstansi);
            $pegawai = $kandidat->first(
                fn($p) => $this->normalisasiTeks($p->kelompok_substansi) === $target
            );
        }

        // Fallback: pejabat aktif role itu, kelompok mana pun
        $pegawai ??= $kandidat->first();

        return $this->approverNameCache[$key] = $pegawai->nama ?? 'Belum ditentukan';
    }

    /**
     * Daftar LENGKAP level approval beserta statusnya:
     * 'setuju' | 'tolak' | 'menunggu' | 'belum_giliran' | 'tangguh'
     */
    private function buildApprovalChain(PengajuanCuti $riwayat, Pegawai $pemohon): array
    {
        $levels = $this->getRequiredLevels(
            $pemohon->role_id,
            $pemohon->kelompok_substansi,
            $pemohon->tim_kerja
        );
        $logsByLevel = $riwayat->approvalLogs->keyBy('level_approval');

        $chain = [];

        foreach ($levels as $level) {
            $roleId = $this->levelToRoleId[$level];
            $label = $this->levelLabels[$level];

            // 1) Sudah punya keputusan tercatat
            if ($logsByLevel->has($level)) {
                $log = $logsByLevel->get($level);
                $chain[] = [
                    'level' => $level,
                    'label' => $label,
                    'nama' => $log->approver->nama ?? $this->getApproverName($roleId, $pemohon->kelompok_substansi, $pemohon->tim_kerja),
                    'status' => $log->keputusan,
                ];
                continue;
            }

            // 2) Sedang ditunggu
            if ($riwayat->status === "menunggu_l{$level}") {
                $chain[] = [
                    'level' => $level,
                    'label' => $label,
                    'nama' => $this->getApproverName($roleId, $pemohon->kelompok_substansi, $pemohon->tim_kerja),
                    'status' => 'menunggu',
                ];
                continue;
            }

            // 3) Belum giliran
            $chain[] = [
                'level' => $level,
                'label' => $label,
                'nama' => $this->getApproverName($roleId, $pemohon->kelompok_substansi, $pemohon->tim_kerja),
                'status' => 'belum_giliran',
            ];
        }

        // Penangguhan/pembatalan oleh L4 (level_approval = 5)
        $logTangguh = $riwayat->approvalLogs->firstWhere('keputusan', 'tangguh');
        if ($logTangguh) {
            $chain[] = [
                'level' => null,
                'label' => 'Penangguhan/Pembatalan oleh Atasan',
                'nama' => $logTangguh->approver->nama ?? $this->getApproverName(6, $pemohon->kelompok_substansi),
                'status' => 'tangguh',
            ];
        }

        return $chain;
    }
    // ================= END HELPER RANTAI APPROVAL =================

    // ================= HELPER: HITUNG CUTI TERPAKAI =================
    // Prioritas Terpakai efektif (satu aturan dengan SaldoCuti::terpakaiEfektif):
    //   1) Ada koreksi BULANAN (Rekap Laporan) di tahun itu -> SaldoCuti::terpakaiEfektif()
    //   2) koreksi_saldo tahunan (data lama) terisi -> dipakai apa adanya (termasuk 0)
    //   3) Selain itu -> hitungan sistem (terpakai murni)
    //
    // PENTING: bedakan NULL dan 0. Jangan pakai empty(), '?:', atau '> 0' untuk
    // mengecek koreksi, karena 0 adalah koreksi yang SAH. Cukup cek '!== null'.

    // Peta koreksi bulanan satu pegawai & tahun: [bulan (1-12) => nilai]. Kosong = tidak ada.
    private function petaKoreksiBulanan(int $pegawaiId, int $tahun): array
    {
        $this->muatKoreksiBulanan($tahun);

        return $this->cacheKoreksiBulanan[$tahun][$pegawaiId] ?? [];
    }

    // Muat koreksi bulanan SEMUA pegawai di satu tahun dalam SATU query:
    // peta [bulan => nilai] dan selisih terhadap hitungan sistem (SUM(nilai - nilai_sistem)).
    private function muatKoreksiBulanan(int $tahun): void
    {
        if (isset($this->cacheKoreksiBulanan[$tahun])) {
            return;
        }

        $baris = KoreksiCutiBulanan::where('tahun', $tahun)
            ->get(['pegawai_id', 'bulan', 'nilai', 'nilai_sistem'])
            ->groupBy('pegawai_id');

        $this->cacheKoreksiBulanan[$tahun] = $baris
            ->map(fn($b) => $b->pluck('nilai', 'bulan')->map(fn($v) => (int) $v)->all())
            ->all();

        $this->cacheSelisihKoreksi[$tahun] = $baris
            ->map(fn($b) => (int) $b->sum(fn($r) => (int) $r->nilai - (int) $r->nilai_sistem))
            ->all();
    }

    // Selisih koreksi bulanan satu pegawai & tahun (0 bila tidak ada).
    // Sama dengan SaldoCuti::petaSelisihTahun().
    private function selisihKoreksiBulanan(int $pegawaiId, int $tahun): int
    {
        $this->muatKoreksiBulanan($tahun);

        return (int) ($this->cacheSelisihKoreksi[$tahun][$pegawaiId] ?? 0);
    }

    // Versi MEMORI: dari relasi pengajuanCuti yang sudah di-eager load. Cuti lintas
    // tahun dihitung per tahun lewat SaldoCuti::hariCutiDiTahun().
    private function hitungCutiTerpakaiMurniDariRelasi(Pegawai $pegawai, int $tahun): int
    {
        if (!$pegawai->relationLoaded('pengajuanCuti')) {
            return $this->queryCutiTerpakaiMurni((int) $pegawai->id, $tahun);
        }

        return (int) $pegawai->pengajuanCuti
            ->where('jenis_cuti', 'Cuti Tahunan')
            ->where('status', 'disetujui')
            ->sum(fn($cuti) => SaldoCuti::hariCutiDiTahun(
                $cuti->tanggal_mulai,
                $cuti->tanggal_selesai,
                (int) $cuti->jumlah_hari,
                $tahun
            ));
    }

    // Versi DATABASE (satu pegawai), logikanya di model SaldoCuti.
    private function queryCutiTerpakaiMurni(int $pegawaiId, int $tahun): int
    {
        return SaldoCuti::terpakaiMurni($pegawaiId, $tahun);
    }

    /** @deprecated Gunakan queryCutiTerpakaiMurni atau hitungCutiTerpakaiMurniDariRelasi */
    private function hitungCutiTerpakaiMurni(int $pegawaiId, int $tahun): int
    {
        return $this->queryCutiTerpakaiMurni($pegawaiId, $tahun);
    }

    private function hitungCutiTerpakaiEfektif(int $terpakaiMurni, ?int $koreksiSaldo): int
    {
        return $koreksiSaldo !== null
            ? max(0, $koreksiSaldo)
            : max(0, $terpakaiMurni);
    }
    // ================= END HELPER CUTI TERPAKAI =================

    // ================= HELPER: NOTIFIKASI PERUBAHAN SALDO =================
    // Dipanggil dari updateSaldo(), importKuota(), dan AdminController (setelah
    // koreksi bulanan disimpan), sehingga PUBLIC. Mengirim notifikasi ke pegawai
    // yang saldonya diubah Admin HR, HANYA bila ada nilai yang benar-benar berubah.
    //
    // $lama & $baru berisi: hak, carry, dua_tahun_lalu, koreksi, terpakai.
    // 'koreksi' int atau null (null = ikut sistem; dipakai untuk memilih kalimat).
    // 'terpakai' = cuti terpakai EFEKTIF yang dilihat pegawai, itulah yang dibandingkan.
    //
    // Perubahan Tgh sengaja tidak ikut notifikasi.
    public function kirimNotifikasiPerubahanSaldo(
        Pegawai $pegawai,
        int $tahunIni,
        array $lama,
        array $baru,
        int $cutiTerpakai
    ): void {
        $tahunLalu = $tahunIni - 1;
        $tahunDuaLalu = $tahunIni - 2;

        $perubahan = [];

        if ($lama['hak'] !== $baru['hak']) {
            $perubahan[] = "Hak cuti {$tahunIni} menjadi {$baru['hak']} hari";
        }

        // Bandingkan terpakai EFEKTIF, bukan nilai mentah koreksi: bila angka yang
        // dilihat pegawai tidak berubah, tidak perlu notifikasi.
        if ($lama['terpakai'] !== $baru['terpakai']) {
            $perubahan[] = $baru['koreksi'] !== null
                ? "Cuti terpakai disesuaikan menjadi {$baru['terpakai']} hari"
                : "Cuti terpakai kembali mengikuti riwayat pengajuan sistem ({$baru['terpakai']} hari)";
        }

        if ($lama['carry'] !== $baru['carry']) {
            $perubahan[] = "Sisa cuti {$tahunLalu} menjadi {$baru['carry']} hari";
        }

        if ($lama['dua_tahun_lalu'] !== $baru['dua_tahun_lalu']) {
            $perubahan[] = "Sisa cuti {$tahunDuaLalu} menjadi {$baru['dua_tahun_lalu']} hari";
        }

        if (empty($perubahan)) {
            return;
        }

        // Sisa akhir dengan rumus yang SAMA seperti hitungKuota() dan dashboard
        $saldoBawaanEligible = SaldoCuti::hitungBawaan(
            $baru['dua_tahun_lalu'],
            $baru['carry'],
            $baru['hak']
        );
        $tghTotal = self::TGH_MASUK_TOTAL
            ? (int) CutiDitangguhkan::where('pegawai_id', $pegawai->id)
                ->where('tahun_asal', $tahunIni - 1)
                ->sum('jumlah_hari')
            : 0;
        $potonganTgh = self::TGH_TAHUN_INI_MENGURANGI_SISA
            ? (int) CutiDitangguhkan::where('pegawai_id', $pegawai->id)
                ->where('tahun_asal', $tahunIni)
                ->sum('jumlah_hari')
            : 0;

        $saldoAkhir = RincianSaldo::hitungTotal(
            (int) $baru['hak'],
            (int) $saldoBawaanEligible,
            (int) $tghTotal,
            (int) $cutiTerpakai,
            (int) $potonganTgh
        )['total_cuti_tersedia'];

        $pesan = "Admin HR memperbarui saldo cuti tahunan Anda tahun {$tahunIni}. "
            . implode('; ', $perubahan)
            . ". Total cuti tersedia Anda saat ini: {$saldoAkhir} hari.";

        Notifikasi::create([
            'pegawai_id' => $pegawai->id,
            'judul'      => 'Saldo Cuti Diperbarui',
            'pesan'      => Str::limit($pesan, 250),
            'tautan'     => route('dashboard'),
            'is_read'    => false,
        ]);
    }
    // ================= END HELPER NOTIFIKASI PERUBAHAN SALDO =================

    // ================= HELPER: EDIT TOTAL CUTI DITANGGUHKAN (Tgh) =================
    // Tgh = SUM(jumlah_hari) baris cuti_ditangguhkans per pegawai & tahun_asal.
    // Admin HR mengetik TOTAL yang diinginkan, lalu method ini menyamakan jumlah baris:
    //   - Naik : selisih ditambahkan ke baris MANUAL (pengajuan_asal_id = null,
    //            kolom harus nullable). alasan_penangguhan WAJIB diisi
    //            (tanpa default di DB), diisi ALASAN_TGH_MANUAL.
    //   - Turun: baris manual dikurangi dulu, lalu baris dari pengajuan
    //            (dikurangi, atau dihapus bila jadi 0).
    //
    // PUBLIC supaya AdminController::updateKoreksiLaporan() memakai jalur yang sama.
    public function setTotalDitangguhkan(int $pegawaiId, int $tahunAsal, int $target): void
    {
        $target = max(0, $target);

        $rows = CutiDitangguhkan::where('pegawai_id', $pegawaiId)
            ->where('tahun_asal', $tahunAsal)
            ->get()
            ->sortBy(fn($r) => $r->pengajuan_asal_id === null ? 0 : 1)
            ->values();

        $sekarang = (int) $rows->sum('jumlah_hari');

        if ($target === $sekarang) {
            return;
        }

        if ($target > $sekarang) {
            $selisih = $target - $sekarang;
            $manual = $rows->first(fn($r) => $r->pengajuan_asal_id === null);

            if ($manual) {
                $manual->increment('jumlah_hari', $selisih);
            } else {
                // [FIX 1364] create() membuang 'alasan_penangguhan' karena tidak ada
                // di $fillable, sehingga INSERT tanpa kolom wajib. forceFill()
                // mengabaikan $fillable.
                $baris = new CutiDitangguhkan();
                $baris->forceFill([
                    'pegawai_id' => $pegawaiId,
                    'pengajuan_asal_id' => null,
                    'tahun_asal' => $tahunAsal,
                    'jumlah_hari' => $selisih,
                    'alasan_penangguhan' => self::ALASAN_TGH_MANUAL,
                ])->save();
            }

            return;
        }

        $kurang = $sekarang - $target;
        foreach ($rows as $row) {
            if ($kurang <= 0) {
                break;
            }

            $potong = min($kurang, (int) $row->jumlah_hari);

            if ($potong >= (int) $row->jumlah_hari) {
                $row->delete();
            } else {
                $row->decrement('jumlah_hari', $potong);
            }

            $kurang -= $potong;
        }
    }
    // ================= END HELPER EDIT Tgh =================

    /**
     * Update saldo cuti perorangan (hanya Admin HR / role_id 5).
     * Menerima 'tahun', Hak, Sisa N-1, Sisa N-2, dan total Tgh (opsional).
     *
     * 'Cuti Terpakai' TIDAK bisa diubah dari sini (diedit per bulan di Rekap
     * Laporan). 'pakai_sistem' / 'koreksi_saldo' dari frontend lama diabaikan
     * dan kolom koreksi_* tidak disentuh.
     *
     * Bila hanya Tgh tahun lalu yang berubah, Sisa tahun lalu ikut dihitung ulang
     * (SaldoCuti::sinkronkanBawaan). Penulisan data dibungkus DB::transaction;
     * notifikasi dikirim SETELAH transaksi berhasil.
     */
    public function updateSaldo(Request $request, int $id)
    {
        abort_unless((int) $request->user()->role_id === 5, 403);

        $data = $request->validate([
            'hak_tahun_ini' => ['required', 'integer', 'min:0'],
            'carry_forward_normal' => ['required', 'integer', 'min:0'],
            'sisa_cuti_dua_tahun_lalu' => ['required', 'integer', 'min:0'],
            'tahun' => ['nullable', 'integer', 'between:2000,2100'],
            // Tgh (opsional): hanya diproses bila dikirim
            'sisa_ditangguhkan_dua_tahun_lalu' => ['nullable', 'integer', 'between:0,365'],
            'sisa_ditangguhkan_tahun_lalu' => ['nullable', 'integer', 'between:0,365'],
            'sisa_ditangguhkan_tahun_ini' => ['nullable', 'integer', 'between:0,365'],
            // Alasan perubahan (dicatat di log; wajib bila WAJIB_ALASAN_KOREKSI aktif)
            'alasan' => ['nullable', 'string', 'max:500'],
        ]);

        $pegawai = Pegawai::findOrFail($id);
        $tahunIni = $this->resolveTahun($request);
        $tahunLalu = $tahunIni - 1;

        // Snapshot nilai LAMA sebelum update (untuk deteksi perubahan & notifikasi)
        $saldoTahunIniLama = SaldoCuti::where('pegawai_id', $pegawai->id)
            ->where('tahun', $tahunIni)
            ->first();

        $saldoTahunLalu = SaldoCuti::where('pegawai_id', $pegawai->id)
            ->where('tahun', $tahunLalu)
            ->first();

        // Terpakai EFEKTIF (ikut koreksi bulanan / tahunan lama). Tidak diubah
        // method ini, jadi nilai lama = nilai baru.
        $cutiTerpakai = SaldoCuti::terpakaiEfektif((int) $pegawai->id, $tahunIni, $saldoTahunIniLama);

        $koreksiLama = ($saldoTahunIniLama && $saldoTahunIniLama->koreksi_saldo !== null)
            ? (int) $saldoTahunIniLama->koreksi_saldo
            : null;

        $lama = [
            'hak'            => (int) ($saldoTahunIniLama->kuota_tahunan ?? 12),
            'carry'          => (int) ($saldoTahunIniLama->carry_forward_normal ?? 0),
            'dua_tahun_lalu' => (int) ($saldoTahunLalu->carry_forward_normal ?? 0),
            'koreksi'        => $koreksiLama,
            'terpakai'       => $cutiTerpakai,
        ];

        $baru = [
            'hak'            => (int) $data['hak_tahun_ini'],
            'carry'          => (int) $data['carry_forward_normal'],
            'dua_tahun_lalu' => (int) $data['sisa_cuti_dua_tahun_lalu'],
            'koreksi'        => $koreksiLama,
            'terpakai'       => $cutiTerpakai,
        ];

        // ---- Deteksi perubahan (dasar alasan wajib & log), SEBELUM menulis ke DB ----
        // 'terpakai' tidak dicatat karena tidak diubah dari halaman ini.
        $daftarPerubahan = [];
        $catatPerubahan = function (string $kolom, $nilaiLama, $nilaiBaru) use (&$daftarPerubahan) {
            if ((int) $nilaiLama !== (int) $nilaiBaru) {
                $daftarPerubahan[] = [
                    'kolom' => $kolom,
                    'lama' => (int) $nilaiLama,
                    'baru' => (int) $nilaiBaru,
                ];
            }
        };

        $catatPerubahan('hak', $lama['hak'], $baru['hak']);
        $catatPerubahan('sisa_n1', $lama['carry'], $baru['carry']);
        $catatPerubahan('sisa_n2', $lama['dua_tahun_lalu'], $baru['dua_tahun_lalu']);

        foreach (
            [
                'tgh_n2' => ['sisa_ditangguhkan_dua_tahun_lalu', $tahunIni - 2],
                'tgh_n1' => ['sisa_ditangguhkan_tahun_lalu', $tahunIni - 1],
                'tgh_n0' => ['sisa_ditangguhkan_tahun_ini', $tahunIni],
            ] as $kolomLog => [$fieldTgh, $tahunAsalTgh]
        ) {
            if ($request->has($fieldTgh) && $request->input($fieldTgh) !== null && $request->input($fieldTgh) !== '') {
                $tghLama = (int) CutiDitangguhkan::where('pegawai_id', $pegawai->id)
                    ->where('tahun_asal', $tahunAsalTgh)
                    ->sum('jumlah_hari');
                $catatPerubahan($kolomLog, $tghLama, (int) $request->input($fieldTgh));
            }
        }

        // Menaikkan Tgh tahun ini mengurangi Terpakai, jadi kenaikan tidak boleh
        // melebihi Terpakai saat ini (tidak boleh minus).
        if ($request->filled('sisa_ditangguhkan_tahun_ini')) {
            $tghIniLama = (int) CutiDitangguhkan::where('pegawai_id', $pegawai->id)
                ->where('tahun_asal', $tahunIni)
                ->sum('jumlah_hari');
            $kenaikan = (int) $request->input('sisa_ditangguhkan_tahun_ini') - $tghIniLama;

            if ($kenaikan > $cutiTerpakai) {
                return back()->withErrors([
                    'sisa_ditangguhkan_tahun_ini' => "Tgh {$tahunIni} naik {$kenaikan} hari, tetapi Terpakai {$tahunIni} hanya {$cutiTerpakai} hari.",
                ]);
            }
        }

        $alasan = trim((string) ($data['alasan'] ?? ''));

        if (self::WAJIB_ALASAN_KOREKSI && !empty($daftarPerubahan) && $alasan === '') {
            return back()->withErrors([
                'alasan' => 'Isi alasan perubahan (mis. "sesuai buku cuti" atau "cuti belum diajukan di sistem").',
            ]);
        }

        // Semua penulisan di bawah berhasil bersama atau dibatalkan bersama.
        DB::transaction(function () use (
            $request,
            $data,
            $pegawai,
            $tahunIni,
            $tahunLalu,
            $saldoTahunLalu,
            $cutiTerpakai,
            $daftarPerubahan,
            $alasan,
            $lama,
            &$baru
        ) {
            // koreksi_saldo & kolom koreksi_* SENGAJA tidak ada di daftar ini.
            SaldoCuti::updateOrCreate(
                ['pegawai_id' => $pegawai->id, 'tahun' => $tahunIni],
                [
                    'kuota_tahunan' => $data['hak_tahun_ini'],
                    'carry_forward_normal' => $data['carry_forward_normal'],
                    'sisa' => max(0, $data['hak_tahun_ini'] - $cutiTerpakai),
                ]
            );

            // [FIX] Baris tahun lalu yang belum ada dibuat sebagai baris BAYANGAN
            // (kuota_tahunan = 0, bukan 12), supaya tidak dianggap "dompet asli"
            // oleh dompetTahunAsli() dan tahun itu tidak mendadak muncul di Rekap.
            SaldoCuti::updateOrCreate(
                ['pegawai_id' => $pegawai->id, 'tahun' => $tahunLalu],
                [
                    'kuota_tahunan' => $saldoTahunLalu->kuota_tahunan ?? 0,
                    'carry_forward_normal' => $data['sisa_cuti_dua_tahun_lalu'],
                    'sisa' => $saldoTahunLalu->sisa ?? 0,
                ]
            );

            // Tgh: hanya field yang dikirim. Nilai 0 SAH (tidak ada hari ditangguhkan).
            foreach (
                [
                    'sisa_ditangguhkan_dua_tahun_lalu' => $tahunIni - 2,
                    'sisa_ditangguhkan_tahun_lalu'     => $tahunIni - 1,
                    'sisa_ditangguhkan_tahun_ini'      => $tahunIni,
                ] as $field => $tahunAsal
            ) {
                if ($request->has($field) && $request->input($field) !== null && $request->input($field) !== '') {
                    $this->setTotalDitangguhkan((int) $pegawai->id, $tahunAsal, (int) $request->input($field));
                }
            }

            // Tgh tahun ini ikut mengubah Terpakai, jadi kolom legacy `sisa` dihitung ulang.
            $terpakaiBaru = SaldoCuti::terpakaiEfektif((int) $pegawai->id, $tahunIni);
            SaldoCuti::where('pegawai_id', $pegawai->id)
                ->where('tahun', $tahunIni)
                ->update(['sisa' => max(0, (int) $data['hak_tahun_ini'] - $terpakaiBaru)]);

            // Log perubahan: satu baris per angka yang berubah
            foreach ($daftarPerubahan as $perubahan) {
                LogPerubahanSaldo::create([
                    'pegawai_id' => $pegawai->id,
                    'diubah_oleh' => $request->user()->id,
                    'tahun' => $tahunIni,
                    'kolom' => $perubahan['kolom'],
                    'nilai_lama' => $perubahan['lama'],
                    'nilai_baru' => $perubahan['baru'],
                    'alasan' => $alasan !== '' ? $alasan : null,
                ]);
            }

            // [AUTO-SYNC] Saldo tahun ini berubah -> bawaan tahun depan dihitung ulang
            SaldoCuti::sinkronkanBawaan((int) $pegawai->id, $tahunIni);
        });

        // Notifikasi dikirim SETELAH transaksi selesai (hanya bila ada yang berubah)
        $this->kirimNotifikasiPerubahanSaldo(
            $pegawai,
            $tahunIni,
            $lama,
            $baru,
            SaldoCuti::terpakaiEfektif((int) $pegawai->id, $tahunIni) // sudah ikut Tgh manual
        );

        return back()->with('success', 'Saldo cuti pegawai berhasil diperbarui.');
    }

    // ================= IMPOR KUOTA CUTI DARI EXCEL =================
    // Membaca template "Saldo Tahunan". HANYA angka input yang disimpan:
    //   Sisa {tahun-2} -> carry_forward_normal tahun-1
    //   Sisa {tahun-1} -> carry_forward_normal tahun berjalan
    //   Hak {tahun}    -> kuota_tahunan
    // Kolom hasil hitungan diabaikan; koreksi_saldo & koreksi bulanan TIDAK disentuh.
    // Sel kosong = nilai lama dipertahankan. Pencocokan lewat NIP semua pegawai
    // non-Admin HR (termasuk non-aktif) agar saldo arsip tetap bisa dikoreksi.

    /**
     * [FIX] Normalisasi NIP untuk pencocokan file <-> database.
     *
     * Kolom NIP di template berupa rumus; saat dibaca, PhpSpreadsheet mengubah
     * teks angka menjadi float, sehingga NIP 18 digit bergeser di dua digit
     * terakhir. Solusi: file DAN database sama-sama dilewatkan ke konversi float
     * yang sama, jadi pembulatannya identik dan tetap cocok.
     */
    private function normalisasiNip(mixed $nilai): string
    {
        $digit = preg_replace('/\D+/', '', (string) $nilai);

        return $digit === '' ? '' : sprintf('%.0f', (float) $digit);
    }

    // Baca nilai sebuah sel. Untuk rumus, pakai hasil hitung tersimpan di file
    // (kolom NIP, Nama, dan Sisa di template berupa rumus ke "Master Pegawai");
    // bila tidak ada, baru dihitung.
    private function bacaNilaiSel(Worksheet $sheet, string $koordinat)
    {
        $sel = $sheet->getCell($koordinat);

        if (!$sel->isFormula()) {
            return $sel->getValue();
        }

        $tersimpan = $sel->getOldCalculatedValue();
        if ($tersimpan !== null && $tersimpan !== '') {
            return $tersimpan;
        }

        try {
            return $sel->getCalculatedValue();
        } catch (\Throwable $e) {
            return null;
        }
    }

    // Cari baris header (berisi "NIP") dan petakan kolom berdasarkan teks header,
    // bukan huruf kolom. Mengembalikan peta kolom, atau string pesan error.
    private function petakanKolomImpor(Worksheet $sheet, int $tahunIni)
    {
        $tahunLalu = $tahunIni - 1;
        $tahunDuaLalu = $tahunIni - 2;
        $kolomTerakhir = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        $barisMaks = min(10, $sheet->getHighestRow());

        for ($baris = 1; $baris <= $barisMaks; $baris++) {
            $peta = ['nip' => null, 'hak' => [], 'sisa' => [], 'tgh' => []];

            for ($i = 1; $i <= $kolomTerakhir; $i++) {
                $kolom = Coordinate::stringFromColumnIndex($i);
                $teks = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $sheet->getCell($kolom . $baris)->getValue())));

                if ($teks === 'nip') {
                    $peta['nip'] = $kolom;
                } elseif (preg_match('/^hak (\d{4})$/', $teks, $m)) {
                    $peta['hak'][(int) $m[1]] = $kolom;
                } elseif (preg_match('/^sisa (\d{4})$/', $teks, $m)) {
                    $peta['sisa'][(int) $m[1]] = $kolom;
                } elseif (preg_match('/^sisa ditangguhkan (\d{4})$/', $teks, $m)) {
                    $peta['tgh'][(int) $m[1]] = $kolom;
                }
            }

            if ($peta['nip'] === null) {
                continue;
            }

            if (empty($peta['hak'])) {
                return 'Kolom "Hak ' . $tahunIni . '" tidak ditemukan. Pastikan file memakai template Rekap Cuti.';
            }

            if (!isset($peta['hak'][$tahunIni])) {
                $tahunFile = implode(',', array_keys($peta['hak']));
                return "File ini untuk tahun {$tahunFile}, sedangkan Filter Tahun aktif {$tahunIni}. Ganti Filter Tahun atau gunakan file yang sesuai.";
            }

            if (!isset($peta['sisa'][$tahunLalu])) {
                return 'Kolom "Sisa ' . $tahunLalu . '" tidak ditemukan di file.';
            }

            return [
                'baris_header' => $baris,
                'nip' => $peta['nip'],
                'hak' => $peta['hak'][$tahunIni],
                'sisa1' => $peta['sisa'][$tahunLalu],
                'sisa2' => $peta['sisa'][$tahunDuaLalu] ?? null, // opsional
                // Kolom "Sisa Ditangguhkan" (opsional; null = tidak ada di file)
                'tgh' => [
                    'tgh_n2' => $peta['tgh'][$tahunDuaLalu] ?? null,
                    'tgh_n1' => $peta['tgh'][$tahunLalu] ?? null,
                    'tgh_n0' => $peta['tgh'][$tahunIni] ?? null,
                ],
            ];
        }

        return 'Kolom "NIP" tidak ditemukan. Pastikan file memakai template Rekap Cuti (sheet "Saldo Tahunan").';
    }

    // Baca satu sel angka (hasil rumus ikut dihitung).
    // null = kosong, false = tidak valid, int = angka bulat >= 0.
    private function bacaAngkaImpor(Worksheet $sheet, string $kolom, int $baris)
    {
        $nilai = $this->bacaNilaiSel($sheet, $kolom . $baris);

        if ($nilai === null || trim((string) $nilai) === '') {
            return null;
        }

        if (!is_numeric($nilai)) {
            return false;
        }

        $angka = (float) $nilai;

        return ($angka < 0 || floor($angka) != $angka) ? false : (int) $angka;
    }

    public function importKuota(Request $request)
    {
        abort_unless((int) $request->user()->role_id === 5, 403);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:5120'],
            'tahun' => ['nullable', 'integer', 'between:2000,2100'],
        ], [
            'file.required' => 'Pilih file Excel atau CSV terlebih dahulu.',
            'file.mimes' => 'File harus berformat .xlsx, .xls, atau .csv.',
            'file.max' => 'Ukuran file maksimal 5 MB.',
        ]);

        $tahunIni = $this->resolveTahun($request);
        $tahunLalu = $tahunIni - 1;
        $idAdmin = (int) $request->user()->id;

        try {
            $spreadsheet = IOFactory::load($request->file('file')->getRealPath());
        } catch (\Throwable $e) {
            return back()->withErrors(['file' => 'File tidak bisa dibaca. Pastikan formatnya valid.']);
        }

        $sheet = $spreadsheet->getSheetByName('Saldo Tahunan') ?? $spreadsheet->getActiveSheet();
        $peta = $this->petakanKolomImpor($sheet, $tahunIni);

        if (is_string($peta)) {
            return back()->withErrors(['file' => $peta]);
        }

        // Kunci pencocokan: NIP ternormalisasi (lihat normalisasiNip())
        $pegawaiByNip = Pegawai::where('role_id', '!=', 5)
            ->get(['id', 'nama', 'nip'])
            ->keyBy(fn($p) => $this->normalisasiNip($p->nip));

        // Total Tgh tersimpan per pegawai & tahun asal (satu query, bukan per baris)
        $tghTersimpan = [];
        $barisTgh = CutiDitangguhkan::whereIn('tahun_asal', [$tahunIni - 2, $tahunLalu, $tahunIni])
            ->get(['pegawai_id', 'tahun_asal', 'jumlah_hari']);

        foreach ($barisTgh as $b) {
            $p = (int) $b->pegawai_id;
            $t = (int) $b->tahun_asal;
            $tghTersimpan[$p][$t] = ($tghTersimpan[$p][$t] ?? 0) + (int) $b->jumlah_hari;
        }

        $diperbarui = 0;
        $tanpaPerubahan = 0;
        $dilewati = [];

        DB::transaction(function () use (
            $sheet,
            $peta,
            $pegawaiByNip,
            $tghTersimpan,
            $idAdmin,
            $tahunIni,
            $tahunLalu,
            &$diperbarui,
            &$tanpaPerubahan,
            &$dilewati
        ) {
            for ($baris = $peta['baris_header'] + 1; $baris <= $sheet->getHighestRow(); $baris++) {
                $nipMentah = $this->bacaNilaiSel($sheet, $peta['nip'] . $baris);

                if (is_int($nipMentah)) {
                    $nipMentah = (string) $nipMentah;
                } elseif (is_float($nipMentah)) {
                    $nipMentah = sprintf('%.0f', $nipMentah);
                }
                $nipMentah = trim((string) $nipMentah);

                // Baris kosong dan baris catatan (mengandung huruf) dilewati diam-diam
                if ($nipMentah === '' || preg_match('/\p{L}/u', $nipMentah)) {
                    continue;
                }

                $nip = $this->normalisasiNip($nipMentah);
                if ($nip === '' || $nip === '0') {
                    continue; // hasil rumus kosong / 0
                }

                $pegawai = $pegawaiByNip->get($nip);
                if (!$pegawai) {
                    $dilewati[] = "baris {$baris}: NIP {$nip} tidak ditemukan";
                    continue;
                }

                $hak = $this->bacaAngkaImpor($sheet, $peta['hak'], $baris);
                $sisa1 = $this->bacaAngkaImpor($sheet, $peta['sisa1'], $baris);
                $sisa2 = $peta['sisa2'] ? $this->bacaAngkaImpor($sheet, $peta['sisa2'], $baris) : null;

                // Tgh (opsional): null = kolom tidak ada / sel kosong -> dipertahankan
                $tghFile = [];
                foreach ($peta['tgh'] as $kolomLog => $kolomExcel) {
                    $tghFile[$kolomLog] = $kolomExcel ? $this->bacaAngkaImpor($sheet, $kolomExcel, $baris) : null;
                }
                $tghTidakValid = collect($tghFile)->contains(
                    fn($v) => $v === false || ($v !== null && $v > 365)
                );

                if (
                    $hak === false || $sisa1 === false || $sisa2 === false
                    || ($sisa1 !== null && $sisa1 > 12) || ($sisa2 !== null && $sisa2 > 12)
                    || $tghTidakValid
                ) {
                    $dilewati[] = "baris {$baris} ({$pegawai->nama}): angka tidak valid";
                    continue;
                }

                $adaTgh = collect($tghFile)->contains(fn($v) => $v !== null);

                if ($hak === null && $sisa1 === null && $sisa2 === null && !$adaTgh) {
                    continue;
                }

                $saldoIni = SaldoCuti::where('pegawai_id', $pegawai->id)->where('tahun', $tahunIni)->first();
                $saldoLalu = SaldoCuti::where('pegawai_id', $pegawai->id)->where('tahun', $tahunLalu)->first();

                $koreksiLama = ($saldoIni && $saldoIni->koreksi_saldo !== null)
                    ? (int) $saldoIni->koreksi_saldo
                    : null;

                // Terpakai EFEKTIF (ikut koreksi bulanan bila ada)
                $terpakai = SaldoCuti::terpakaiEfektif((int) $pegawai->id, $tahunIni, $saldoIni);

                $lama = [
                    'hak'            => (int) ($saldoIni->kuota_tahunan ?? 12),
                    'carry'          => (int) ($saldoIni->carry_forward_normal ?? 0),
                    'dua_tahun_lalu' => (int) ($saldoLalu->carry_forward_normal ?? 0),
                    'koreksi'        => $koreksiLama,
                    'terpakai'       => $terpakai,
                ];

                // Sel kosong = pertahankan nilai lama
                $baru = $lama;
                $baru['hak'] = $hak ?? $lama['hak'];
                $baru['carry'] = $sisa1 ?? $lama['carry'];
                $baru['dua_tahun_lalu'] = $sisa2 ?? $lama['dua_tahun_lalu'];

                // Tgh lama vs baru (kunci sama dengan kolom log di updateSaldo)
                $tahunAsalTgh = [
                    'tgh_n2' => $tahunIni - 2,
                    'tgh_n1' => $tahunLalu,
                    'tgh_n0' => $tahunIni,
                ];
                $tghLama = [];
                $tghBaru = [];
                foreach ($tahunAsalTgh as $kolomLog => $tahunAsal) {
                    $tghLama[$kolomLog] = (int) ($tghTersimpan[(int) $pegawai->id][$tahunAsal] ?? 0);
                    $tghBaru[$kolomLog] = $tghFile[$kolomLog] ?? $tghLama[$kolomLog];
                }

                if ($baru == $lama && $tghBaru == $tghLama) {
                    $tanpaPerubahan++;
                    continue;
                }

                // koreksi_saldo sengaja TIDAK ada di daftar kolom ini
                SaldoCuti::updateOrCreate(
                    ['pegawai_id' => $pegawai->id, 'tahun' => $tahunIni],
                    [
                        'kuota_tahunan' => $baru['hak'],
                        'carry_forward_normal' => $baru['carry'],
                        'sisa' => max(0, $baru['hak'] - $terpakai),
                    ]
                );

                if ($sisa2 !== null) {
                    // [FIX] Baris bayangan tahun lalu: kuota_tahunan default 0, bukan 12
                    // (lihat catatan di updateSaldo()).
                    SaldoCuti::updateOrCreate(
                        ['pegawai_id' => $pegawai->id, 'tahun' => $tahunLalu],
                        [
                            'kuota_tahunan' => $saldoLalu->kuota_tahunan ?? 0,
                            'carry_forward_normal' => $baru['dua_tahun_lalu'],
                            'sisa' => $saldoLalu->sisa ?? 0,
                        ]
                    );
                }

                // [TGH] Total Tgh di file menimpa total tersimpan (lewat
                // setTotalDitangguhkan, sama dengan edit di baris) dan dicatat di log.
                foreach ($tahunAsalTgh as $kolomLog => $tahunAsal) {
                    if ($tghBaru[$kolomLog] === $tghLama[$kolomLog]) {
                        continue;
                    }

                    $this->setTotalDitangguhkan((int) $pegawai->id, $tahunAsal, $tghBaru[$kolomLog]);

                    LogPerubahanSaldo::create([
                        'pegawai_id' => $pegawai->id,
                        'diubah_oleh' => $idAdmin,
                        'tahun' => $tahunIni,
                        'kolom' => $kolomLog,
                        'nilai_lama' => $tghLama[$kolomLog],
                        'nilai_baru' => $tghBaru[$kolomLog],
                        'alasan' => 'Impor Excel',
                    ]);
                }

                // Tgh tahun ini dari file bisa mengubah Terpakai
                $terpakai = SaldoCuti::terpakaiEfektif((int) $pegawai->id, $tahunIni);
                SaldoCuti::where('pegawai_id', $pegawai->id)
                    ->where('tahun', $tahunIni)
                    ->update(['sisa' => max(0, (int) $baru['hak'] - $terpakai)]);

                // [AUTO-SYNC] Bawaan tahun depan dihitung ulang
                SaldoCuti::sinkronkanBawaan((int) $pegawai->id, $tahunIni);

                $this->kirimNotifikasiPerubahanSaldo($pegawai, $tahunIni, $lama, $baru, $terpakai);
                $diperbarui++;
            }
        });

        if ($diperbarui === 0 && $tanpaPerubahan === 0) {
            $alasan = $dilewati ? ' ' . implode('; ', array_slice($dilewati, 0, 5)) . '.' : '';
            return back()->withErrors(['file' => 'Tidak ada baris yang bisa diproses.' . $alasan]);
        }

        $pesan = "Impor {$tahunIni} selesai: {$diperbarui} pegawai diperbarui, {$tanpaPerubahan} tanpa perubahan";

        if ($dilewati) {
            $pesan .= ', ' . count($dilewati) . ' baris dilewati (' . implode('; ', array_slice($dilewati, 0, 5))
                . (count($dilewati) > 5 ? '; dan ' . (count($dilewati) - 5) . ' lainnya' : '') . ')';
        }

        return back()->with('success', $pesan . '.');
    }
    // ================= END IMPOR KUOTA CUTI =================

    // Hitung field-field kuota cuti satu pegawai.
    // Dipakai index(), indexAtasan(), exportExcel(), dan hitungKpi().
    private function hitungKuota(Pegawai $pegawai, int $tahunIni, bool $muatApprovalChain = true): array
    {
        if ($pegawai->relationLoaded('saldoCuti')) {
            $saldo = $pegawai->saldoCuti->firstWhere('tahun', $tahunIni);
        } else {
            $saldo = $pegawai->saldoCuti->first();
        }

        $kuotaTahunan = (int) ($saldo->kuota_tahunan ?? 12);
        $carryForward = (int) ($saldo->carry_forward_normal ?? 0);

        // NULL = ikut sistem, angka (termasuk 0) = koreksi tahunan lama Admin HR
        $koreksiRaw = $saldo?->koreksi_saldo;
        $koreksiSaldo = $koreksiRaw === null ? null : max(0, (int) $koreksiRaw);

        // Saldo tahun lalu: dari relasi bila sudah di-load, fallback query
        if ($pegawai->relationLoaded('saldoCuti')) {
            $saldoTahunLalu = $pegawai->saldoCuti->firstWhere('tahun', $tahunIni - 1);
            // Pastikan $saldo adalah tahun terpilih (bukan tahun-1)
            $saldo = $pegawai->saldoCuti->firstWhere('tahun', $tahunIni) ?? $saldo;
            $kuotaTahunan = (int) ($saldo->kuota_tahunan ?? 12);
            $carryForward = (int) ($saldo->carry_forward_normal ?? 0);
            $koreksiRaw = $saldo?->koreksi_saldo;
            $koreksiSaldo = $koreksiRaw === null ? null : max(0, (int) $koreksiRaw);
        } else {
            $saldoTahunLalu = SaldoCuti::where('pegawai_id', $pegawai->id)
                ->where('tahun', $tahunIni - 1)
                ->first();
        }

        // Dompet tahun lalu ASLI (bukan bayangan)? Dipakai Vue untuk tahu apakah
        // mengubah Tgh tahun lalu ikut menghitung ulang Sisa tahun lalu.
        $dompetTahunLaluAsli = $saldoTahunLalu !== null
            && (int) ($saldoTahunLalu->kuota_tahunan ?? 0) > 0;

        $cutiTerpakaiMurni = $this->hitungCutiTerpakaiMurniDariRelasi($pegawai, $tahunIni);

        // [KOREKSI BULANAN] Bila ada, Terpakai mengikuti jumlah bulanan hasil edit
        // di Rekap Laporan. Bila tidak, aturan lama.
        $petaKoreksiBulanan = $this->petaKoreksiBulanan((int) $pegawai->id, $tahunIni);
        $adaKoreksiBulanan = !empty($petaKoreksiBulanan);

        // Koreksi bulanan = SELISIH terhadap hitungan sistem (aturan sama dengan
        // SaldoCuti::terpakaiEfektif), dihitung dari cache tanpa query per pegawai.
        $cutiTerpakai = $adaKoreksiBulanan
            ? max(0, $cutiTerpakaiMurni + $this->selisihKoreksiBulanan((int) $pegawai->id, $tahunIni))
            : $this->hitungCutiTerpakaiEfektif($cutiTerpakaiMurni, $koreksiSaldo);

        // [TGH MANUAL] Tgh tahun ini yang diketik Admin HR (tanpa pengajuan asal)
        // mengurangi Terpakai (hari itu kembali ke saldo), sama dengan
        // SaldoCuti::terpakaiEfektif().
        $tghManualTahunIni = (int) ($pegawai->relationLoaded('cutiDitangguhkan')
            ? $pegawai->cutiDitangguhkan
            ->where('tahun_asal', $tahunIni)
            ->whereNull('pengajuan_asal_id')
            ->sum('jumlah_hari')
            : $pegawai->cutiDitangguhkan()
            ->where('tahun_asal', $tahunIni)
            ->whereNull('pengajuan_asal_id')
            ->sum('jumlah_hari'));
        $cutiTerpakai = max(0, $cutiTerpakai - $tghManualTahunIni);

        $sisaDuaTahunLalu = (int) ($saldoTahunLalu?->carry_forward_normal ?? 0);
        $saldoBawaanEligible = SaldoCuti::hitungBawaan($sisaDuaTahunLalu, $carryForward, $kuotaTahunan);

        // Sisa ditangguhkan: dari relasi eager-load (tanpa N+1), fallback query
        $sisaDitangguhkanDuaTahunLalu = $pegawai->relationLoaded('cutiDitangguhkan')
            ? (int) $pegawai->cutiDitangguhkan
                ->where('tahun_asal', $tahunIni - 2)
                ->sum('jumlah_hari')
            : (int) $pegawai->cutiDitangguhkan()
                ->where('tahun_asal', $tahunIni - 2)
                ->sum('jumlah_hari');
        $sisaDitangguhkanTahunLalu = $pegawai->relationLoaded('cutiDitangguhkan')
            ? (int) $pegawai->cutiDitangguhkan
                ->where('tahun_asal', $tahunIni - 1)
                ->sum('jumlah_hari')
            : (int) $pegawai->cutiDitangguhkan()
                ->where('tahun_asal', $tahunIni - 1)
                ->sum('jumlah_hari');

        // Ditangguhkan pada tahun yang dilihat (hari Desember dari cuti lintas
        // tahun tercatat di tahun asalnya masing-masing)
        $sisaDitangguhkanTahunIni = $pegawai->relationLoaded('cutiDitangguhkan')
            ? (int) $pegawai->cutiDitangguhkan
                ->where('tahun_asal', $tahunIni)
                ->sum('jumlah_hari')
            : (int) $pegawai->cutiDitangguhkan()
                ->where('tahun_asal', $tahunIni)
                ->sum('jumlah_hari');

        // Total Hak Tersedia = Hak + Bawaan Eligible + Tgh tahun-1 (bila TGH_MASUK_TOTAL).
        // SENGAJA tidak lewat hitungTotal() karena itu mengembalikan total SESUDAH
        // dikurangi Terpakai.
        $totalTgh = self::TGH_MASUK_TOTAL
            ? $sisaDitangguhkanTahunLalu
            : 0;
        $totalCutiTersedia = $kuotaTahunan + $saldoBawaanEligible + $totalTgh;

        // Tgh TAHUN INI memotong Sisa tahun ini & Saldo Akhir (Tgh N-2 hanya catatan)
        $potonganTghTahunIni = self::TGH_TAHUN_INI_MENGURANGI_SISA
            ? $sisaDitangguhkanTahunIni
            : 0;

        // Rumus aritmetika satu sumber dengan Dashboard, Ajukan Cuti, dan Monitoring
        $hasilSaldo = RincianSaldo::hitungTotal(
            (int) $kuotaTahunan,
            (int) $saldoBawaanEligible,
            (int) $totalTgh,
            (int) $cutiTerpakai,
            (int) $potonganTghTahunIni
        );
        $sisaKuotaTahunIni = $hasilSaldo['sisa_hak_tahun_berjalan'];
        $saldoAkhir        = $hasilSaldo['total_cuti_tersedia'];

        // Info audit koreksi tahunan (data lama). 'koreksi_basi' = ada koreksi, tetapi
        // angka sistem sekarang berbeda dari saat koreksi dibuat, jadi perlu dicek
        // ulang. Tidak berlaku bila koreksi bulanan dipakai.
        $sistemSaatKoreksi = $saldo?->koreksi_terpakai_sistem;
        $koreksiBasi = !$adaKoreksiBulanan
            && $koreksiSaldo !== null
            && $sistemSaatKoreksi !== null
            && (int) $sistemSaatKoreksi !== $cutiTerpakaiMurni;

        // Riwayat perubahan saldo (dilewati untuk KPI/Export). Dibaca dari
        // $cacheLogPerubahan; bila cache belum terisi, fallback query per pegawai.
        $logPerubahan = [];
        if ($muatApprovalChain) {
            $logPerubahan = $this->cacheLogPerubahan[$tahunIni][(int) $pegawai->id]
                ?? LogPerubahanSaldo::with('pengubah:id,nama')
                ->where('pegawai_id', $pegawai->id)
                ->where('tahun', $tahunIni)
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn($log) => $this->formatLogPerubahan($log))
                ->all();
        }

        $previewCarryOver = SaldoCuti::hitungPreviewCarryOver($saldoAkhir);

        // Tempel approval_chain (dilewati untuk KPI/Export agar lebih cepat)
        if ($muatApprovalChain && $pegawai->relationLoaded('pengajuanCuti')) {
            $pegawai->pengajuanCuti->each(function ($riwayat) use ($pegawai, $tahunIni) {
                $riwayat->approval_chain = $this->buildApprovalChain($riwayat, $pegawai);

                // Hari yang jatuh di tahun aktif (cuti lintas tahun), fungsi yang
                // sama dengan Terpakai
                $riwayat->hari_di_tahun_ini = SaldoCuti::hariCutiDiTahun(
                    $riwayat->tanggal_mulai,
                    $riwayat->tanggal_selesai,
                    (int) $riwayat->jumlah_hari,
                    $tahunIni
                );
            });
        }

        return [
            'id' => $pegawai->id,
            'nama' => $pegawai->nama,
            'nip' => $pegawai->nip,
            'jabatan' => $pegawai->jabatan,
            'kelompok_substansi' => $pegawai->kelompok_substansi,
            'tim_kerja' => $pegawai->tim_kerja,
            'role_id' => $pegawai->role_id,

            // Field lama (kompatibilitas Export)
            'sisa_cuti_tahun_ini' => $sisaKuotaTahunIni,

            // Field utama
            'kuota_tahunan' => $kuotaTahunan,
            'hak_cuti' => $kuotaTahunan,
            'carry_forward_normal' => $carryForward,
            'sisa_cuti_dua_tahun_lalu' => $sisaDuaTahunLalu,
            'sisa_kuota_tahun_ini' => $sisaKuotaTahunIni,
            'sisa_hak_tahun_berjalan' => $sisaKuotaTahunIni,
            'saldo_bawaan_eligible' => $saldoBawaanEligible,
            'total_hak_cuti' => $totalCutiTersedia,
            'total_tgh_tersedia' => $totalTgh,
            'cuti_terpakai' => $cutiTerpakai,
            'sisa_cuti' => $saldoAkhir,
            'saldo_akhir' => $saldoAkhir,
            'total_cuti_tersedia' => $totalCutiTersedia,
            'preview_carry_over' => $previewCarryOver,

            // Sisa ditangguhkan
            'sisa_ditangguhkan_dua_tahun_lalu' => $sisaDitangguhkanDuaTahunLalu,
            'sisa_ditangguhkan_tahun_lalu' => $sisaDitangguhkanTahunLalu,
            'sisa_ditangguhkan_tahun_ini' => $sisaDitangguhkanTahunIni,
            'potongan_tgh_tahun_ini' => $potonganTghTahunIni,
            // Bagian Tgh tahun ini yang diketik manual (pratinjau di Vue)
            'tgh_manual_tahun_ini' => $tghManualTahunIni,

            // Bila true, mengubah Tgh tahun lalu di Vue ikut menghitung ulang Sisa tahun lalu
            'dompet_tahun_lalu_asli' => $dompetTahunLaluAsli,

            // Koreksi
            // 'terpakai_dikunci' selalu true: Vue menampilkan Terpakai sebagai teks
            // dan menunjuk Admin HR ke Rekap Laporan untuk mengubahnya.
            // koreksi_saldo: koreksi tahunan LAMA (null = tidak ada), hanya info.
            // koreksi_bulanan: [bulan => nilai] hasil edit Rekap Laporan.
            'terpakai_dikunci' => true,
            'cuti_terpakai_murni' => $cutiTerpakaiMurni,
            'cuti_terpakai_sistem' => $cutiTerpakaiMurni,
            'koreksi_saldo' => $koreksiSaldo,
            'ada_koreksi_bulanan' => $adaKoreksiBulanan,
            'koreksi_bulanan' => $petaKoreksiBulanan,
            'ada_koreksi' => $koreksiSaldo !== null || $adaKoreksiBulanan,
            'koreksi_alasan' => $koreksiSaldo !== null ? $saldo?->koreksi_alasan : null,
            'koreksi_pada' => $koreksiSaldo !== null ? $saldo?->koreksi_pada : null,
            'koreksi_terpakai_sistem_saat_koreksi' => $koreksiSaldo !== null ? $sistemSaatKoreksi : null,
            'koreksi_basi' => $koreksiBasi,
            'log_perubahan' => $logPerubahan,

            'pengajuan_cuti' => $pegawai->pengajuanCuti,
        ];
    }

    // ================= HELPER: FILTER WILAYAH UNTUK ATASAN =================
    //   - L1 (role 2): 'tim_kerja'
    //   - L2 (role 3): 'kelompok_substansi'
    //   - L3, L4 (role 4, 6): tidak dibatasi
    private function resolveWilayahFilterUntukAtasan(object $user): array
    {
        return match ((int) $user->role_id) {
            2 => ['field' => 'tim_kerja', 'value' => $user->tim_kerja],
            3 => ['field' => 'kelompok_substansi', 'value' => $user->kelompok_substansi],
            default => ['field' => null, 'value' => null],
        };
    }
    // ================= END HELPER FILTER WILAYAH ATASAN =================

    // ================= HELPER: DATA FILTER BERJENJANG UNTUK ATASAN =================
    private function buildFilterPropsUntukAtasan(Pegawai $user): array
    {
        $listTimKerja = [];
        $listKelompok = [];

        if ($user->role_id === 3) {
            $listTimKerja = Pegawai::where('kelompok_substansi', $user->kelompok_substansi)
                ->whereNotNull('tim_kerja')
                ->where('tim_kerja', '!=', '-')
                ->distinct()
                ->orderBy('tim_kerja')
                ->pluck('tim_kerja')
                ->values();
        } elseif (in_array($user->role_id, [4, 6], true)) {
            $listKelompok = Pegawai::where('role_id', '!=', 5)
                ->whereNotNull('kelompok_substansi')
                ->distinct()
                ->orderBy('kelompok_substansi')
                ->pluck('kelompok_substansi')
                ->values();
        }

        return [
            'userRoleId' => $user->role_id,
            'userTimKerja' => $user->tim_kerja,
            'listTimKerja' => $listTimKerja,
            'listKelompok' => $listKelompok,
        ];
    }
    // ================= END HELPER FILTER BERJENJANG ATASAN =================

    // ================= HELPER: KPI RINGKASAN =================
    // Dihitung dari SELURUH hasil filter (bukan hanya satu halaman).
    private function hitungKpi(Request $request, int $tahunIni, ?string $wilayahField, ?string $wilayahValue): array
    {
        $pegawaiSemua = $this->buildQuery($request, $wilayahField, $wilayahValue, false)->get();

        $dataLengkap = $pegawaiSemua->map(function ($pegawai) use ($tahunIni) {
            return $this->hitungKuota($pegawai, $tahunIni, false);
        });

        $totalPegawai = $dataLengkap->count();

        return [
            'total_pegawai' => $totalPegawai,
            'rata_rata_terpakai' => $totalPegawai > 0
                ? (int) round($dataLengkap->avg('cuti_terpakai'))
                : 0,
            'rata_rata_saldo_akhir' => $totalPegawai > 0
                ? (int) round($dataLengkap->avg('saldo_akhir'))
                : 0,
            'akumulasi_penuh' => $dataLengkap->filter(function ($item) {
                return (int) $item['sisa_cuti_dua_tahun_lalu'] === 12
                    && (int) $item['carry_forward_normal'] === 12;
            })->count(),
        ];
    }
    // ================= END HELPER KPI RINGKASAN =================

    // ================= HELPER BERSAMA: RENDER HALAMAN =================
    private function renderRekap(
        Request $request,
        string $inertiaView,
        ?string $wilayahField = null,
        ?string $wilayahValue = null,
        bool $sertakanDaftarDepartemen = false,
        array $extraProps = []
    ) {
        $tahunIni = $this->resolveTahun($request);

        $pegawaiPaginated = $this->buildQuery($request, $wilayahField, $wilayahValue)
            ->paginate(10)
            ->withQueryString();

        // Muat log perubahan semua pegawai di halaman ini dalam SATU query,
        // sebelum hitungKuota() dipanggil per pegawai.
        $this->muatLogPerubahan(
            $pegawaiPaginated->getCollection()->pluck('id')->map(fn($id) => (int) $id)->all(),
            $tahunIni
        );

        $pegawaiPaginated->getCollection()->transform(function ($pegawai) use ($tahunIni) {
            return $this->hitungKuota($pegawai, $tahunIni);
        });

        $kpi = $this->hitungKpi($request, $tahunIni, $wilayahField, $wilayahValue);

        $props = array_merge([
            'dataPegawai' => $pegawaiPaginated,
            'kpi' => $kpi,
            'tahunTerpilih' => $tahunIni,
            'daftarTahun' => $this->buildDaftarTahun($tahunIni),
            'filters' => $request->only('search', 'kelompok_substansi', 'tim_kerja', 'tahun'),
        ], $extraProps);

        if ($sertakanDaftarDepartemen) {
            $props['daftarKelompokSubstansi'] = Pegawai::where('role_id', '!=', 5)
                ->whereNotNull('kelompok_substansi')
                ->distinct()
                ->orderBy('kelompok_substansi')
                ->pluck('kelompok_substansi');
        }

        return Inertia::render($inertiaView, $props);
    }
    // ================= END HELPER RENDER HALAMAN =================

    /**
     * Rekap Kuota Detail (Admin HR) — semua pegawai.
     * Hanya role_id 5; atasan yang membuka URL ini mendapat 403.
     */
    public function index(Request $request)
    {
        abort_unless((int) $request->user()->role_id === 5, 403);

        // Tombol Generate dikunci bila semua pegawai aktif sudah punya dompet tahun
        // depan. Pegawai baru tanpa dompet mengaktifkannya lagi.
        $extraProps = [
            'sudahGenerateTahunDepan' => SaldoCuti::jumlahPegawaiBelumPunyaDompet((int) date('Y') + 1) === 0,
        ];

        return $this->renderRekap($request, 'Admin/RekapKuotaDetail', null, null, true, $extraProps);
    }

    /**
     * Versi Atasan (L1-L4), read-only, tanpa Impor Kuota & Export.
     * L1 = se-Tim Kerja, L2 = se-Kelompok Substansi, L3 & L4 = semua.
     */
    public function indexAtasan(Request $request)
    {
        $user = $request->user();
        $wilayah = $this->resolveWilayahFilterUntukAtasan($user);

        return $this->renderRekap(
            $request,
            'Atasan/RekapKuotaPegawai',
            $wilayah['field'],
            $wilayah['value'],
            false,
            $this->buildFilterPropsUntukAtasan($user),
        );
    }

    // ================= HELPER BERSAMA: BUILD FILE EXCEL =================
    // Kolom: A No | B NIP | C Nama | D Sisa N-2 | E Tgh N-2 | F Sisa N-1 | G Tgh N-1
    //        H Sisa N | I Tgh N | J Bawaan Eligible | K Hak | L Total Tersedia
    //        M Terpakai | N Saldo Akhir | O Status Saldo
    //
    // Kolom hasil hitungan berisi RUMUS Excel:
    //   M = Terpakai + Tgh tahun ini (bila TGH_TAHUN_INI_MENGURANGI_SISA), sama dengan
    //       web & dashboard. Karena M sudah memuat Tgh tahun ini, rumus H dan N tidak
    //       mengurangi kolom I lagi (kalau tidak, Tgh terhitung dua kali).
    //   H = MAX(K - M, 0)
    //   J = terjemahan SaldoCuti::hitungBawaan() (dari D, F, K)
    //   L = K + J + G   (G = Tgh N-1, bila TGH_MASUK_TOTAL aktif)
    //   N = MAX(L - M, 0)
    //   O = IF(N > 0, "TERSISA", "HABIS")
    private function buildRekapSpreadsheet(Request $request, ?string $wilayahField = null, ?string $wilayahValue = null): Spreadsheet
    {
        $tahunIni = $this->resolveTahun($request);
        $tahunDuaLalu = $tahunIni - 2;
        $tahunLalu = $tahunIni - 1;

        // Export tidak butuh rantai approval
        $pegawais = $this->buildQuery($request, $wilayahField, $wilayahValue, false)->get();

        $data = $pegawais->map(function ($pegawai) use ($tahunIni) {
            return $this->hitungKuota($pegawai, $tahunIni, false);
        })->values();

        // Warna
        $GREEN_TITLE   = '14532D';
        $GREEN_HEADER  = '16A34A';
        $ZEBRA         = 'F0FDF4';
        $ORANGE        = 'FDE9CE';
        $STATUS_OK     = 'DCFCE7';
        $STATUS_HABIS  = 'FEE2E2';

        // Kolom terakhir (O = Status Saldo)
        $LAST_COL = 'O';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Saldo Tahunan');

        // Judul
        $sheet->mergeCells("A1:{$LAST_COL}1");
        $sheet->setCellValue('A1', 'Rekap Kuota Detail Cuti — Biro Perencanaan');
        $sheet->getStyle('A1')->getFont()->setName('Arial')->setSize(14)->setBold(true)->getColor()->setRGB($GREEN_TITLE);
        $sheet->getRowDimension(1)->setRowHeight(22);

        $sheet->mergeCells("A2:{$LAST_COL}2");
        $sheet->setCellValue('A2', "Diunduh otomatis dari sistem — data per tahun {$tahunIni}");
        $sheet->getStyle('A2')->getFont()->setName('Arial')->setSize(10)->getColor()->setRGB('6B7280');
        $sheet->getRowDimension(3)->setRowHeight(6);

        // Header
        $headerRow = 4;
        $headers = [
            'A' => 'No',
            'B' => 'NIP',
            'C' => 'Nama Pegawai',
            'D' => "Sisa {$tahunDuaLalu}",
            'E' => "Sisa Ditangguhkan {$tahunDuaLalu}",
            'F' => "Sisa {$tahunLalu}",
            'G' => "Sisa Ditangguhkan {$tahunLalu}",
            'H' => "Sisa {$tahunIni}",
            'I' => "Sisa Ditangguhkan {$tahunIni}",
            'J' => "Saldo Bawaan {$tahunIni} yang Eligible",
            'K' => "Hak {$tahunIni}",
            'L' => "Total Hak Tersedia {$tahunIni}",
            'M' => "Terpakai Cuti Tahunan {$tahunIni}",
            'N' => "Saldo Akhir {$tahunIni}",
            'O' => 'Status Saldo',
        ];

        foreach ($headers as $col => $label) {
            $sheet->setCellValue("{$col}{$headerRow}", $label);
            $sheet->getStyle("{$col}{$headerRow}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 9, 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $GREEN_HEADER]],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
            ]);
        }

        // Catatan pada header kolom "Sisa Ditangguhkan"
        foreach (['E', 'G', 'I'] as $col) {
            if ($col === 'I' && self::TGH_TAHUN_INI_MENGURANGI_SISA) {
                $teksTgh = "Hari cuti ditangguhkan tahun {$tahunIni}: sudah dihitung di kolom Terpakai, sehingga MENGURANGI Sisa {$tahunIni} dan Saldo Akhir.";
            } elseif ($col === 'G' && self::TGH_MASUK_TOTAL) {
                $teksTgh = "Hari cuti ditangguhkan tahun {$tahunLalu}: MENAMBAH Total Hak Tersedia {$tahunIni}.";
            } elseif ($col === 'I') {
                $teksTgh = "Hari cuti ditangguhkan tahun {$tahunIni}: dikembalikan ke saldo {$tahunIni} "
                    . '(Terpakai berkurang, Sisa bertambah), lalu dibawa ke ' . ($tahunIni + 1)
                    . ' dan menambah Total Hak Tersedia tahun itu.';
            } else {
                $teksTgh = 'Catatan Admin HR: tidak memengaruhi saldo.';
            }

            $sheet->getComment("{$col}{$headerRow}")
                ->getText()
                ->createTextRun($teksTgh);
        }

        // Catatan rumus pada header kolom hasil hitungan
        $catatanRumus = [
            'H' => self::TGH_TAHUN_INI_MENGURANGI_SISA
                ? "Rumus: Hak - Terpakai (minimal 0). Terpakai sudah termasuk Sisa Ditangguhkan {$tahunIni}."
                : 'Rumus: Hak - Terpakai (minimal 0).',
            'J' => "Rumus: 12 jika Sisa {$tahunDuaLalu} dan Sisa {$tahunLalu} sama-sama 12, selain itu MIN(6, Sisa {$tahunLalu})."
                . (SaldoCuti::TERAPKAN_BATAS_ATAS_TOTAL_HAK ? ' Dibatasi Hak + Bawaan maksimal ' . SaldoCuti::BATAS_ATAS_TOTAL_HAK . '.' : ''),
            'L' => self::TGH_MASUK_TOTAL
                ? "Rumus: Hak + Saldo Bawaan Eligible + Sisa Ditangguhkan {$tahunLalu}."
                : 'Rumus: Hak + Saldo Bawaan Eligible.',
            'M' => self::TGH_TAHUN_INI_MENGURANGI_SISA
                ? "Terpakai = cuti yang digunakan (sudah ikut koreksi bulanan Rekap Laporan) + Sisa Ditangguhkan {$tahunIni} (sama dengan tampilan di web)."
                : 'Terpakai = cuti yang digunakan (sudah ikut koreksi bulanan Rekap Laporan).',
            'N' => self::TGH_TAHUN_INI_MENGURANGI_SISA
                ? "Rumus: Total Hak Tersedia - Terpakai (minimal 0). Terpakai sudah termasuk Sisa Ditangguhkan {$tahunIni}."
                : 'Rumus: Total Hak Tersedia - Terpakai (minimal 0).',
            'O' => 'Rumus: TERSISA jika Saldo Akhir > 0, selain itu HABIS.',
        ];
        foreach ($catatanRumus as $col => $teksCatatan) {
            $sheet->getComment("{$col}{$headerRow}")->getText()->createTextRun($teksCatatan);
        }
        $sheet->getRowDimension($headerRow)->setRowHeight(34);

        // Data
        $dataStartRow = 5;
        $r = $dataStartRow;

        foreach ($data as $i => $item) {
            $zebra = $i % 2 === 1 ? $ZEBRA : null;

            // Terpakai untuk Excel = digunakan + Tgh tahun ini (sama dengan web).
            // 'potongan_tgh_tahun_ini' sudah 0 bila TGH_TAHUN_INI_MENGURANGI_SISA = false.
            $terpakaiExcel = (int) $item['cuti_terpakai'] + (int) $item['potongan_tgh_tahun_ini'];

            $sheet->setCellValue("A{$r}", $i + 1);
            $sheet->setCellValueExplicit("B{$r}", (string) $item['nip'], DataType::TYPE_STRING);
            // Kolom C (Nama) ditulis lewat tulisTeksAman() SETELAH styling (di bawah)
            $sheet->setCellValue("D{$r}", $item['sisa_cuti_dua_tahun_lalu']);
            $sheet->setCellValue("E{$r}", $item['sisa_ditangguhkan_dua_tahun_lalu']);
            $sheet->setCellValue("F{$r}", $item['carry_forward_normal']);
            $sheet->setCellValue("G{$r}", $item['sisa_ditangguhkan_tahun_lalu']);
            // Sisa tahun ini = Hak - Terpakai (Terpakai sudah memuat Tgh tahun ini)
            $sheet->setCellValue("H{$r}", "=MAX(K{$r}-M{$r},0)");
            $sheet->setCellValue("I{$r}", $item['sisa_ditangguhkan_tahun_ini']);

            // Saldo Bawaan = terjemahan SaldoCuti::hitungBawaan(): Sisa N-2 = 12 DAN
            // Sisa N-1 = 12 -> 12, selain itu MIN(6, Sisa N-1). Batas atas hanya
            // dipasang bila SaldoCuti::TERAPKAN_BATAS_ATAS_TOTAL_HAK = true.
            $rumusBawaan = "IF(AND(D{$r}=12,F{$r}=12),12,MIN(6,MAX(0,F{$r})))";
            $sheet->setCellValue("J{$r}", '=' . (SaldoCuti::TERAPKAN_BATAS_ATAS_TOTAL_HAK
                ? 'MAX(0,MIN(' . $rumusBawaan . ',' . SaldoCuti::BATAS_ATAS_TOTAL_HAK . "-K{$r}))"
                : $rumusBawaan));

            $sheet->setCellValue("K{$r}", $item['kuota_tahunan']);
            $sheet->setCellValue("L{$r}", self::TGH_MASUK_TOTAL
                ? "=K{$r}+J{$r}+G{$r}"   // Hak + Bawaan + Tgh tahun lalu
                : "=K{$r}+J{$r}");       // Hak + Bawaan
            $sheet->setCellValue("M{$r}", $terpakaiExcel);                // Terpakai (sudah termasuk Tgh tahun ini)
            $sheet->setCellValue("N{$r}", "=MAX(L{$r}-M{$r},0)");         // Saldo Akhir = Total - Terpakai

            $statusSaldo = $item['saldo_akhir'] > 0 ? 'TERSISA' : 'HABIS';
            $sheet->setCellValue("O{$r}", "=IF(N{$r}>0,\"TERSISA\",\"HABIS\")");

            foreach (['A', 'B', 'C', 'D', 'F', 'H', 'J', 'K', 'L', 'M', 'N'] as $col) {
                $sheet->getStyle("{$col}{$r}")->applyFromArray([
                    'font' => ['name' => 'Arial', 'size' => 10],
                    'alignment' => [
                        'horizontal' => $col === 'C' ? Alignment::HORIZONTAL_LEFT : Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                    ],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
                    'fill' => $zebra ? ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $zebra]] : [],
                ]);
            }

            // [SEC-09] Teks dari database: SELALU string murni (setelah styling C)
            $this->tulisTeksAman($sheet, "C{$r}", $item['nama']);

            // Sisa Ditangguhkan (oranye bila > 0)
            foreach (['E', 'G', 'I'] as $col) {
                $nilai = (int) $sheet->getCell("{$col}{$r}")->getValue();
                $sheet->getStyle("{$col}{$r}")->applyFromArray([
                    'font' => ['name' => 'Arial', 'size' => 10, 'bold' => $nilai > 0],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $nilai > 0 ? $ORANGE : ($zebra ?? 'FFFFFF')]],
                ]);
            }

            // Status Saldo
            $sheet->getStyle("O{$r}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 9, 'bold' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $statusSaldo === 'TERSISA' ? $STATUS_OK : $STATUS_HABIS]],
            ]);

            $r++;
        }

        // ---- Rumus ringkasan + format kondisional (hanya bila ada data) ----
        $dataEndRow = $r - 1;
        if ($dataEndRow >= $dataStartRow) {
            // Warna Status Saldo mengikuti hasil rumus, jadi ikut berubah bila angka diedit
            $kondisi = [];
            foreach (['TERSISA' => [$STATUS_OK, '166534'], 'HABIS' => [$STATUS_HABIS, '991B1B']] as $teks => [$warnaBg, $warnaFont]) {
                $c = new Conditional();
                $c->setConditionType(Conditional::CONDITION_CELLIS)
                    ->setOperatorType(Conditional::OPERATOR_EQUAL)
                    ->addCondition('"' . $teks . '"');
                $c->getStyle()->getFont()->getColor()->setRGB($warnaFont);
                $c->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($warnaBg);
                $c->getStyle()->getFill()->getEndColor()->setRGB($warnaBg);
                $kondisi[] = $c;
            }
            $sheet->getStyle("O{$dataStartRow}:O{$dataEndRow}")->setConditionalStyles($kondisi);

            // Baris TOTAL dan RATA-RATA
            $barisTotal = $r;
            $barisRata = $r + 1;
            $kolomAngka = ['D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N'];

            $sheet->mergeCells("A{$barisTotal}:C{$barisTotal}");
            $sheet->setCellValue("A{$barisTotal}", 'TOTAL');
            $sheet->mergeCells("A{$barisRata}:C{$barisRata}");
            $sheet->setCellValue("A{$barisRata}", 'RATA-RATA PER PEGAWAI');

            foreach ($kolomAngka as $col) {
                $sheet->setCellValue("{$col}{$barisTotal}", "=SUM({$col}{$dataStartRow}:{$col}{$dataEndRow})");
                $sheet->setCellValue("{$col}{$barisRata}", "=ROUND(AVERAGE({$col}{$dataStartRow}:{$col}{$dataEndRow}),1)");
            }
            $sheet->setCellValue("O{$barisTotal}", "=COUNTA(O{$dataStartRow}:O{$dataEndRow})&\" pegawai\"");
            $sheet->setCellValue("O{$barisRata}", "=COUNTIF(O{$dataStartRow}:O{$dataEndRow},\"HABIS\")&\" habis\"");

            foreach ([$barisTotal, $barisRata] as $baris) {
                $sheet->getStyle("A{$baris}:{$LAST_COL}{$baris}")->applyFromArray([
                    'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => ['rgb' => $GREEN_TITLE]],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $STATUS_OK]],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
                ]);
                $sheet->getStyle("A{$baris}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
                $sheet->getRowDimension($baris)->setRowHeight(20);
            }
            $sheet->getStyle("D{$barisRata}:N{$barisRata}")->getNumberFormat()->setFormatCode('0.0');
        }

        // Lebar kolom
        $lebar = [
            'A' => 5,
            'B' => 20,
            'C' => 30,
            'D' => 9,
            'E' => 13,
            'F' => 9,
            'G' => 13,
            'H' => 9,
            'I' => 13,
            'J' => 13,
            'K' => 9,
            'L' => 13,
            'M' => 13,
            'N' => 11,
            'O' => 11,
        ];
        foreach ($lebar as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }

        $sheet->freezePane('A' . $dataStartRow);

        return $spreadsheet;
    }

    // [SEC-09] Tulis teks dari database ke sel Excel sebagai STRING murni (bukan rumus).
    // setCellValue() biasa mengubah string berawalan "=" menjadi rumus aktif;
    // setCellValueExplicit(TYPE_STRING) memaksa teks, dan quotePrefix menjadi lapis
    // kedua agar tetap dianggap teks oleh Excel/WPS. WAJIB dipanggil SETELAH
    // applyFromArray() pada sel yang sama.
    private function tulisTeksAman(Worksheet $sheet, string $koordinat, ?string $nilai): void
    {
        $nilai = (string) $nilai;

        $sheet->setCellValueExplicit($koordinat, $nilai, DataType::TYPE_STRING);

        if ($nilai !== '' && preg_match('/^[=+\-@\t\r]/', $nilai)) {
            $sheet->getStyle($koordinat)->setQuotePrefix(true);
        }
    }

    private function streamRekapSpreadsheet(Spreadsheet $spreadsheet, Request $request)
    {
        $tahunIni = $this->resolveTahun($request);
        $filename = "rekap_kuota_detail_cuti_{$tahunIni}_" . date('Y-m-d') . '.xlsx';

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
    // ================= END HELPER BUILD FILE EXCEL =================

    /**
     * Export Excel (Admin HR) — semua pegawai, ikut Filter Tahun.
     * Hanya role_id 5. Versi Atasan sudah dihapus karena atasan tidak boleh mengekspor.
     */
    public function exportExcel(Request $request)
    {
        abort_unless((int) $request->user()->role_id === 5, 403);

        $spreadsheet = $this->buildRekapSpreadsheet($request);

        return $this->streamRekapSpreadsheet($spreadsheet, $request);
    }
}
