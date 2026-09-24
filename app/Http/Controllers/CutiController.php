<?php

namespace App\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use App\Models\Notifikasi;
use App\Models\Pegawai;
use App\Models\HariLibur;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Carbon\Carbon;

// ================= CATATAN =================
// File ini adalah controller SISI KARYAWAN (form pengajuan cuti, riwayat,
// kalender tim, unduh PDF, batal, revisi). Logika approve/reject (termasuk
// perbaikan field 'alasan' & level_saat_ini) ada di app/Http/Controllers/
// MonitoringCutiController.php (controller SISI ATASAN), bukan di sini.
//
// ================= PERBAIKAN TERBARU (Unit Tanpa Level Approval L1) =================
// BUG YANG DITEMUKAN: staf di unit yang TIDAK memiliki pegawai berlevel L1
// (role_id 2) di departemennya — misalnya "Subbagian Tata Usaha", yang
// menurut struktur organisasinya staf langsung lapor ke Kasubag TU (L3)
// tanpa melalui Ketua Tim Kerja (L1) atau Ketua Kelompok Substansi (L2) —
// SEBELUMNYA selalu mendapat status awal hardcode 'menunggu_l1' di
// store() (dan 'menunggu_l1' juga di revisi()), TANPA mengecek apakah L1
// itu benar-benar ada di departemen mereka.
//
// Akibatnya: sistem gagal menemukan atasan L1 di departemen yang sama,
// lalu fallback ke `Pegawai::where('role_id', 2)->first()` yang mengambil
// L1 dari DEPARTEMEN LAIN secara asal. Pengajuan jadi macet permanen di
// status 'menunggu_l1' karena L1 "salah comot" itu akan selalu ditolak
// (abort 403) oleh MonitoringCutiController::process() saat mencoba
// approve, sebab departemennya tidak cocok dengan pemohon.
//
// ================= PERBAIKAN LANJUTAN (Staf Tanpa Tim Kerja, BEDA dari
// Unit Tanpa L1 Sama Sekali) =================
// TEMUAN LEBIH LANJUT (dari data pegawai riil, sheet "KELOMPOK KEBIJAKAN
// PEMBANGUNAN PERTANIAN" vs "SUBBAGIAN TATA USAHA"):
//
// Ternyata ada 3 skenario berbeda untuk staf (role_id 1), BUKAN cuma 2:
//   1. Staf yang punya tim_kerja DAN tim_kerja itu punya L1 (Ketua Tim
//      Kerja) -> alur normal: mulai dari L1.
//   2. Staf yang tim_kerja-nya KOSONG ("-"/null), TAPI kelompok_substansi
//      (departemen)-nya TETAP punya L2 (Ketua Kelompok Substansi) —
//      contoh nyata: TATI KOMARAWATI di Kelompok Kebijakan Pembangunan
//      Pertanian, tim_kerja "-", tapi kelompoknya punya L2 (DANI ABDUL
//      AZIZ). Staf seperti ini HARUS skip L1 saja dan mulai dari L2 —
//      BUKAN lompat sampai L3, karena L2 tetap ada dan berwenang.
//   3. Staf yang kelompok_substansi-nya sama sekali TIDAK punya L1 MAUPUN
//      L2 — contoh: seluruh staf di Subbagian Tata Usaha. Staf ini baru
//      benar-benar skip L1 & L2 sekaligus, mulai dari L3.
//
// BUG PADA VERSI SEBELUMNYA: helper tentukanLevelAwalCuti() versi lama
// hanya mengecek "apakah kelompok_substansi punya L1" (role_id 2, scoped
// ke kelompok_substansi). Ini keliru granularitasnya, karena wewenang L1
// di MonitoringCutiController::process() di-scope ketat per tim_kerja
// (bukan kelompok_substansi) — lihat pengecekan
// `$pengajuan->pegawai->tim_kerja !== $user->tim_kerja`. Akibatnya:
//   - Staf tim_kerja kosong di departemen yang PUNYA L1 (untuk tim lain)
//     akan lolos pengecekan lama (dianggap "departemen punya L1") dan
//     tetap dikirim ke 'menunggu_l1' -> MACET PERMANEN, karena tidak ada
//     L1 mana pun yang tim_kerja-nya cocok dengan staf ini (null/kosong
//     tidak akan pernah sama dengan tim_kerja L1 mana pun).
//
// PERBAIKAN: logic sekarang CASCADING dan di-scope dengan benar:
//   a. timKerjaPunyaAtasanL1($user->tim_kerja) — cek L1 berdasarkan
//      tim_kerja SPESIFIK milik staf (bukan kelompok_substansi). Kalau
//      ada -> mulai dari L1.
//   b. Kalau tidak ada L1 yang cocok, baru cek departemenPunyaAtasanLevel
//      (3, kelompok_substansi) — apakah L2 (role_id 3) ada di
//      kelompok_substansi staf ini. Kalau ada -> skip L1 saja, mulai dari
//      L2.
//   c. Kalau L1 maupun L2 sama-sama tidak ada -> skip keduanya, mulai
//      dari L3 (perilaku lama untuk kasus Subbagian TU tetap sama).
//
// Untuk Atasan yang mengajukan cuti untuk dirinya sendiri (role_id 2,3,4),
// logika PERSIS SAMA seperti sebelumnya (tidak diubah) — mereka mulai dari
// level SETELAH level mereka sendiri.
//
// Perbaikan yang sama (cascade L1 -> L2 -> L3) diterapkan di revisi(),
// yang juga memanggil tentukanLevelAwalCuti() yang sama.
//
// Flag `ada_atasan_l1` yang dikirim ke frontend lewat history() juga
// disesuaikan supaya konsisten: sekarang dihitung berdasarkan tim_kerja
// milik staf yang login (timKerjaPunyaAtasanL1), bukan lagi berdasarkan
// kelompok_substansi — karena makna flag ini adalah "apakah STAF INI akan
// melalui tahap L1", bukan "apakah ada L1 di departemen, entah untuk tim
// mana".
//
// (Semua catatan perbaikan sebelumnya — sinkronisasi saldo cuti, filter
// jenis cuti di riwayat, perhitungan hari libur nasional/cuti bersama,
// syarat masa kerja Cuti Besar — TETAP berlaku dan tidak diubah sama
// sekali oleh perbaikan ini.)
//
// ================= PERBAIKAN BARU (NAMA ATASAN L1 & L2 DI MODAL DETAIL) =================
// Sebelumnya Modal Detail Cuti di RiwayatPengajuan.vue hardcode nama
// generik "Ketua Tim Kerja Pegawai" / "Ketua Kelompok Substansi" untuk
// L1/L2 (beda dengan L3/L4 yang menampilkan nama pejabat sungguhan).
// Sekarang history() menghitung nama atasan L1/L2 MILIK $user (pemilik
// pengajuan) memakai accessor baru di model Pegawai
// (getKetuaTimKerjaAttribute / getKetuaKelompokAttribute), lalu
// menempelkannya ke $item->pegawai->nama_l1 / nama_l2 untuk setiap baris
// riwayat. Karena halaman ini SELALU berisi pengajuan milik $user
// sendiri, nilainya sama untuk semua baris — dihitung sekali di luar
// loop, bukan per baris.
// ================= END PERBAIKAN =================
// ================= END CATATAN =================
class CutiController extends Controller
{
    // Syarat minimal masa kerja (tahun) untuk bisa mengajukan Cuti Besar.
    private const MASA_KERJA_MINIMAL_CUTI_BESAR = 5;

    private function normalizeJenisCuti(?string $value)
    {
        if ($value === null) {
            return 'Cuti Tahunan';
        }

        $normalized = trim((string) $value);

        $map = [
            'cuti tahunan' => 'Cuti Tahunan',
            'cuti melahirkan' => 'Cuti Melahirkan',
            'cuti besar' => 'Cuti Besar',
            'cuti alasan penting' => 'Cuti Alasan Penting',
            'cuti_alasan_penting' => 'Cuti Alasan Penting',
        ];

        $lower = strtolower($normalized);

        return $map[$lower] ?? $normalized;
    }

    // ================= HELPER: MASA KERJA (SYARAT CUTI BESAR) =================
    private function hitungMasaKerjaTahun(Pegawai $pegawai): int
    {
        if (empty($pegawai->tanggal_masuk)) {
            return 0;
        }

        try {
            $tanggalMasuk = Carbon::parse($pegawai->tanggal_masuk);
        } catch (\Exception $e) {
            return 0;
        }

        return (int) $tanggalMasuk->diffInYears(Carbon::now());
    }
    // ================= END HELPER MASA KERJA =================

    // ================= HELPER: HARI LIBUR NASIONAL & CUTI BERSAMA =================
    private function getHariLiburDates(): array
    {
        return HariLibur::pluck('tanggal')
            ->map(function ($tgl) {
                return Carbon::parse($tgl)->toDateString();
            })
            ->all();
    }

    private function hitungHariKerja(string $mulai, string $selesai, ?array $hariLiburDates = null): int
    {
        $hariLiburDates = $hariLiburDates ?? $this->getHariLiburDates();

        $startDate = Carbon::parse($mulai);
        $endDate = Carbon::parse($selesai);
        $jumlahHari = 0;

        $currentDate = $startDate->copy();
        while ($currentDate->lte($endDate)) {
            $tanggalStr = $currentDate->toDateString();
            $isHariLibur = in_array($tanggalStr, $hariLiburDates, true);

            if (!$currentDate->isWeekend() && !$isHariLibur) {
                $jumlahHari++;
            }

            $currentDate->addDay();
        }

        return $jumlahHari;
    }
    // ================= END HELPER HARI LIBUR =================

    // ================= HELPER: STRUKTUR APPROVAL PER DEPARTEMEN =================
    // Dipakai untuk cek apakah suatu kelompok_substansi (departemen) punya
    // pegawai dengan role_id tertentu (dipakai untuk cek L2/role_id 3, dan
    // dulunya juga dipakai keliru untuk cek L1 — lihat catatan perbaikan
    // di atas class).
    private function departemenPunyaAtasanLevel(int $roleId, ?string $kelompokSubstansi): bool
    {
        if (empty($kelompokSubstansi)) {
            return false;
        }

        return Pegawai::where('role_id', $roleId)
            ->where('kelompok_substansi', $kelompokSubstansi)
            ->exists();
    }
    // ================= END HELPER STRUKTUR APPROVAL PER DEPARTEMEN =================

    // ================= HELPER BARU: CEK L1 SESUAI TIM KERJA SPESIFIK =================
    // Wewenang L1 di MonitoringCutiController::process() di-scope ketat per
    // tim_kerja (bukan kelompok_substansi). Jadi pengecekan "apakah staf ini
    // punya atasan L1" HARUS ikut di-scope ke tim_kerja miliknya sendiri —
    // kalau dicek pakai kelompok_substansi, staf tanpa tim_kerja (atau
    // tim_kerja tanpa L1) bisa salah dianggap "punya L1" hanya karena
    // kebetulan ada L1 lain di tim kerja berbeda dalam kelompok yang sama.
    private function timKerjaPunyaAtasanL1(?string $timKerja): bool
    {
        if (empty($timKerja)) {
            return false;
        }

        return Pegawai::where('role_id', 2)
            ->where('tim_kerja', $timKerja)
            ->exists();
    }
    // ================= END HELPER CEK L1 PER TIM KERJA =================

    private function tentukanLevelAwalCuti(Pegawai $user): array
    {
        if ($user->role_id === 2) {
            return ['status' => 'menunggu_l2', 'level' => 2, 'target_role' => 3];
        }
        if ($user->role_id === 3) {
            return ['status' => 'menunggu_l3', 'level' => 3, 'target_role' => 4];
        }
        if ($user->role_id === 4) {
            return ['status' => 'menunggu_l4', 'level' => 4, 'target_role' => 6];
        }

        // ================= STAF (role_id 1): CASCADE L1 -> L2 -> L3 =================
        // 1. Kalau staf punya tim_kerja DAN tim_kerja itu punya L1 -> mulai
        //    dari L1. (contoh: NURLELA, tim_kerja = "TIM KERJA KEBIJAKAN
        //    PERTANIAN", ada Ketua Tim Kerja-nya)
        if ($this->timKerjaPunyaAtasanL1($user->tim_kerja)) {
            return ['status' => 'menunggu_l1', 'level' => 1, 'target_role' => 2];
        }

        // 2. Kalau staf TIDAK punya L1 yang cocok (tim_kerja kosong, atau
        //    tim_kerja-nya memang tidak punya Ketua Tim Kerja), TAPI
        //    kelompok_substansi-nya tetap punya L2 (role_id 3) -> skip L1
        //    saja, mulai dari L2. (contoh: TATI KOMARAWATI, tim_kerja "-",
        //    tapi Kelompok Kebijakan Pembangunan Pertanian punya L2 yaitu
        //    DANI ABDUL AZIZ)
        if ($this->departemenPunyaAtasanLevel(3, $user->kelompok_substansi)) {
            return ['status' => 'menunggu_l2', 'level' => 2, 'target_role' => 3];
        }

        // 3. Kalau kelompok_substansi-nya sama sekali tidak punya L1 maupun
        //    L2 -> skip keduanya, langsung ke L3. (contoh: semua staf di
        //    SUBBAGIAN TATA USAHA)
        return ['status' => 'menunggu_l3', 'level' => 3, 'target_role' => 4];
        // ================= END CASCADE L1 -> L2 -> L3 =================
    }

    // 1. Menampilkan Halaman Form Pengajuan
    public function create()
    {
        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();
        $tahunCuti = date('Y');

        $cutiMenunggu = PengajuanCuti::where('pegawai_id', $user->id)
            ->where('jenis_cuti', 'Cuti Tahunan')
            ->whereIn('status', ['menunggu_l1', 'menunggu_l2', 'menunggu_l3', 'menunggu_l4'])
            ->whereYear('tanggal_mulai', $tahunCuti)
            ->sum('jumlah_hari');

        $saldo = SaldoCuti::where('pegawai_id', $user->id)->where('tahun', $tahunCuti)->first();

        $kuotaTahunan = $saldo ? $saldo->kuota_tahunan : ($user->jatah_cuti ?? 12);
        $sisaTahunLalu = $saldo ? $saldo->carry_forward_normal : 0;

        $cutiTerpakai = PengajuanCuti::where('pegawai_id', $user->id)
            ->where('jenis_cuti', 'Cuti Tahunan')
            ->where('status', 'disetujui')
            ->whereYear('tanggal_mulai', $tahunCuti)
            ->sum('jumlah_hari');

        $totalTersedia = ($kuotaTahunan + $sisaTahunLalu) - $cutiTerpakai;
        $sisaCutiAsli = $totalTersedia - $cutiMenunggu;

        $hariLiburs = HariLibur::orderBy('tanggal')
            ->get(['tanggal', 'keterangan', 'is_cuti_bersama'])
            ->map(function ($h) {
                return [
                    'tanggal' => Carbon::parse($h->tanggal)->toDateString(),
                    'keterangan' => $h->keterangan,
                    'is_cuti_bersama' => (bool) $h->is_cuti_bersama,
                ];
            });

        $masaKerjaTahun = $this->hitungMasaKerjaTahun($user);
        $bolehCutiBesar = $masaKerjaTahun >= self::MASA_KERJA_MINIMAL_CUTI_BESAR;

        return Inertia::render('Karyawan/AjukanCuti', [
            'sisa_cuti' => (int) $sisaCutiAsli,
            'total_cuti_tersedia' => (int) $totalTersedia,
            'hariLiburs' => $hariLiburs,
            'masa_kerja_tahun' => $masaKerjaTahun,
            'boleh_cuti_besar' => $bolehCutiBesar,
            'masa_kerja_minimal_cuti_besar' => self::MASA_KERJA_MINIMAL_CUTI_BESAR,
        ]);
    }

    // 2. Memproses Simpan Data
    public function store(Request $request)
    {
        $request->validate([
            'jenis_cuti' => ['required', 'string', 'in:Cuti Tahunan,Cuti Melahirkan,Cuti Besar,Cuti Alasan Penting'],
            'tanggal_mulai' => ['required', 'date', 'after_or_equal:today'],
            'tanggal_selesai' => ['required', 'date', 'after_or_equal:tanggal_mulai'],
            'keterangan' => ['required', 'string'],
            'alamat_cuti' => ['required', 'string'],
            'no_telp' => ['required', 'string', 'max:20'],
            'lampiran' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:2048'],
        ]);

        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();

        $jenisCuti = $this->normalizeJenisCuti($request->jenis_cuti);

        // ================= VALIDASI: SYARAT MASA KERJA UNTUK CUTI BESAR =================
        if ($jenisCuti === 'Cuti Besar') {
            $masaKerja = $this->hitungMasaKerjaTahun($user);

            if ($masaKerja < self::MASA_KERJA_MINIMAL_CUTI_BESAR) {
                return back()->withErrors([
                    'jenis_cuti' => 'Cuti Besar hanya dapat diajukan jika masa kerja sudah mencapai minimal '
                        . self::MASA_KERJA_MINIMAL_CUTI_BESAR
                        . " tahun. Masa kerja Anda saat ini: {$masaKerja} tahun.",
                ])->withInput();
            }
        }
        // ================= END VALIDASI MASA KERJA =================

        if (in_array($user->role_id, [2, 3, 4, 6], true)) {
            SaldoCuti::firstOrCreate(
                ['pegawai_id' => $user->id, 'tahun' => Carbon::parse($request->tanggal_mulai)->year],
                ['kuota_tahunan' => 12, 'sisa' => 12]
            );
        }

        // ================= PERHITUNGAN HARI KERJA =================
        $jumlah_hari = $this->hitungHariKerja($request->tanggal_mulai, $request->tanggal_selesai);

        if ($jumlah_hari === 0) {
            return back()->withErrors([
                'tanggal_mulai' => 'Tanggal yang dipilih tidak valid karena hanya mencakup akhir pekan dan/atau hari libur nasional/cuti bersama.',
            ])->withInput();
        }
        // ================= END PERHITUNGAN HARI KERJA =================

        if ($jenisCuti === 'Cuti Tahunan') {
            $tahunCuti = Carbon::parse($request->tanggal_mulai)->year;

            // 1. Ambil cuti yang masih MENGANTRE (Belum disetujui tapi memakan kuota)
            $cutiMenunggu = PengajuanCuti::where('pegawai_id', $user->id)
                ->where('jenis_cuti', 'Cuti Tahunan')
                ->whereIn('status', ['menunggu_l1', 'menunggu_l2', 'menunggu_l3', 'menunggu_l4'])
                ->whereYear('tanggal_mulai', $tahunCuti)
                ->sum('jumlah_hari');

            // 2. Ambil cuti yang SUDAH DISETUJUI
            $cutiTerpakai = PengajuanCuti::where('pegawai_id', $user->id)
                ->where('jenis_cuti', 'Cuti Tahunan')
                ->where('status', 'disetujui')
                ->whereYear('tanggal_mulai', $tahunCuti)
                ->sum('jumlah_hari');

            // 3. Ambil data Saldo
            $saldo = SaldoCuti::where('pegawai_id', $user->id)->where('tahun', $tahunCuti)->first();

            // 4. LOGIKA BARU: Total = (Kuota + CarryForward) - (Terpakai + Menunggu)
            $kuota = $saldo ? $saldo->kuota_tahunan : ($user->jatah_cuti ?? 12);
            $carryForward = $saldo ? $saldo->carry_forward_normal : 0;

            $sisaTersedia = ($kuota + $carryForward) - ($cutiTerpakai + $cutiMenunggu);

            // 5. Validasi Final
            if ($jumlah_hari > $sisaTersedia) {
                return back()->withErrors([
                    'jenis_cuti' => "Pengajuan Cuti Tahunan melebihi sisa saldo. Sisa yang tersedia saat ini: {$sisaTersedia} hari.",
                ])->withInput();
            }
        }

        // ================================================= ========
        // PROSES UPLOAD FILE LAMPIRAN (JIKA ADA)
        // =========================================================
        $lampiranPath = null;
        if ($request->hasFile('lampiran')) {
            $lampiranPath = $request->file('lampiran')->store('lampiran-cuti', 'public');
        }

        // =========================================================
        // MENENTUKAN STATUS AWAL & TARGET NOTIFIKASI (HIERARKI)
        // =========================================================
        $levelAwal = $this->tentukanLevelAwalCuti($user);
        $statusAwal = $levelAwal['status'];
        $levelSaatIni = $levelAwal['level'];
        $targetRoleNotifikasi = $levelAwal['target_role'];

        $pengajuan = PengajuanCuti::create([
            'pegawai_id' => $user->id,
            'jenis_cuti' => $jenisCuti,
            'tanggal_mulai' => $request->tanggal_mulai,
            'tanggal_selesai' => $request->tanggal_selesai,
            'jumlah_hari' => $jumlah_hari,
            'keterangan' => $request->keterangan,
            'alamat_cuti' => $request->alamat_cuti,
            'no_telp' => $request->no_telp,
            'lampiran' => $lampiranPath,
            'status' => $statusAwal,
            'level_saat_ini' => $levelSaatIni,
        ]);

        // =========================================================
        // PROSES TRIGGER NOTIFIKASI KE ATASAN YANG TEPAT
        // =========================================================
        $atasanTarget = Pegawai::where('role_id', $targetRoleNotifikasi)
            ->where('kelompok_substansi', $user->kelompok_substansi)
            ->first();

        if (!$atasanTarget) {
            $atasanTarget = Pegawai::where('role_id', $targetRoleNotifikasi)->first();
        }

        if ($atasanTarget) {
            Notifikasi::create([
                'pegawai_id' => $atasanTarget->id,
                'judul'      => 'Pengajuan Cuti Baru',
                'pesan'      => 'Ada pengajuan cuti baru dari ' . $user->nama . ' yang butuh persetujuan Anda.',
                'tautan'     => route('atasan.approval'),
                'is_read'    => false,
            ]);
        }

        return redirect()->route('karyawan.riwayat')->with('success', 'Pengajuan cuti berhasil dikirim.');
    }

    // 3. Menampilkan Halaman Riwayat Pengajuan
    public function history(Request $request)
    {
        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();

        // PERBAIKAN: tambahkan 'pegawai' ke eager load supaya
        // $item->pegawai tersedia untuk ditempeli nama_l1/nama_l2 di
        // bawah (dipakai oleh Modal Detail di RiwayatPengajuan.vue untuk
        // menampilkan nama atasan L1/L2, mengikuti pola yang sama
        // seperti L3/L4).
        $query = PengajuanCuti::with(['pegawai', 'atasanL1', 'atasanL3', 'atasanL4', 'approvalLogs'])
            ->where('pegawai_id', $user->id)
            ->orderBy('created_at', 'desc');

        if ($request->filled('search')) {
            $query->where('keterangan', 'like', '%' . $request->search . '%');
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('jenis_cuti')) {
            $query->where('jenis_cuti', $request->jenis_cuti);
        }

        $riwayat = $query->paginate(5)->withQueryString();

        // ================= NAMA ATASAN L1 & L2 DINAMIS =================
        // Halaman ini selalu berisi pengajuan milik $user sendiri, jadi
        // atasan L1 (Ketua Tim Kerja, berdasarkan tim_kerja) & L2 (Ketua
        // Kelompok Substansi, berdasarkan kelompok_substansi) SAMA untuk
        // semua baris — dihitung sekali di luar loop, bukan per baris.
        // Accessor ketua_tim_kerja / ketua_kelompok ada di model Pegawai.
        $namaL1 = optional($user->ketua_tim_kerja)->nama;
        $namaL2 = optional($user->ketua_kelompok)->nama;

        foreach ($riwayat->getCollection() as $item) {
            if ($item->pegawai) {
                $item->pegawai->nama_l1 = $namaL1;
                $item->pegawai->nama_l2 = $namaL2;
            }
        }
        // ================= END NAMA ATASAN L1 & L2 DINAMIS =================

        $tahunCuti = date('Y');
        $cutiMenunggu = PengajuanCuti::where('pegawai_id', $user->id)
            ->where('jenis_cuti', 'Cuti Tahunan')
            ->whereIn('status', ['menunggu_l1', 'menunggu_l2', 'menunggu_l3', 'menunggu_l4'])
            ->whereYear('tanggal_mulai', $tahunCuti)
            ->sum('jumlah_hari');

        $saldo = SaldoCuti::where('pegawai_id', $user->id)->where('tahun', $tahunCuti)->first();

        $kuotaTahunan = $saldo ? $saldo->kuota_tahunan : 0;
        $sisaTahunLalu = $saldo ? $saldo->carry_forward_normal : 0;

        $cutiTerpakai = PengajuanCuti::where('pegawai_id', $user->id)
            ->where('jenis_cuti', 'Cuti Tahunan')
            ->where('status', 'disetujui')
            ->whereYear('tanggal_mulai', $tahunCuti)
            ->sum('jumlah_hari');

        $totalTersedia = ($kuotaTahunan + $sisaTahunLalu) - $cutiTerpakai;
        $sisaCutiAsli = $totalTersedia - $cutiMenunggu;

        $stats = [
            'kuota_tahunan' => $kuotaTahunan,
            'carry_forward_normal' => $sisaTahunLalu,
            'cuti_terpakai' => $cutiTerpakai,
            'total_cuti_tersedia' => $totalTersedia,
        ];

        // ================= PERBAIKAN =================
        // Sebelumnya: $adaAtasanL1 = $this->departemenPunyaAtasanLevel(2, $user->kelompok_substansi);
        // Diganti supaya konsisten dengan cascade L1->L2->L3 di
        // tentukanLevelAwalCuti(): makna flag ini adalah "apakah STAF YANG
        // LOGIN INI akan melalui tahap L1", bukan "apakah ada L1 di
        // departemen, entah untuk tim kerja mana". Jadi harus di-scope ke
        // tim_kerja milik user, bukan kelompok_substansi.
        $adaAtasanL1 = $this->timKerjaPunyaAtasanL1($user->tim_kerja);
        // ================= END PERBAIKAN =================

        return Inertia::render('Karyawan/RiwayatPengajuan', [
            'riwayat' => $riwayat,
            'filters' => $request->only(['search', 'status', 'jenis_cuti']),
            'sisa_cuti' => (int) $sisaCutiAsli,
            'stats' => $stats,
            'hariLiburs' => HariLibur::orderBy('tanggal')->get(),
            'ada_atasan_l1' => $adaAtasanL1,
        ]);
    }

    // 4. Menampilkan Kalender Tim
    public function teamCalendar()
    {
        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();

        $listTimKerja = [];
        $listKelompok = [];

        // SESUDAH — tambahkan foto_profil supaya avatar pegawai bisa ditampilkan
        // di modal Kalender Tim (pola sama seperti Dashboard.vue)
        $pegawaiSelect = 'pegawai:id,nama,kelompok_substansi,tim_kerja,jabatan,foto_profil';

        if ($user->role_id === 2) {
            // ================= L1 (Ketua Tim Kerja) =================
            // Hanya melihat staff di tim_kerja-nya sendiri. Tidak ada dropdown
            // filter untuk level ini — scope sudah otomatis sempit. Nama tim
            // kerjanya sendiri dikirim lewat 'userTimKerja' untuk ditampilkan
            // sebagai badge info di frontend.
            $query = PengajuanCuti::with($pegawaiSelect)
                ->whereHas('pegawai', function ($q) use ($user) {
                    $q->where('tim_kerja', $user->tim_kerja)
                        ->where('id', '!=', $user->id);
                });
            // ================= END L1 =================

        } elseif ($user->role_id === 3) {
            // ================= L2 (Ketua Kelompok Substansi) =================
            // Melihat seluruh kelompok_substansi-nya, tapi dropdown filter yang
            // ditampilkan ke frontend adalah daftar tim_kerja DI DALAM
            // kelompok_substansi tsb (bukan lintas kelompok).
            $query = PengajuanCuti::with($pegawaiSelect)
                ->whereHas('pegawai', function ($q) use ($user) {
                    $q->where('kelompok_substansi', $user->kelompok_substansi)
                        ->where('id', '!=', $user->id);
                });

            $listTimKerja = Pegawai::where('kelompok_substansi', $user->kelompok_substansi)
                ->whereNotNull('tim_kerja')
                ->where('tim_kerja', '!=', '-')
                ->distinct()
                ->orderBy('tim_kerja')
                ->pluck('tim_kerja')
                ->values();
            // ================= END L2 =================

        } elseif (in_array($user->role_id, [4, 6], true)) {
            // ================= L3 & L4 =================
            // Melihat lintas kelompok_substansi (seluruh departemen), difilter
            // lewat dropdown "Semua Kelompok" seperti fitur Approval Cuti.
            $query = PengajuanCuti::with($pegawaiSelect)
                ->whereHas('pegawai', function ($q) use ($user) {
                    $q->where('id', '!=', $user->id);
                });

            $listKelompok = Pegawai::whereNotNull('kelompok_substansi')
                ->distinct()
                ->orderBy('kelompok_substansi')
                ->pluck('kelompok_substansi')
                ->values();
            // ================= END L3 & L4 =================

        } else {
            // ================= STAFF (role_id 1) & fallback =================
            // Perilaku lama: rekan setim di kelompok_substansi yang sama.
            $query = PengajuanCuti::with($pegawaiSelect)
                ->whereHas('pegawai', function ($q) use ($user) {
                    $q->where('kelompok_substansi', $user->kelompok_substansi)
                        ->where('id', '!=', $user->id);
                });
            // ================= END STAFF =================
        }

        $cutiTim = $query
            ->where('status', 'disetujui')
            ->where('tanggal_selesai', '>=', Carbon::today()->toDateString())
            ->orderBy('tanggal_mulai', 'asc')
            ->get();

        $hariLiburs = \App\Models\HariLibur::all();

        return Inertia::render('Karyawan/KalenderTim', [
            'cutiTim' => $cutiTim,
            'kelompok_substansi' => $user->kelompok_substansi,
            'hariLiburs' => $hariLiburs,
            'userRoleId' => $user->role_id,
            'userTimKerja' => $user->tim_kerja, // dipakai untuk badge info L1
            'listTimKerja' => $listTimKerja,
            'listKelompok' => $listKelompok,
        ]);
    }

    // 5. Unduh PDF Bukti Cuti
    public function downloadPdf(int $id)
    {
        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();

        $pengajuan = PengajuanCuti::with(['pegawai', 'atasanL1', 'atasanL3', 'atasanL4', 'approvalLogs'])->findOrFail($id);

        if ($pengajuan->pegawai_id !== $user->id || $pengajuan->status !== 'disetujui') {
            abort(403, 'Anda tidak memiliki akses, atau cuti belum disetujui sepenuhnya.');
        }

        $tanggalMasuk = Carbon::parse($pengajuan->pegawai->tanggal_masuk);
        $masaKerja = $tanggalMasuk->diff(Carbon::now())->format('%y Tahun / %m Bulan');

        $tahunCuti = date('Y', strtotime($pengajuan->tanggal_mulai));
        $saldo = SaldoCuti::where('pegawai_id', $pengajuan->pegawai_id)->where('tahun', $tahunCuti)->first();

        if ($saldo) {
            // Karena tidak ada lagi field sisa, hitung dinamis untuk PDF
            $kuota = $saldo->kuota_tahunan;
            $carryForward = $saldo->carry_forward_normal;
            $terpakai = PengajuanCuti::where('pegawai_id', $pengajuan->pegawai_id)
                ->where('jenis_cuti', 'Cuti Tahunan')
                ->where('status', 'disetujui')
                ->whereYear('tanggal_mulai', $tahunCuti)
                ->sum('jumlah_hari');
            $sisaCutiAsli = ($kuota + $carryForward) - $terpakai;
        } else {
            $jatahCuti = $pengajuan->pegawai->jatah_cuti ?? 12;
            $cutiTerpakai = PengajuanCuti::where('pegawai_id', $pengajuan->pegawai_id)
                ->where('jenis_cuti', 'Cuti Tahunan')
                ->where('status', 'disetujui')
                ->whereYear('tanggal_mulai', $tahunCuti)
                ->sum('jumlah_hari');
            $sisaCutiAsli = $jatahCuti - $cutiTerpakai;
        }

        $data = [
            'pengajuan' => $pengajuan,
            'pegawai' => $pengajuan->pegawai,
            'masaKerja' => $masaKerja,
            'sisaCuti' => (int) $sisaCutiAsli,
        ];

        $pdf = Pdf::loadView('pdf.surat-cuti', $data)->setPaper('A4', 'portrait');
        $namaFile = 'Surat_Izin_Cuti_' . $pengajuan->pegawai->nama . '_' . $pengajuan->tanggal_mulai . '.pdf';

        return $pdf->download($namaFile);
    }

    // 6. Karyawan Membatalkan Pengajuan Cuti Sendiri (Yang Masih Antrean)
    public function cancel(int $id)
    {
        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();

        $pengajuan = PengajuanCuti::findOrFail($id);

        if ($pengajuan->pegawai_id !== $user->id) {
            abort(403, 'Anda tidak diizinkan membatalkan pengajuan ini.');
        }

        if (!in_array($pengajuan->status, ['menunggu_l1', 'menunggu_l2', 'menunggu_l3', 'menunggu_l4'])) {
            return back()->with('error', 'Cuti ini sudah tidak dapat dibatalkan.');
        }

        $pengajuan->update([
            'status' => 'dibatalkan_reguler'
        ]);

        return back()->with('success', 'Pengajuan cuti berhasil dibatalkan.');
    }

    // 7. Karyawan Membatalkan Cuti Mandiri (Yang Sudah Disetujui)
    public function batalkanMandiri(Request $request, int $id)
    {
        $request->validate([
            'alasan_pembatalan' => 'required|string|max:255',
        ], [
            'alasan_pembatalan.required' => 'Alasan pembatalan wajib diisi.',
        ]);

        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();

        $cuti = PengajuanCuti::findOrFail($id);

        if ($cuti->pegawai_id !== $user->id || $cuti->status !== 'disetujui') {
            return redirect()->back()->with('error', 'Aksi tidak diizinkan atau cuti tidak dapat dibatalkan.');
        }

        // Saldo tidak perlu dikembalikan secara manual ke tabel jika kita
        // menghitung sisa secara dinamis (Terpakai). Mengubah status menjadi 
        // 'dibatalkan_reguler' otomatis akan memulihkan sisa cuti saat dihitung ulang.
        $cuti->status = 'dibatalkan_reguler';
        $cuti->keterangan = $cuti->keterangan . ' | Batal Mandiri: ' . $request->alasan_pembatalan;
        $cuti->save();

        return redirect()->back()->with('success', 'Cuti berhasil dibatalkan dan saldo telah dikembalikan.');
    }

    // 8. Karyawan Merevisi Cuti (Khusus Status Ditangguhkan)
    public function revisi(Request $request, int $id)
    {
        $cuti = PengajuanCuti::findOrFail($id);

        /** @var \App\Models\Pegawai $userLogin */
        $userLogin = Auth::user();

        if ((int) $cuti->pegawai_id !== (int) $userLogin->id) {
            abort(403, 'Unauthorized action.');
        }

        $request->validate([
            'tanggal_mulai' => ['required', 'date', 'after_or_equal:today'],
            'tanggal_selesai' => ['required', 'date', 'after_or_equal:tanggal_mulai'],
        ]);

        if ($cuti->pegawai_id !== $userLogin->id || $cuti->status !== 'dibatalkan_ditangguhkan') {
            return redirect()->back()->with('error', 'Cuti ini tidak dapat direvisi.');
        }

        if ($cuti->jenis_cuti === 'Cuti Besar') {
            $masaKerja = $this->hitungMasaKerjaTahun($userLogin);

            if ($masaKerja < self::MASA_KERJA_MINIMAL_CUTI_BESAR) {
                return redirect()->back()->with(
                    'error',
                    'Cuti Besar hanya dapat direvisi/diajukan ulang jika masa kerja sudah mencapai minimal '
                        . self::MASA_KERJA_MINIMAL_CUTI_BESAR . ' tahun.'
                );
            }
        }

        $jumlah_hari = $this->hitungHariKerja($request->tanggal_mulai, $request->tanggal_selesai);

        if ($jumlah_hari === 0) {
            return redirect()->back()->with('error', 'Tanggal yang dipilih jatuh pada hari libur sepenuhnya (akhir pekan dan/atau hari libur nasional/cuti bersama).');
        }

        $levelAwal = $this->tentukanLevelAwalCuti($userLogin);

        $cuti->update([
            'tanggal_mulai' => $request->tanggal_mulai,
            'tanggal_selesai' => $request->tanggal_selesai,
            'jumlah_hari' => $jumlah_hari,
            'status' => $levelAwal['status'],
            'level_saat_ini' => $levelAwal['level'],
            'keterangan' => $cuti->keterangan . ' | [DIREVISI]',
        ]);

        $atasanTarget = Pegawai::where('role_id', $levelAwal['target_role'])
            ->where('kelompok_substansi', $userLogin->kelompok_substansi)
            ->first()
            ?? Pegawai::where('role_id', $levelAwal['target_role'])->first();

        if ($atasanTarget) {
            Notifikasi::create([
                'pegawai_id' => $atasanTarget->id,
                'judul'      => 'Revisi Pengajuan Cuti',
                'pesan'      => 'Ada revisi tanggal cuti dari ' . $userLogin->nama . ' yang butuh persetujuan Anda.',
                'tautan'     => route('atasan.approval'),
                'is_read'    => false,
            ]);
        }

        return redirect()->back()->with('success', 'Tanggal cuti berhasil direvisi dan diajukan ulang.');
    }
}
