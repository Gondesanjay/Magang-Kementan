<?php


namespace App\Http\Controllers;


use App\Models\Pegawai;
use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use App\Models\CutiDitangguhkan;
use App\Models\HistoriSaldo;
use App\Models\HariLibur;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;


class AdminController extends Controller
{
    private array $keyBulan = ['jan', 'feb', 'mar', 'apr', 'mei', 'jun', 'jul', 'agu', 'sep', 'okt', 'nov', 'des'];
    private array $labelBulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];


    // Role ID yang dipakai untuk akun Admin (HR/Kepegawaian), BUKAN pegawai/atasan
    // biasa. Dipusatkan sebagai konstanta supaya definisinya konsisten di
    // seluruh controller (dipakai buildLaporanData() untuk Rekap Laporan &
    // kelolaPegawai() untuk Master Data Pegawai), tidak didefinisikan ulang
    // dengan cara berbeda di tempat lain.
    private const ROLE_ID_ADMIN = 5;


    // ---> BARU: Jumlah baris per halaman untuk Rekap Laporan (server-side pagination) <---
    private const REKAP_PER_PAGE = 10;


    // ================= URUTAN HIERARKI JABATAN =================
    // Mapping role_id -> urutan tampil (angka lebih kecil = tampil lebih atas).
    // Dipakai untuk mengurutkan pegawai dari jabatan tertinggi ke terendah,
    // BUKAN hardcode nama satu-satu, dan BUKAN pencocokan teks nama jabatan
    // (LIKE '%Kepala Biro%' dsb.) yang rawan meleset kalau penulisan jabatan
    // sedikit berbeda di database. Cukup mengacu ke role_id yang sudah
    // dipakai konsisten di seluruh sistem approval cuti (lihat
    // CutiController@store untuk hierarki level approval l1-l4 yang sama).
    //
    // Urutan hierarki (dari atas ke bawah):
    // role_id 6 -> Kepala Biro Perencanaan (L4 / Approval tertinggi)
    // role_id 4 -> Kasubag TU (L3)
    // role_id 3 -> Ketua Kelompok Substansi (L2)
    // role_id 2 -> Ketua Tim Kerja (L1)
    // role_id 1 -> Pegawai / Staf (paling bawah)
    // role_id lain yang tidak terdaftar di mapping ini akan otomatis
    // ditempatkan paling bawah (lihat fallback ?? 99 di bawah).
    private array $urutanJabatan = [
        6 => 1,
        4 => 2,
        3 => 3,
        2 => 4,
        1 => 5,
    ];
    // ================= END URUTAN HIERARKI JABATAN =================


    // ================= HELPER BERSAMA: URUTKAN KOLEKSI PEGAWAI SESUAI HIERARKI =================
    private function urutkanSesuaiHierarki($pegawais)
    {
        return $pegawais
            ->sortBy(function ($pegawai) {
                return mb_strtolower(trim($pegawai->nama ?? ''));
            })
            ->sortBy(function ($pegawai) {
                return $this->urutanJabatan[$pegawai->role_id] ?? 99;
            })
            ->values();
    }
    // ================= END HELPER URUTKAN PEGAWAI =================


    // ================= HELPER BARU: EKSTRAK TANGGAL MASUK DARI NIP =================
    private function extractTanggalMasukDariNip(?string $nip): ?string
    {
        if (empty($nip) || strlen($nip) !== 18 || !ctype_digit($nip)) {
            return null;
        }


        $tahunTmt = (int) substr($nip, 8, 4);
        $bulanTmt = (int) substr($nip, 12, 2);


        if ($bulanTmt < 1 || $bulanTmt > 12) {
            return null;
        }


        try {
            return Carbon::createFromDate($tahunTmt, $bulanTmt, 1)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }
    // ================= END HELPER EKSTRAK TANGGAL MASUK DARI NIP =================


    // ---> BARU: Rapikan teks ALL CAPS jadi Title Case <---
    // Dipakai khusus saat import Excel/CSV, supaya "IGNATIUS AGUS HENDARTO"
    // otomatis jadi "Ignatius Agus Hendarto". mb_convert_case menjaga huruf
    // pertama tiap kata jadi kapital dan sisanya kecil, termasuk pada gelar
    // singkat seperti "S.E." / "M.M." (hasilnya "S.e." / "M.m." — sedikit
    // tidak sempurna untuk gelar, tapi jauh lebih rapi daripada full caps).
    private function toTitleCase(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return $text;
        }


        return mb_convert_case(trim($text), MB_CASE_TITLE, 'UTF-8');
    }


    // ================= PAGINATION SERVER-SIDE UNTUK REKAP LAPORAN =================
    // $paginate = true  -> dipakai oleh rekapLaporan() (tampilan web), hanya
    //                      ambil & hitung rekap cuti untuk 10 pegawai di
    //                      halaman aktif saja (hemat query).
    // $paginate = false -> dipakai oleh exportExcel(), tetap mengambil &
    //                      menghitung SEMUA pegawai karena file Excel harus
    //                      lengkap, tidak boleh terpotong per halaman.
    private function buildLaporanData(Request $request, bool $paginate = false): array
    {
        $tahun = $request->input('tahun', date('Y'));
        $search = $request->input('search');
        $kelompok = $request->input('kelompok_substansi');
        $bulanFilter = $request->input('bulan');


        $query = Pegawai::where('role_id', '!=', self::ROLE_ID_ADMIN);


        $query->when($kelompok, function ($q) use ($kelompok) {
            return $q->where('kelompok_substansi', $kelompok);
        });


        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('nama', 'like', "%{$search}%")
                    ->orWhere('nip', 'like', "%{$search}%");
            });
        }


        $pegawais = $query->get();


        // Urutan hierarki (jabatan -> nama) dihitung dulu terhadap SELURUH
        // hasil filter/search, sebelum dipotong per halaman. Ini penting
        // supaya urutan pegawai di halaman 2, 3, dst tetap konsisten dan
        // tidak berubah-ubah setiap ganti halaman.
        $pegawais = $this->urutkanSesuaiHierarki($pegawais);


        $paginator = null;


        if ($paginate) {
            $perPage = self::REKAP_PER_PAGE;
            $page = max(1, (int) $request->input('page', 1));
            $total = $pegawais->count();


            // Potong koleksi yang SUDAH terurut sesuai halaman aktif.
            // Perhitungan rekap cuti per bulan di bawah jadi hanya berjalan
            // untuk pegawai pada halaman ini saja (maks 10 pegawai), bukan
            // untuk semua pegawai hasil filter.
            $pegawais = $pegawais->slice(($page - 1) * $perPage, $perPage)->values();


            $paginator = new LengthAwarePaginator(
                $pegawais,
                $total,
                $perPage,
                $page,
                [
                    'path' => $request->url(),
                    'query' => $request->query(),
                ]
            );
        }


        $laporan = $pegawais->map(function ($pegawai) use ($tahun) {
            $cutis = PengajuanCuti::where('pegawai_id', $pegawai->id)
                ->where('jenis_cuti', 'Cuti Tahunan')
                ->whereYear('tanggal_mulai', $tahun)
                ->where('status', 'disetujui')
                ->get();


            $rekapBulan = array_fill_keys($this->keyBulan, 0);


            foreach ($cutis as $cuti) {
                $bulanAngka = (int) date('n', strtotime($cuti->tanggal_mulai));
                $keyBulan = $this->keyBulan[$bulanAngka - 1];
                $rekapBulan[$keyBulan] += $cuti->jumlah_hari;
            }


            return [
                'id' => $pegawai->id,
                'nama' => $pegawai->nama,
                'nip' => $pegawai->nip,
                'jabatan' => $pegawai->jabatan,
                'kelompok_substansi' => $pegawai->kelompok_substansi,
                'tim_kerja' => $pegawai->tim_kerja,
                'role_id' => $pegawai->role_id,
                'cuti' => $rekapBulan,
                'total' => array_sum($rekapBulan),
            ];
        })->values();


        return [
            'laporan' => $laporan,
            'tahun' => (int) $tahun,
            'bulanFilter' => $bulanFilter ? (int) $bulanFilter : null,
            'paginator' => $paginator,
        ];
    }
    // ================= END PAGINATION SERVER-SIDE UNTUK REKAP LAPORAN =================


    // ================= HELPER BERSAMA: HITUNG SISA CUTI OTOMATIS =================
    private function hitungSisaOtomatis(int $pegawaiId, int $tahun, int $kuotaTahunan): int
    {
        $cutiTerpakai = PengajuanCuti::where('pegawai_id', $pegawaiId)
            ->where('jenis_cuti', 'Cuti Tahunan')
            ->where('status', 'disetujui')
            ->whereYear('tanggal_mulai', $tahun)
            ->sum('jumlah_hari');


        return max(0, $kuotaTahunan - $cutiTerpakai);
    }
    // ================= END HELPER BERSAMA =================


    // ================= [DIUBAH] HELPER: PREVIEW CARRY-OVER (2 SUMBER) =================
    // Logika PERSIS SAMA dengan yang dipakai di Command
    // GenerateSaldoCutiTahunBaru (app/Console/Commands/GenerateSaldoCutiTahunBaru.php)
    // setelah REVISI menggabungkan 2 sumber carry-over:
    //   SUMBER 1: sisa saldo biasa (Jalur Normal maks 6 hari / Jalur
    //              Khusus kalau 2 tahun berturut-turut tidak pakai cuti).
    //   SUMBER 2: hari dari tabel `cuti_ditangguhkans` yang berstatus
    //              'belum_dipakai' di tahun lalu (SELALU dibawa penuh,
    //              dicatat otomatis oleh PembatalanController@process
    //              setiap kali L4 menangguhkan sebuah pengajuan).
    //
    // Dipertahankan di sini HANYA untuk keperluan PREVIEW di halaman
    // Kelola Saldo Cuti (menampilkan ke Admin berapa carry-over yang akan
    // didapat pegawai kalau digenerate), TIDAK dipakai untuk benar-benar
    // menulis ke database — proses generate sesungguhnya SELALU lewat
    // Command yang sama (dipanggil oleh scheduler otomatis MAUPUN tombol
    // manual generateSaldoManual() di bawah), supaya logikanya tidak
    // pernah tertulis dua kali secara terpisah dan berisiko tidak sinkron.
    private function hitungPreviewCarryOver(int $pegawaiId, int $tahunBaru): array
    {
        $tahunLalu = $tahunBaru - 1;

        // SUMBER 1
        $saldoTahunLalu = SaldoCuti::where('pegawai_id', $pegawaiId)
            ->where('tahun', $tahunLalu)
            ->first();

        $saldoDuaTahunLalu = SaldoCuti::where('pegawai_id', $pegawaiId)
            ->where('tahun', $tahunBaru - 2)
            ->first();

        $carryDariSaldoBiasa = 0;

        if ($saldoTahunLalu) {
            $sisaTahunLalu = (int) $saldoTahunLalu->sisa;

            $tidakDipakaiTahunLalu = $sisaTahunLalu >= $saldoTahunLalu->kuota_tahunan;
            $tidakDipakaiDuaTahunLalu = $saldoDuaTahunLalu
                && (int) $saldoDuaTahunLalu->sisa >= $saldoDuaTahunLalu->kuota_tahunan;

            $carryDariSaldoBiasa = ($tidakDipakaiTahunLalu && $tidakDipakaiDuaTahunLalu)
                ? $sisaTahunLalu
                : min($sisaTahunLalu, 6);
        }

        // SUMBER 2
        $carryDariPenangguhanResmi = CutiDitangguhkan::belumDipakai($pegawaiId, $tahunLalu)
            ->sum('jumlah_hari');

        return [
            'dari_saldo_biasa' => $carryDariSaldoBiasa,
            'dari_penangguhan_resmi' => $carryDariPenangguhanResmi,
            'total' => $carryDariSaldoBiasa + $carryDariPenangguhanResmi,
        ];
    }
    // ================= [END DIUBAH] =================


    // 1. Menampilkan Daftar Pegawai
    public function kelolaPegawai()
    {
        $tahun = date('Y');


        $pegawai = Pegawai::with(['saldoCuti' => function ($query) use ($tahun) {
            $query->where('tahun', $tahun);
        }])
            ->where('role_id', '!=', self::ROLE_ID_ADMIN)
            ->orderBy('nama', 'asc')
            ->get();


        $pegawai = $this->urutkanSesuaiHierarki($pegawai);


        return Inertia::render('Admin/KelolaPegawai', [
            'pegawai' => $pegawai,
            'tahunBerjalan' => $tahun,
        ]);
    }


    // ---> Menyimpan Data Pegawai Baru dari Modal <---
    public function storePegawai(Request $request)
    {
        $request->validate([
            'nip' => ['required', 'string', 'max:50', 'unique:pegawais,nip'],
            'nama' => ['required', 'string', 'max:255'],
            'kelompok_substansi' => ['required', 'string', 'max:255'],
            'tim_kerja' => ['nullable', 'string', 'max:255'],
            'jabatan' => ['required', 'string', 'max:255'],
            'role_id' => ['required', 'integer'],
            'tanggal_masuk' => ['nullable', 'date'],
            'no_telepon' => ['nullable', 'string', 'max:25'],
            'alamat_domisili' => ['nullable', 'string'],
            'kuota_tahunan' => ['nullable', 'integer', 'min:0'],
            'sisa' => ['nullable', 'integer', 'min:0'],
            'carry_forward_normal' => ['nullable', 'integer', 'min:0'],
        ]);


        $tanggalMasuk = $request->tanggal_masuk
            ?? $this->extractTanggalMasukDariNip($request->nip)
            ?? now()->format('Y-m-d');


        $pegawai = Pegawai::create([
            'nip' => $request->nip,
            'nama' => $request->nama,
            'kelompok_substansi' => $request->kelompok_substansi,
            'tim_kerja' => $request->tim_kerja,
            'jabatan' => $request->jabatan,
            'role_id' => $request->role_id,
            'tanggal_masuk' => $tanggalMasuk,
            'no_telepon' => $request->no_telepon,
            'alamat_domisili' => $request->alamat_domisili,
            'password' => Hash::make('password123'),
            'is_first_login' => true,
        ]);


        $tahunIni = (int) date('Y');
        $kuotaTahunan = $request->kuota_tahunan ?? 12;


        SaldoCuti::create([
            'pegawai_id' => $pegawai->id,
            'tahun' => $tahunIni,
            'kuota_tahunan' => $kuotaTahunan,
            'sisa' => $this->hitungSisaOtomatis($pegawai->id, $tahunIni, $kuotaTahunan),
            'carry_forward_normal' => $request->carry_forward_normal ?? 0,
        ]);


        return back()->with('success', 'Data pegawai berhasil ditambahkan.');
    }


    // ---> Mengupdate Data Pegawai dari Modal Edit <---
    public function updatePegawai(Request $request, int $id)
    {
        $pegawai = Pegawai::findOrFail($id);


        $request->validate([
            'nip' => ['required', 'string', 'max:50', 'unique:pegawais,nip,' . $id],
            'nama' => ['required', 'string', 'max:255'],
            'kelompok_substansi' => ['required', 'string', 'max:255'],
            'tim_kerja' => ['nullable', 'string', 'max:255'],
            'jabatan' => ['required', 'string', 'max:255'],
            'role_id' => ['required', 'integer'],
            'tanggal_masuk' => ['nullable', 'date'],
            'no_telepon' => ['nullable', 'string', 'max:25'],
            'alamat_domisili' => ['nullable', 'string'],
            'kuota_tahunan' => ['nullable', 'integer', 'min:0'],
            'sisa' => ['nullable', 'integer', 'min:0'],
            'carry_forward_normal' => ['nullable', 'integer', 'min:0'],
        ]);


        $pegawai->update([
            'nip' => $request->nip,
            'nama' => $request->nama,
            'kelompok_substansi' => $request->kelompok_substansi,
            'tim_kerja' => $request->tim_kerja,
            'jabatan' => $request->jabatan,
            'role_id' => $request->role_id,
            ...$request->filled('tanggal_masuk') ? ['tanggal_masuk' => $request->tanggal_masuk] : [],
            ...$request->filled('no_telepon') ? ['no_telepon' => $request->no_telepon] : [],
            ...$request->filled('alamat_domisili') ? ['alamat_domisili' => $request->alamat_domisili] : [],
        ]);


        if ($request->filled('kuota_tahunan') || $request->filled('sisa') || $request->filled('carry_forward_normal')) {
            $tahunIni = (int) date('Y');


            $saldoSaatIni = SaldoCuti::where('pegawai_id', $pegawai->id)
                ->where('tahun', $tahunIni)
                ->first();


            $kuotaTahunan = $request->filled('kuota_tahunan')
                ? (int) $request->kuota_tahunan
                : ($saldoSaatIni->kuota_tahunan ?? 12);


            $carryForward = $request->filled('carry_forward_normal')
                ? (int) $request->carry_forward_normal
                : ($saldoSaatIni->carry_forward_normal ?? 0);


            $sisaTerhitung = $this->hitungSisaOtomatis($pegawai->id, $tahunIni, $kuotaTahunan);


            SaldoCuti::updateOrCreate(
                ['pegawai_id' => $pegawai->id, 'tahun' => $tahunIni],
                [
                    'kuota_tahunan' => $kuotaTahunan,
                    'sisa' => $sisaTerhitung,
                    'carry_forward_normal' => $carryForward,
                ]
            );
        }


        return back()->with('success', 'Data pegawai berhasil diperbarui.');
    }


    // ---> Import Data Pegawai dari Excel/CSV <---
    // Nama, Kelompok Substansi, Tim Kerja, dan Jabatan otomatis dirapikan ke Title
    // Case lewat toTitleCase() supaya tidak tersimpan dalam ALL CAPS meski
    // file sumbernya ditulis full huruf besar.
    //
    // ---> DIPERBAIKI (v2): sebelumnya pakai Pegawai::updateOrCreate(['nip'
    // => $nip], $dataUmum) supaya NIP yang sudah ada tidak memicu
    // UniqueConstraintViolationException. Tapi pendekatan itu menimbulkan
    // bug baru: untuk pegawai BARU, updateOrCreate() langsung menjalankan
    // INSERT dari $dataUmum SAJA (tanpa kolom `password`), padahal kolom
    // `password` di tabel `pegawais` NOT NULL tanpa default value ->
    // MySQL menolak INSERT itu dengan error 1364 SEBELUM sempat sampai ke
    // pengecekan wasRecentlyCreated/save() untuk mengisi password.
    //
    // Sekarang dipakai pengecekan manual (cek dulu by NIP, baru
    // create/update) supaya:
    //   - Kalau NIP sudah terdaftar -> $pegawai->update($dataUmum), data
    //     umum (nama, jabatan, kelompok substansi, tim kerja, role_id,
    //     tanggal_masuk) DIPERBARUI mengikuti isi file yang baru diimpor,
    //     TANPA menimpa nip/password/is_first_login yang sudah berjalan.
    //   - Kalau NIP belum ada -> Pegawai::create() dipanggil dengan `nip`,
    //     `password`, DAN `is_first_login` LANGSUNG disertakan semua di
    //     payload-nya (bukan disusulkan lewat save() terpisah setelah
    //     insert), supaya tidak ada celah INSERT tanpa kolom-kolom yang
    //     NOT NULL tanpa default value tsb. (Riwayat bug: percobaan
    //     pertama lupa menyertakan `password` -> error 1364 pada kolom
    //     password; percobaan kedua lupa menyertakan `nip` di $dataUmum
    //     -> error 1364 berikutnya pada kolom nip; percobaan ketiga
    //     pengecekan NIP tidak memakai withTrashed() -> pegawai yang
    //     sudah di-soft-delete tidak terdeteksi, sistem coba create() baru
    //     -> UniqueConstraintViolationException karena NIP masih terikat
    //     unique constraint. Sekarang ketiganya sudah diperbaiki.)
    // SaldoCuti tahun berjalan memakai SaldoCuti::firstOrCreate(): kalau
    // baris SaldoCuti pegawai tsb di tahun ini sudah ada, dibiarkan apa
    // adanya (sisa cuti yang sudah terpakai TIDAK ikut ke-reset balik ke
    // kuota penuh saat re-import); kalau belum ada (termasuk untuk pegawai
    // lama yang entah kenapa belum kebagian baris SaldoCuti tahun ini),
    // baru dibuatkan otomatis dengan kuota default 12 hari.
    public function importPegawai(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
        ]);


        $path = $request->file('file')->getRealPath();
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);


        if (empty($rows)) {
            return back()->with('error', 'File kosong atau tidak terbaca.');
        }


        $header = array_map(fn($h) => strtolower(trim((string) $h)), $rows[0]);


        $findCol = function (array $aliases) use ($header) {
            foreach ($aliases as $alias) {
                $idx = array_search($alias, $header, true);
                if ($idx !== false) {
                    return $idx;
                }
            }
            return false;
        };


        $idxNip = $findCol(['nip']);
        $idxNama = $findCol(['nama']);
        $idxKelompokSubstansi = $findCol(['kelompok substansi', 'subbagian/kelompok']);
        $idxTimKerja = $findCol(['tim kerja']);
        $idxJabatan = $findCol(['jabatan']);
        $idxRoleOrLevel = $findCol(['role', 'level', 'hak akses']);


        if ($idxNip === false || $idxNama === false || $idxJabatan === false || $idxRoleOrLevel === false) {
            return back()->with('error', 'Header file tidak sesuai. Pastikan ada kolom: NIP, Nama, Jabatan, dan Role/Level.');
        }


        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $tahunIni = (int) date('Y');


        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];


            $nip = trim((string) ($row[$idxNip] ?? ''));
            $nama = $this->toTitleCase((string) ($row[$idxNama] ?? ''));
            $jabatan = $this->toTitleCase((string) ($row[$idxJabatan] ?? ''));
            $roleTeks = trim((string) ($row[$idxRoleOrLevel] ?? '')); // dipetakan ke role_id, tidak perlu title case
            $kelompokSubstansi = $idxKelompokSubstansi !== false ? $this->toTitleCase((string) ($row[$idxKelompokSubstansi] ?? '')) : '';
            $timKerja = $idxTimKerja !== false ? $this->toTitleCase((string) ($row[$idxTimKerja] ?? '')) : null;


            if ($timKerja === '-' || $timKerja === '') {
                $timKerja = null;
            }


            if ($nip === '' && $nama === '') {
                continue;
            }


            if ($nip === '' || $nama === '' || $jabatan === '' || $roleTeks === '') {
                $skipped++;
                continue;
            }


            $roleId = $this->mapRoleOrLevelTeks($roleTeks);
            if ($roleId === null) {
                $skipped++;
                continue;
            }


            $tanggalMasuk = $this->extractTanggalMasukDariNip($nip) ?? now()->format('Y-m-d');


            // 1) Cek dulu apakah NIP sudah terdaftar. Pendekatan manual ini
            //    dipakai (bukan updateOrCreate) supaya kolom `password` bisa
            //    disertakan LANGSUNG di dalam create() untuk pegawai baru.
            //    updateOrCreate() sebelumnya gagal karena INSERT awal tidak
            //    menyertakan `password`, padahal kolom itu NOT NULL tanpa
            //    default value di database -> query ditolak MySQL (error
            //    1364) sebelum sempat sampai ke pengecekan wasRecentlyCreated.
            // 1) Cek dulu apakah NIP sudah terdaftar. PAKAI withTrashed()
            //    supaya pegawai yang sudah di-soft-delete (diarsipkan lewat
            //    destroyPegawai()) TETAP TERDETEKSI di sini. Kalau memakai
            //    where('nip', ...) biasa (tanpa withTrashed), Eloquent
            //    otomatis menyembunyikan baris yang sudah soft-deleted,
            //    sehingga sistem mengira NIP itu belum ada -> mencoba
            //    create() baru -> ditolak MySQL dengan
            //    UniqueConstraintViolationException (23000) karena baris
            //    lamanya, meski sudah "diarsipkan", masih ada secara fisik
            //    di tabel dan tetap terikat unique constraint kolom nip.
            $pegawai = Pegawai::withTrashed()->where('nip', $nip)->first();


            $dataUmum = [
                'nama' => $nama,
                'kelompok_substansi' => $kelompokSubstansi ?: '-',
                'tim_kerja' => $timKerja,
                'jabatan' => $jabatan,
                'role_id' => $roleId,
                'tanggal_masuk' => $tanggalMasuk,
            ];


            if ($pegawai) {
                // NIP sudah ada -> update data umum saja. Kolom `nip` SENGAJA
                // tidak diikutkan di update ini (dan tidak perlu), karena NIP
                // dipakai sebagai kunci pencarian dan tidak berubah.
                // password & is_first_login TIDAK disentuh sama sekali.
                //
                // Kalau baris ini sebelumnya soft-deleted (pernah dihapus/
                // diarsipkan lewat destroyPegawai()), otomatis DIPULIHKAN
                // (restore()) begitu muncul lagi di file import -> masuk
                // akal karena mengimpor NIP yang sama artinya pegawai itu
                // aktif kembali / datanya perlu direfresh.
                if ($pegawai->trashed()) {
                    $pegawai->restore();
                }
                $pegawai->update($dataUmum);
                $isBaru = false;
            } else {
                // NIP belum ada -> buat baru, password default & flag wajib
                // ganti password disertakan LANGSUNG di sini (bukan lewat
                // save() terpisah setelah insert), supaya tidak ada celah
                // INSERT tanpa kolom password.
                // NIP belum ada -> buat baru. `nip` DISERTAKAN LANGSUNG di
                // sini (bukan cuma dipakai di where() pengecekan di atas),
                // karena kolom `nip` NOT NULL tanpa default value. Ini juga
                // yang jadi penyebab error 1364 "Field 'nip' doesn't have a
                // default value" sebelumnya -> $dataUmum tidak berisi nip
                // sama sekali sehingga hilang dari query INSERT.
                $pegawai = Pegawai::create([
                    'nip' => $nip,
                    ...$dataUmum,
                    'password' => Hash::make('password123'),
                    'is_first_login' => true,
                ]);
                $isBaru = true;
            }


            // 2) Daftarkan ke Rekap Kuota Cuti tahun berjalan kalau belum
            //    ada (firstOrCreate) -> saldo pegawai lama yang sudah
            //    berkurang tidak ikut ke-reset balik ke kuota penuh.
            $kuotaTahunan = 12;
            SaldoCuti::firstOrCreate(
                [
                    'pegawai_id' => $pegawai->id,
                    'tahun' => $tahunIni,
                ],
                [
                    'kuota_tahunan' => $kuotaTahunan,
                    'sisa' => $this->hitungSisaOtomatis($pegawai->id, $tahunIni, $kuotaTahunan),
                    'carry_forward_normal' => 0,
                ]
            );


            if ($isBaru) {
                $inserted++;
            } else {
                $updated++;
            }
        }


        return back()->with(
            'success',
            "Berhasil mengimpor: {$inserted} pegawai baru ditambahkan, {$updated} pegawai diperbarui. {$skipped} baris dilewati (data kosong/role-level tidak dikenali)."
        );
    }


    private function mapRoleOrLevelTeks(string $teks): ?int
    {
        $key = strtolower(trim($teks));


        return match (true) {
            $key === 'staff', $key === 'staf' => 1,
            $key === 'level 1' => 2,
            $key === 'level 2' => 3,
            $key === 'level 3' => 4,
            $key === 'level 4' => 6,
            str_contains($key, 'kepala biro') => 6,
            str_contains($key, 'kasubag') => 4,
            str_contains($key, 'ketua kelompok') => 3,
            str_contains($key, 'ketua tim') => 2,
            str_contains($key, 'admin') => 5,
            str_contains($key, 'karyawan'), str_contains($key, 'staf'), str_contains($key, 'staff') => 1,
            default => null,
        };
    }


    // ---> Reset Password Pegawai yang Lupa Sandi <---
    public function resetPasswordPegawai(int $id)
    {
        $pegawai = Pegawai::findOrFail($id);


        $pegawai->update([
            'password' => Hash::make('password123'),
            'is_first_login' => true,
        ]);


        return back()->with('success', "Password untuk {$pegawai->nama} berhasil direset ke default. Pegawai wajib menggantinya saat login berikutnya.");
    }


    // ---> Menghapus (Mengarsipkan) Data Pegawai — Soft Delete <---
    // Dipanggil oleh Admin HR lewat tombol "Hapus" di halaman Kelola Pegawai
    // (route DELETE /admin/pegawai/{id}, name: admin.pegawai.destroy).
    //
    // Model Pegawai memakai trait SoftDeletes (lihat app/Models/Pegawai.php).
    // Karena itu, pemanggilan $pegawai->delete() di bawah ini TIDAK
    // menghapus baris secara permanen — Eloquent otomatis hanya mengisi
    // kolom `deleted_at` pegawai tersebut. Efeknya:
    //   1) Pegawai langsung hilang dari tabel Kelola Pegawai, karena
    //      SoftDeletes menambahkan filter `WHERE deleted_at IS NULL` secara
    //      otomatis ke SEMUA query Pegawai (termasuk kelolaPegawai(),
    //      buildLaporanData(), dsb — tidak perlu diubah manual satu-satu).
    //   2) Data pegawai, beserta riwayat SaldoCuti & PengajuanCuti miliknya,
    //      SECARA FISIK TETAP ADA di database (tidak hilang, tidak error
    //      foreign key), sehingga bisa dipulihkan lagi kapan saja lewat
    //      Pegawai::withTrashed()->find($id)->restore() bila diperlukan.
    //
    // Tidak ada baris SaldoCuti::where(...)->delete() di sini secara
    // sengaja: karena pegawai hanya diarsipkan (baris pegawai TETAP ADA),
    // data saldo_cutis-nya justru harus DIPERTAHANKAN supaya konsisten
    // dengan tujuan Soft Delete — riwayat & laporan cuti di masa lalu tetap
    // utuh. Tidak ada model `User` terpisah di aplikasi ini — akun login
    // (password, is_first_login) melekat langsung pada model Pegawai, jadi
    // begitu soft delete berjalan, baris ini otomatis tersembunyi dari query
    // normal (termasuk proses login), sehingga akun praktis tidak bisa lagi
    // dipakai masuk, tanpa perlu menyentuh tabel lain.
    public function destroyPegawai(int $id)
    {
        $pegawai = Pegawai::findOrFail($id);


        // Soft delete: hanya mengisi kolom `deleted_at` (butuh trait
        // SoftDeletes aktif di model Pegawai + kolom `deleted_at` sudah
        // ada di tabel `pegawais` lewat migration). Data pegawai & seluruh
        // riwayat terkait (SaldoCuti, PengajuanCuti) tetap tersimpan utuh.
        $pegawai->delete();


        return back()->with('success', 'Pegawai berhasil dihapus dan diarsipkan.');
    }


    // 2. Menampilkan Daftar Saldo Cuti Pegawai Tahun Ini
    //
    // ================= [DIUBAH] TAMBAHAN: PREVIEW CARRY-OVER (2 SUMBER) =================
    // Setiap pegawai sekarang dilengkapi field `preview_carry_over` (array
    // berisi `dari_saldo_biasa`, `dari_penangguhan_resmi`, `total`) —
    // perkiraan carry-over yang AKAN didapat pegawai tersebut andai saldo
    // tahun INI digenerate ulang lewat Command GenerateSaldoCutiTahunBaru.
    // Ini HANYA preview/informasi untuk Admin, BUKAN nilai yang otomatis
    // tersimpan — angka `sisa`/`kuota_tahunan` yang benar-benar aktif
    // tetap yang ada di kolom database (`saldo_cutis`), yang bisa diedit
    // manual di tabel atau digenerate ulang lewat tombol "Generate Saldo
    // Tahun Baru".
    public function kelolaSaldo()
    {
        $tahun = date('Y');


        $pegawai = Pegawai::with(['saldoCuti' => function ($query) use ($tahun) {
            $query->where('tahun', $tahun);
        }])->orderBy('nama', 'asc')->get();


        $pegawai->each(function ($p) use ($tahun) {
            $p->preview_carry_over = $this->hitungPreviewCarryOver($p->id, $tahun);
        });


        return Inertia::render('Admin/KelolaSaldo', [
            'pegawai' => $pegawai,
            'tahun' => $tahun
        ]);
    }
    // ================= [END DIUBAH] =================


    // 3. Memperbarui atau Membuat Saldo Cuti Baru
    //
    // ================= [DIUBAH] CATATAN AUDIT KE HISTORI SALDO =================
    // Kolom `ditangguhkan_resmi` yang sebelumnya ada di sini SUDAH TIDAK
    // DIPAKAI — penangguhan resmi sekarang tercatat otomatis lewat tabel
    // `cuti_ditangguhkans` (dibuat oleh PembatalanController@process saat
    // L4 menangguhkan sebuah pengajuan cuti), BUKAN ditandai manual lewat
    // checkbox di halaman ini. Lihat GenerateSaldoCutiTahunBaru untuk
    // detail lengkap.
    //
    // TAMBAHAN: setiap kali Admin mengubah `sisa` secara manual dari
    // angka yang sudah ada sebelumnya, perubahan itu dicatat sebagai satu
    // baris baru di `histori_saldos` (tabel audit yang sudah lama
    // dirancang tapi belum pernah dipakai) — supaya ada jejak siapa yang
    // mengubah, kapan, dan berapa besar penyesuaiannya. Baris histori
    // HANYA dibuat kalau nilai `sisa` benar-benar berubah dari sebelumnya
    // (bukan setiap kali tombol Simpan ditekan tanpa perubahan apa pun).
    public function updateSaldo(Request $request, int $id)
    {
        abort_unless((int) $request->user()->role_id === 5, 403);

        $request->validate([
            'kuota_tahunan' => ['required', 'numeric', 'min:0'],
            'carry_forward_normal' => ['nullable', 'numeric', 'min:0', 'required_without:sisa'],
            'sisa' => ['nullable', 'numeric', 'min:0', 'required_without:carry_forward_normal'],
        ]);


        $tahun = date('Y');


        $saldoSebelumnya = SaldoCuti::where('pegawai_id', $id)->where('tahun', $tahun)->first();
        $sisaSebelumnya = $saldoSebelumnya->sisa ?? null;
        $sisa = $request->has('sisa')
            ? $request->sisa
            : ($saldoSebelumnya->sisa ?? 0);
        $carryForward = $request->has('carry_forward_normal')
            ? $request->carry_forward_normal
            : ($saldoSebelumnya->carry_forward_normal ?? 0);


        $saldo = SaldoCuti::updateOrCreate(
            ['pegawai_id' => $id, 'tahun' => $tahun],
            [
                'kuota_tahunan' => $request->kuota_tahunan,
                'sisa' => $sisa,
                'carry_forward_normal' => $carryForward,
            ]
        );


        // Catat ke histori HANYA kalau ada baris sebelumnya DAN nilai
        // sisa benar-benar berubah (bukan pertama kali dibuat, dan bukan
        // simpan tanpa perubahan apa pun).
        if ($sisaSebelumnya !== null && (int) $sisaSebelumnya !== (int) $sisa) {
            HistoriSaldo::create([
                'saldo_cuti_id' => $saldo->id,
                'pegawai_id' => $id,
                'jumlah_penyesuaian' => (int) $sisa - (int) $sisaSebelumnya,
                'alasan' => 'Penyesuaian manual oleh Admin HR lewat halaman Kelola Saldo Cuti.',
                'diubah_oleh' => auth()->id(),
                'tanggal_perubahan' => now(),
            ]);
        }


        return back()->with('success', 'Saldo cuti berhasil diperbarui.');
    }
    // ================= [END DIUBAH] =================


    // ================= [BARU] GENERATE SALDO TAHUN BARU (TOMBOL MANUAL) =================
    // Dipanggil dari tombol "Generate Saldo Tahun Baru" di halaman Kelola
    // Saldo Cuti. Method ini SENGAJA tidak menulis ulang logika
    // perhitungan carry-over di sini — cukup memanggil Artisan Command
    // `saldo-cuti:generate-tahun-baru` yang SAMA PERSIS dengan yang
    // dijalankan otomatis oleh scheduler setiap 1 Januari (lihat
    // routes/console.php & app/Console/Commands/GenerateSaldoCutiTahunBaru.php).
    // Dengan begini, hasil generate manual dan otomatis SELALU identik,
    // tidak mungkin tidak sinkron karena keduanya memanggil kode yang
    // sama persis.
    //
    // Tombol ini berguna sebagai CADANGAN kalau scheduler otomatis gagal
    // jalan (misalnya server belum dipasang cron job saat masih di
    // tahap development/XAMPP lokal), atau kalau Admin perlu meng-generate
    // ulang tahun tertentu setelah ada koreksi data histori.
    public function generateSaldoManual(Request $request)
    {
        abort_unless((int) $request->user()->role_id === 5, 403);

        $tahun = $request->input('tahun', date('Y'));

        Artisan::call('saldo-cuti:generate-tahun-baru', [
            '--tahun' => $tahun,
        ]);

        return back()->with('success', "Saldo cuti tahun {$tahun} berhasil digenerate ulang untuk semua pegawai.");
    }
    // ================= [END BARU] =================


    // 4. Menampilkan Rekap Laporan Cuti Seluruh Pegawai (Rekap Per Bulan, dengan pagination)
    public function rekapLaporan(Request $request)
    {
        // paginate=true, ambil 10 pegawai per halaman
        $data = $this->buildLaporanData($request, true);


        return Inertia::render('Admin/RekapLaporan', [
            'laporan' => $data['laporan'],
            'tahun' => $data['tahun'],
            'filters' => $request->only(['search', 'tahun', 'bulan', 'kelompok_substansi']),
            'daftarKelompokSubstansi' => Pegawai::where('role_id', '!=', self::ROLE_ID_ADMIN)
                ->whereNotNull('kelompok_substansi')
                ->where('kelompok_substansi', '!=', '')
                ->distinct()
                ->orderBy('kelompok_substansi')
                ->pluck('kelompok_substansi'),
            // info pagination dikirim ke frontend
            'pagination' => [
                'current_page' => $data['paginator']->currentPage(),
                'last_page' => $data['paginator']->lastPage(),
                'per_page' => $data['paginator']->perPage(),
                'total' => $data['paginator']->total(),
            ],
        ]);
    }


    // 5. Export Data Rekap Cuti ke Excel
    public function exportExcel(Request $request)
    {
        // Export TETAP mengambil semua data (paginate=false / default),
        // karena file Excel yang diunduh harus berisi seluruh pegawai,
        // bukan cuma 10 baris dari halaman yang sedang aktif di layar.
        $data = $this->buildLaporanData($request);
        $laporan = $data['laporan'];
        $tahun = $data['tahun'];
        $bulanFilter = $data['bulanFilter'];


        if ($bulanFilter && isset($this->keyBulan[$bulanFilter - 1])) {
            $exportKeyBulan = [$this->keyBulan[$bulanFilter - 1]];
            $exportLabelBulan = [$this->labelBulan[$bulanFilter - 1]];
        } else {
            $exportKeyBulan = $this->keyBulan;
            $exportLabelBulan = $this->labelBulan;
        }


        $bulanBerjalanIdx = null;
        if ($tahun == date('Y')) {
            $currentKey = $this->keyBulan[(int) date('n') - 1];
            $idxInExport = array_search($currentKey, $exportKeyBulan);
            $bulanBerjalanIdx = $idxInExport !== false ? $idxInExport : null;
        }


        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle("Rekap Cuti {$tahun}");


        $nBulan = count($exportLabelBulan);
        $bulanStartCol = 5; // E
        $totalCol = $bulanStartCol + $nBulan;
        $lastColLetter = Coordinate::stringFromColumnIndex($totalCol);


        // ---------- Warna ----------
        $GREEN_DARK   = '1D9E75';
        $GREEN_LIGHT  = 'E1F5EE';
        $GREEN_HEADER = '97C459';
        $GREEN_TOTAL  = '639922';
        $AMBER        = 'FAC775';
        $BLUE_BADGE   = '85B7EB';
        $PURPLE_TINGGI = 'AFA9EC';
        $PURPLE_MUDA   = 'CECBF6';
        $GRAY_STAF    = 'D3D1C7';
        $TOTAL_ROW_BG = 'FAEEDA';
        $ZEBRA        = 'F4F8F0';


        // ---------- Judul ----------
        $sheet->mergeCells("A1:{$lastColLetter}1");
        $sheet->setCellValue('A1', 'REKAPITULASI CUTI TAHUNAN PEGAWAI — TAHUN ' . $tahun);
        $sheet->getStyle('A1')->getFont()->setName('Arial')->setSize(14)->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($GREEN_DARK);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(26);


        $sheet->mergeCells("A2:{$lastColLetter}2");
        $sheet->setCellValue('A2', 'Kementerian Pertanian RI — Biro Organisasi dan SDM Aparatur');
        $sheet->getStyle('A2')->getFont()->setName('Arial')->setSize(10)->getColor()->setRGB('04342C');
        $sheet->getStyle('A2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($GREEN_LIGHT);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(2)->setRowHeight(18);
        $sheet->getRowDimension(3)->setRowHeight(6);


        // ---------- Header 2 tingkat ----------
        $headerRow1 = 4;
        $headerRow2 = 5;
        $headersFixed = ['No', 'Nama Pegawai', 'NIP', 'Jabatan'];


        foreach ($headersFixed as $idx => $label) {
            $col = $idx + 1;
            $letter = Coordinate::stringFromColumnIndex($col);
            $sheet->mergeCells("{$letter}{$headerRow1}:{$letter}{$headerRow2}");
            $sheet->setCellValue("{$letter}{$headerRow1}", $label);
            $style = $sheet->getStyle("{$letter}{$headerRow1}:{$letter}{$headerRow2}");
            $style->getFont()->setName('Arial')->setSize(10)->setBold(true)->getColor()->setRGB('FFFFFF');
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('26215C');
            $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('B4B2A9');
        }


        $bulanStartLetter = Coordinate::stringFromColumnIndex($bulanStartCol);
        $bulanEndLetter = Coordinate::stringFromColumnIndex($bulanStartCol + $nBulan - 1);
        $sheet->mergeCells("{$bulanStartLetter}{$headerRow1}:{$bulanEndLetter}{$headerRow1}");
        $sheet->setCellValue("{$bulanStartLetter}{$headerRow1}", 'Jumlah Hari Cuti per Bulan');
        $sheet->getStyle("{$bulanStartLetter}{$headerRow1}:{$bulanEndLetter}{$headerRow1}")->applyFromArray([
            'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $GREEN_HEADER]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
        ]);


        foreach ($exportLabelBulan as $i => $label) {
            $col = $bulanStartCol + $i;
            $letter = Coordinate::stringFromColumnIndex($col);
            $sheet->setCellValue("{$letter}{$headerRow2}", $label);
            $isCurrent = $bulanBerjalanIdx !== null && $i === $bulanBerjalanIdx;
            $sheet->getStyle("{$letter}{$headerRow2}")->applyFromArray([
                'font' => [
                    'name' => 'Arial',
                    'size' => 10,
                    'bold' => true,
                    'color' => ['rgb' => $isCurrent ? '412402' : 'FFFFFF'],
                ],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $isCurrent ? $AMBER : $GREEN_HEADER]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
            ]);
        }


        $totalLetter = Coordinate::stringFromColumnIndex($totalCol);
        $sheet->mergeCells("{$totalLetter}{$headerRow1}:{$totalLetter}{$headerRow2}");
        $sheet->setCellValue("{$totalLetter}{$headerRow1}", 'Total Cuti');
        $sheet->getStyle("{$totalLetter}{$headerRow1}")->applyFromArray([
            'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $GREEN_TOTAL]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
        ]);


        $sheet->getRowDimension($headerRow1)->setRowHeight(18);
        $sheet->getRowDimension($headerRow2)->setRowHeight(16);


        // ---------- Data ----------
        $dataStartRow = 6;
        $r = $dataStartRow;


        foreach ($laporan as $i => $item) {
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
            $sheet->setCellValueExplicit("C{$r}", $item['nip'], DataType::TYPE_STRING);
            $sheet->setCellValue("D{$r}", $item['jabatan'] ?? '-');


            $sheet->getStyle("A{$r}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 10],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
                'fill' => $zebra ? ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $zebra]] : [],
            ]);
            $sheet->getStyle("B{$r}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 10],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
                'fill' => $zebra ? ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $zebra]] : [],
            ]);
            $sheet->getStyle("C{$r}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 10],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
                'fill' => $zebra ? ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $zebra]] : [],
            ]);
            $sheet->getStyle("D{$r}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 9, 'bold' => true, 'color' => ['rgb' => $jabatanTextColor]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $jabatanColor]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
            ]);


            foreach ($exportKeyBulan as $j => $key) {
                $col = $bulanStartCol + $j;
                $letter = Coordinate::stringFromColumnIndex($col);
                $val = $item['cuti'][$key] ?? 0;
                $sheet->setCellValue("{$letter}{$r}", $val);


                if ($val > 0) {
                    $sheet->getStyle("{$letter}{$r}")->applyFromArray([
                        'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => ['rgb' => '042C53']],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $BLUE_BADGE]],
                    ]);
                } else {
                    $sheet->getStyle("{$letter}{$r}")->applyFromArray([
                        'font' => ['name' => 'Arial', 'size' => 10, 'color' => ['rgb' => '888780']],
                        'fill' => $zebra ? ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $zebra]] : [],
                    ]);
                }
                $sheet->getStyle("{$letter}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
                $sheet->getStyle("{$letter}{$r}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('B4B2A9');
            }


            $sheet->setCellValue("{$totalLetter}{$r}", "=SUM({$bulanStartLetter}{$r}:{$bulanEndLetter}{$r})");
            $sheet->getStyle("{$totalLetter}{$r}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => ['rgb' => '173404']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EAF3DE']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
            ]);


            $r++;
        }


        $dataEndRow = $r - 1;


        // ---------- Baris Total Keseluruhan ----------
        $totalRow = $dataEndRow + 2;
        $sheet->mergeCells("A{$totalRow}:D{$totalRow}");
        $sheet->setCellValue("A{$totalRow}", 'Total Keseluruhan');
        $sheet->getStyle("A{$totalRow}:D{$totalRow}")->applyFromArray([
            'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => ['rgb' => '412402']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $TOTAL_ROW_BG]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
        ]);


        foreach ($exportKeyBulan as $j => $key) {
            $col = $bulanStartCol + $j;
            $letter = Coordinate::stringFromColumnIndex($col);
            $sheet->setCellValue("{$letter}{$totalRow}", "=SUM({$letter}{$dataStartRow}:{$letter}{$dataEndRow})");
            $sheet->getStyle("{$letter}{$totalRow}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => ['rgb' => '412402']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $TOTAL_ROW_BG]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
            ]);
        }


        $sheet->setCellValue("{$totalLetter}{$totalRow}", "=SUM({$totalLetter}{$dataStartRow}:{$totalLetter}{$dataEndRow})");
        $sheet->getStyle("{$totalLetter}{$totalRow}")->applyFromArray([
            'font' => ['name' => 'Arial', 'size' => 11, 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $GREEN_DARK]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
        ]);


        // ---------- Legenda warna (memanjang ke bawah) ----------
        $legendTitleRow = $totalRow + 2;
        $sheet->setCellValue("A{$legendTitleRow}", 'Keterangan warna:');
        $sheet->getStyle("A{$legendTitleRow}")->getFont()->setName('Arial')->setSize(9)->setItalic(true)->setBold(true)->getColor()->setRGB('5F5E5A');


        $legends = [
            ['Jabatan struktural (Kepala Biro / Kasubag)', $PURPLE_TINGGI],
            ['Ketua Kelompok / Ketua Tim Kerja', $PURPLE_MUDA],
            ['Staf', $GRAY_STAF],
            ['Ada cuti di bulan tersebut', $BLUE_BADGE],
            ['Bulan berjalan', $AMBER],
        ];


        foreach ($legends as $i => $item) {
            [$label, $color] = $item;
            $row = $legendTitleRow + 1 + $i;
            $sheet->getStyle("A{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($color);
            $sheet->getStyle("A{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('B4B2A9');
            $sheet->mergeCells("B{$row}:D{$row}");
            $sheet->setCellValue("B{$row}", $label);
            $sheet->getStyle("B{$row}")->getFont()->setName('Arial')->setSize(9)->getColor()->setRGB('2C2C2A');
            $sheet->getStyle("B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getRowDimension($row)->setRowHeight(16);
        }


        // ---------- Lebar kolom ----------
        $sheet->getColumnDimension('A')->setWidth(5);
        $sheet->getColumnDimension('B')->setWidth(38);
        $sheet->getColumnDimension('C')->setWidth(20);
        $sheet->getColumnDimension('D')->setWidth(22);
        foreach ($exportKeyBulan as $j => $key) {
            $letter = Coordinate::stringFromColumnIndex($bulanStartCol + $j);
            $sheet->getColumnDimension($letter)->setWidth(6);
        }
        $sheet->getColumnDimension($totalLetter)->setWidth(11);


        $sheet->freezePane('E' . $dataStartRow);


        $filename = "Rekap_Cuti_Tahunan_{$tahun}_" . date('Y-m-d') . ".xlsx";


        $writer = new Xlsx($spreadsheet);


        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }


    // 6. Fungsi Menangguhkan Cuti (Oleh Admin/L4)
    public function suspendCuti(Request $request, int $id)
    {
        abort_unless($request->user()->role_id === 6, 403, 'Hanya L4 yang dapat menangguhkan cuti.');


        $request->validate([
            'alasan' => ['required', 'string', 'max:255']
        ]);


        $pengajuan = PengajuanCuti::findOrFail($id);


        if ($pengajuan->status !== 'disetujui') {
            return back()->with('error', 'Hanya cuti yang telah disetujui yang dapat ditangguhkan.');
        }


        $tahunCuti = date('Y', strtotime($pengajuan->tanggal_mulai));
        $saldo = SaldoCuti::where('pegawai_id', $pengajuan->pegawai_id)
            ->where('tahun', $tahunCuti)
            ->first();


        if ($saldo) {
            $saldo->update([
                'sisa' => $saldo->sisa + $pengajuan->jumlah_hari
            ]);
        }


        $keteranganBaru = $pengajuan->keterangan . ' | [DITANGGUHKAN: ' . $request->alasan . ']';


        $pengajuan->update([
            'status' => 'dibatalkan_ditangguhkan',
            'keterangan' => $keteranganBaru
        ]);


        return back()->with('success', 'Cuti berhasil ditangguhkan dan saldo telah dikembalikan.');
    }


    // 7. Menampilkan Halaman Kelola Hari Libur
    public function kelolaLibur()
    {
        $libur = HariLibur::where('tanggal', '>=', Carbon::today()->toDateString())
            ->orderBy('tanggal', 'asc')
            ->paginate(7);


        return Inertia::render('Admin/KelolaLibur', [
            'libur' => $libur
        ]);
    }


    // 8. Menyimpan Data Hari Libur Baru
    public function storeLibur(Request $request)
    {
        $request->validate([
            'tanggal' => ['required', 'date', 'unique:hari_liburs,tanggal'],
            'keterangan' => ['required', 'string', 'max:255'],
            'is_cuti_bersama' => ['required', 'boolean'],
        ], [
            'tanggal.unique' => 'Tanggal ini sudah didaftarkan sebagai hari libur.'
        ]);


        HariLibur::create([
            'tanggal' => $request->tanggal,
            'keterangan' => $request->keterangan,
            'is_cuti_bersama' => $request->is_cuti_bersama,
        ]);


        return back();
    }


    // 9. Mengimpor Data Hari Libur dari File CSV
    public function importLiburCsv(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt'],
        ]);


        $file = $request->file('file');
        $path = $file->getRealPath();
        $handle = fopen($path, 'r');


        if ($handle === false) {
            return back()->with('error', 'File CSV tidak dapat dibuka.');
        }


        $delimiter = ',';
        $firstLine = fgets($handle);


        if ($firstLine !== false) {
            $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        }


        rewind($handle);


        $inserted = 0;
        $skipped = 0;
        $headerSkipped = false;


        while (($row = fgetcsv($handle, 1000, $delimiter)) !== false) {
            $row = array_map(function ($value) {
                return trim((string) $value);
            }, $row);


            if (empty(array_filter($row, fn($value) => $value !== ''))) {
                continue;
            }


            $firstCell = strtolower($row[0] ?? '');
            if (!$headerSkipped && in_array($firstCell, ['tanggal', 'date', 'tgl'])) {
                $headerSkipped = true;
                continue;
            }


            if (count($row) < 2) {
                $skipped++;
                continue;
            }


            $tanggal = $row[0];
            $keterangan = $row[1];
            $isCutiBersama = $row[2] ?? 'false';


            if (empty($tanggal) || empty($keterangan)) {
                $skipped++;
                continue;
            }


            try {
                $parsedDate = Carbon::parse($tanggal)->format('Y-m-d');
            } catch (\Exception $e) {
                $skipped++;
                continue;
            }


            if (HariLibur::whereDate('tanggal', $parsedDate)->exists()) {
                $skipped++;
                continue;
            }


            $jenis = strtolower((string) $isCutiBersama);
            $isCutiBersamaFlag = in_array($jenis, ['1', 'true', 'yes', 'ya', 'cuti bersama', 'cuti_bersama'], true);


            HariLibur::create([
                'tanggal' => $parsedDate,
                'keterangan' => $keterangan,
                'is_cuti_bersama' => $isCutiBersamaFlag ? 1 : 0,
            ]);


            $inserted++;
        }


        fclose($handle);


        return back()->with(
            'success',
            "Berhasil mengimpor {$inserted} hari libur dari CSV. {$skipped} baris dilewati."
        );
    }


    // 10. Menghapus Data Hari Libur
    public function destroyLibur(int $id)
    {
        HariLibur::findOrFail($id)->delete();
        return back();
    }
}
