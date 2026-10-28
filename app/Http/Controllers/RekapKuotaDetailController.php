<?php

namespace App\Http\Controllers;

use App\Models\Notifikasi;
use App\Models\Pegawai;
use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class RekapKuotaDetailController extends Controller
{
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
    //   4) Eager-load saldo tahun + tahun-1 + tahun-2, pengajuan
    //      (+ approval opsional), cuti ditangguhkan — mengurangi N+1.
    //      Saldo tahun-2 dimuat karena override "Ditangguhkan N-2"
    //      (koreksi_ditangguhkan) disimpan di baris tahun-2.
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
                    $q->whereIn('tahun', [$tahunIni, $tahunIni - 1, $tahunIni - 2]);
                },
                'pengajuanCuti' => function ($q) use ($muatApprovalLogs) {
                    if ($muatApprovalLogs) {
                        $q->with('approvalLogs.approver:id,nama,jabatan');
                    }
                    $q->latest();
                },
                'cutiDitangguhkan' => function ($q) use ($tahunIni) {
                    $q->whereIn('tahun_asal', [$tahunIni - 2, $tahunIni - 1]);
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
    // KONSEP KOREKSI SALDO (INPUT LANGSUNG / OVERRIDE):
    //   - koreksi_saldo = NULL   -> Terpakai Efektif = Terpakai Murni (ikut sistem)
    //   - koreksi_saldo = 0..365 -> Terpakai Efektif = koreksi_saldo (angka Admin HR
    //                               dipakai apa adanya, TERMASUK 0)
    //
    // PENTING: bedakan NULL dan 0. Jangan pakai empty(), '?:', atau '> 0'
    // untuk mengecek koreksi, karena 0 adalah koreksi yang SAH ("tidak ada
    // cuti yang benar-benar terpakai"). Cukup cek '!== null'.

    /**
     * Versi MEMORI: dari relasi pengajuanCuti yang sudah di-eager load.
     */
    private function hitungCutiTerpakaiMurniDariRelasi(Pegawai $pegawai, int $tahun): int
    {
        if (!$pegawai->relationLoaded('pengajuanCuti')) {
            return $this->queryCutiTerpakaiMurni((int) $pegawai->id, $tahun);
        }

        return (int) $pegawai->pengajuanCuti
            ->where('jenis_cuti', 'Cuti Tahunan')
            ->filter(function ($cuti) use ($tahun) {
                if (!$cuti->tanggal_mulai || Carbon::parse($cuti->tanggal_mulai)->year !== $tahun) {
                    return false;
                }

                return $cuti->status === 'disetujui';
            })
            ->sum('jumlah_hari');
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
    // tersimpan. Mengirim notifikasi ke PEGAWAI yang saldonya diubah Admin HR,
    // memakai pola yang sama dengan MonitoringCutiController (model Notifikasi:
    // pegawai_id, judul, pesan, tautan, is_read).
    //
    // Notifikasi HANYA dikirim kalau ada nilai yang benar-benar berubah,
    // supaya pegawai tidak menerima notifikasi kosong ketika Admin HR
    // menekan Simpan tanpa mengubah apa pun.
    //
    // $lama & $baru berisi kunci: hak, carry, dua_tahun_lalu, koreksi, terpakai
    // ('koreksi' bernilai int atau null; null = ikut sistem. 'terpakai' adalah
    // cuti terpakai EFEKTIF yang dilihat pegawai, dan itulah yang dibandingkan).
    //
    // Perubahan angka "Ditangguhkan" (Tgh) ikut dibandingkan lewat kunci
    // OPSIONAL 'tgh_n1' dan 'tgh_n2' (angka yang DITAMPILKAN: override Admin HR
    // bila ada, kalau tidak hitungan sistem). Hanya updateSaldo() yang
    // mengisinya; importKuota() tidak menyentuh Tgh sehingga kuncinya tidak
    // ada dan bagian ini dilewati. Tgh hanya informasi: pesan notifikasi
    // menyebutkan bahwa saldo cuti tidak berubah.
    private function kirimNotifikasiPerubahanSaldo(
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

        // Ditangguhkan (Tgh): hanya dibandingkan kalau kuncinya ada di KEDUA
        // array (dikirim updateSaldo(), tidak oleh importKuota()).
        foreach ([['tgh_n1', $tahunLalu], ['tgh_n2', $tahunDuaLalu]] as [$kunciTgh, $tahunAsalTgh]) {
            if (
                isset($lama[$kunciTgh], $baru[$kunciTgh])
                && $lama[$kunciTgh] !== $baru[$kunciTgh]
            ) {
                $perubahan[] = "Cuti ditangguhkan {$tahunAsalTgh} menjadi {$baru[$kunciTgh]} hari (hanya informasi, saldo tidak berubah)";
            }
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
        $saldoAkhir = max(($baru['hak'] + $saldoBawaanEligible) - $cutiTerpakai, 0);

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

    /**
     * Update saldo cuti perorangan (hanya Admin HR / role_id 5).
     * Menerima 'tahun' (dari Filter Tahun), 'pakai_sistem', dan 'koreksi_saldo'.
     *
     *   - pakai_sistem = true  -> koreksi_saldo disimpan NULL (ikut angka sistem)
     *   - pakai_sistem = false -> koreksi_saldo WAJIB diisi (boleh 0) dan dipakai
     *                             apa adanya sebagai total cuti terpakai
     *
     * Angka "Ditangguhkan" (Tgh) juga bisa diedit, dengan pola override yang sama:
     *   - pakai_sistem_tgh_n1 / ditangguhkan_n1 -> Tgh tahun (N-1)
     *   - pakai_sistem_tgh_n2 / ditangguhkan_n2 -> Tgh tahun (N-2)
     *   Dicentang -> koreksi_ditangguhkan = NULL (ikut hitungan sistem).
     *   Tidak dicentang -> angka dipakai apa adanya (termasuk 0).
     *   Disimpan di baris saldo_cutis tahun asalnya. Hanya informasi: TIDAK
     *   memengaruhi Terpakai, Bawaan, maupun Saldo Akhir.
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
            'pakai_sistem' => ['required', 'boolean'],
            'koreksi_saldo' => [
                // 'bail': berhenti di aturan pertama yang gagal, supaya aturan
                // kustom di bawah hanya jalan kalau angkanya sudah valid.
                'bail',
                Rule::requiredIf(fn() => !$request->boolean('pakai_sistem')),
                'nullable',
                'integer',
                'between:0,365',
                // Cuti terpakai tidak boleh melebihi TOTAL HAK TERSEDIA pegawai
                // (Hak tahun ini + Saldo Bawaan Eligible). Batas dihitung dari
                // isian form yang sedang dikirim, dengan rumus yang SAMA seperti
                // hitungKuota(), notifikasi, dan frontend (SaldoCuti::hitungBawaan).
                function ($attribute, $value, $fail) use ($request) {
                    // Dilewati kalau Admin HR memilih "Gunakan data dari sistem"
                    // (angka sistem tidak diketik manual) atau kotak kosong.
                    if ($request->boolean('pakai_sistem') || $value === null) {
                        return;
                    }

                    $hak = (int) $request->input('hak_tahun_ini', 0);
                    $carry = (int) $request->input('carry_forward_normal', 0);
                    $duaTahunLalu = (int) $request->input('sisa_cuti_dua_tahun_lalu', 0);

                    $bawaanEligible = SaldoCuti::hitungBawaan($duaTahunLalu, $carry, $hak);
                    $maksimal = $hak + $bawaanEligible;

                    if ((int) $value > $maksimal) {
                        $fail("Gagal menyimpan. Cuti terpakai ({$value} hari) melebihi total hak yang tersedia ({$maksimal} hari).");
                    }
                },
            ],

            // ---- Override "Ditangguhkan" (Tgh) N-1 & N-2 ----
            // 'sometimes': kalau kunci tidak dikirim, nilai tersimpan tidak diubah.
            'pakai_sistem_tgh_n1' => ['sometimes', 'boolean'],
            'ditangguhkan_n1' => [
                'bail',
                Rule::requiredIf(fn() => $request->has('pakai_sistem_tgh_n1') && !$request->boolean('pakai_sistem_tgh_n1')),
                'nullable',
                'integer',
                'between:0,365',
            ],
            'pakai_sistem_tgh_n2' => ['sometimes', 'boolean'],
            'ditangguhkan_n2' => [
                'bail',
                Rule::requiredIf(fn() => $request->has('pakai_sistem_tgh_n2') && !$request->boolean('pakai_sistem_tgh_n2')),
                'nullable',
                'integer',
                'between:0,365',
            ],
        ], [
            'koreksi_saldo.required' => 'Isi angka cuti terpakai (boleh 0), atau centang "Gunakan data dari sistem".',
            'ditangguhkan_n1.required' => 'Isi angka ditangguhkan (boleh 0), atau centang "Ikut data sistem".',
            'ditangguhkan_n2.required' => 'Isi angka ditangguhkan (boleh 0), atau centang "Ikut data sistem".',
        ]);

        $pegawai = Pegawai::findOrFail($id);
        $tahunIni = $this->resolveTahun($request);
        $tahunLalu = $tahunIni - 1;
        $tahunDuaLaluInt = $tahunIni - 2;

        // NULL = ikut sistem, angka (termasuk 0) = koreksi manual Admin HR.
        $koreksiSaldo = $request->boolean('pakai_sistem')
            ? null
            : (int) $data['koreksi_saldo'];

        // Ambil snapshot nilai LAMA sebelum di-update, untuk mendeteksi
        // apa saja yang berubah (dipakai membuat isi notifikasi).
        $saldoTahunIniLama = SaldoCuti::where('pegawai_id', $pegawai->id)
            ->where('tahun', $tahunIni)
            ->first();

        $saldoTahunLalu = SaldoCuti::where('pegawai_id', $pegawai->id)
            ->where('tahun', $tahunLalu)
            ->first();

        $saldoDuaTahunLalu = SaldoCuti::where('pegawai_id', $pegawai->id)
            ->where('tahun', $tahunDuaLaluInt)
            ->first();

        // Override Tgh: NULL = ikut sistem, angka = dipakai apa adanya.
        // Kalau kunci form tidak dikirim, pertahankan nilai yang sudah tersimpan.
        $tghN1 = $request->has('pakai_sistem_tgh_n1')
            ? ($request->boolean('pakai_sistem_tgh_n1') ? null : (int) $data['ditangguhkan_n1'])
            : ($saldoTahunLalu?->koreksi_ditangguhkan);
        $tghN2 = $request->has('pakai_sistem_tgh_n2')
            ? ($request->boolean('pakai_sistem_tgh_n2') ? null : (int) $data['ditangguhkan_n2'])
            : ($saldoDuaTahunLalu?->koreksi_ditangguhkan);

        // Angka Tgh yang DILIHAT pegawai sebelum & sesudah simpan (override Admin
        // HR bila ada, kalau tidak hitungan sistem). Dipakai hanya untuk
        // mendeteksi perubahan pada notifikasi. $tghLama* dihitung SEKARANG,
        // sebelum baris saldo_cutis diperbarui di bawah.
        $tghSistemN1 = (int) $pegawai->cutiDitangguhkan()->where('tahun_asal', $tahunLalu)->sum('jumlah_hari');
        $tghSistemN2 = (int) $pegawai->cutiDitangguhkan()->where('tahun_asal', $tahunDuaLaluInt)->sum('jumlah_hari');
        $tghLamaN1 = $saldoTahunLalu?->koreksi_ditangguhkan !== null
            ? (int) $saldoTahunLalu->koreksi_ditangguhkan
            : $tghSistemN1;
        $tghLamaN2 = $saldoDuaTahunLalu?->koreksi_ditangguhkan !== null
            ? (int) $saldoDuaTahunLalu->koreksi_ditangguhkan
            : $tghSistemN2;

        $cutiTerpakaiMurni = $this->hitungCutiTerpakaiMurni($pegawai->id, $tahunIni);

        $koreksiLama = ($saldoTahunIniLama && $saldoTahunIniLama->koreksi_saldo !== null)
            ? (int) $saldoTahunIniLama->koreksi_saldo
            : null;

        // 'terpakai' = cuti terpakai EFEKTIF, yaitu angka yang benar-benar
        // dilihat pegawai di dashboard. Notifikasi hanya dikirim kalau angka ini
        // berubah, bukan sekadar karena kolom koreksi berganti dari NULL ke angka
        // yang nilainya sama dengan hitungan sistem.
        $lama = [
            'hak'            => (int) ($saldoTahunIniLama->kuota_tahunan ?? 12),
            'carry'          => (int) ($saldoTahunIniLama->carry_forward_normal ?? 0),
            'dua_tahun_lalu' => (int) ($saldoTahunLalu->carry_forward_normal ?? 0),
            'koreksi'        => $koreksiLama,
            'terpakai'       => $this->hitungCutiTerpakaiEfektif($cutiTerpakaiMurni, $koreksiLama),
            'tgh_n1'         => $tghLamaN1,
            'tgh_n2'         => $tghLamaN2,
        ];

        $cutiTerpakai = $this->hitungCutiTerpakaiEfektif($cutiTerpakaiMurni, $koreksiSaldo);

        $baru = [
            'hak'            => (int) $data['hak_tahun_ini'],
            'carry'          => (int) $data['carry_forward_normal'],
            'dua_tahun_lalu' => (int) $data['sisa_cuti_dua_tahun_lalu'],
            'koreksi'        => $koreksiSaldo,
            'terpakai'       => $cutiTerpakai,
            'tgh_n1'         => $tghN1 ?? $tghSistemN1,
            'tgh_n2'         => $tghN2 ?? $tghSistemN2,
        ];

        SaldoCuti::updateOrCreate(
            ['pegawai_id' => $pegawai->id, 'tahun' => $tahunIni],
            [
                'kuota_tahunan' => $data['hak_tahun_ini'],
                'carry_forward_normal' => $data['carry_forward_normal'],
                'koreksi_saldo' => $koreksiSaldo,
                'sisa' => max(0, $data['hak_tahun_ini'] - $cutiTerpakai),
            ]
        );

        // [FIX] Baris tahun lalu yang BELUM ada dibuat sebagai baris bayangan
        // (kuota_tahunan = 0), bukan 12. Kalau 12, baris itu dianggap "dompet
        // asli" oleh dompetTahunAsli() dan tahun tersebut mendadak muncul di Rekap.
        // Baris ini juga menyimpan override "Ditangguhkan N-1".
        SaldoCuti::updateOrCreate(
            ['pegawai_id' => $pegawai->id, 'tahun' => $tahunLalu],
            [
                'kuota_tahunan' => $saldoTahunLalu->kuota_tahunan ?? 0,
                'carry_forward_normal' => $data['sisa_cuti_dua_tahun_lalu'],
                'sisa' => $saldoTahunLalu->sisa ?? 0,
                'koreksi_ditangguhkan' => $tghN1,
            ]
        );

        // Override "Ditangguhkan N-2" disimpan di baris saldo_cutis tahun (N-2).
        //   - Baris sudah ada -> hanya kolom koreksi_ditangguhkan yang diubah,
        //     kolom lain (kuota, carry, sisa) tidak disentuh.
        //   - Baris belum ada -> dibuat HANYA kalau ada angka manual, sebagai
        //     baris bayangan (kuota_tahunan = 0, BUKAN default 12 milik database)
        //     supaya tahun tersebut tidak mendadak muncul di Rekap.
        if ($saldoDuaTahunLalu) {
            $saldoDuaTahunLalu->update(['koreksi_ditangguhkan' => $tghN2]);
        } elseif ($tghN2 !== null) {
            SaldoCuti::create([
                'pegawai_id' => $pegawai->id,
                'tahun' => $tahunDuaLaluInt,
                'kuota_tahunan' => 0,
                'carry_forward_normal' => 0,
                'sisa' => 0,
                'koreksi_ditangguhkan' => $tghN2,
            ]);
        }

        // Kirim notifikasi ke pegawai (hanya jika ada nilai yang berubah).
        $this->kirimNotifikasiPerubahanSaldo($pegawai, $tahunIni, $lama, $baru, $cutiTerpakai);

        return back()->with('success', 'Saldo cuti pegawai berhasil diperbarui.');
    }

    // ================= IMPOR KUOTA CUTI DARI EXCEL =================
    // Membaca template "Saldo Tahunan". HANYA angka input yang disimpan:
    //   Sisa {tahun-2} -> carry_forward_normal tahun-1
    //   Sisa {tahun-1} -> carry_forward_normal tahun berjalan
    //   Hak {tahun}    -> kuota_tahunan
    // Kolom hasil hitungan diabaikan; koreksi_saldo TIDAK disentuh.
    // koreksi_ditangguhkan juga TIDAK disentuh oleh impor.
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
                $tahunFile = implode(', ', array_keys($peta['hak']));
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
                $terpakai = $this->hitungCutiTerpakaiEfektif(
                    $this->hitungCutiTerpakaiMurni($pegawai->id, $tahunIni),
                    $koreksiLama
                );

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
                    // koreksi_ditangguhkan sengaja TIDAK ada di daftar kolom ini,
                    // jadi override Tgh yang sudah ada tidak hilang saat impor.
                    SaldoCuti::updateOrCreate(
                        ['pegawai_id' => $pegawai->id, 'tahun' => $tahunLalu],
                        [
                            'kuota_tahunan' => $saldoLalu->kuota_tahunan ?? 0,
                            'carry_forward_normal' => $baru['dua_tahun_lalu'],
                            'sisa' => $saldoLalu->sisa ?? 0,
                        ]
                    );
                }

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

        // NULL = ikut sistem, angka (termasuk 0) = koreksi manual Admin HR.
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
        $cutiTerpakai = $this->hitungCutiTerpakaiEfektif($cutiTerpakaiMurni, $koreksiSaldo);

        $sisaKuotaTahunIni = max($kuotaTahunan - $cutiTerpakai, 0);
        $sisaDuaTahunLalu = (int) ($saldoTahunLalu?->carry_forward_normal ?? 0);
        $saldoBawaanEligible = SaldoCuti::hitungBawaan($sisaDuaTahunLalu, $carryForward, $kuotaTahunan);
        $totalCutiTersedia = $kuotaTahunan + $saldoBawaanEligible;
        $saldoAkhir = max($totalCutiTersedia - $cutiTerpakai, 0);

        // Sisa ditangguhkan versi SISTEM: dari relasi eager-load (tanpa N+1),
        // fallback query.
        $tghSistemN2 = $pegawai->relationLoaded('cutiDitangguhkan')
            ? (int) $pegawai->cutiDitangguhkan
                ->where('tahun_asal', $tahunIni - 2)
                ->sum('jumlah_hari')
            : (int) $pegawai->cutiDitangguhkan()
                ->where('tahun_asal', $tahunIni - 2)
                ->sum('jumlah_hari');
        $tghSistemN1 = $pegawai->relationLoaded('cutiDitangguhkan')
            ? (int) $pegawai->cutiDitangguhkan
                ->where('tahun_asal', $tahunIni - 1)
                ->sum('jumlah_hari')
            : (int) $pegawai->cutiDitangguhkan()
                ->where('tahun_asal', $tahunIni - 1)
                ->sum('jumlah_hari');

        // Override Admin HR untuk Tgh. NULL = ikut sistem, angka (termasuk 0)
        // = dipakai apa adanya. Disimpan di baris saldo_cutis tahun asalnya:
        //   Tgh N-1 -> baris tahun (N-1) ($saldoTahunLalu)
        //   Tgh N-2 -> baris tahun (N-2)
        $saldoDuaTahunLaluRow = $pegawai->relationLoaded('saldoCuti')
            ? $pegawai->saldoCuti->firstWhere('tahun', $tahunIni - 2)
            : SaldoCuti::where('pegawai_id', $pegawai->id)->where('tahun', $tahunIni - 2)->first();

        $koreksiTghN1Raw = $saldoTahunLalu?->koreksi_ditangguhkan;
        $koreksiTghN2Raw = $saldoDuaTahunLaluRow?->koreksi_ditangguhkan;
        $koreksiTghN1 = $koreksiTghN1Raw === null ? null : max(0, (int) $koreksiTghN1Raw);
        $koreksiTghN2 = $koreksiTghN2Raw === null ? null : max(0, (int) $koreksiTghN2Raw);

        $sisaDitangguhkanDuaTahunLalu = $koreksiTghN2 ?? $tghSistemN2;
        $sisaDitangguhkanTahunLalu = $koreksiTghN1 ?? $tghSistemN1;

        $previewCarryOver = SaldoCuti::hitungPreviewCarryOver($saldoAkhir);

        // Tempel approval_chain (dilewati untuk KPI/Export agar lebih cepat)
        if ($muatApprovalChain && $pegawai->relationLoaded('pengajuanCuti')) {
            $pegawai->pengajuanCuti->each(function ($riwayat) use ($pegawai) {
                $riwayat->approval_chain = $this->buildApprovalChain($riwayat, $pegawai);
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
            'cuti_terpakai' => $cutiTerpakai,
            'sisa_cuti' => $saldoAkhir,
            'saldo_akhir' => $saldoAkhir,
            'total_cuti_tersedia' => $totalCutiTersedia,
            'preview_carry_over' => $previewCarryOver,

            // Sisa ditangguhkan (angka yang DITAMPILKAN = override Admin HR
            // bila ada, kalau tidak angka sistem)
            'sisa_ditangguhkan_dua_tahun_lalu' => $sisaDitangguhkanDuaTahunLalu,
            'sisa_ditangguhkan_tahun_lalu' => $sisaDitangguhkanTahunLalu,

            // Ditangguhkan: angka murni sistem + override Admin HR (untuk form
            // Edit Saldo di Vue). koreksi_ditangguhkan_*: null = ikut sistem.
            'ditangguhkan_sistem_n1' => $tghSistemN1,
            'ditangguhkan_sistem_n2' => $tghSistemN2,
            'koreksi_ditangguhkan_n1' => $koreksiTghN1,
            'koreksi_ditangguhkan_n2' => $koreksiTghN2,

            // Koreksi (untuk form Edit Saldo di Vue)
            // koreksi_saldo: null = ikut sistem, angka (termasuk 0) = koreksi manual
            'cuti_terpakai_murni' => $cutiTerpakaiMurni,
            'cuti_terpakai_sistem' => $cutiTerpakaiMurni,
            'koreksi_saldo' => $koreksiSaldo,
            'ada_koreksi' => $koreksiSaldo !== null,

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

        return $this->renderRekap($request, 'Admin/RekapKuotaDetail', null, null, true);
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
        $PURPLE_TINGGI = 'AFA9EC';
        $PURPLE_MUDA   = 'CECBF6';
        $GRAY_STAF     = 'D3D1C7';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Saldo Tahunan');

        // Judul
        $sheet->mergeCells('A1:N1');
        $sheet->setCellValue('A1', 'Rekap Kuota Detail Cuti — Biro Perencanaan');
        $sheet->getStyle('A1')->getFont()->setName('Arial')->setSize(14)->setBold(true)->getColor()->setRGB($GREEN_TITLE);
        $sheet->getRowDimension(1)->setRowHeight(22);

        $sheet->mergeCells('A2:N2');
        $sheet->setCellValue('A2', "Diunduh otomatis dari sistem — data per tahun {$tahunIni}");
        $sheet->getStyle('A2')->getFont()->setName('Arial')->setSize(10)->getColor()->setRGB('6B7280');
        $sheet->getRowDimension(3)->setRowHeight(6);

        // Header
        $headerRow = 4;
        $headers = [
            'A' => 'No',
            'B' => 'NIP',
            'C' => 'Nama Pegawai',
            'D' => 'Jabatan',
            'E' => "Sisa {$tahunDuaLalu}",
            'F' => "Sisa Ditangguhkan {$tahunDuaLalu}",
            'G' => "Sisa {$tahunLalu}",
            'H' => "Sisa Ditangguhkan {$tahunLalu}",
            'I' => "Saldo Bawaan {$tahunIni} yang Eligible",
            'J' => "Hak {$tahunIni}",
            'K' => "Total Hak Tersedia {$tahunIni}",
            'L' => "Terpakai Cuti Tahunan {$tahunIni}",
            'M' => "Saldo Akhir {$tahunIni}",
            'N' => 'Status Saldo',
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
        foreach (['F', 'H'] as $col) {
            $sheet->getComment("{$col}{$headerRow}")
                ->getText()
                ->createTextRun('Hari cuti ditangguhkan (saldo sudah dikembalikan, hanya informasi).');
        }
        $sheet->getRowDimension($headerRow)->setRowHeight(34);

        // Data
        $dataStartRow = 5;
        $r = $dataStartRow;

        foreach ($data as $i => $item) {
            $zebra = $i % 2 === 1 ? $ZEBRA : null;

            $roleId = $item['role_id'] ?? 1;
            if ($roleId >= 3) {
                $jabatanColor = $PURPLE_TINGGI;
                $jabatanTextColor = '26215C';
            } elseif ($roleId === 2) {
                $jabatanColor = $PURPLE_MUDA;
                $jabatanTextColor = '3C3489';
            } else {
                $jabatanColor = $GRAY_STAF;
                $jabatanTextColor = '444441';
            }

            $sheet->setCellValue("A{$r}", $i + 1);
            $sheet->setCellValueExplicit("B{$r}", (string) $item['nip'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            // Kolom C (Nama) & D (Jabatan) ditulis lewat tulisTeksAman() SETELAH styling (lihat di bawah).
            $sheet->setCellValue("E{$r}", $item['sisa_cuti_dua_tahun_lalu']);
            $sheet->setCellValue("F{$r}", $item['sisa_ditangguhkan_dua_tahun_lalu']);
            $sheet->setCellValue("G{$r}", $item['carry_forward_normal']);
            $sheet->setCellValue("H{$r}", $item['sisa_ditangguhkan_tahun_lalu']);
            $sheet->setCellValue("I{$r}", $item['saldo_bawaan_eligible']);
            $sheet->setCellValue("J{$r}", $item['kuota_tahunan']);
            $sheet->setCellValue("K{$r}", $item['total_cuti_tersedia']);
            $sheet->setCellValue("L{$r}", $item['cuti_terpakai']);
            $sheet->setCellValue("M{$r}", $item['saldo_akhir']);

            $statusSaldo = $item['saldo_akhir'] > 0 ? 'TERSISA' : 'HABIS';
            $sheet->setCellValue("N{$r}", $statusSaldo);

            foreach (['A', 'B', 'C', 'E', 'G', 'I', 'J', 'K', 'L', 'M'] as $col) {
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

            // Badge Jabatan
            $sheet->getStyle("D{$r}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 9, 'bold' => true, 'color' => ['rgb' => $jabatanTextColor]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $jabatanColor]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
            ]);

            // [SEC-09] Teks dari database: SELALU string murni (setelah styling C & D)
            $this->tulisTeksAman($sheet, "C{$r}", $item['nama']);
            $this->tulisTeksAman($sheet, "D{$r}", $item['jabatan'] ?? '-');

            // Sisa Ditangguhkan (oranye jika > 0)
            foreach (['F', 'H'] as $col) {
                $nilai = (int) $sheet->getCell("{$col}{$r}")->getValue();
                $sheet->getStyle("{$col}{$r}")->applyFromArray([
                    'font' => ['name' => 'Arial', 'size' => 10, 'bold' => $nilai > 0],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $nilai > 0 ? $ORANGE : ($zebra ?? 'FFFFFF')]],
                ]);
            }

            // Status Saldo
            $sheet->getStyle("N{$r}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 9, 'bold' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $statusSaldo === 'TERSISA' ? $STATUS_OK : $STATUS_HABIS]],
            ]);

            $r++;
        }

        // Legenda
        $legendTitleRow = $r + 1;
        $sheet->setCellValue("A{$legendTitleRow}", 'Keterangan warna Jabatan:');
        $sheet->getStyle("A{$legendTitleRow}")->getFont()->setName('Arial')->setSize(9)->setItalic(true)->setBold(true)->getColor()->setRGB('5F5E5A');

        $legends = [
            ['Jabatan struktural (Kepala Biro / Kasubag)', $PURPLE_TINGGI],
            ['Ketua Kelompok / Ketua Tim Kerja', $PURPLE_MUDA],
            ['Staf', $GRAY_STAF],
        ];

        foreach ($legends as $i => $item) {
            [$label, $color] = $item;
            $row = $legendTitleRow + 1 + $i;
            $sheet->getStyle("A{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($color);
            $sheet->mergeCells("B{$row}:D{$row}");
            $sheet->setCellValue("B{$row}", $label);
            $sheet->getStyle("B{$row}")->getFont()->setName('Arial')->setSize(9)->getColor()->setRGB('2C2C2A');
            $sheet->getRowDimension($row)->setRowHeight(16);
        }

        // Lebar kolom
        $sheet->getColumnDimension('A')->setWidth(5);
        $sheet->getColumnDimension('B')->setWidth(20);
        $sheet->getColumnDimension('C')->setWidth(30);
        $sheet->getColumnDimension('D')->setWidth(24);
        $sheet->getColumnDimension('E')->setWidth(9);
        $sheet->getColumnDimension('F')->setWidth(13);
        $sheet->getColumnDimension('G')->setWidth(9);
        $sheet->getColumnDimension('H')->setWidth(13);
        $sheet->getColumnDimension('I')->setWidth(13);
        $sheet->getColumnDimension('J')->setWidth(9);
        $sheet->getColumnDimension('K')->setWidth(13);
        $sheet->getColumnDimension('L')->setWidth(13);
        $sheet->getColumnDimension('M')->setWidth(11);
        $sheet->getColumnDimension('N')->setWidth(11);

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
