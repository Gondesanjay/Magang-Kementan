<?php

namespace App\Http\Controllers;

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
    // BARU: parameter $departemenFilter (opsional). Dipakai khusus jalur
    // Atasan (indexAtasan/exportExcelAtasan) supaya hanya menampilkan
    // pegawai satu departemen dengan atasan yang login. Admin HR
    // (index()/exportExcel()) selalu memanggil tanpa parameter ini,
    // sehingga nilainya tetap null dan Admin HR tetap melihat SEMUA
    // pegawai seperti sebelumnya — tidak ada perubahan perilaku untuk Admin.
    private function buildQuery(Request $request, ?string $departemenFilter = null): Builder
    {
        $tahunIni = (int) date('Y');
        $search = $request->input('search');

        $query = Pegawai::query()
            ->select('id', 'nama', 'nip', 'jabatan', 'departemen', 'role_id')
            ->where('role_id', '!=', 5) // Admin HR tidak ikut direkap
            ->with([
                'saldoCuti' => function ($q) use ($tahunIni) {
                    $q->where('tahun', $tahunIni);
                },
                'pengajuanCuti' => function ($q) {
                    $q->with('approvalLogs')->latest();
                },
            ]);

        // BARU: filter khusus Atasan — hanya tampilkan pegawai satu
        // departemen dengan atasan yang login. Tidak berpengaruh ke Admin
        // HR karena $departemenFilter selalu null saat dipanggil dari sana.
        if ($departemenFilter) {
            $query->where('departemen', $departemenFilter);
        }

        // BARU: filter dropdown departemen khusus halaman Admin HR
        // ('Rekap Kuota Detail'). Dikirim lewat query string ?departemen=...
        // dari pilihan dropdown di UI, BUKAN dipaksa seperti $departemenFilter
        // di atas. Aman digabung dengan $departemenFilter (Atasan) sekalipun,
        // karena keduanya memakai AND — tidak mungkin memperluas data yang
        // seharusnya sudah dibatasi untuk role Atasan.
        if ($departemenPilihan = $request->input('departemen')) {
            $query->where('departemen', $departemenPilihan);
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
    private function getApproverName(int $roleId, ?string $departemen): string
    {
        $key = $roleId . '-' . ($departemen ?? '');

        if (isset($this->approverNameCache[$key])) {
            return $this->approverNameCache[$key];
        }

        $pegawai = Pegawai::where('role_id', $roleId)
            ->when($departemen, fn($q) => $q->where('departemen', $departemen))
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
                    'nama' => $log->approver->nama ?? $this->getApproverName($roleId, $pemohon->departemen),
                    'status' => $log->keputusan, // 'setuju' | 'tolak'
                ];
                continue;
            }

            // 2) Level ini PERSIS level yang sedang ditunggu sekarang
            if ($riwayat->status === "menunggu_l{$level}") {
                $chain[] = [
                    'level' => $level,
                    'label' => $label,
                    'nama' => $this->getApproverName($roleId, $pemohon->departemen),
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
                'nama' => $this->getApproverName($roleId, $pemohon->departemen),
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
                'nama' => $logTangguh->approver->nama ?? $this->getApproverName(6, $pemohon->departemen),
                'status' => 'tangguh',
            ];
        }

        return $chain;
    }
    // ================= END HELPER RANTAI APPROVAL =================

    // Hitung field-field kuota cuti untuk satu pegawai. Dipusatkan di sini
    // supaya index() (transform per halaman) dan exportExcel() (semua baris)
    // memakai rumus yang SAMA PERSIS.
    //
    // PENTING: "Cuti Terpakai" TIDAK diambil dari kolom saldo_cutis.terpakai
    // (kolom itu tidak pernah di-update saat approval terjadi, jadi selalu 0).
    // Dihitung live dari pengajuan_cutis yang statusnya 'disetujui', sama
    // persis dengan cara DashboardController & CutiController menghitungnya.
    private function hitungKuota(Pegawai $pegawai, int $tahunIni): array
    {
        $jatahCuti = $pegawai->jatah_cuti ?? 12;
        $saldo = $pegawai->saldoCuti->first();
        $sisaTahunLalu = $saldo->sisa_cuti_tahun_lalu ?? 0;

        $cutiTerpakai = PengajuanCuti::where('pegawai_id', $pegawai->id)
            ->where('jenis_cuti', 'Cuti Tahunan')
            ->where('status', 'disetujui')
            ->whereYear('tanggal_mulai', $tahunIni)
            ->sum('jumlah_hari');

        $sisaTahunIni = $jatahCuti - $cutiTerpakai;

        // ====== BARU: tempel approval_chain lengkap (L1-L4 sesuai rantai
        // role pemohon) ke tiap riwayat pengajuan cuti pegawai ini, supaya
        // modal Rekap Kuota Detail bisa menampilkan seluruh jenjang
        // approval di status apapun (menunggu, disetujui, ditolak,
        // dibatalkan/ditangguhkan, dibatalkan reguler). ======
        $pegawai->pengajuanCuti->each(function ($riwayat) use ($pegawai) {
            $riwayat->approval_chain = $this->buildApprovalChain($riwayat, $pegawai);
        });

        return [
            'id' => $pegawai->id,
            'nama' => $pegawai->nama,
            'nip' => $pegawai->nip,
            'jabatan' => $pegawai->jabatan,
            'departemen' => $pegawai->departemen,
            'role_id' => $pegawai->role_id,
            'sisa_cuti_tahun_ini' => $sisaTahunIni,
            'sisa_cuti_tahun_lalu' => $sisaTahunLalu,
            'cuti_terpakai' => $cutiTerpakai,
            'pengajuan_cuti' => $pegawai->pengajuanCuti,
        ];
    }

    // ================= HELPER BARU: FILTER DEPARTEMEN UNTUK ATASAN =================
    // Tentukan filter departemen yang berlaku untuk role Atasan.
    // - Kepala Biro Perencanaan (role_id 6) mengawasi SELURUH biro
    //   (lintas departemen/subbagian), jadi TIDAK difilter (null = lihat
    //   semua pegawai, sama seperti Admin HR).
    // - Role atasan lainnya (2 = Ketua Tim Kerja, 3 = Ketua Kelompok
    //   Substansi, 4 = Kasubag TU) hanya boleh melihat pegawai satu
    //   departemen dengan dirinya sendiri.
    // Dipusatkan di sini supaya indexAtasan() dan exportExcelAtasan()
    // memakai aturan yang SAMA PERSIS, tidak ada kemungkinan beda logic.
    private function resolveDepartemenFilterUntukAtasan($user): ?string
    {
        return $user->role_id === 6 ? null : $user->departemen;
    }
    // ================= END HELPER FILTER DEPARTEMEN ATASAN =================

    // ================= HELPER BERSAMA: RENDER HALAMAN (BARU) =================
    // Dipusatkan di sini supaya index() (Admin) dan indexAtasan() (Atasan)
    // memakai query + perhitungan kuota yang SAMA PERSIS, cuma beda target
    // komponen Inertia yang dirender. Tidak ada perubahan pada logic lama —
    // ini murni ekstraksi supaya tidak duplikasi kode antara Admin & Atasan.
    //
    // BARU: parameter $departemenFilter diteruskan ke buildQuery(). Untuk
    // index() (Admin HR) parameter ini tidak dikirim sama sekali sehingga
    // tetap null (lihat semua pegawai, tidak ada perubahan otomatis). Untuk
    // indexAtasan() nilainya ditentukan oleh resolveDepartemenFilterUntukAtasan().
    //
    // BARU: parameter $sertakanDaftarDepartemen (default false). Kalau true,
    // ikut mengirim prop 'daftarDepartemen' (semua nama departemen unik)
    // supaya halaman Vue-nya bisa menampilkan dropdown filter departemen.
    // Hanya dipakai oleh index() (Admin HR) — halaman Atasan tidak perlu
    // dropdown ini karena datanya sudah otomatis dibatasi per departemen.
    private function renderRekap(Request $request, string $inertiaView, ?string $departemenFilter = null, bool $sertakanDaftarDepartemen = false)
    {
        $tahunIni = (int) date('Y');

        $pegawaiPaginated = $this->buildQuery($request, $departemenFilter)
            ->paginate(10)
            ->withQueryString();

        $pegawaiPaginated->getCollection()->transform(function ($pegawai) use ($tahunIni) {
            return $this->hitungKuota($pegawai, $tahunIni);
        });

        $props = [
            'dataPegawai' => $pegawaiPaginated,
            // 'departemen' ditambahkan supaya nilai dropdown yang sedang
            // aktif tetap ke-load ulang saat halaman di-refresh/paginasi.
            'filters' => $request->only('search', 'departemen'),
        ];

        if ($sertakanDaftarDepartemen) {
            // Daftar departemen diambil dari pegawai yang direkap saja
            // (role_id != 5), supaya opsi dropdown selalu relevan dengan
            // data yang memang ditampilkan di tabel ini.
            $props['daftarDepartemen'] = Pegawai::where('role_id', '!=', 5)
                ->whereNotNull('departemen')
                ->distinct()
                ->orderBy('departemen')
                ->pluck('departemen');
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
     * dipaksa filter departemen tertentu, $departemenFilter = null).
     *
     * DIPERBARUI: sekarang mengirim 'daftarDepartemen' (parameter keempat
     * = true) supaya halaman Vue Admin bisa menampilkan dropdown filter
     * departemen. Admin memilih sendiri departemen mana yang mau dilihat
     * lewat dropdown ini — filter dari query string ?departemen=...
     * ditangani otomatis di buildQuery().
     */
    public function index(Request $request)
    {
        return $this->renderRekap($request, 'Admin/RekapKuotaDetail', null, true);
    }

    /**
     * Versi Atasan (L1-L4 / role 2,3,4,6) dari halaman Rekap Kuota Detail.
     * Memakai query, rumus kuota, dan rantai approval yang SAMA PERSIS
     * dengan index() milik Admin HR — bedanya komponen Vue yang dirender
     * ('Atasan/RekapKuotaPegawai', bukan 'Admin/RekapKuotaDetail'), karena
     * halaman Atasan tidak menampilkan tombol/fitur "Impor Kuota".
     *
     * DIPERBARUI: sekarang atasan HANYA melihat pegawai di departemennya
     * sendiri (bawahannya), KECUALI Kepala Biro (role 6) yang tetap
     * melihat semua pegawai karena mengawasi seluruh biro. Lihat
     * resolveDepartemenFilterUntukAtasan().
     */
    public function indexAtasan(Request $request)
    {
        $departemenFilter = $this->resolveDepartemenFilterUntukAtasan($request->user());

        return $this->renderRekap($request, 'Atasan/RekapKuotaPegawai', $departemenFilter);
    }

    // ================= HELPER BERSAMA: BUILD FILE EXCEL (BARU) =================
    // Diekstrak dari exportExcel() lama supaya exportExcel() (Admin) dan
    // exportExcelAtasan() (Atasan) memakai builder Excel yang SAMA PERSIS
    // (styling, legenda, formula SUM, dst) tanpa duplikasi kode. Tidak ada
    // satupun baris styling/formula yang diubah dari versi asli.
    //
    // BARU: parameter $departemenFilter diteruskan ke buildQuery(), supaya
    // export Excel milik Atasan ikut terfilter sama seperti tampilan
    // layarnya. exportExcel() (Admin) tetap memanggil tanpa parameter ini.
    private function buildRekapSpreadsheet(Request $request, ?string $departemenFilter = null): Spreadsheet
    {
        $tahunIni = (int) date('Y');

        $pegawais = $this->buildQuery($request, $departemenFilter)->get();

        $data = $pegawais->map(function ($pegawai) use ($tahunIni) {
            return $this->hitungKuota($pegawai, $tahunIni);
        })->values();

        // ---------- Warna ----------
        $GREEN_TITLE  = '14532D';
        $GREEN_HEADER = '16A34A';
        $ZEBRA        = 'F0FDF4';
        $TOTAL_BG     = 'DCFCE7';
        $PURPLE_TINGGI = 'AFA9EC';
        $PURPLE_MUDA   = 'CECBF6';
        $GRAY_STAF     = 'D3D1C7';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Rekap Kuota Cuti');

        // ---------- Judul ----------
        $sheet->mergeCells('A1:H1');
        $sheet->setCellValue('A1', 'Rekap Kuota Detail Cuti — Biro Perencanaan');
        $sheet->getStyle('A1')->getFont()->setName('Arial')->setSize(14)->setBold(true)->getColor()->setRGB($GREEN_TITLE);
        $sheet->getRowDimension(1)->setRowHeight(22);

        $sheet->mergeCells('A2:H2');
        $sheet->setCellValue('A2', "Diunduh otomatis dari sistem — data per tahun {$tahunIni}");
        $sheet->getStyle('A2')->getFont()->setName('Arial')->setSize(10)->getColor()->setRGB('6B7280');
        $sheet->getRowDimension(3)->setRowHeight(6);

        // ---------- Header ----------
        $headerRow = 4;
        $headers = [
            'A' => 'No',
            'B' => 'Nama Pegawai',
            'C' => 'NIP / Identitas',
            'D' => 'Jabatan',
            'E' => 'Sisa Cuti Tahun Ini',
            'F' => 'Sisa Cuti Tahun Lalu',
            'G' => 'Total Kuota Tersedia',
            'H' => 'Cuti Terpakai',
        ];

        foreach ($headers as $col => $label) {
            $sheet->setCellValue("{$col}{$headerRow}", $label);
            $sheet->getStyle("{$col}{$headerRow}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $GREEN_HEADER]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
            ]);
        }
        $sheet->getRowDimension($headerRow)->setRowHeight(18);

        // ---------- Data ----------
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
            $sheet->setCellValue("B{$r}", $item['nama']);
            $sheet->setCellValueExplicit("C{$r}", $item['nip'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue("D{$r}", $item['jabatan'] ?? '-');
            $sheet->setCellValue("E{$r}", $item['sisa_cuti_tahun_ini']);
            $sheet->setCellValue("F{$r}", $item['sisa_cuti_tahun_lalu']);
            $sheet->setCellValue("G{$r}", "=E{$r}+F{$r}");
            $sheet->setCellValue("H{$r}", $item['cuti_terpakai']);

            foreach (['A', 'B', 'C', 'E', 'F', 'G', 'H'] as $col) {
                $sheet->getStyle("{$col}{$r}")->applyFromArray([
                    'font' => ['name' => 'Arial', 'size' => 10],
                    'alignment' => [
                        'horizontal' => $col === 'B' ? Alignment::HORIZONTAL_LEFT : Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                    ],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
                    'fill' => $zebra ? ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $zebra]] : [],
                ]);
            }

            $sheet->getStyle("D{$r}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 9, 'bold' => true, 'color' => ['rgb' => $jabatanTextColor]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $jabatanColor]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
            ]);

            $r++;
        }

        $dataEndRow = $r - 1;

        // ---------- Baris TOTAL ----------
        $totalRow = $dataEndRow + 1;
        $sheet->mergeCells("A{$totalRow}:D{$totalRow}");
        $sheet->setCellValue("A{$totalRow}", 'TOTAL');
        $sheet->getStyle("A{$totalRow}")->getFont()->setName('Arial')->setSize(10)->setBold(true);

        foreach (['E', 'F', 'G', 'H'] as $col) {
            $sheet->setCellValue("{$col}{$totalRow}", "=SUM({$col}{$dataStartRow}:{$col}{$dataEndRow})");
            $sheet->getStyle("{$col}{$totalRow}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $TOTAL_BG]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
            ]);
        }

        // ---------- Legenda warna ----------
        $legendTitleRow = $totalRow + 2;
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
        $sheet->getColumnDimension('A')->setWidth(6);
        $sheet->getColumnDimension('B')->setWidth(34);
        $sheet->getColumnDimension('C')->setWidth(20);
        $sheet->getColumnDimension('D')->setWidth(26);
        $sheet->getColumnDimension('E')->setWidth(16);
        $sheet->getColumnDimension('F')->setWidth(16);
        $sheet->getColumnDimension('G')->setWidth(16);
        $sheet->getColumnDimension('H')->setWidth(14);

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
     * dengan exportExcel() milik Admin HR (styling, legenda, formula SUM,
     * dst) — tidak ada perbedaan struktur file yang dihasilkan, cuma
     * dipanggil dari route yang berbeda ('atasan.kuota.export').
     *
     * DIPERBARUI: sekarang ikut difilter sesuai departemen atasan yang
     * login (kecuali Kepala Biro/role 6 yang tetap export semua), memakai
     * aturan yang SAMA PERSIS dengan indexAtasan() lewat
     * resolveDepartemenFilterUntukAtasan(), supaya file Excel yang diunduh
     * selalu konsisten dengan apa yang tampil di layar Atasan tersebut.
     */
    public function exportExcelAtasan(Request $request)
    {
        $departemenFilter = $this->resolveDepartemenFilterUntukAtasan($request->user());

        $spreadsheet = $this->buildRekapSpreadsheet($request, $departemenFilter);

        return $this->streamRekapSpreadsheet($spreadsheet);
    }
}
