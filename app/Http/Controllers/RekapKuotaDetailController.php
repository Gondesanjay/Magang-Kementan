<?php

namespace App\Http\Controllers;

use App\Models\CutiDitangguhkan;
use App\Models\KoreksiCutiBulanan;
use App\Models\LogPerubahanSaldo;
use App\Models\Notifikasi;
use App\Models\Pegawai;
use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// ================= PERUBAHAN (TERPAKAI DIKUNCI, EDIT DI REKAP LAPORAN) =================
// "Cuti Terpakai" di halaman ini sekarang HANYA TAMPIL (read-only). Angkanya
// diedit per bulan di Rekap Laporan (tabel koreksi_cuti_bulanan), sehingga
// selalu jelas koreksinya jatuh di bulan mana.
//
//   - hitungKuota()  : bila pegawai punya koreksi bulanan di tahun itu, Terpakai
//                      memakai SaldoCuti::terpakaiEfektif() (satu rumus dengan
//                      Rekap Laporan). Bila tidak, perilaku lama (koreksi_saldo
//                      tahunan / hitungan sistem).
//   - updateSaldo()  : TIDAK lagi menerima koreksi_saldo / pakai_sistem dan tidak
//                      menyentuh kolom koreksi_*. Hak, Sisa N-1, Sisa N-2, dan
//                      Tgh tetap bisa diedit di sini.
//   - Field baru ke Vue: 'ada_koreksi_bulanan', 'koreksi_bulanan' (bulan => nilai),
//                      'terpakai_dikunci' (selalu true).
//   - kirimNotifikasiPerubahanSaldo() & setTotalDitangguhkan() dibuat public
//                      supaya AdminController bisa memakainya setelah koreksi
//                      bulanan disimpan.
//   - buildQuery()   : pengajuan cuti yang di-eager load dibatasi pada yang
//                      bersinggungan dengan tahun yang dilihat (cuti lintas tahun
//                      tampil di kedua tahunnya).
// ================= END PERUBAHAN =================

class RekapKuotaDetailController extends Controller
{
    // Alasan perubahan WAJIB diisi kalau ada angka yang berubah.
    // Biarkan false sampai form di RekapKuotaDetail.vue punya kolom alasan,
    // lalu ubah ke true (kalau true lebih dulu, tombol Simpan akan ditolak).
    private const WAJIB_ALASAN_KOREKSI = false;

    // Teks pengisi kolom cuti_ditangguhkans.alasan_penangguhan (kolom ini WAJIB,
    // tidak punya default di database) untuk baris yang dibuat dari edit manual
    // Tgh oleh Admin HR, supaya dibedakan dari penangguhan asli oleh Kepala Biro.
    private const ALASAN_TGH_MANUAL = 'Koreksi manual Admin HR';

    // true  : Total Tersedia tahun N = Hak N + Bawaan Eligible N + Tgh tahun asal N-1
    //         (2026 -> Tgh 2025, 2027 -> Tgh 2026, 2025 -> Tgh 2024)
    // false : Total Tersedia = Hak + Bawaan Eligible (Tgh hanya informasi, perilaku lama)
    // [DIUBAH] Sisa Ditangguhkan tahun asal N-1 (mis. Sisa Ditangguhkan 2025 untuk
    // rekap 2026) kini MASUK Total Hak Tersedia. Tgh N-2 tetap hanya catatan.
    private const TGH_MASUK_TOTAL = true;

    // true  : Tgh TAHUN YANG SEDANG DILIHAT (N) MENGURANGI Sisa N dan Saldo Akhir N
    //         (Sisa N = Hak - Terpakai - Tgh N; Saldo Akhir = Total - Terpakai - Tgh N).
    // false : Tgh N hanya catatan, tidak mengurangi apa pun.
    private const TGH_TAHUN_INI_MENGURANGI_SISA = true;

    // ================= URUTAN HIERARKI JABATAN =================
    // Mapping role_id -> urutan tampil (angka lebih kecil = tampil lebih atas).
    // SAMA PERSIS dengan $urutanJabatan di AdminController.
    //
    // role_id 6 -> Kepala Biro Perencanaan (L4)
    // role_id 4 -> Kasubag TU (L3)
    // role_id 3 -> Ketua Kelompok Substansi (L2)
    // role_id 2 -> Ketua Tim Kerja (L1)
    // role_id 1 -> Pegawai / Staf
    // role_id lain -> fallback ELSE 99
    private array $urutanJabatan = [
        6 => 1,
        4 => 2,
        3 => 3,
        2 => 4,
        1 => 5,
    ];
    // ================= END URUTAN HIERARKI JABATAN =================

    // ================= RANTAI APPROVAL =================
    // Mapping level approval (1-4) -> role_id yang bertugas.
    // SAMA PERSIS dengan $roleYangDibutuhkan di ApprovalController.
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

    // Cache koreksi bulanan per tahun: [tahun => [pegawai_id => [bulan => nilai]]].
    // Dimuat SEKALI per tahun (satu query), supaya hitungKuota() tidak N+1.
    private array $cacheKoreksiBulanan = [];
    // ================= END RANTAI APPROVAL =================

    // ================= HELPER: FILTER TAHUN =================
    // SATU-SATUNYA tempat yang menentukan "tahun rekap".
    // Tahun dibaca dari query string (?tahun=2025).
    // Kalau tidak dikirim / tidak valid, jatuh ke tahun berjalan.
    private function resolveTahun(Request $request): int
    {
        $tahun = (int) $request->input('tahun', date('Y'));

        return ($tahun >= 2000 && $tahun <= 2100)
            ? $tahun
            : (int) date('Y');
    }

    // Daftar opsi Filter Tahun untuk dropdown di Vue.
    // Tahun Sekarang +1, 0, -1, -2. Tahun yang sedang dipilih selalu ikut.
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
    // Nilai status_aktif harus sama dengan STATUS_PEGAWAI di AdminController:
    // 'Aktif', 'Pensiun', 'Mutasi Keluar', 'Resign / Berhenti', 'Meninggal Dunia'.
    // Pegawai lama yang kolom status_aktif-nya NULL dianggap 'Aktif'
    // (sama dengan perilaku KelolaPegawai.vue).

    /**
     * Hanya pegawai yang masih aktif (atau status_aktif NULL).
     * Dipakai untuk mencari nama approver: pegawai yang sudah pensiun / keluar
     * tidak boleh lagi tampil sebagai penyetuju yang ditunggu.
     */
    private function hanyaAktif(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->where('status_aktif', 'Aktif')
                ->orWhereNull('status_aktif');
        });
    }

    /**
     * Untuk daftar Rekap pada tahun tertentu:
     *   - Pegawai AKTIF selalu tampil, dan
     *   - Pegawai NON-AKTIF (pensiun, mutasi keluar, dst.) HANYA tampil kalau
     *     pada tahun itu memang punya cuti tahunan yang disetujui atau
     *     ditangguhkan setelah disetujui, supaya rekap tahun-tahun lalu
     *     tetap utuh sebagai arsip.
     *
     * Dasarnya sengaja riwayat cuti, BUKAN keberadaan baris saldo_cutis,
     * sehingga saldo "siluman" hasil Generate Saldo Tahun Baru untuk pegawai
     * yang sudah pensiun tidak membuatnya muncul di tahun yang baru.
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
    // Baris saldo_cutis "ASLI" = hasil generate / input hak (kuota_tahunan > 0).
    // Baris bayangan (kuota_tahunan = 0) hanya menyimpan carry-forward, tidak
    // membuat pegawai muncul di Rekap tahun tersebut.
    private function dompetTahunAsli(Builder $query, int $tahun): Builder
    {
        return $query->where('tahun', $tahun)
            ->where('kuota_tahunan', '>', 0);
    }
    // ================= END HELPER GEMBOK DOMPET ASLI =================

    // ================= HELPER BERSAMA: QUERY DASAR =================
    // Dipakai index(), indexAtasan(), exportExcel(), hitungKpi().
    //
    //   1) GEMBOK DOMPET: hanya pegawai yang punya saldo_cutis ASLI
    //      (kuota_tahunan > 0) di tahun terpilih.
    //   2) STATUS: non-aktif hanya tampil jika punya Cuti Tahunan
    //      disetujui / ditangguhkan di tahun itu (hindari "siluman"
    //      setelah Generate Saldo).
    //   3) Admin HR (role 5) dikecualikan.
    //   4) Eager-load saldo tahun + tahun-1, pengajuan (+ approval opsional),
    //      cuti ditangguhkan — mengurangi N+1.
    //   5) Urutan mengikuti hierarki jabatan lewat CASE WHEN di SQL.
    //   6) wilayahField/wilayahValue (opsional) dipakai jalur Atasan.
    //
    // $muatApprovalLogs = false untuk KPI & Export (lebih ringan).
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
                    }
                    // Hanya pengajuan yang bersinggungan dengan tahun yang dilihat.
                    // Cuti lintas tahun (31 Des 2026 - 4 Jan 2027) tampil di 2026 dan 2027.
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

        // Non-aktif: hanya jika ada cuti disetujui/ditangguhkan di tahun itu (arsip)
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
     * Tentukan urutan level approval (1/2/3/4) yang WAJIB dilalui.
     * Diselaraskan dengan getApprovalRoute() di MonitoringCuti.vue.
     *
     *   - Staf (role_id 1) di Subbagian Tata Usaha -> [3, 4]
     *   - Ketua Tim Kerja (role_id 2)               -> [2, 3, 4]
     *   - Ketua Kelompok Substansi (role_id 3)       -> [3, 4]
     *   - Kasubag TU (role_id 4)                     -> [4]
     *   - Kepala Biro (role_id 6)                    -> [] (disetujui otomatis)
     *   - Staf (role_id 1) lainnya:
     *       - TANPA Tim Kerja ('-'/kosong) -> [2, 3, 4]
     *       - DENGAN Tim Kerja             -> [1, 3, 4]
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
            // Kepala Biro (L4): disetujui otomatis, tanpa rantai atasan
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

    /**
     * Cari nama pegawai yang menjabat di role_id tertentu.
     *   - L1 (role_id 2): dicari per tim_kerja pemohon
     *   - Role lain: diprioritaskan satu kelompok_substansi, fallback ke role_id manapun
     * Hanya pegawai yang MASIH AKTIF yang dipertimbangkan, supaya pegawai
     * yang sudah pensiun / keluar tidak tampil sebagai penyetuju.
     */
    private function getApproverName(int $roleId, ?string $kelompokSubstansi, ?string $timKerja = null): string
    {
        // ---- L1: scope per tim_kerja ----
        if ($roleId === 2) {
            $timKerjaBersih = trim((string) $timKerja);

            if ($timKerjaBersih === '' || $timKerjaBersih === '-') {
                return 'Belum ditentukan';
            }

            $key = '2-tim-' . $timKerjaBersih;

            if (isset($this->approverNameCache[$key])) {
                return $this->approverNameCache[$key];
            }

            $pegawaiL1 = $this->hanyaAktif(
                Pegawai::where('role_id', 2)->where('tim_kerja', $timKerjaBersih)
            )->first();

            return $this->approverNameCache[$key] = $pegawaiL1->nama ?? 'Belum ditentukan';
        }

        // ---- Role lain ----
        $key = $roleId . '-' . ($kelompokSubstansi ?? '');

        if (isset($this->approverNameCache[$key])) {
            return $this->approverNameCache[$key];
        }

        $pegawai = $this->hanyaAktif(
            Pegawai::where('role_id', $roleId)
                ->when($kelompokSubstansi, fn($q) => $q->where('kelompok_substansi', $kelompokSubstansi))
        )->first();

        if (!$pegawai) {
            $pegawai = $this->hanyaAktif(Pegawai::where('role_id', $roleId))->first();
        }

        return $this->approverNameCache[$key] = $pegawai->nama ?? 'Belum ditentukan';
    }

    /**
     * Bangun daftar LENGKAP level approval beserta status masing-masing:
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

            // 2) Sedang ditunggu sekarang
            if ($riwayat->status === "menunggu_l{$level}") {
                $chain[] = [
                    'level' => $level,
                    'label' => $label,
                    'nama' => $this->getApproverName($roleId, $pemohon->kelompok_substansi, $pemohon->tim_kerja),
                    'status' => 'menunggu',
                ];
                continue;
            }

            // 3) Belum pernah dilalui
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
    // URUTAN PRIORITAS TERPAKAI EFEKTIF (satu aturan dengan SaldoCuti::terpakaiEfektif):
    //   1) Ada koreksi BULANAN (Rekap Laporan) di tahun itu -> SaldoCuti::terpakaiEfektif()
    //   2) koreksi_saldo (tahunan, data lama) terisi -> dipakai apa adanya (termasuk 0)
    //   3) Selain itu -> ikut hitungan sistem (terpakai murni)
    //
    // PENTING: bedakan NULL dan 0. Jangan pakai empty(), '?:', atau '> 0'
    // untuk mengecek koreksi, karena 0 adalah koreksi yang SAH. Cukup cek '!== null'.

    /**
     * Peta koreksi bulanan satu pegawai & tahun: [bulan (1-12) => nilai].
     * Kosong = tidak ada koreksi bulanan. Satu query per tahun per request.
     */
    private function petaKoreksiBulanan(int $pegawaiId, int $tahun): array
    {
        if (!isset($this->cacheKoreksiBulanan[$tahun])) {
            $this->cacheKoreksiBulanan[$tahun] = KoreksiCutiBulanan::where('tahun', $tahun)
                ->get(['pegawai_id', 'bulan', 'nilai'])
                ->groupBy('pegawai_id')
                ->map(fn($baris) => $baris->pluck('nilai', 'bulan')->map(fn($v) => (int) $v)->all())
                ->all();
        }

        return $this->cacheKoreksiBulanan[$tahun][$pegawaiId] ?? [];
    }

    /**
     * Versi MEMORI: dari relasi pengajuanCuti yang sudah di-eager load.
     * Cuti lintas tahun dihitung PER TAHUN lewat SaldoCuti::hariCutiDiTahun(),
     * jadi hanya bagian cuti yang jatuh di $tahun yang ikut terhitung.
     */
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

    /**
     * Versi DATABASE: untuk updateSaldo / importKuota (satu pegawai).
     * Logika dipusatkan di model SaldoCuti supaya konsisten dengan modul lain.
     */
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
    // Dipanggil dari updateSaldo() dan importKuota() setelah data saldo
    // tersimpan, dan dari AdminController setelah koreksi bulanan disimpan
    // (karena itu PUBLIC). Mengirim notifikasi ke PEGAWAI yang saldonya diubah
    // Admin HR, memakai pola yang sama dengan MonitoringCutiController (model
    // Notifikasi: pegawai_id, judul, pesan, tautan, is_read).
    //
    // Notifikasi HANYA dikirim kalau ada nilai yang benar-benar berubah,
    // supaya pegawai tidak menerima notifikasi kosong ketika Admin HR
    // menekan Simpan tanpa mengubah apa pun.
    //
    // $lama & $baru berisi kunci: hak, carry, dua_tahun_lalu, koreksi, terpakai
    // ('koreksi' bernilai int atau null; null = ikut sistem. 'terpakai' adalah
    // cuti terpakai EFEKTIF yang dilihat pegawai, dan itulah yang dibandingkan).
    // Untuk koreksi bulanan, isi 'koreksi' dengan nilai non-null bila ada koreksi
    // yang aktif (dipakai hanya untuk pilihan kalimat di pesan).
    //
    // Perubahan Tgh (ditangguhkan) SENGAJA tidak ikut notifikasi: angkanya
    // belum dicatat di sini (Tgh tahun ini kini memotong saldo, tetapi angka
    // saldo di pesan sudah memakainya).
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

        // Bandingkan cuti terpakai EFEKTIF, bukan nilai mentah koreksi.
        // Kalau Admin HR hanya mengganti mode (mis. dari "ikut sistem" ke angka
        // manual yang nilainya sama dengan sistem), angka yang dilihat pegawai
        // tidak berubah, jadi tidak perlu notifikasi.
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

        // Tidak ada perubahan nyata -> jangan kirim notifikasi.
        if (empty($perubahan)) {
            return;
        }

        // Hitung sisa cuti akhir dengan rumus yang SAMA seperti hitungKuota()
        // dan dashboard pegawai, supaya angka di notifikasi = angka di kartu.
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
        $saldoAkhir = max(($baru['hak'] + $saldoBawaanEligible + $tghTotal) - $cutiTerpakai - $potonganTgh, 0);

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
    // Satu baris biasanya berasal dari satu pengajuan, jadi angka total tidak
    // bisa langsung ditimpa. Admin HR mengetik TOTAL yang diinginkan, lalu
    // method ini menyamakan jumlah baris:
    //   - Naik  : selisihnya ditambahkan ke baris MANUAL
    //             (pengajuan_asal_id = null; kolom ini harus nullable).
    //             Kolom alasan_penangguhan WAJIB diisi (tanpa default di DB),
    //             jadi diisi dengan ALASAN_TGH_MANUAL.
    //   - Turun : baris manual dikurangi dulu, baru baris dari pengajuan
    //             (dikurangi, atau dihapus kalau jadi 0).
    // Pengaruh Tgh ke saldo diatur oleh TGH_MASUK_TOTAL (Tgh N-1 masuk Total
    // Tersedia) dan TGH_TAHUN_INI_MENGURANGI_SISA (Tgh N mengurangi Sisa & Saldo Akhir).
    //
    // PUBLIC supaya AdminController::updateKoreksiLaporan() memakai jalur yang
    // SAMA saat Admin HR mengedit total Tgh dari Rekap Laporan.
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
                // [FIX 1364] Sebelumnya CutiDitangguhkan::create([...]) membuang
                // 'alasan_penangguhan' secara diam-diam karena kolom itu tidak ada
                // di $fillable model, sehingga INSERT berjalan tanpa kolom wajib.
                // forceFill() mengabaikan $fillable, jadi kolom pasti ikut tersimpan.
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
     * Menerima 'tahun' (dari Filter Tahun), Hak, Sisa N-1, Sisa N-2, serta
     * total Tgh (sisa_ditangguhkan_*) yang bersifat opsional.
     *
     * [TERPAKAI DIKUNCI] 'Cuti Terpakai' TIDAK bisa diubah dari sini. Angkanya
     * diedit per bulan di Rekap Laporan. Field lama 'pakai_sistem' dan
     * 'koreksi_saldo' kalau masih dikirim frontend lama DIABAIKAN, dan kolom
     * koreksi_* tidak disentuh.
     *
     * Setelah tersimpan, pegawai yang bersangkutan otomatis menerima
     * notifikasi berisi ringkasan perubahan (hanya jika ada yang berubah).
     */
    public function updateSaldo(Request $request, int $id)
    {
        abort_unless((int) $request->user()->role_id === 5, 403);

        $data = $request->validate([
            'hak_tahun_ini' => ['required', 'integer', 'min:0'],
            'carry_forward_normal' => ['required', 'integer', 'min:0'],
            'sisa_cuti_dua_tahun_lalu' => ['required', 'integer', 'min:0'],
            'tahun' => ['nullable', 'integer', 'between:2000,2100'],
            // Tgh. Opsional: hanya diproses kalau dikirim.
            'sisa_ditangguhkan_dua_tahun_lalu' => ['nullable', 'integer', 'between:0,365'],
            'sisa_ditangguhkan_tahun_lalu' => ['nullable', 'integer', 'between:0,365'],
            'sisa_ditangguhkan_tahun_ini' => ['nullable', 'integer', 'between:0,365'],
            // Alasan perubahan (dicatat di log; wajib bila WAJIB_ALASAN_KOREKSI aktif)
            'alasan' => ['nullable', 'string', 'max:500'],
        ]);

        $pegawai = Pegawai::findOrFail($id);
        $tahunIni = $this->resolveTahun($request);
        $tahunLalu = $tahunIni - 1;

        // Ambil snapshot nilai LAMA sebelum di-update, untuk mendeteksi
        // apa saja yang berubah (dipakai membuat isi notifikasi).
        $saldoTahunIniLama = SaldoCuti::where('pegawai_id', $pegawai->id)
            ->where('tahun', $tahunIni)
            ->first();

        $saldoTahunLalu = SaldoCuti::where('pegawai_id', $pegawai->id)
            ->where('tahun', $tahunLalu)
            ->first();

        // Terpakai EFEKTIF (ikut koreksi bulanan / koreksi tahunan lama). Tidak
        // diubah oleh method ini, jadi nilai lama = nilai baru.
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

        // ---- Deteksi perubahan (dasar alasan wajib & log) ----
        // Dihitung SEBELUM ada yang ditulis ke database. Tgh dibandingkan dengan
        // total tersimpan. 'terpakai' tidak dicatat di sini karena tidak diubah
        // dari halaman ini.
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

        $alasan = trim((string) ($data['alasan'] ?? ''));

        if (self::WAJIB_ALASAN_KOREKSI && !empty($daftarPerubahan) && $alasan === '') {
            return back()->withErrors([
                'alasan' => 'Isi alasan perubahan (mis. "sesuai buku cuti" atau "cuti belum diajukan di sistem").',
            ]);
        }

        // koreksi_saldo & kolom koreksi_* SENGAJA tidak ada di daftar ini.
        SaldoCuti::updateOrCreate(
            ['pegawai_id' => $pegawai->id, 'tahun' => $tahunIni],
            [
                'kuota_tahunan' => $data['hak_tahun_ini'],
                'carry_forward_normal' => $data['carry_forward_normal'],
                'sisa' => max(0, $data['hak_tahun_ini'] - $cutiTerpakai),
            ]
        );

        // [FIX] Baris tahun lalu yang BELUM ada dibuat sebagai baris bayangan
        // (kuota_tahunan = 0), bukan 12. Kalau 12, baris itu dianggap "dompet
        // asli" oleh dompetTahunAsli() dan tahun tersebut mendadak muncul di Rekap.
        SaldoCuti::updateOrCreate(
            ['pegawai_id' => $pegawai->id, 'tahun' => $tahunLalu],
            [
                'kuota_tahunan' => $saldoTahunLalu->kuota_tahunan ?? 0,
                'carry_forward_normal' => $data['sisa_cuti_dua_tahun_lalu'],
                'sisa' => $saldoTahunLalu->sisa ?? 0,
            ]
        );

        // Tgh: hanya field yang benar-benar dikirim yang diproses.
        // Nilai 0 SAH (artinya "tidak ada hari ditangguhkan").
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

        // Log perubahan: satu baris per angka yang berubah.
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

        // [AUTO-SYNC] Saldo tahun ini berubah -> bawaan tahun depan ikut dihitung ulang.
        SaldoCuti::sinkronkanBawaan((int) $pegawai->id, $tahunIni);

        // Kirim notifikasi ke pegawai (hanya jika ada nilai yang berubah).
        $this->kirimNotifikasiPerubahanSaldo($pegawai, $tahunIni, $lama, $baru, $cutiTerpakai);

        return back()->with('success', 'Saldo cuti pegawai berhasil diperbarui.');
    }

    // ================= IMPOR KUOTA CUTI DARI EXCEL =================
    // Membaca template "Saldo Tahunan". HANYA angka input yang disimpan:
    //   Sisa {tahun-2} -> carry_forward_normal tahun-1
    //   Sisa {tahun-1} -> carry_forward_normal tahun berjalan
    //   Hak {tahun}    -> kuota_tahunan
    // Kolom hasil hitungan diabaikan; koreksi_saldo & koreksi bulanan TIDAK disentuh.
    // Sel kosong = nilai lama dipertahankan.
    // Pencocokan memakai NIP seluruh pegawai non-Admin HR (termasuk yang sudah
    // non-aktif), supaya saldo arsip tahun-tahun lalu tetap bisa dikoreksi.

    /**
     * [FIX] Normalisasi NIP untuk pencocokan file <-> database.
     *
     * Di template asli, kolom NIP berupa RUMUS ke sheet/file lain. Saat nilai
     * tersimpan (teks "197908142003122002") dibaca, PhpSpreadsheet mengubah teks
     * berbentuk angka menjadi float. NIP 18 digit tidak muat di float, sehingga
     * dua digit terakhir bergeser (…002 -> …016) dan tidak cocok dengan database.
     *
     * Solusinya: KEDUA sisi (file dan database) dilewatkan ke konversi float yang
     * sama, sehingga pembulatannya identik dan tetap cocok. Dua NIP berbeda baru
     * bisa bertabrakan kalau selisihnya < ~32, yang praktis tidak mungkin karena
     * digit awal (tanggal lahir/TMT) sudah berbeda.
     */
    private function normalisasiNip(mixed $nilai): string
    {
        $digit = preg_replace('/\D+/', '', (string) $nilai);

        return $digit === '' ? '' : sprintf('%.0f', (float) $digit);
    }

    /**
     * Baca nilai sebuah sel. Untuk rumus, pakai hasil hitung yang tersimpan di
     * file (sama dengan yang terlihat di Excel); kalau tidak ada, baru dihitung.
     * Di template asli, kolom NIP, Nama, dan Sisa berupa rumus ke sheet
     * "Master Pegawai", jadi getValue() saja akan mengembalikan teks rumusnya.
     */
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

    /**
     * Cari baris header (yang berisi "NIP") dan petakan kolom berdasarkan teks
     * header, bukan huruf kolom. Mengembalikan array peta kolom, atau string
     * pesan error.
     */
    private function petakanKolomImpor(Worksheet $sheet, int $tahunIni)
    {
        $tahunLalu = $tahunIni - 1;
        $tahunDuaLalu = $tahunIni - 2;
        $kolomTerakhir = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        $barisMaks = min(10, $sheet->getHighestRow());

        for ($baris = 1; $baris <= $barisMaks; $baris++) {
            $peta = ['nip' => null, 'hak' => [], 'sisa' => []];

            for ($i = 1; $i <= $kolomTerakhir; $i++) {
                $kolom = Coordinate::stringFromColumnIndex($i);
                $teks = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $sheet->getCell($kolom . $baris)->getValue())));

                if ($teks === 'nip') {
                    $peta['nip'] = $kolom;
                } elseif (preg_match('/^hak (\d{4})$/', $teks, $m)) {
                    $peta['hak'][(int) $m[1]] = $kolom;
                } elseif (preg_match('/^sisa (\d{4})$/', $teks, $m)) {
                    $peta['sisa'][(int) $m[1]] = $kolom;
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
            ];
        }

        return 'Kolom "NIP" tidak ditemukan. Pastikan file memakai template Rekap Cuti (sheet "Saldo Tahunan").';
    }

    /**
     * Baca satu sel angka (hasil rumus ikut dihitung).
     * null = kosong, false = tidak valid, int = angka bulat >= 0.
     */
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

        // Kunci pencocokan: NIP yang sudah dinormalisasi (lihat normalisasiNip()),
        // dipakai di sisi file maupun database supaya pembulatan float identik.
        $pegawaiByNip = Pegawai::where('role_id', '!=', 5)
            ->get(['id', 'nama', 'nip'])
            ->keyBy(fn($p) => $this->normalisasiNip($p->nip));

        $diperbarui = 0;
        $tanpaPerubahan = 0;
        $dilewati = [];

        DB::transaction(function () use (
            $sheet,
            $peta,
            $pegawaiByNip,
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

                // Baris kosong dan baris catatan (mengandung huruf) dilewati diam-diam.
                if ($nipMentah === '' || preg_match('/\p{L}/u', $nipMentah)) {
                    continue;
                }

                // Sisakan angka saja, lalu normalisasi (sama dengan sisi database).
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

                if (
                    $hak === false || $sisa1 === false || $sisa2 === false
                    || ($sisa1 !== null && $sisa1 > 12) || ($sisa2 !== null && $sisa2 > 12)
                ) {
                    $dilewati[] = "baris {$baris} ({$pegawai->nama}): angka tidak valid";
                    continue;
                }

                if ($hak === null && $sisa1 === null && $sisa2 === null) {
                    continue;
                }

                $saldoIni = SaldoCuti::where('pegawai_id', $pegawai->id)->where('tahun', $tahunIni)->first();
                $saldoLalu = SaldoCuti::where('pegawai_id', $pegawai->id)->where('tahun', $tahunLalu)->first();

                $koreksiLama = ($saldoIni && $saldoIni->koreksi_saldo !== null)
                    ? (int) $saldoIni->koreksi_saldo
                    : null;

                // Terpakai EFEKTIF (ikut koreksi bulanan bila ada), satu rumus
                // dengan Rekap Laporan.
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

                if ($baru == $lama) {
                    $tanpaPerubahan++;
                    continue;
                }

                // koreksi_saldo sengaja TIDAK ada di daftar kolom ini.
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

                // [AUTO-SYNC] Bawaan tahun depan ikut dihitung ulang.
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

    // Hitung field-field kuota cuti untuk satu pegawai.
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

        // NULL = ikut sistem, angka (termasuk 0) = koreksi tahunan lama Admin HR.
        $koreksiRaw = $saldo?->koreksi_saldo;
        $koreksiSaldo = $koreksiRaw === null ? null : max(0, (int) $koreksiRaw);

        // Saldo tahun lalu: dari relasi jika sudah di-load, fallback query
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

        $cutiTerpakaiMurni = $this->hitungCutiTerpakaiMurniDariRelasi($pegawai, $tahunIni);

        // [KOREKSI BULANAN] Bila ada, Terpakai mengikuti jumlah bulanan hasil edit
        // di Rekap Laporan (SaldoCuti::terpakaiEfektif). Bila tidak, aturan lama.
        $petaKoreksiBulanan = $this->petaKoreksiBulanan((int) $pegawai->id, $tahunIni);
        $adaKoreksiBulanan = !empty($petaKoreksiBulanan);

        $cutiTerpakai = $adaKoreksiBulanan
            ? SaldoCuti::terpakaiEfektif((int) $pegawai->id, $tahunIni, $saldo)
            : $this->hitungCutiTerpakaiEfektif($cutiTerpakaiMurni, $koreksiSaldo);

        // (Sisa Kuota Tahun Ini dihitung di bawah, setelah Tgh tahun ini tersedia)
        $sisaDuaTahunLalu = (int) ($saldoTahunLalu?->carry_forward_normal ?? 0);
        $saldoBawaanEligible = SaldoCuti::hitungBawaan($sisaDuaTahunLalu, $carryForward, $kuotaTahunan);
        // Sisa ditangguhkan: dari relasi eager-load (tanpa N+1), fallback query.
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

        // Ditangguhkan pada tahun yang sedang dilihat (hari Desember dari cuti
        // lintas tahun tercatat di tahun asalnya masing-masing).
        $sisaDitangguhkanTahunIni = $pegawai->relationLoaded('cutiDitangguhkan')
            ? (int) $pegawai->cutiDitangguhkan
                ->where('tahun_asal', $tahunIni)
                ->sum('jumlah_hari')
            : (int) $pegawai->cutiDitangguhkan()
                ->where('tahun_asal', $tahunIni)
                ->sum('jumlah_hari');

        // Total Tersedia = Hak + Bawaan Eligible + Tgh tahun sebelumnya (tahun - 1)
        // bila TGH_MASUK_TOTAL aktif. Dihitung SETELAH nilai Tgh di atas tersedia.
        $totalTgh = self::TGH_MASUK_TOTAL
            ? $sisaDitangguhkanTahunLalu
            : 0;
        $totalCutiTersedia = $kuotaTahunan + $saldoBawaanEligible + $totalTgh;
        // Tgh TAHUN INI memotong Sisa tahun ini & Saldo Akhir.
        // Tgh N-2 hanya catatan Admin HR.
        $potonganTghTahunIni = self::TGH_TAHUN_INI_MENGURANGI_SISA
            ? $sisaDitangguhkanTahunIni
            : 0;
        $sisaKuotaTahunIni = max($kuotaTahunan - $cutiTerpakai - $potonganTghTahunIni, 0);
        $saldoAkhir = max($totalCutiTersedia - $cutiTerpakai - $potonganTghTahunIni, 0);

        // Info audit koreksi tahunan (data lama). 'koreksi_basi' = ada koreksi,
        // tetapi angka sistem sekarang berbeda dari angka sistem saat koreksi dibuat
        // (mis. ada cuti baru disetujui/dibatalkan sesudahnya), jadi koreksi perlu
        // dicek ulang. Tidak berlaku bila koreksi bulanan sudah dipakai.
        $sistemSaatKoreksi = $saldo?->koreksi_terpakai_sistem;
        $koreksiBasi = !$adaKoreksiBulanan
            && $koreksiSaldo !== null
            && $sistemSaatKoreksi !== null
            && (int) $sistemSaatKoreksi !== $cutiTerpakaiMurni;

        // Riwayat perubahan saldo tahun ini (dilewati untuk KPI/Export).
        $logPerubahan = [];
        if ($muatApprovalChain) {
            $logPerubahan = LogPerubahanSaldo::with('pengubah:id,nama')
                ->where('pegawai_id', $pegawai->id)
                ->where('tahun', $tahunIni)
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn($log) => [
                    'id' => $log->id,
                    'kolom' => $log->kolom,
                    'nilai_lama' => $log->nilai_lama,
                    'nilai_baru' => $log->nilai_baru,
                    'alasan' => $log->alasan,
                    'oleh' => $log->pengubah->nama ?? '-',
                    'waktu' => $log->created_at?->format('d-m-Y H:i'),
                ])
                ->all();
        }

        $previewCarryOver = SaldoCuti::hitungPreviewCarryOver($saldoAkhir);

        // Tempel approval_chain (dilewati untuk KPI/Export agar lebih cepat)
        if ($muatApprovalChain && $pegawai->relationLoaded('pengajuanCuti')) {
            $pegawai->pengajuanCuti->each(function ($riwayat) use ($pegawai, $tahunIni) {
                $riwayat->approval_chain = $this->buildApprovalChain($riwayat, $pegawai);

                // Hari yang jatuh di tahun aktif (untuk cuti lintas tahun),
                // dihitung dengan fungsi yang sama dengan Terpakai.
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

            // Koreksi
            // [TERPAKAI DIKUNCI] 'terpakai_dikunci' selalu true: Vue menampilkan
            // Terpakai sebagai teks (tanpa input) dan menunjuk Admin HR ke Rekap
            // Laporan untuk mengubahnya.
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
    //   - L1 (role_id 2): 'tim_kerja'
    //   - L2 (role_id 3): 'kelompok_substansi'
    //   - L3, L4 (role_id 4, 6): tidak dibatasi
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
    // Dihitung dari SELURUH hasil filter (bukan hanya 1 halaman).
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
     * Rekap Kuota Detail (Admin HR) — melihat SEMUA pegawai.
     * Hanya Admin HR (role_id 5); atasan yang membuka URL ini
     * langsung akan mendapat 403.
     */
    public function index(Request $request)
    {
        abort_unless((int) $request->user()->role_id === 5, 403);

        // Tombol Generate dikunci kalau semua pegawai aktif sudah punya dompet tahun depan.
        // Pegawai baru yang belum punya dompet membuat tombol aktif lagi.
        $extraProps = [
            'sudahGenerateTahunDepan' => SaldoCuti::jumlahPegawaiBelumPunyaDompet((int) date('Y') + 1) === 0,
        ];

        return $this->renderRekap($request, 'Admin/RekapKuotaDetail', null, null, true, $extraProps);
    }

    /**
     * Versi Atasan (L1-L4), read-only, tanpa Impor Kuota & tanpa Export.
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
    // Kolom (tanpa Jabatan, supaya tidak terlalu lebar):
    //   A No | B NIP | C Nama | D Sisa N-2 | E Tgh N-2 | F Sisa N-1 | G Tgh N-1
    //   H Sisa N | I Tgh N | J Bawaan Eligible | K Hak | L Total Tersedia
    //   M Terpakai | N Saldo Akhir | O Status Saldo
    //
    // Kolom hasil hitungan berisi RUMUS Excel:
    //   M = Terpakai (digunakan) + Tgh tahun ini (bila TGH_TAHUN_INI_MENGURANGI_SISA),
    //       sama dengan angka Terpakai di halaman web & dashboard.
    //       Karena M SUDAH memuat Tgh tahun ini, rumus H dan N TIDAK lagi
    //       mengurangi kolom I (kalau dikurangi lagi, Tgh terhitung dua kali).
    //   H = MAX(K - M, 0)
    //   J = terjemahan SaldoCuti::hitungBawaan() (dari D, F, dan K)
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
            // 'potongan_tgh_tahun_ini' sudah bernilai 0 bila
            // TGH_TAHUN_INI_MENGURANGI_SISA = false, jadi aman di kedua mode.
            $terpakaiExcel = (int) $item['cuti_terpakai'] + (int) $item['potongan_tgh_tahun_ini'];

            $sheet->setCellValue("A{$r}", $i + 1);
            $sheet->setCellValueExplicit("B{$r}", (string) $item['nip'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            // Kolom C (Nama) ditulis lewat tulisTeksAman() SETELAH styling (lihat di bawah).
            $sheet->setCellValue("D{$r}", $item['sisa_cuti_dua_tahun_lalu']);
            $sheet->setCellValue("E{$r}", $item['sisa_ditangguhkan_dua_tahun_lalu']);
            $sheet->setCellValue("F{$r}", $item['carry_forward_normal']);
            $sheet->setCellValue("G{$r}", $item['sisa_ditangguhkan_tahun_lalu']);
            // Sisa tahun ini = Hak - Terpakai (Terpakai sudah memuat Tgh tahun ini)
            $sheet->setCellValue("H{$r}", "=MAX(K{$r}-M{$r},0)");
            $sheet->setCellValue("I{$r}", $item['sisa_ditangguhkan_tahun_ini']);

            // Saldo Bawaan = terjemahan SaldoCuti::hitungBawaan():
            //   Sisa N-2 = 12 DAN Sisa N-1 = 12 -> 12, selain itu MIN(6, Sisa N-1).
            //   Batas 24 hanya dipasang bila SaldoCuti::TERAPKAN_BATAS_ATAS_TOTAL_HAK = true.
            $rumusBawaan = "IF(AND(D{$r}=12,F{$r}=12),12,MIN(6,MAX(0,F{$r})))";
            $sheet->setCellValue("J{$r}", '=' . (SaldoCuti::TERAPKAN_BATAS_ATAS_TOTAL_HAK
                ? 'MAX(0,MIN(' . $rumusBawaan . ',' . SaldoCuti::BATAS_ATAS_TOTAL_HAK . "-K{$r}))"
                : $rumusBawaan));

            $sheet->setCellValue("K{$r}", $item['kuota_tahunan']);
            $sheet->setCellValue("L{$r}", self::TGH_MASUK_TOTAL
                ? "=K{$r}+J{$r}+G{$r}"                                              // Hak + Bawaan + Tgh tahun lalu
                : "=K{$r}+J{$r}");                                                  // Hak + Bawaan
            $sheet->setCellValue("M{$r}", $terpakaiExcel);                          // Terpakai (sudah termasuk Tgh tahun ini)
            $sheet->setCellValue("N{$r}", "=MAX(L{$r}-M{$r},0)");                   // Saldo Akhir = Total - Terpakai

            $statusSaldo = $item['saldo_akhir'] > 0 ? 'TERSISA' : 'HABIS';
            $sheet->setCellValue("O{$r}", "=IF(N{$r}>0,\"TERSISA\",\"HABIS\")");    // Status mengikuti Saldo Akhir

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

            // Sisa Ditangguhkan (oranye jika > 0)
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

        // ---- Rumus ringkasan + format kondisional (hanya jika ada data) ----
        $dataEndRow = $r - 1;
        if ($dataEndRow >= $dataStartRow) {
            // Warna Status Saldo mengikuti hasil rumus, jadi ikut berubah kalau angka diedit.
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
    // setCellValue() biasa memakai binder bawaan PhpSpreadsheet yang mengubah string
    // berawalan "=" menjadi rumus aktif. setCellValueExplicit(TYPE_STRING) memaksa
    // nilai disimpan sebagai teks, dan quotePrefix menjadi lapis kedua supaya sel
    // tetap dianggap teks oleh Excel/WPS (juga saat pengguna menekan F2 lalu Enter).
    // WAJIB dipanggil SETELAH applyFromArray() pada sel yang sama.
    private function tulisTeksAman(Worksheet $sheet, string $koordinat, ?string $nilai): void
    {
        $nilai = (string) $nilai;

        $sheet->setCellValueExplicit($koordinat, $nilai, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

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
     * Export Excel (Admin HR) — SEMUA pegawai, ikut Filter Tahun.
     * Hanya Admin HR (role_id 5). Versi Atasan (exportExcelAtasan)
     * sudah dihapus karena atasan tidak boleh mengekspor.
     */
    public function exportExcel(Request $request)
    {
        abort_unless((int) $request->user()->role_id === 5, 403);

        $spreadsheet = $this->buildRekapSpreadsheet($request);

        return $this->streamRekapSpreadsheet($spreadsheet, $request);
    }
}
