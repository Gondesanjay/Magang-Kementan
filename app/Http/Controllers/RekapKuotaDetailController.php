<?php

namespace App\Http\Controllers;

use App\Models\CutiDitangguhkan;
use App\Models\Pegawai;
use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class RekapKuotaDetailController extends Controller
{
    // ================= URUTAN HIERARKI JABATAN =================
    // Mapping role_id -> urutan tampil (angka lebih kecil = tampil lebih atas).
    // SAMA PERSIS dengan $urutanJabatan di AdminController, supaya urutan
    // pegawai di halaman Rekap Kuota Detail konsisten dengan Rekap Laporan.
    //
    // role_id 6 -> Kepala Biro Perencanaan (L4 / Approval tertinggi)
    // role_id 4 -> Kasubag TU (L3)
    // role_id 3 -> Ketua Kelompok Substansi (L2)
    // role_id 2 -> Ketua Tim Kerja (L1)
    // role_id 1 -> Pegawai / Staf (paling bawah)
    // role_id lain otomatis ditempatkan paling bawah (fallback ELSE 99).
    private array $urutanJabatan = [
        6 => 1,
        4 => 2,
        3 => 3,
        2 => 4,
        1 => 5,
    ];
    // ================= END URUTAN HIERARKI JABATAN =================

    // ================= RANTAI APPROVAL (BARU) =================
    // Mapping level approval (1-4) -> role_id yang bertugas di level tsb.
    // 1 = Ketua Tim Kerja (role 2), 2 = Ketua Kelompok Substansi (role 3),
    // 3 = Kasubag TU (role 4), 4 = Kepala Biro Perencanaan (role 6).
    // SAMA PERSIS dengan mapping $roleYangDibutuhkan di ApprovalController.
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

    // Cache nama approver per (role_id-departemen) supaya tidak query
    // berulang untuk banyak baris pengajuan pada pegawai yang sama.
    private array $approverNameCache = [];
    // ================= END RANTAI APPROVAL =================

    // ================= HELPER BERSAMA: QUERY DASAR =================
    // Dipakai oleh index() (tampilan web, dipaginasi) dan exportExcel()
    // (semua baris tanpa pagination), supaya data, filter pencarian, dan
    // urutan pegawai SELALU sama persis di kedua tempat.
    //
    // - role_id = 5 (Admin HR) DIKECUALIKAN, karena Admin HR tidak
    //   mengajukan cuti sehingga tidak relevan direkap di sini — sama
    //   seperti perlakuan role_id 5 di AdminController::buildLaporanData().
    // - Urutan mengikuti hierarki jabatan ($urutanJabatan) lewat CASE WHEN
    //   di level SQL (bukan sortBy() collection), supaya urutan tetap benar
    //   walau data dipaginasi.
    //
    // BARU: parameter $wilayahField/$wilayahValue (opsional). Dipakai
    // khusus jalur Atasan (indexAtasan/exportExcelAtasan) supaya hanya
    // menampilkan pegawai se-Tim Kerja (`divisi`, untuk L1) atau
    // se-Kelompok Substansi (`departemen`, untuk L2) dengan atasan yang
    // login — lihat resolveWilayahFilterUntukAtasan(). Admin HR
    // (index()/exportExcel()) selalu memanggil tanpa parameter ini,
    // sehingga nilainya tetap null dan Admin HR tetap melihat SEMUA
    // pegawai seperti sebelumnya — tidak ada perubahan perilaku untuk Admin.
    private function buildQuery(Request $request, ?string $wilayahField = null, ?string $wilayahValue = null): Builder
    {
        $tahunIni = (int) date('Y');
        $search = $request->input('search');

        $query = Pegawai::query()
            ->select('id', 'nama', 'nip', 'jabatan', 'kelompok_substansi', 'tim_kerja', 'role_id')
            ->where('role_id', '!=', 5) // Admin HR tidak ikut direkap
            ->with([
                'saldoCuti' => function ($q) use ($tahunIni) {
                    $q->where('tahun', $tahunIni);
                },
                'pengajuanCuti' => function ($q) {
                    $q->with('approvalLogs')->latest();
                },
            ]);

        // Filter WAJIB (scope wilayah) khusus Atasan — L1 hanya tim_kerja-nya,
        // L2 hanya kelompok_substansi-nya. Tidak berpengaruh ke Admin HR karena
        // $wilayahField selalu null saat dipanggil dari sana.
        if ($wilayahField) {
            $query->where($wilayahField, $wilayahValue);
        }

        // Filter dropdown OPSIONAL dari pilihan user di UI. Dikirim lewat query
        // string ?kelompok_substansi=... (dipakai Admin HR & Atasan L3/L4) atau
        // ?tim_kerja=... (dipakai Atasan L2, untuk menyaring tim kerja tertentu
        // DI DALAM kelompok_substansi-nya sendiri — AND, bukan OR, dengan
        // $wilayahField di atas, jadi tidak mungkin memperluas data L2 ke luar
        // kelompoknya).
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

    // ================= HELPER RANTAI APPROVAL (BARU) =================

    /**
     * Tentukan urutan level approval (1/2/3/4) yang WAJIB dilalui,
     * berdasarkan role_id pegawai yang MENGAJUKAN cuti.
     * Logikanya harus SAMA PERSIS dengan penentuan $statusAwal di
     * CutiController::store() — supaya rantai yang ditampilkan di modal
     * konsisten dengan rantai yang benar-benar dijalankan sistem.
     */
    private function getRequiredLevels(int $roleIdPemohon): array
    {
        return match ($roleIdPemohon) {
            2 => [2, 3, 4],
            3 => [3, 4],
            4 => [4],
            default => [1, 3, 4],
        };
    }

    /**
     * Cari nama pegawai yang menjabat di role_id tertentu, diprioritaskan
     * yang satu departemen dengan pemohon (fallback ke role_id manapun
     * kalau tidak ketemu) — meniru persis logika pencarian atasan yang
     * dipakai CutiController::store() & ApprovalController::process()
     * saat mengirim notifikasi.
     */
    private function getApproverName(int $roleId, ?string $kelompokSubstansi): string
    {
        $key = $roleId . '-' . ($kelompokSubstansi ?? '');

        if (isset($this->approverNameCache[$key])) {
            return $this->approverNameCache[$key];
        }

        $pegawai = Pegawai::where('role_id', $roleId)
            ->when($kelompokSubstansi, fn($q) => $q->where('kelompok_substansi', $kelompokSubstansi))
            ->first();

        if (!$pegawai) {
            $pegawai = Pegawai::where('role_id', $roleId)->first();
        }

        return $this->approverNameCache[$key] = $pegawai->nama ?? 'Belum ditentukan';
    }

    /**
     * Bangun daftar LENGKAP L1-L4 (sesuai rantai role pemohon) beserta
     * status masing-masing level: 'setuju' | 'tolak' | 'menunggu' |
     * 'belum_giliran'. Dipakai supaya modal Rekap Kuota Detail SELALU
     * menampilkan seluruh jenjang approval, di status APAPUN (menunggu,
     * disetujui, ditolak, dibatalkan/ditangguhkan, dibatalkan reguler).
     */
    private function buildApprovalChain(PengajuanCuti $riwayat, Pegawai $pemohon): array
    {
        $levels = $this->getRequiredLevels($pemohon->role_id);
        $logsByLevel = $riwayat->approvalLogs->keyBy('level_approval');

        $chain = [];

        foreach ($levels as $level) {
            $roleId = $this->levelToRoleId[$level];
            $label = $this->levelLabels[$level];

            // 1) Level ini sudah punya keputusan tercatat (setuju/tolak)
            if ($logsByLevel->has($level)) {
                $log = $logsByLevel->get($level);
                $chain[] = [
                    'level' => $level,
                    'label' => $label,
                    'nama' => $log->approver->nama ?? $this->getApproverName($roleId, $pemohon->kelompok_substansi),
                    'status' => $log->keputusan, // 'setuju' | 'tolak'
                ];
                continue;
            }

            // 2) Level ini PERSIS level yang sedang ditunggu sekarang
            if ($riwayat->status === "menunggu_l{$level}") {
                $chain[] = [
                    'level' => $level,
                    'label' => $label,
                    'nama' => $this->getApproverName($roleId, $pemohon->kelompok_substansi),
                    'status' => 'menunggu',
                ];
                continue;
            }

            // 3) Level ini belum pernah dilalui sama sekali (baik karena
            //    urutan belum sampai, atau proses sudah berhenti/ditolak
            //    di level sebelumnya)
            $chain[] = [
                'level' => $level,
                'label' => $label,
                'nama' => $this->getApproverName($roleId, $pemohon->kelompok_substansi),
                'status' => 'belum_giliran',
            ];
        }

        // ====== TAMBAHAN: langkah ekstra untuk aksi PENANGGUHAN/PEMBATALAN
        // yang dilakukan L4 lewat PembatalanController (BUKAN bagian dari
        // rantai approval L1-L4 biasa, tapi aksi terpisah setelah cuti
        // sudah lolos/berjalan). Dicatat dengan level_approval = 5 di
        // approval_logs. Kalau ada, ditambahkan sebagai baris terakhir
        // supaya modal menampilkan SIAPA yang menangguhkan & alasannya. ======
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

    public function updateSaldo(Request $request, int $id)
    {
        abort_unless((int) $request->user()->role_id === 5, 403);

        $data = $request->validate([
            'hak_tahun_ini' => ['required', 'integer', 'min:0'],
            'carry_forward_normal' => ['required', 'integer', 'min:0'],
            'sisa_cuti_dua_tahun_lalu' => ['required', 'integer', 'min:0'],
        ]);

        $pegawai = Pegawai::findOrFail($id);
        $tahunIni = (int) date('Y');
        $tahunLalu = $tahunIni - 1;

        $saldoTahunIni = SaldoCuti::where('pegawai_id', $pegawai->id)
            ->where('tahun', $tahunIni)
            ->first();
        $saldoTahunLalu = SaldoCuti::where('pegawai_id', $pegawai->id)
            ->where('tahun', $tahunLalu)
            ->first();

        $cutiTerpakai = PengajuanCuti::where('pegawai_id', $pegawai->id)
            ->where('jenis_cuti', 'Cuti Tahunan')
            ->where('status', 'disetujui')
            ->whereYear('tanggal_mulai', $tahunIni)
            ->sum('jumlah_hari');

        SaldoCuti::updateOrCreate(
            ['pegawai_id' => $pegawai->id, 'tahun' => $tahunIni],
            [
                'kuota_tahunan' => $data['hak_tahun_ini'],
                'carry_forward_normal' => $data['carry_forward_normal'],
                'sisa' => max(0, $data['hak_tahun_ini'] - $cutiTerpakai),
            ]
        );

        SaldoCuti::updateOrCreate(
            ['pegawai_id' => $pegawai->id, 'tahun' => $tahunLalu],
            [
                'kuota_tahunan' => $saldoTahunLalu->kuota_tahunan ?? 12,
                'carry_forward_normal' => $data['sisa_cuti_dua_tahun_lalu'],
                'sisa' => $saldoTahunLalu->sisa ?? 0,
            ]
        );

        return back()->with('success', 'Saldo cuti pegawai berhasil diperbarui.');
    }

    // ================= [BARU] HELPER: SISA DITANGGUHKAN (RESMI) =================
    // Total hari yang PERNAH ditangguhkan secara resmi (lewat L4 di
    // PembatalanController) untuk pegawai tsb pada satu tahun ASAL
    // tertentu. Dipakai untuk kolom "Sisa Ditangguhkan {tahun}" di
    // tabel/modal Vue (RekapKuotaDetail.vue) & export Excel — SEBELUMNYA
    // field ini dikirim sebagai 0 statis (belum pernah benar-benar
    // dihitung), sehingga badge oranye "Ditangguhkan" di modal & kolom
    // Sisa Ditangguhkan di export selalu kosong meski data penangguhan
    // resminya sudah ada di tabel `cuti_ditangguhkans`.
    //
    // SEMUA baris (bukan hanya yang status_pakai = 'belum_dipakai')
    // dihitung di sini, karena kolom ini murni catatan HISTORIS "berapa
    // hari yang pernah ditangguhkan resmi di tahun tsb" — beda dengan
    // carry-over aktif yang dihitung Command GenerateSaldoCutiTahunBaru
    // (yang memang hanya mengambil baris 'belum_dipakai').
    //
    // [PERBAIKAN 23/09/2026] Sempat error 500 (QueryException / Column
    // not found: 1054 Unknown column 'tahun') karena tabel
    // `cuti_ditangguhkans` TIDAK punya kolom bernama `tahun` — kolom yang
    // benar adalah `tahun_asal` (lihat struktur tabel di phpMyAdmin:
    // id, pegawai_id, pengajuan_asal_id, jumlah_hari, tahun_asal,
    // tahun_penggunaan, status_pakai, alasan_penangguhan, created_at,
    // updated_at). Kalau ke depan muncul error serupa "Unknown column"
    // untuk tabel lain, cek dulu nama kolom aslinya di database (phpMyAdmin
    // > Structure, atau `php artisan tinker` lalu
    // `Schema::getColumnListing('nama_tabel')`) sebelum mengubah query,
    // karena nama kolom di migration/DB bisa saja beda dengan asumsi kode.
    private function hitungSisaDitangguhkan(int $pegawaiId, int $tahun): int
    {
        return (int) CutiDitangguhkan::where('pegawai_id', $pegawaiId)
            ->where('tahun_asal', $tahun) // kolom aslinya 'tahun_asal', BUKAN 'tahun'
            ->sum('jumlah_hari');
    }
    // ================= END HELPER SISA DITANGGUHKAN =================

    // Hitung field-field kuota cuti untuk satu pegawai. Dipusatkan di sini
    // supaya index() (transform per halaman) dan exportExcel() (semua baris)
    // memakai rumus yang SAMA PERSIS.
    private function hitungKuota(Pegawai $pegawai, int $tahunIni): array
    {
        // 1. Ambil data asli dari relasi saldo
        $saldo = $pegawai->saldoCuti->first();

        // 2. Baca kuota_tahunan dan carry_forward_normal dari saldo (default 12 dan 0)
        $kuotaTahunan = $saldo->kuota_tahunan ?? 12;
        $carryForward = $saldo->carry_forward_normal ?? 0;
        $saldoTahunLalu = SaldoCuti::where('pegawai_id', $pegawai->id)
            ->where('tahun', $tahunIni - 1)
            ->first();

        // 3. Hitung Cuti Terpakai dari pengajuan yang 'disetujui'
        $cutiTerpakai = PengajuanCuti::where('pegawai_id', $pegawai->id)
            ->where('jenis_cuti', 'Cuti Tahunan')
            ->where('status', 'disetujui')
            ->whereYear('tanggal_mulai', $tahunIni)
            ->sum('jumlah_hari');

        // 4. Pisahkan total tersedia dari saldo akhir.
        $sisaKuotaTahunIni = max($kuotaTahunan - $cutiTerpakai, 0);
        $sisaDuaTahunLalu = $saldoTahunLalu?->carry_forward_normal ?? 0;
        $saldoBawaanEligible = ($sisaDuaTahunLalu === 12 && $carryForward === 12)
            ? 12
            : min(6, $carryForward);
        $totalCutiTersedia = $kuotaTahunan + $saldoBawaanEligible;
        $saldoAkhir = max($totalCutiTersedia - $cutiTerpakai, 0);

        // 5. Hitung Preview Carry Over menggunakan Helper di Model
        $previewCarryOver = \App\Models\SaldoCuti::hitungPreviewCarryOver($saldoAkhir);

        // ================= [BARU] SISA DITANGGUHKAN (RESMI) =================
        // Dihitung dari tabel cuti_ditangguhkans untuk tahun N-2 dan N-1,
        // dipakai di modal Kelola Kuota (badge oranye "Ditangguhkan") &
        // export Excel (kolom "Sisa Ditangguhkan"). Lihat catatan lengkap
        // di hitungSisaDitangguhkan() di atas.
        $sisaDitangguhkanDuaTahunLalu = $this->hitungSisaDitangguhkan($pegawai->id, $tahunIni - 2);
        $sisaDitangguhkanTahunLalu = $this->hitungSisaDitangguhkan($pegawai->id, $tahunIni - 1);
        // ================= [END BARU] =================

        // ====== BARU: tempel approval_chain lengkap ======
        $pegawai->pengajuanCuti->each(function ($riwayat) use ($pegawai) {
            $riwayat->approval_chain = $this->buildApprovalChain($riwayat, $pegawai);
        });

        return [
            'id' => $pegawai->id,
            'nama' => $pegawai->nama,
            'nip' => $pegawai->nip,
            'jabatan' => $pegawai->jabatan,
            'kelompok_substansi' => $pegawai->kelompok_substansi,
            'tim_kerja' => $pegawai->tim_kerja,
            'role_id' => $pegawai->role_id,

            // Field lama (dipertahankan agar Export Excel tidak error)
            'sisa_cuti_tahun_ini' => $sisaKuotaTahunIni,

            // Field baru untuk kebutuhan sinkronisasi data Vue
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

            // ================= [BARU] =================
            'sisa_ditangguhkan_dua_tahun_lalu' => $sisaDitangguhkanDuaTahunLalu,
            'sisa_ditangguhkan_tahun_lalu' => $sisaDitangguhkanTahunLalu,
            // ================= [END BARU] =================

            'pengajuan_cuti' => $pegawai->pengajuanCuti,
        ];
    }

    // ================= HELPER BARU: FILTER WILAYAH UNTUK ATASAN =================
    // PERBAIKAN GRANULARITAS (menyamakan dengan DashboardController &
    // MonitoringCutiController — lihat komentar "PERBAIKAN GRANULARITAS
    // WILAYAH" di kedua file tsb): sebelumnya method ini (dulu bernama
    // resolveDepartemenFilterUntukAtasan) memfilter SEMUA role atasan
    // (2, 3, 4) memakai kolom `departemen` saja, sehingga:
    //   - L1 (role_id 2 / Ketua Tim Kerja) salah granularitas: ikut
    //     melihat SELURUH Tim Kerja dalam satu Departemen/Kelompok,
    //     padahal harusnya hanya Tim Kerja (`divisi`) miliknya sendiri.
    //   - L3 (role_id 4 / Kasubag TU) malah DIBATASI satu departemen,
    //     padahal di MonitoringCutiController & DashboardController L3
    //     sengaja TIDAK dibatasi (lintas departemen/skala biro).
    //
    // Tentukan wilayah (field + value) yang berlaku untuk role Atasan:
    //   - L1 (role_id 2): field 'divisi'     (Tim Kerja sendiri)
    //   - L2 (role_id 3): field 'departemen' (Kelompok Substansi sendiri)
    //   - L3, L4 (role_id 4, 6): TIDAK dibatasi (field null)
    // Dipusatkan di sini supaya indexAtasan() dan exportExcelAtasan()
    // memakai aturan yang SAMA PERSIS, tidak ada kemungkinan beda logic.
    private function resolveWilayahFilterUntukAtasan($user): array
    {
        return match ($user->role_id) {
            2 => ['field' => 'tim_kerja', 'value' => $user->tim_kerja],
            3 => ['field' => 'kelompok_substansi', 'value' => $user->kelompok_substansi],
            default => ['field' => null, 'value' => null],
        };
    }
    // ================= END HELPER FILTER WILAYAH ATASAN =================

    // ================= HELPER BARU: DATA FILTER BERJENJANG UNTUK ATASAN =================
    // Menyiapkan props tambahan yang dibutuhkan Vue untuk menentukan mode
    // filter yang tepat sesuai level atasan yang login — SAMA PERSIS dengan
    // pola yang dipakai CutiController::teamCalendar() untuk Kalender Tim,
    // supaya kedua halaman konsisten:
    //   L1 (role_id 2) : tanpa dropdown, badge "Tim Anda: ..."
    //   L2 (role_id 3) : dropdown Tim Kerja (dalam kelompoknya sendiri)
    //   L3 & L4 (4, 6) : dropdown Kelompok/Subbagian (lintas kelompok)
    private function buildFilterPropsUntukAtasan($user): array
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

    // ================= [BARU] HELPER: KPI RINGKASAN (SELURUH HASIL FILTER) =================
    // PENTING: KPI (Total Pegawai, Rata-rata Terpakai, Rata-rata Saldo
    // Akhir, Akumulasi Penuh) SEBELUMNYA dihitung di sisi Vue dari
    // 'dataPegawai.data' — padahal itu cuma data SATU HALAMAN hasil
    // paginate(10). Selama total pegawai hasil filter kebetulan <= 10,
    // angkanya kelihatan benar (karena cuma ada 1 halaman). Begitu suatu
    // kelompok substansi punya > 10 pegawai (butuh > 1 halaman), KPI jadi
    // SALAH karena cuma menghitung dari 10 baris yang sedang tampil, bukan
    // dari SEMUA baris yang cocok dengan filter aktif.
    //
    // Method ini memakai buildQuery() yang SAMA PERSIS dengan yang dipakai
    // menampilkan tabel & export Excel (jadi filter search/kelompok
    // substansi/tim kerja/wilayah atasan otomatis ikut), TAPI tanpa
    // paginate() — supaya KPI selalu dihitung dari SELURUH baris hasil
    // filter, bukan cuma satu halaman.
    //
    // Kalau ke depan menambah KPI baru, tambahkan perhitungannya di sini
    // (bukan di Vue) supaya tetap akurat walau datanya lebih dari 10 baris.
    private function hitungKpi(Request $request, int $tahunIni, ?string $wilayahField, ?string $wilayahValue): array
    {
        $pegawaiSemua = $this->buildQuery($request, $wilayahField, $wilayahValue)->get();

        $dataLengkap = $pegawaiSemua->map(function ($pegawai) use ($tahunIni) {
            return $this->hitungKuota($pegawai, $tahunIni);
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
            // "Akumulasi Penuh (12/12)": pegawai yang sisa N-2 DAN sisa N-1
            // sama-sama 12 hari (carry-over penuh), sama seperti definisi
            // yang tadinya dihitung di Vue (getSisaDuaTahunLalu &
            // getSisaTahunLalu keduanya === 12).
            'akumulasi_penuh' => $dataLengkap->filter(function ($item) {
                return (int) $item['sisa_cuti_dua_tahun_lalu'] === 12
                    && (int) $item['carry_forward_normal'] === 12;
            })->count(),
        ];
    }
    // ================= END HELPER KPI RINGKASAN =================

    // ================= HELPER BERSAMA: RENDER HALAMAN (BARU) =================
    // Dipusatkan di sini supaya index() (Admin) dan indexAtasan() (Atasan)
    // memakai query + perhitungan kuota yang SAMA PERSIS, cuma beda target
    // komponen Inertia yang dirender. Tidak ada perubahan pada logic lama —
    // ini murni ekstraksi supaya tidak duplikasi kode antara Admin & Atasan.
    //
    // BARU: parameter $wilayahField/$wilayahValue diteruskan ke buildQuery().
    // Untuk index() (Admin HR) parameter ini tidak dikirim sama sekali
    // sehingga tetap null (lihat semua pegawai, tidak ada perubahan
    // otomatis). Untuk indexAtasan() nilainya ditentukan oleh
    // resolveWilayahFilterUntukAtasan().
    //
    // BARU: parameter $sertakanDaftarDepartemen (default false). Kalau true,
    // ikut mengirim prop 'daftarDepartemen' (semua nama departemen unik)
    // supaya halaman Vue-nya bisa menampilkan dropdown filter departemen.
    // Hanya dipakai oleh index() (Admin HR) — halaman Atasan tidak perlu
    // dropdown ini karena datanya sudah otomatis dibatasi per wilayah.
    //
    // BARU: parameter $extraProps (default []). Dipakai indexAtasan() untuk
    // menyisipkan props filter berjenjang (userRoleId, userTimKerja,
    // listTimKerja, listKelompok) tanpa mengubah signature index() Admin.
    private function renderRekap(
        Request $request,
        string $inertiaView,
        ?string $wilayahField = null,
        ?string $wilayahValue = null,
        bool $sertakanDaftarDepartemen = false,
        array $extraProps = []
    ) {
        $tahunIni = (int) date('Y');

        $pegawaiPaginated = $this->buildQuery($request, $wilayahField, $wilayahValue)
            ->paginate(10)
            ->withQueryString();

        $pegawaiPaginated->getCollection()->transform(function ($pegawai) use ($tahunIni) {
            return $this->hitungKuota($pegawai, $tahunIni);
        });

        // [BARU] KPI dihitung terpisah dari SELURUH hasil filter (bukan
        // hanya halaman yang sedang tampil) — lihat catatan lengkap di
        // hitungKpi() di atas. Filter yang sama (search, kelompok
        // substansi, tim kerja, wilayah atasan) otomatis ikut karena
        // memakai buildQuery() yang sama dengan $pegawaiPaginated di atas.
        $kpi = $this->hitungKpi($request, $tahunIni, $wilayahField, $wilayahValue);

        $props = array_merge([
            'dataPegawai' => $pegawaiPaginated,
            'kpi' => $kpi,
            // 'kelompok_substansi' & 'tim_kerja' ditambahkan supaya nilai
            // dropdown yang sedang aktif tetap ke-load ulang saat halaman
            // di-refresh/paginasi.
            'filters' => $request->only('search', 'kelompok_substansi', 'tim_kerja'),
        ], $extraProps);

        if ($sertakanDaftarDepartemen) {
            // Daftar departemen diambil dari pegawai yang direkap saja
            // (role_id != 5), supaya opsi dropdown selalu relevan dengan
            // data yang memang ditampilkan di tabel ini.
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
     * Rekap Kuota Detail — satu baris per pegawai, dipakai Admin HR
     * untuk melihat sisa cuti tahun ini/lalu + cuti terpakai, dan
     * membuka modal riwayat pengajuan + status approval L1/L3/L4.
     * Admin HR sendiri (role_id 5) tidak ditampilkan karena tidak
     * mengajukan cuti.
     *
     * TIDAK BERUBAH: tetap melihat SEMUA pegawai secara default (tidak
     * dipaksa filter wilayah tertentu, $wilayahField = null).
     *
     * DIPERBARUI: sekarang mengirim 'daftarDepartemen' (parameter kelima
     * = true) supaya halaman Vue Admin bisa menampilkan dropdown filter
     * departemen. Admin memilih sendiri departemen mana yang mau dilihat
     * lewat dropdown ini — filter dari query string ?departemen=...
     * ditangani otomatis di buildQuery().
     */
    public function index(Request $request)
    {
        return $this->renderRekap($request, 'Admin/RekapKuotaDetail', null, null, true);
    }

    /**
     * Versi Atasan (L1-L4 / role 2,3,4,6) dari halaman Rekap Kuota Detail.
     * Memakai query, rumus kuota, dan rantai approval yang SAMA PERSIS
     * dengan index() milik Admin HR — bedanya komponen Vue yang dirender
     * ('Atasan/RekapKuotaPegawai', bukan 'Admin/RekapKuotaDetail'), karena
     * halaman Atasan tidak menampilkan tombol/fitur "Impor Kuota".
     *
     * PERBAIKAN GRANULARITAS: sekarang atasan L1 (Ketua Tim Kerja) hanya
     * melihat pegawai se-Tim Kerja (`divisi`) miliknya sendiri, L2 (Ketua
     * Kelompok Substansi) melihat se-Kelompok Substansi (`departemen`)nya,
     * sedangkan L3 (Kasubag TU) dan L4/Kepala Biro tetap melihat semua
     * pegawai (lintas departemen) — konsisten dengan
     * MonitoringCutiController & DashboardController. Lihat
     * resolveWilayahFilterUntukAtasan().
     *
     * BARU: mengirim props filter berjenjang (userRoleId, userTimKerja,
     * listTimKerja, listKelompok) lewat buildFilterPropsUntukAtasan() —
     * pola sama dengan CutiController::teamCalendar() untuk Kalender Tim.
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

    // ================= HELPER BERSAMA: BUILD FILE EXCEL (DIPERBARUI) =================
    // Diekstrak dari exportExcel() lama supaya exportExcel() (Admin) dan
    // exportExcelAtasan() (Atasan) memakai builder Excel yang SAMA PERSIS
    // (styling, legenda, dst) tanpa duplikasi kode. Kolomnya SEKARANG
    // DISELARASKAN dengan Template_Rekap_Cuti_PNS_2026.xlsx (sheet
    // "Saldo Tahunan") yang sudah dipakai Admin secara manual sebelum
    // sistem ini ada:
    //   No | NIP | Nama Pegawai | Jabatan | Sisa N-2 | Sisa Ditangguhkan N-2 |
    //   Sisa N-1 | Sisa Ditangguhkan N-1 | Saldo Bawaan Eligible | Hak |
    //   Total Hak Tersedia | Terpakai | Saldo Akhir | Status Saldo
    //
    // Kolom "Sisa Ditangguhkan" SEKARANG BENAR-BENAR TERISI (sebelumnya
    // tidak ada di export lama sama sekali), diambil dari
    // hitungSisaDitangguhkan() lewat hitungKuota() — TIDAK ADA formula
    // SUM/Total Keseluruhan seperti versi lama, karena template acuan
    // tidak punya baris Total di sheet Saldo Tahunan.
    //
    // BARU: parameter $wilayahField/$wilayahValue diteruskan ke buildQuery(),
    // supaya export Excel milik Atasan ikut terfilter sama seperti
    // tampilan layarnya. exportExcel() (Admin) tetap memanggil tanpa
    // parameter ini.
    //
    // [PERBAIKAN 23/09/2026] Header kolom E/F/G/H sebelumnya ditulis
    // langsung sebagai ekspresi aritmatika di dalam string interpolation
    // PHP, contoh: "Sisa {$tahunIni - 2}". Ini TIDAK VALID di PHP — syntax
    // "{$var}" hanya boleh berisi akses variabel murni (termasuk
    // array/object access), BUKAN operasi matematika, sehingga selalu
    // menyebabkan ParseError "unexpected token '-'" begitu file ini
    // di-load. Solusinya: hitung dulu tahunnya ke variabel terpisah
    // ($tahunDuaLalu, $tahunLalu) SEBELUM dipakai di dalam string. Kalau
    // ke depan mau menambah kolom header dengan tahun N-x lain, JANGAN
    // menulis "{$tahunIni - x}" langsung di dalam string — selalu buat
    // variabel bantunya dulu.
    private function buildRekapSpreadsheet(Request $request, ?string $wilayahField = null, ?string $wilayahValue = null): Spreadsheet
    {
        $tahunIni = (int) date('Y');
        // Variabel bantu supaya TIDAK perlu menulis ekspresi matematika
        // di dalam interpolasi string "{$...}" (lihat catatan perbaikan
        // di atas method ini).
        $tahunDuaLalu = $tahunIni - 2;
        $tahunLalu = $tahunIni - 1;

        $pegawais = $this->buildQuery($request, $wilayahField, $wilayahValue)->get();

        $data = $pegawais->map(function ($pegawai) use ($tahunIni) {
            return $this->hitungKuota($pegawai, $tahunIni);
        })->values();

        // ---------- Warna ----------
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

        // ---------- Judul ----------
        $sheet->mergeCells('A1:N1');
        $sheet->setCellValue('A1', 'Rekap Kuota Detail Cuti — Biro Perencanaan');
        $sheet->getStyle('A1')->getFont()->setName('Arial')->setSize(14)->setBold(true)->getColor()->setRGB($GREEN_TITLE);
        $sheet->getRowDimension(1)->setRowHeight(22);

        $sheet->mergeCells('A2:N2');
        $sheet->setCellValue('A2', "Diunduh otomatis dari sistem — data per tahun {$tahunIni}");
        $sheet->getStyle('A2')->getFont()->setName('Arial')->setSize(10)->getColor()->setRGB('6B7280');
        $sheet->getRowDimension(3)->setRowHeight(6);

        // ---------- Header — SELARAS dengan Template_Rekap_Cuti_PNS.xlsx
        // (sheet Saldo Tahunan), dengan No & Jabatan tetap dipertahankan
        // sesuai kebutuhan Admin (tidak ada di template acuan, tapi tetap
        // dibutuhkan Admin untuk identifikasi cepat). ----------
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
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
            ]);
        }
        $sheet->getRowDimension($headerRow)->setRowHeight(34);

        // ---------- Data ----------
        $dataStartRow = 5;
        $r = $dataStartRow;

        foreach ($data as $i => $item) {
            $zebra = $i % 2 === 1 ? $ZEBRA : null;

            // Warna badge Jabatan — sama seperti versi lama, biar konsisten
            // dengan legenda "jabatan struktural / ketua / staf" di bawah.
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
            $sheet->setCellValueExplicit("B{$r}", $item['nip'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue("C{$r}", $item['nama']);
            $sheet->setCellValue("D{$r}", $item['jabatan'] ?? '-');
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

            // Kolom Jabatan (D) tetap pakai badge warna khusus.
            $sheet->getStyle("D{$r}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 9, 'bold' => true, 'color' => ['rgb' => $jabatanTextColor]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $jabatanColor]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
            ]);

            // Kolom Sisa Ditangguhkan (F & H) diberi warna oranye lembut
            // kalau ada isinya (> 0), supaya langsung kelihatan tanpa perlu
            // baca angkanya satu-satu.
            foreach (['F', 'H'] as $col) {
                $nilai = (int) $sheet->getCell("{$col}{$r}")->getValue();
                $sheet->getStyle("{$col}{$r}")->applyFromArray([
                    'font' => ['name' => 'Arial', 'size' => 10, 'bold' => $nilai > 0],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $nilai > 0 ? $ORANGE : ($zebra ?? 'FFFFFF')]],
                ]);
            }

            // Kolom Status Saldo (N) diberi warna hijau (TERSISA) / merah (HABIS).
            $sheet->getStyle("N{$r}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 9, 'bold' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $statusSaldo === 'TERSISA' ? $STATUS_OK : $STATUS_HABIS]],
            ]);

            $r++;
        }

        // ---------- Legenda warna Jabatan ----------
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

        // ---------- Lebar kolom ----------
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

    private function streamRekapSpreadsheet(Spreadsheet $spreadsheet)
    {
        $tahunIni = (int) date('Y');
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
     * Export Rekap Kuota Detail ke Excel. Memakai buildQuery() & hitungKuota()
     * yang SAMA dengan index(), jadi data, urutan, dan hasil filter pencarian
     * di file Excel SELALU sama persis dengan yang tampil di layar (bedanya
     * export mengambil SEMUA baris, tidak dipaginasi 10 per halaman).
     *
     * TIDAK BERUBAH: tetap export SEMUA pegawai (Admin HR tidak difilter
     * departemen), karena buildRekapSpreadsheet() dipanggil tanpa parameter kedua.
     */
    public function exportExcel(Request $request)
    {
        $spreadsheet = $this->buildRekapSpreadsheet($request);

        return $this->streamRekapSpreadsheet($spreadsheet);
    }

    /**
     * Export Excel versi Atasan. Memakai builder Excel yang SAMA PERSIS
     * dengan exportExcel() milik Admin HR (styling, legenda, dst) — tidak
     * ada perbedaan struktur file yang dihasilkan, cuma dipanggil dari
     * route yang berbeda ('atasan.kuota.export').
     *
     * DIPERBARUI: sekarang ikut difilter sesuai wilayah atasan yang login
     * (Tim Kerja untuk L1, Kelompok Substansi untuk L2; L3 & L4 tetap
     * export semua), memakai aturan yang SAMA PERSIS dengan indexAtasan()
     * lewat resolveWilayahFilterUntukAtasan(), supaya file Excel yang
     * diunduh selalu konsisten dengan apa yang tampil di layar Atasan
     * tersebut.
     *
     * Filter opsional ?tim_kerja=... dan ?kelompok_substansi=... dari UI
     * juga ikut terbaca lewat buildQuery() di dalam buildRekapSpreadsheet().
     */
    public function exportExcelAtasan(Request $request)
    {
        $wilayah = $this->resolveWilayahFilterUntukAtasan($request->user());

        $spreadsheet = $this->buildRekapSpreadsheet($request, $wilayah['field'], $wilayah['value']);

        return $this->streamRekapSpreadsheet($spreadsheet);
    }
}
