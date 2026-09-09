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
// PERBAIKAN: ditambahkan helper privat departemenPunyaAtasanLevel() dan
// tentukanLevelAwalCuti(). Untuk staf (role_id 1), status awal SEKARANG
// ditentukan secara dinamis:
//   - Jika ada pegawai role_id=2 (L1) di departemen yang sama -> alur
//     normal, mulai dari 'menunggu_l1' (sama seperti sebelumnya).
//   - Jika TIDAK ada L1 di departemen tersebut -> langsung mulai dari
//     'menunggu_l3' (skip L1 & L2 sekaligus), KONSISTEN dengan logika
//     "lompat L2" yang sudah ada di
//     MonitoringCutiController::process() TAHAP 1 (L1 approve staf ->
//     otomatis lompat ke L3, karena L2 memang bukan bagian dari alur
//     staf biasa).
// Untuk Atasan yang mengajukan cuti untuk dirinya sendiri (role_id 2,3,4),
// logika PERSIS SAMA seperti sebelumnya (tidak diubah) — mereka mulai dari
// level SETELAH level mereka sendiri.
//
// Perbaikan yang sama diterapkan di revisi(), yang sebelumnya JUGA
// hardcode 'menunggu_l1' tanpa pengecekan serupa.
//
// (Semua catatan perbaikan sebelumnya — sinkronisasi saldo cuti, filter
// jenis cuti di riwayat, perhitungan hari libur nasional/cuti bersama,
// syarat masa kerja Cuti Besar — TETAP berlaku dan tidak diubah sama
// sekali oleh perbaikan ini.)
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


    // ================= HELPER: STRUKTUR APPROVAL PER DEPARTEMEN (BARU) =================
    // Mengecek apakah ada pegawai dengan role_id tertentu (mis. 2 = L1)
    // yang terdaftar di departemen yang sama dengan $departemen. Dipakai
    // untuk mendeteksi unit yang strukturnya "lebih pendek" (mis. Subbagian
    // Tata Usaha yang tidak punya L1/L2, staf langsung lapor ke L3).
    private function departemenPunyaAtasanLevel(int $roleId, ?string $departemen): bool
    {
        if (empty($departemen)) {
            return false;
        }


        return Pegawai::where('role_id', $roleId)
            ->where('departemen', $departemen)
            ->exists();
    }


    // Menentukan status awal, level_saat_ini, dan target role notifikasi
    // untuk sebuah pengajuan cuti BARU, berdasarkan siapa yang mengajukan
    // DAN struktur approval yang benar-benar tersedia di departemennya.
    //
    // Mengembalikan array asosiatif: ['status' => ..., 'level' => ...,
    // 'target_role' => ...].
    //
    // Aturan:
    //   - Atasan (role_id 2, 3, 4) yang mengajukan cuti untuk dirinya
    //     sendiri: TIDAK BERUBAH dari logika lama — mulai dari level
    //     SETELAH level mereka sendiri (L1 mengajukan -> masuk ke antrean
    //     L2; L2 -> L3; L3 -> L4). Level-level ini adalah level personal
    //     atasan yang mengajukan, bukan level unit, jadi tidak perlu
    //     pengecekan struktur departemen.
    //   - Staf (role_id 1): NORMALNYA mulai dari L1 (Ketua Tim Kerja).
    //     Tapi kalau departemen staf tersebut TIDAK memiliki siapa pun
    //     berrole_id 2 (L1) — seperti Subbagian Tata Usaha — maka alur
    //     langsung dimulai dari L3 (Kasubag TU), KONSISTEN dengan logika
    //     "lompat L2" yang sudah ada di
    //     MonitoringCutiController::process() TAHAP 1 (L1 approve staf ->
    //     otomatis lompat ke L3, karena L2 memang bukan bagian dari alur
    //     staf biasa).
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

        // Default: role_id 1 (Staf)
        if ($this->departemenPunyaAtasanLevel(2, $user->departemen)) {
            // Alur normal: unit ini punya L1, mulai dari sana seperti biasa.
            return ['status' => 'menunggu_l1', 'level' => 1, 'target_role' => 2];
        }

        // Unit ini TIDAK punya L1 (mis. Subbagian Tata Usaha) -> langsung
        // ke L3 (Kasubag TU), tidak boleh macet menunggu L1 yang tidak ada.
        return ['status' => 'menunggu_l3', 'level' => 3, 'target_role' => 4];
    }
    // ================= END HELPER STRUKTUR APPROVAL =================


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


            $cutiMenunggu = PengajuanCuti::where('pegawai_id', $user->id)
                ->where('jenis_cuti', 'Cuti Tahunan')
                ->whereIn('status', ['menunggu_l1', 'menunggu_l2', 'menunggu_l3', 'menunggu_l4'])
                ->whereYear('tanggal_mulai', $tahunCuti)
                ->sum('jumlah_hari');


            $saldo = SaldoCuti::where('pegawai_id', $user->id)->where('tahun', $tahunCuti)->first();


            if ($saldo) {
                $sisaTersedia = $saldo->sisa - $cutiMenunggu;
            } else {
                $jatahCuti = $user->jatah_cuti ?? 12;
                $cutiTerpakai = PengajuanCuti::where('pegawai_id', $user->id)
                    ->where('jenis_cuti', 'Cuti Tahunan')
                    ->where('status', 'disetujui')
                    ->whereYear('tanggal_mulai', $tahunCuti)
                    ->sum('jumlah_hari');


                $sisaTersedia = $jatahCuti - $cutiTerpakai - $cutiMenunggu;
            }


            if ($jumlah_hari > $sisaTersedia) {
                return back()->withErrors([
                    'jenis_cuti' => "Pengajuan Cuti Tahunan melebihi sisa saldo. Sisa yang tersedia saat ini: {$sisaTersedia} hari.",
                ])->withInput();
            }
        }


        // =========================================================
        // PROSES UPLOAD FILE LAMPIRAN (JIKA ADA)
        // =========================================================
        $lampiranPath = null;
        if ($request->hasFile('lampiran')) {
            $lampiranPath = $request->file('lampiran')->store('lampiran-cuti', 'public');
        }


        // =========================================================
        // MENENTUKAN STATUS AWAL & TARGET NOTIFIKASI (HIERARKI)
        // =========================================================
        // PERBAIKAN: sebelumnya blok ini hardcode 'menunggu_l1' untuk semua
        // staf (role_id 1) tanpa mengecek apakah L1 benar-benar ada di
        // departemen mereka. Sekarang memakai helper tentukanLevelAwalCuti()
        // yang mengecek struktur approval riil di departemen pemohon —
        // lihat penjelasan lengkap di komentar "PERBAIKAN TERBARU (Unit
        // Tanpa Level Approval L1)" di bagian atas file ini.
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
            ->where('departemen', $user->departemen)
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


        $query = PengajuanCuti::with(['atasanL1', 'atasanL3', 'atasanL4', 'approvalLogs'])
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


        $tahunCuti = date('Y');
        $cutiMenunggu = PengajuanCuti::where('pegawai_id', $user->id)
            ->where('jenis_cuti', 'Cuti Tahunan')
            ->whereIn('status', ['menunggu_l1', 'menunggu_l2', 'menunggu_l3', 'menunggu_l4'])
            ->whereYear('tanggal_mulai', $tahunCuti)
            ->sum('jumlah_hari');


        $saldo = SaldoCuti::where('pegawai_id', $user->id)->where('tahun', $tahunCuti)->first();


        if ($saldo) {
            $sisaCutiAsli = $saldo->sisa - $cutiMenunggu;
        } else {
            $jatahCuti = $user->jatah_cuti ?? 12;
            $cutiTerpakaiLama = PengajuanCuti::where('pegawai_id', $user->id)
                ->where('jenis_cuti', 'Cuti Tahunan')
                ->where('status', 'disetujui')
                ->whereYear('tanggal_mulai', $tahunCuti)
                ->sum('jumlah_hari');
            $sisaCutiAsli = $jatahCuti - $cutiTerpakaiLama - $cutiMenunggu;
        }


        $kuotaTahunan = $saldo ? $saldo->kuota_tahunan : 0;
        $sisaTahunLalu = $saldo ? $saldo->carry_forward_normal : 0;
        $cutiTerpakai = PengajuanCuti::where('pegawai_id', $user->id)
            ->where('jenis_cuti', 'Cuti Tahunan')
            ->where('status', 'disetujui')
            ->whereYear('tanggal_mulai', $tahunCuti)
            ->sum('jumlah_hari');
        $totalTersedia = ($kuotaTahunan + $sisaTahunLalu) - $cutiTerpakai;


        $stats = [
            'kuota_tahunan' => $kuotaTahunan,
            'sisa_cuti_tahun_lalu' => $sisaTahunLalu,
            'cuti_terpakai' => $cutiTerpakai,
            'total_cuti_tersedia' => $totalTersedia,
        ];


        return Inertia::render('Karyawan/RiwayatPengajuan', [
            'riwayat' => $riwayat,
            'filters' => $request->only(['search', 'status', 'jenis_cuti']),
            'sisa_cuti' => (int) $sisaCutiAsli,
            'stats' => $stats,
        ]);
    }


    // 4. Menampilkan Kalender Tim
    public function teamCalendar()
    {
        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();


        $cutiTim = PengajuanCuti::with('pegawai:id,nama,departemen,jabatan')
            ->whereHas('pegawai', function ($query) use ($user) {
                $query->where('departemen', $user->departemen);
            })
            ->where('status', 'disetujui')
            ->where('tanggal_selesai', '>=', Carbon::today()->toDateString())
            ->orderBy('tanggal_mulai', 'asc')
            ->get();


        $hariLiburs = \App\Models\HariLibur::all();


        return Inertia::render('Karyawan/KalenderTim', [
            'cutiTim' => $cutiTim,
            'departemen' => $user->departemen,
            'hariLiburs' => $hariLiburs
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
            $sisaCutiAsli = $saldo->sisa;
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


        if (strtolower($cuti->jenis_cuti) === 'cuti tahunan') {
            $tahunCuti = date('Y', strtotime($cuti->tanggal_mulai));
            $saldo = SaldoCuti::where('pegawai_id', $cuti->pegawai_id)
                ->where('tahun', $tahunCuti)
                ->first();


            if ($saldo) {
                $saldo->sisa += $cuti->jumlah_hari;
                $saldo->save();
            }
        }


        $cuti->status = 'dibatalkan_reguler';
        $cuti->keterangan = $cuti->keterangan . ' | Batal Mandiri: ' . $request->alasan_pembatalan;
        $cuti->save();


        return redirect()->back()->with('success', 'Cuti berhasil dibatalkan dan saldo telah dikembalikan.');
    }


    // 8. Karyawan Merevisi Cuti (Khusus Status Ditangguhkan)
    //
    // PERBAIKAN TERBARU (Unit Tanpa Level Approval L1): status & level
    // awal saat revisi SEBELUMNYA hardcode 'menunggu_l1'/level 1, dengan
    // bug yang SAMA PERSIS seperti di store() — staf dari unit tanpa L1
    // (mis. Subbagian Tata Usaha) akan macet lagi setelah direvisi.
    // Sekarang memakai helper tentukanLevelAwalCuti() yang sama supaya
    // konsisten dengan alur pengajuan baru.
    public function revisi(Request $request, int $id)
    {
        $request->validate([
            'tanggal_mulai' => ['required', 'date', 'after_or_equal:today'],
            'tanggal_selesai' => ['required', 'date', 'after_or_equal:tanggal_mulai'],
        ]);


        $cuti = PengajuanCuti::findOrFail($id);


        /** @var \App\Models\Pegawai $userLogin */
        $userLogin = Auth::user();


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


        // PERBAIKAN: tentukan ulang status & level awal berdasarkan
        // struktur approval riil di departemen $userLogin, BUKAN hardcode
        // 'menunggu_l1'/level 1 seperti sebelumnya. Lihat penjelasan di
        // komentar method ini & di komentar atas file (PERBAIKAN TERBARU
        // - Unit Tanpa Level Approval L1).
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
            ->where('departemen', $userLogin->departemen)
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
 