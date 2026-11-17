<?php

namespace App\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\CutiDitangguhkan;
use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use App\Models\Notifikasi;
use App\Models\Pegawai;
use App\Models\HariLibur;
use App\Models\ApprovalLog;
use App\Support\LeaveYearWindow;
use App\Support\RincianSaldo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Carbon\Carbon;

// Controller SISI KARYAWAN: form pengajuan cuti, riwayat, kalender tim, unduh
// PDF, batal, dan revisi. Logika approve/reject ada di MonitoringCutiController.
//
// Aturan penting:
//   - Lampiran disimpan di disk private ('local'), diunduh lewat LampiranController.
//   - Pengajuan boleh tanggal mundur (backdate) selama masih dalam JENDELA TAHUN
//     (config/leave.php, App\Support\LeaveYearWindow).
//   - Cuti Tahunan hanya boleh untuk tahun yang saldonya sudah dibuat Admin.
//   - Cuti lintas tahun dipecah per tahun untuk validasi saldo.
//   - Rumus saldo satu pintu: hitungSaldoCuti() (sama dengan Dashboard & Rekap Kuota).
//   - Kepala Biro (role 6) disetujui otomatis (setujuiOtomatisKabiro).
//   - Batalkan mandiri: cuti berstatus disetujui boleh dibatalkan pemiliknya
//     kapan saja. Lihat bisaDibatalkanMandiri().
//   - Setelah batal/setuju, bawaan tahun-tahun berikutnya disinkronkan lewat
//     SaldoCuti::sinkronkanBawaanPengajuan().
//   - Cuti Alasan Penting HANYA untuk PNS (status_kepegawaian = 'PNS').
//     PPPK & pegawai yang status kepegawaiannya belum diisi ditolak.
//     Lihat bolehCutiAlasanPenting().
//   - Cuti Melahirkan HANYA untuk pegawai perempuan. Jenis kelamin dibaca dari
//     digit ke-15 NIP 18 digit (1 = laki-laki, 2 = perempuan). NIP yang tidak
//     valid ditolak. Lihat bolehCutiMelahirkan().
//   - Halaman Riwayat Pengajuan dibatasi JENDELA TAHUN (config/leave.php,
//     App\Support\LeaveYearWindow): default tahun berjalan, pilihan 2 tahun ke
//     belakang dan 1 tahun ke depan. Data di luar jendela tetap ada di database.
//
// PERBAIKAN (SALDO AJUKAN CUTI = DASHBOARD):
//   hitungSaldoCuti() sekarang ikut mengurangi cuti ditangguhkan (Tgh) tahun
//   berjalan, sama seperti DashboardController::hitungSaldoPegawai(). Untuk
//   revisi(), Tgh milik cuti yang sedang direvisi dikecualikan lewat parameter
//   $kecualikanTangguh supaya tidak memblokir dirinya sendiri.
class CutiController extends Controller
{
    private const MASA_KERJA_MINIMAL_CUTI_BESAR = 5;

    // Status yang memblokir pengajuan di tanggal yang sama.
    private const STATUS_AKTIF = ['menunggu_l1', 'menunggu_l2', 'menunggu_l3', 'menunggu_l4', 'disetujui'];

    private const MAKS_RENTANG_HARI = 92;

    // Cuti Alasan Penting hanya untuk PNS. PPPK & pegawai yang status
    // kepegawaiannya belum diisi ditolak (isi lewat Kelola Pegawai / impor).
    private function bolehCutiAlasanPenting(Pegawai $pegawai): bool
    {
        return strtoupper(trim((string) $pegawai->status_kepegawaian)) === 'PNS';
    }

    // Jenis kelamin dari NIP 18 digit: digit ke-15 (1 = laki-laki, 2 = perempuan).
    // Cuti Melahirkan hanya untuk perempuan. NIP yang tidak valid ditolak.
    private function bolehCutiMelahirkan(Pegawai $pegawai): bool
    {
        $nip = preg_replace('/\D/', '', (string) $pegawai->nip);

        return strlen($nip) === 18 && $nip[14] === '2';
    }

    // Tolak rentang tidak wajar sebelum hitung hari kerja. Tanggal mundur
    // diperbolehkan dalam jendela tahun (LeaveYearWindow).
    private function pastikanRentangWajar(
        string $mulai,
        string $selesai,
        string $fieldError = 'tanggal_selesai'
    ): void {
        try {
            $tMulai = Carbon::parse($mulai)->startOfDay();
            $tSelesai = Carbon::parse($selesai)->startOfDay();
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'tanggal_mulai' => 'Tanggal cuti tidak valid.',
            ]);
        }

        if ($tSelesai->lt($tMulai)) {
            throw ValidationException::withMessages([
                'tanggal_selesai' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
            ]);
        }

        $tahunTertua  = min(LeaveYearWindow::years());
        $tahunTerjauh = max(LeaveYearWindow::years());

        if ($tMulai->year < $tahunTertua) {
            throw ValidationException::withMessages([
                'tanggal_mulai' => "Tanggal mulai terlalu lama ke belakang. Paling awal 1 Januari {$tahunTertua}.",
            ]);
        }

        if ($tMulai->year > $tahunTerjauh) {
            throw ValidationException::withMessages([
                'tanggal_mulai' => "Tanggal mulai terlalu jauh ke depan. Maksimal 31 Desember {$tahunTerjauh}.",
            ]);
        }

        $hariKalender = $tMulai->diffInDays($tSelesai) + 1;
        if ($hariKalender > self::MAKS_RENTANG_HARI) {
            throw ValidationException::withMessages([
                $fieldError => 'Rentang cuti terlalu panjang. Maksimal '
                    . self::MAKS_RENTANG_HARI
                    . " hari kalender, Anda memilih {$hariKalender} hari.",
            ]);
        }
    }

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

        return $map[strtolower($normalized)] ?? $normalized;
    }

    private function pesanValidasiLampiran(): array
    {
        return [
            'jenis_cuti.required' => 'Jenis cuti wajib dipilih.',
            'jenis_cuti.in'       => 'Jenis cuti tidak valid.',
            'anak_ke.required_if' => 'Kolom anak ke- wajib diisi untuk Cuti Melahirkan.',
            'anak_ke.integer'     => 'Kolom anak ke- harus berupa angka.',
            'anak_ke.min'         => 'Kolom anak ke- minimal 1.',
            'anak_ke.max'         => 'Kolom anak ke- maksimal 20.',

            'tanggal_mulai.required'         => 'Tanggal mulai wajib diisi.',
            'tanggal_mulai.date'             => 'Tanggal mulai tidak valid.',
            'tanggal_selesai.required'       => 'Tanggal selesai wajib diisi.',
            'tanggal_selesai.date'           => 'Tanggal selesai tidak valid.',
            'tanggal_selesai.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',

            'keterangan.required'  => 'Keterangan / alasan cuti wajib diisi.',
            'alamat_cuti.required' => 'Alamat selama cuti wajib diisi.',
            'no_telp.required'     => 'Nomor telepon wajib diisi.',
            'no_telp.max'          => 'Nomor telepon maksimal 20 karakter.',

            'lampiran.file'     => 'Lampiran harus berupa file.',
            'lampiran.mimes'    => 'Format lampiran harus PDF, JPG, atau PNG.',
            'lampiran.max'      => 'Ukuran lampiran maksimal 2 MB.',
            'lampiran.uploaded' => 'Lampiran gagal diunggah. Pastikan ukurannya tidak lebih dari 2 MB.',
        ];
    }

    // ================= HITUNG SALDO CUTI TAHUNAN =================
    // Satu-satunya tempat perhitungan saldo di controller ini. Rumus sama dengan
    // DashboardController::hitungSaldoPegawai() dan RekapKuotaDetailController::hitungKuota():
    //   terpakai total  = digunakan (termasuk koreksi Admin HR) + Tgh tahun ini
    //   total tersedia  = kuota + bawaan eligible + dikembalikan - terpakai total
    //   sisa_cuti       = total tersedia - hari yang masih menunggu persetujuan
    //
    // $kecualikanTangguh = [tahun_asal => hari]: hari Tgh yang TIDAK dihitung.
    // Dipakai revisi(): Tgh milik cuti yang sedang direvisi akan dihapus saat
    // revisi diproses, jadi tidak boleh ikut mengurangi saldo.
    private function hitungSaldoCuti(
        int $pegawaiId,
        int $tahun,
        int $kuotaDefault = 12,
        array $kecualikanTangguh = []
    ): array {
        $saldo = SaldoCuti::where('pegawai_id', $pegawaiId)->where('tahun', $tahun)->first();
        $saldoTahunLalu = SaldoCuti::where('pegawai_id', $pegawaiId)->where('tahun', $tahun - 1)->first();

        $kuota = (int) ($saldo->kuota_tahunan ?? $kuotaDefault);
        $sisaTahunLalu = (int) ($saldo->carry_forward_normal ?? 0);
        $sisaDuaTahunLalu = (int) ($saldoTahunLalu->carry_forward_normal ?? 0);

        // Ikut koreksi_saldo Admin HR (NULL = ikut sistem).
        $terpakai = (int) SaldoCuti::terpakaiEfektif($pegawaiId, $tahun, $saldo);

        $bawaanEligible = SaldoCuti::hitungBawaan($sisaDuaTahunLalu, $sisaTahunLalu, $kuota);

        // Tgh tahun LALU: dikembalikan ke saldo tahun ini.
        $dikembalikan = (int) CutiDitangguhkan::where('pegawai_id', $pegawaiId)
            ->where('tahun_asal', $tahun - 1)
            ->sum('jumlah_hari');

        // Tgh tahun berjalan: mengurangi saldo (sama dengan Dashboard).
        $tangguh = max(
            0,
            (int) CutiDitangguhkan::where('pegawai_id', $pegawaiId)
                ->where('tahun_asal', $tahun)
                ->sum('jumlah_hari') - (int) ($kecualikanTangguh[$tahun] ?? 0)
        );

        $menunggu = $this->hariMenungguDiTahun($pegawaiId, $tahun);

        $totalTersedia = max(
            ($kuota + $bawaanEligible + $dikembalikan) - ($terpakai + $tangguh),
            0
        );

        return [
            'kuota_tahunan'         => $kuota,
            'carry_forward_normal'  => $sisaTahunLalu,
            'sisa_dua_tahun_lalu'   => $sisaDuaTahunLalu,
            'saldo_bawaan_eligible' => $bawaanEligible,
            'cuti_dikembalikan'     => $dikembalikan,
            'cuti_terpakai'         => $terpakai,
            'cuti_ditangguhkan'     => $tangguh,
            'cuti_menunggu'         => $menunggu,
            'total_cuti_tersedia'   => $totalTersedia,
            'sisa_cuti'             => max($totalTersedia - $menunggu, 0),
        ];
    }

    // Hari kerja Cuti Tahunan berstatus menunggu_lX yang jatuh di $tahun.
    private function hariMenungguDiTahun(int $pegawaiId, int $tahun): int
    {
        $pending = PengajuanCuti::where('pegawai_id', $pegawaiId)
            ->where('jenis_cuti', 'Cuti Tahunan')
            ->whereIn('status', ['menunggu_l1', 'menunggu_l2', 'menunggu_l3', 'menunggu_l4'])
            ->whereDate('tanggal_mulai', '<=', "{$tahun}-12-31")
            ->whereDate('tanggal_selesai', '>=', "{$tahun}-01-01")
            ->get(['tanggal_mulai', 'tanggal_selesai']);

        if ($pending->isEmpty()) {
            return 0;
        }

        $libur = $this->getHariLiburDates();
        $total = 0;

        foreach ($pending as $p) {
            $perTahun = $this->hitungHariKerjaPerTahun(
                Carbon::parse($p->tanggal_mulai)->toDateString(),
                Carbon::parse($p->tanggal_selesai)->toDateString(),
                $libur
            );
            $total += (int) ($perTahun[$tahun] ?? 0);
        }

        return $total;
    }

    // Tanggal masuk efektif, dengan cadangan TMT dari NIP.
    private function tanggalMasukEfektif(Pegawai $pegawai): ?Carbon
    {
        if (!empty($pegawai->tanggal_masuk)) {
            try {
                $tgl = Carbon::parse($pegawai->tanggal_masuk);
                if ($tgl->year >= 1950 && !$tgl->isFuture()) {
                    return $tgl->startOfDay();
                }
            } catch (\Exception $e) {
                // jatuh ke cadangan NIP
            }
        }

        $nip = preg_replace('/\D/', '', (string) $pegawai->nip);
        if (strlen($nip) === 18) {
            $tahun = (int) substr($nip, 8, 4);
            $bulan = (int) substr($nip, 12, 2);
            if ($tahun >= 1950 && $bulan >= 1 && $bulan <= 12) {
                return Carbon::create($tahun, $bulan, 1)->startOfDay();
            }
        }

        return null;
    }

    private function hitungMasaKerjaTahun(Pegawai $pegawai): int
    {
        $tanggalMasuk = $this->tanggalMasukEfektif($pegawai);

        if (!$tanggalMasuk) {
            return 0;
        }

        return (int) floor($tanggalMasuk->diffInYears(Carbon::now()));
    }

    // ================= HARI LIBUR & HARI KERJA =================
    private function getHariLiburDates(): array
    {
        return HariLibur::pluck('tanggal')
            ->map(fn($tgl) => Carbon::parse($tgl)->toDateString())
            ->all();
    }

    private function hitungHariKerja(string $mulai, string $selesai, ?array $hariLiburDates = null): int
    {
        $hariLiburDates = $hariLiburDates ?? $this->getHariLiburDates();

        $endDate = Carbon::parse($selesai);
        $jumlahHari = 0;

        $currentDate = Carbon::parse($mulai);
        while ($currentDate->lte($endDate)) {
            $isHariLibur = in_array($currentDate->toDateString(), $hariLiburDates, true);

            if (!$currentDate->isWeekend() && !$isHariLibur) {
                $jumlahHari++;
            }

            $currentDate->addDay();
        }

        return $jumlahHari;
    }

    // Pecah hari kerja per tahun kalender, mis. 29 Des 2026 - 4 Jan 2027
    // menjadi [2026 => 3, 2027 => 2]. Cuti satu tahun menghasilkan satu elemen.
    private function hitungHariKerjaPerTahun(string $mulai, string $selesai, ?array $hariLiburDates = null): array
    {
        $hariLiburDates = $hariLiburDates ?? $this->getHariLiburDates();

        $awalRentang = Carbon::parse($mulai)->startOfDay();
        $akhirRentang = Carbon::parse($selesai)->startOfDay();

        $hasil = [];

        for ($tahun = $awalRentang->year; $tahun <= $akhirRentang->year; $tahun++) {
            $awal = $tahun === $awalRentang->year
                ? $awalRentang->copy()
                : Carbon::create($tahun, 1, 1)->startOfDay();
            $akhir = $tahun === $akhirRentang->year
                ? $akhirRentang->copy()
                : Carbon::create($tahun, 12, 31)->startOfDay();

            $hasil[$tahun] = $this->hitungHariKerja($awal->toDateString(), $akhir->toDateString(), $hariLiburDates);
        }

        return $hasil;
    }

    // Pesan error bila salah satu tahun tidak cukup saldonya, atau null bila aman.
    private function pesanSaldoKurang(
        int $pegawaiId,
        array $hariPerTahun,
        int $kuotaDefault,
        array $kecualikanTangguh = []
    ): ?string {
        $lintasTahun = count($hariPerTahun) > 1;

        foreach ($hariPerTahun as $tahun => $hari) {
            if ($hari <= 0) {
                continue;
            }

            $info = $this->hitungSaldoCuti($pegawaiId, (int) $tahun, $kuotaDefault, $kecualikanTangguh);

            if ($hari > $info['sisa_cuti']) {
                return $lintasTahun
                    ? "Saldo cuti tahun {$tahun} tidak cukup. Pengajuan ini membutuhkan {$hari} hari kerja di tahun {$tahun}, sisa yang tersedia {$info['sisa_cuti']} hari."
                    : "Pengajuan Cuti Tahunan melebihi sisa saldo. Sisa yang tersedia saat ini: {$info['sisa_cuti']} hari.";
            }
        }

        return null;
    }

    // ================= PENGAJUAN GANDA / TANGGAL BENTROK =================
    private function pengajuanBentrok(int $pegawaiId, string $mulai, string $selesai, ?int $kecualiId = null): ?PengajuanCuti
    {
        return PengajuanCuti::where('pegawai_id', $pegawaiId)
            ->whereIn('status', self::STATUS_AKTIF)
            ->when($kecualiId, fn($q) => $q->where('id', '!=', $kecualiId))
            ->whereDate('tanggal_mulai', '<=', $selesai)
            ->whereDate('tanggal_selesai', '>=', $mulai)
            ->first();
    }

    private function pesanBentrok(PengajuanCuti $bentrok): string
    {
        $mulai = Carbon::parse($bentrok->tanggal_mulai)->format('d/m/Y');
        $selesai = Carbon::parse($bentrok->tanggal_selesai)->format('d/m/Y');
        $rentang = $mulai === $selesai ? $mulai : "{$mulai} - {$selesai}";
        $status = $bentrok->status === 'disetujui' ? 'sudah disetujui' : 'masih menunggu persetujuan';

        return "Tanggal yang Anda pilih bentrok dengan pengajuan {$bentrok->jenis_cuti} ({$rentang}) yang {$status}. "
            . 'Batalkan pengajuan tersebut terlebih dahulu atau pilih tanggal lain.';
    }

    // ================= ATURAN TUNGGAL BATAL MANDIRI =================
    // Cuti disetujui boleh dibatalkan pemiliknya kapan saja, karena pengajuan
    // sering dicatat mundur. Dipakai history() (flag tombol) dan batalkanMandiri().
    private function bisaDibatalkanMandiri(PengajuanCuti $cuti): bool
    {
        return $cuti->status === 'disetujui';
    }

    // Tahun pertama dalam rentang yang belum punya baris saldo, atau null bila semua ada.
    private function tahunTanpaSaldo(int $pegawaiId, string $mulai, string $selesai): ?int
    {
        $tahunAwal = Carbon::parse($mulai)->year;
        $tahunAkhir = Carbon::parse($selesai)->year;

        for ($tahun = $tahunAwal; $tahun <= $tahunAkhir; $tahun++) {
            $ada = SaldoCuti::where('pegawai_id', $pegawaiId)
                ->where('tahun', $tahun)
                ->exists();

            if (!$ada) {
                return $tahun;
            }
        }

        return null;
    }

    private function tahunSaldoTersedia(int $pegawaiId): array
    {
        return SaldoCuti::where('pegawai_id', $pegawaiId)
            ->pluck('tahun')
            ->map(fn($t) => (int) $t)
            ->unique()
            ->values()
            ->all();
    }

    // ================= STRUKTUR APPROVAL =================
    private function departemenPunyaAtasanLevel(int $roleId, ?string $kelompokSubstansi): bool
    {
        if (empty($kelompokSubstansi)) {
            return false;
        }

        return Pegawai::where('role_id', $roleId)
            ->where('kelompok_substansi', $kelompokSubstansi)
            ->exists();
    }

    private function timKerjaPunyaAtasanL1(?string $timKerja): bool
    {
        if (empty($timKerja)) {
            return false;
        }

        return Pegawai::where('role_id', 2)
            ->where('tim_kerja', $timKerja)
            ->exists();
    }

    private function cariAtasanTujuan(Pegawai $pemohon, int $targetRole): ?Pegawai
    {
        if ($targetRole === 2) {
            if (empty($pemohon->tim_kerja)) {
                return null;
            }

            return Pegawai::where('role_id', 2)
                ->where('tim_kerja', $pemohon->tim_kerja)
                ->first();
        }

        return Pegawai::where('role_id', $targetRole)
            ->where('kelompok_substansi', $pemohon->kelompok_substansi)
            ->first()
            ?? Pegawai::where('role_id', $targetRole)->first();
    }

    private function tentukanLevelAwalCuti(Pegawai $user): array
    {
        // L4 (Kepala Biro) tidak punya atasan -> langsung disetujui.
        if ((int) $user->role_id === 6) {
            return ['status' => 'disetujui', 'level' => 6, 'target_role' => 5];
        }

        if ($user->role_id === 2) {
            return ['status' => 'menunggu_l2', 'level' => 2, 'target_role' => 3];
        }
        if ($user->role_id === 3) {
            return ['status' => 'menunggu_l3', 'level' => 3, 'target_role' => 4];
        }
        if ($user->role_id === 4) {
            return ['status' => 'menunggu_l4', 'level' => 4, 'target_role' => 6];
        }

        // Staf: cascade L1 -> L2 -> L3.
        if ($this->timKerjaPunyaAtasanL1($user->tim_kerja)) {
            return ['status' => 'menunggu_l1', 'level' => 1, 'target_role' => 2];
        }

        if ($this->departemenPunyaAtasanLevel(3, $user->kelompok_substansi)) {
            return ['status' => 'menunggu_l2', 'level' => 2, 'target_role' => 3];
        }

        return ['status' => 'menunggu_l3', 'level' => 3, 'target_role' => 4];
    }

    // Persetujuan otomatis Kepala Biro. Dipanggil DI DALAM DB::transaction.
    // Kolom legacy `sisa` dikurangi per tahun (hanya Cuti Tahunan), lalu bawaan
    // disinkronkan. Jalur revisi() oleh Kepala Biro juga lewat sini.
    private function setujuiOtomatisKabiro(PengajuanCuti $pengajuan, Pegawai $user): void
    {
        $pengajuan->update([
            'status'         => 'disetujui',
            'level_saat_ini' => 6,
            'atasan_l4_id'   => $user->id,
        ]);

        if ($pengajuan->jenis_cuti === 'Cuti Tahunan') {
            $hariPerTahun = $this->hitungHariKerjaPerTahun(
                Carbon::parse($pengajuan->tanggal_mulai)->toDateString(),
                Carbon::parse($pengajuan->tanggal_selesai)->toDateString()
            );

            foreach ($hariPerTahun as $tahun => $hari) {
                if ($hari <= 0) {
                    continue;
                }

                $saldo = SaldoCuti::where('pegawai_id', $user->id)
                    ->where('tahun', $tahun)
                    ->lockForUpdate()
                    ->first();

                if ($saldo) {
                    $saldo->update(['sisa' => max(0, (int) $saldo->sisa - (int) $hari)]);
                }
            }
        }

        SaldoCuti::sinkronkanBawaanPengajuan($pengajuan);

        ApprovalLog::create([
            'pengajuan_id'      => $pengajuan->id,
            'approver_id'       => $user->id,
            'level_approval'    => 4,
            'keputusan'         => 'setuju',
            'catatan'           => 'Disetujui otomatis (pemohon adalah Kepala Biro)',
            'tanggal_keputusan' => now(),
        ]);

        foreach (Pegawai::where('role_id', 5)->get() as $hr) {
            Notifikasi::create([
                'pegawai_id' => $hr->id,
                'judul'      => 'Info Cuti Kepala Biro',
                'pesan'      => $user->nama . ' (Kepala Biro) mengajukan cuti ' . $pengajuan->jenis_cuti
                    . ' dan disetujui otomatis. Info saja, tidak perlu tindakan.',
                'tautan'     => route('admin.rekap'),
                'is_read'    => false,
            ]);
        }
    }

    // Level "Menunggu" untuk dropdown filter status riwayat.
    private function hitungRuteLevel(Pegawai $user): array
    {
        switch ((int) $user->role_id) {
            case 5:
                return [1, 2, 3, 4];
            case 6:
                return [];
            case 2:
                return [2, 3, 4];
            case 3:
                return [3, 4];
            case 4:
                return [4];
        }

        return match ($this->tentukanLevelAwalCuti($user)['level']) {
            1 => [1, 3, 4],
            2 => [2, 3, 4],
            default => [3, 4],
        };
    }

    // 1. Halaman Form Pengajuan
    public function create()
    {
        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();
        $tahunCuti = (int) date('Y');

        $saldoInfo = $this->hitungSaldoCuti($user->id, $tahunCuti, (int) ($user->jatah_cuti ?? 12));

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

        // Peringatan dini bentrok di form; penjaga akhir tetap store().
        $rentangAktif = PengajuanCuti::where('pegawai_id', $user->id)
            ->whereIn('status', self::STATUS_AKTIF)
            ->whereDate('tanggal_selesai', '>=', Carbon::today())
            ->get(['jenis_cuti', 'tanggal_mulai', 'tanggal_selesai', 'status'])
            ->map(fn($p) => [
                'jenis_cuti' => $p->jenis_cuti,
                'tanggal_mulai' => Carbon::parse($p->tanggal_mulai)->toDateString(),
                'tanggal_selesai' => Carbon::parse($p->tanggal_selesai)->toDateString(),
                'status' => $p->status,
            ])
            ->values();

        return Inertia::render('Karyawan/AjukanCuti', [
            'sisa_cuti' => (int) $saldoInfo['sisa_cuti'],
            'total_cuti_tersedia' => (int) $saldoInfo['total_cuti_tersedia'],
            // Hari yang sudah mengurangi sisa_cuti karena masih menunggu persetujuan.
            'cuti_menunggu' => (int) $saldoInfo['cuti_menunggu'],
            'hariLiburs' => $hariLiburs,
            'masa_kerja_tahun' => $masaKerjaTahun,
            'boleh_cuti_besar' => $bolehCutiBesar,
            // Cuti Alasan Penting hanya untuk PNS (status_kepegawaian = 'PNS').
            'boleh_cuti_alasan_penting' => $this->bolehCutiAlasanPenting($user),
            // Cuti Melahirkan hanya untuk perempuan (digit ke-15 NIP = 2).
            'boleh_cuti_melahirkan' => $this->bolehCutiMelahirkan($user),
            'masa_kerja_minimal_cuti_besar' => self::MASA_KERJA_MINIMAL_CUTI_BESAR,
            'tahun_saldo_tersedia' => $this->tahunSaldoTersedia($user->id),
            // Sisa saldo per tahun, mis. {"2026": 8, "2027": 12}.
            'saldo_per_tahun' => $this->saldoPerTahun($user),
            'pengajuan_aktif' => $rentangAktif,
            'batas_tahun_pengajuan' => [
                'min' => min(LeaveYearWindow::years()),
                'max' => max(LeaveYearWindow::years()),
            ],
        ]);
    }

    private function saldoPerTahun(Pegawai $user): array
    {
        $hasil = [];

        foreach ($this->tahunSaldoTersedia($user->id) as $tahun) {
            $hasil[(string) $tahun] = (int) $this->hitungSaldoCuti(
                $user->id,
                (int) $tahun,
                (int) ($user->jatah_cuti ?? 12)
            )['sisa_cuti'];
        }

        return $hasil;
    }

    // 2. Simpan Pengajuan
    public function store(Request $request)
    {
        $request->validate([
            'jenis_cuti' => ['required', 'string', 'in:Cuti Tahunan,Cuti Melahirkan,Cuti Besar,Cuti Alasan Penting'],
            'anak_ke' => ['nullable', 'required_if:jenis_cuti,Cuti Melahirkan', 'integer', 'min:1', 'max:20'],
            // Tanggal mulai boleh yang sudah lewat (backdate).
            'tanggal_mulai' => ['required', 'date'],
            'tanggal_selesai' => ['required', 'date', 'after_or_equal:tanggal_mulai'],
            'keterangan' => ['required', 'string'],
            'alamat_cuti' => ['required', 'string'],
            'no_telp' => ['required', 'string', 'max:20'],
            'lampiran' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:2048'],
        ], $this->pesanValidasiLampiran());

        $this->pastikanRentangWajar($request->tanggal_mulai, $request->tanggal_selesai);

        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();

        $jenisCuti = $this->normalizeJenisCuti($request->jenis_cuti);

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

        // Cuti Alasan Penting hanya untuk PNS. PPPK dan pegawai yang status
        // kepegawaiannya belum diisi ditolak.
        if ($jenisCuti === 'Cuti Alasan Penting' && !$this->bolehCutiAlasanPenting($user)) {
            return back()->withErrors([
                'jenis_cuti' => 'Cuti Alasan Penting hanya dapat diajukan oleh pegawai berstatus PNS.',
            ])->withInput();
        }

        // Cuti Melahirkan hanya untuk pegawai perempuan (digit ke-15 NIP = 2).
        if ($jenisCuti === 'Cuti Melahirkan' && !$this->bolehCutiMelahirkan($user)) {
            return back()->withErrors([
                'jenis_cuti' => 'Cuti Melahirkan hanya dapat diajukan oleh pegawai perempuan.',
            ])->withInput();
        }

        // Semua tahun yang disentuh rentang tanggal harus punya saldo (termasuk
        // cuti lintas tahun dan tanggal mundur ke tahun sebelumnya).
        if ($jenisCuti === 'Cuti Tahunan') {
            $tahunKosong = $this->tahunTanpaSaldo($user->id, $request->tanggal_mulai, $request->tanggal_selesai);

            if ($tahunKosong !== null) {
                return back()->withErrors([
                    'jenis_cuti' => "Saldo cuti tahun {$tahunKosong} belum tersedia. Cuti Tahunan untuk tahun tersebut baru bisa diajukan setelah Admin HR membuat saldonya.",
                ])->withInput();
            }
        }

        $hariLiburDates = $this->getHariLiburDates();
        $hariPerTahun = $this->hitungHariKerjaPerTahun($request->tanggal_mulai, $request->tanggal_selesai, $hariLiburDates);
        $jumlah_hari = array_sum($hariPerTahun);

        if ($jumlah_hari === 0) {
            return back()->withErrors([
                'tanggal_mulai' => 'Tanggal yang dipilih tidak valid karena hanya mencakup akhir pekan dan/atau hari libur nasional/cuti bersama.',
            ])->withInput();
        }

        if ($bentrok = $this->pengajuanBentrok($user->id, $request->tanggal_mulai, $request->tanggal_selesai)) {
            return back()->withErrors([
                'tanggal_mulai' => $this->pesanBentrok($bentrok),
            ])->withInput();
        }

        // Cek awal untuk pesan cepat; cek ulang kedap race condition ada di transaksi.
        $kuotaDefault = (int) ($user->jatah_cuti ?? 12);

        if ($jenisCuti === 'Cuti Tahunan') {
            if ($pesan = $this->pesanSaldoKurang($user->id, $hariPerTahun, $kuotaDefault)) {
                return back()->withErrors([
                    'jenis_cuti' => $pesan,
                ])->withInput();
            }
        }

        // Lampiran disimpan di disk 'local' (private).
        $lampiranPath = null;
        if ($request->hasFile('lampiran')) {
            $lampiranPath = $request->file('lampiran')->store('lampiran-cuti', 'local');
        }

        $levelAwal = $this->tentukanLevelAwalCuti($user);
        $statusAwal = $levelAwal['status'];
        $levelSaatIni = $levelAwal['level'];
        $targetRoleNotifikasi = $levelAwal['target_role'];

        $isKabiro = (int) $user->role_id === 6;

        try {
            $pengajuan = DB::transaction(function () use ($user, $jenisCuti, $request, $jumlah_hari, $hariPerTahun, $lampiranPath, $statusAwal, $levelSaatIni, $isKabiro, $kuotaDefault) {
                // Kunci baris pegawai, lalu cek ulang bentrok dan saldo.
                Pegawai::where('id', $user->id)->lockForUpdate()->first();

                if ($bentrok = $this->pengajuanBentrok($user->id, $request->tanggal_mulai, $request->tanggal_selesai)) {
                    throw ValidationException::withMessages([
                        'tanggal_mulai' => $this->pesanBentrok($bentrok),
                    ]);
                }

                if ($jenisCuti === 'Cuti Tahunan') {
                    if ($pesan = $this->pesanSaldoKurang($user->id, $hariPerTahun, $kuotaDefault)) {
                        throw ValidationException::withMessages([
                            'jenis_cuti' => $pesan,
                        ]);
                    }
                }

                $p = PengajuanCuti::create([
                    'pegawai_id' => $user->id,
                    'jenis_cuti' => $jenisCuti,
                    'anak_ke' => $jenisCuti === 'Cuti Melahirkan' ? $request->anak_ke : null,
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

                if ($isKabiro) {
                    $this->setujuiOtomatisKabiro($p, $user);
                }

                return $p;
            });
        } catch (\Throwable $e) {
            // Hapus lampiran yang sudah terunggah supaya tidak jadi file yatim.
            if ($lampiranPath) {
                Storage::disk('local')->delete($lampiranPath);
                Storage::disk('public')->delete($lampiranPath);
            }

            throw $e;
        }

        if ($isKabiro) {
            return redirect()->route('karyawan.riwayat')
                ->with('success', 'Pengajuan cuti Anda disetujui otomatis.');
        }

        $atasanTarget = $this->cariAtasanTujuan($user, (int) $targetRoleNotifikasi);

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

    // 3. Halaman Riwayat Pengajuan
    //
    // JENDELA TAHUN: hanya pengajuan yang rentang tanggalnya BERSINGGUNGAN (overlap)
    // dengan tahun yang dipilih yang diambil dari database. Cuti lintas tahun
    // (mis. 31 Des 2026 - 04 Jan 2027) muncul di 2026 maupun 2027. Tahun yang
    // dipilih divalidasi di server (LeaveYearWindow::resolve), jadi ?tahun=2019
    // otomatis kembali ke tahun berjalan. Data lama TIDAK dihapus, hanya tidak diambil.
    public function history(Request $request)
    {
        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();

        $tahun = LeaveYearWindow::resolve($request->query('tahun'));
        [$awalTahun, $akhirTahun] = LeaveYearWindow::range($tahun);

        $query = PengajuanCuti::with([
            'pegawai',
            'atasanL1:id,nama,jabatan',
            'atasanL3:id,nama,jabatan',
            'atasanL4:id,nama,jabatan',
            'approvalLogs.approver:id,nama,jabatan',
            'cutiDitangguhkan',
        ])
            ->where('pegawai_id', $user->id)
            // Overlap tanpa DATE(): perbandingan langsung supaya index
            // (pegawai_id, tanggal_mulai, tanggal_selesai) bisa dipakai.
            ->where('tanggal_mulai', '<=', $akhirTahun->toDateTimeString())
            ->where('tanggal_selesai', '>=', $awalTahun->toDateTimeString())
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');

        if ($request->filled('search')) {
            $query->where('keterangan', 'like', '%' . $request->search . '%');
        }

        // Filter status memakai STATUS EFEKTIF (sama dengan frontend).
        if ($request->filled('status')) {
            $statusFilter = $request->status;

            $query->where(function ($q) use ($statusFilter) {
                if ($statusFilter === 'ditangguhkan') {
                    $q->whereIn('status', ['ditangguhkan', 'dibatalkan_ditangguhkan'])
                        ->orWhere(function ($x) {
                            $x->where('status', 'ditolak')
                                ->where('keterangan', 'like', '%ditangguhkan%');
                        });
                } elseif ($statusFilter === 'ditolak') {
                    $q->where('status', 'ditolak')
                        ->where(function ($x) {
                            $x->whereNull('keterangan')
                                ->orWhere('keterangan', 'not like', '%ditangguhkan%');
                        });
                } elseif ($statusFilter === 'dibatalkan_reguler') {
                    $q->whereIn('status', ['dibatalkan_reguler', 'dibatalkan']);
                } else {
                    $q->where('status', $statusFilter);
                }
            });
        }

        if ($request->filled('jenis_cuti')) {
            $query->where('jenis_cuti', $request->jenis_cuti);
        }

        $riwayat = $query->paginate(5)->withQueryString();

        // Nama atasan L1-L4 untuk timeline approval di modal detail.
        $namaL1 = optional($user->ketua_tim_kerja)->nama;
        $namaL2 = optional($user->ketua_kelompok)->nama;
        $namaL3 = Pegawai::where('role_id', 4)
            ->where(fn($q) => $q->where('status_aktif', 'Aktif')->orWhereNull('status_aktif'))
            ->orderBy('id')
            ->value('nama') ?? 'Kasubag TU';
        $namaL4 = Pegawai::where('role_id', 6)
            ->where(fn($q) => $q->where('status_aktif', 'Aktif')->orWhereNull('status_aktif'))
            ->orderBy('id')
            ->value('nama') ?? 'Kepala Biro Perencanaan';

        // Lengkapi info saldo (kuota, terpakai, Tgh, dikembalikan, total tersedia, dll.)
        // lewat RincianSaldo sebelum menempelkan nama atasan & flag aksi.
        RincianSaldo::lengkapi($riwayat->getCollection(), $tahun);

        foreach ($riwayat->getCollection() as $item) {
            if ($item->pegawai) {
                $item->pegawai->nama_l1 = $namaL1;
                $item->pegawai->nama_l2 = $namaL2;
                $item->pegawai->nama_l3 = $namaL3;
                $item->pegawai->nama_l4 = $namaL4;
            }

            $item->bisa_batal_mandiri = $this->bisaDibatalkanMandiri($item);
            $item->bisa_direvisi = $item->bisaDirevisi();
        }

        $saldoInfo = $this->hitungSaldoCuti($user->id, (int) date('Y'), (int) ($user->jatah_cuti ?? 12));

        $stats = [
            'kuota_tahunan' => $saldoInfo['kuota_tahunan'],
            'carry_forward_normal' => $saldoInfo['carry_forward_normal'],
            'saldo_bawaan_eligible' => $saldoInfo['saldo_bawaan_eligible'],
            'cuti_dikembalikan' => $saldoInfo['cuti_dikembalikan'],
            'cuti_terpakai' => $saldoInfo['cuti_terpakai'],
            'cuti_ditangguhkan' => $saldoInfo['cuti_ditangguhkan'],
            'sisa_kuota_tahun_ini' => max(
                $saldoInfo['kuota_tahunan']
                    - $saldoInfo['cuti_terpakai']
                    - $saldoInfo['cuti_ditangguhkan'],
                0
            ),
            'total_cuti_tersedia' => $saldoInfo['total_cuti_tersedia'],
        ];

        $adaAtasanL1 = $this->timKerjaPunyaAtasanL1($user->tim_kerja);

        return Inertia::render('Karyawan/RiwayatPengajuan', [
            'riwayat' => $riwayat,
            'filters' => $request->only(['search', 'status', 'jenis_cuti']),
            // Filter tahun: tahun yang sedang dipilih (sudah divalidasi) dan
            // daftar pilihan dropdown (menurun, mis. 2027, 2026, 2025, 2024).
            'tahun_dipilih' => $tahun,
            'daftar_tahun' => LeaveYearWindow::years(),
            'sisa_cuti' => (int) $saldoInfo['sisa_cuti'],
            'stats' => $stats,
            'hariLiburs' => HariLibur::orderBy('tanggal')->get(),
            'ada_atasan_l1' => $adaAtasanL1,
            'rute_level' => $this->hitungRuteLevel($user),
            'tahun_saldo_tersedia' => $this->tahunSaldoTersedia($user->id),
        ]);
    }

    // 4. Kalender Tim (per bulan lewat ?bulan=YYYY-MM, hanya status disetujui).
    // L1 (role 2) diperlakukan sama seperti L2 (role 3).
    public function teamCalendar(Request $request)
    {
        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();

        $bulan = $request->input('bulan');
        if (!is_string($bulan) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $bulan)) {
            $bulan = now()->format('Y-m');
        }
        $awalBulan = Carbon::createFromFormat('Y-m-d', $bulan . '-01')->startOfMonth()->toDateString();
        $akhirBulan = Carbon::createFromFormat('Y-m-d', $bulan . '-01')->endOfMonth()->toDateString();

        $listTimKerja = [];
        $listKelompok = [];

        $pegawaiSelect = 'pegawai:id,nama,kelompok_substansi,tim_kerja,jabatan,foto_profil';

        if (in_array((int) $user->role_id, [2, 3], true)) {
            $query = PengajuanCuti::with($pegawaiSelect)
                ->whereHas('pegawai', function ($q) use ($user) {
                    $q->where('kelompok_substansi', $user->kelompok_substansi)
                        ->where('id', '!=', $user->id);
                });

            $listTimKerja = Pegawai::where('kelompok_substansi', $user->kelompok_substansi)
                ->whereNotNull('tim_kerja')
                ->whereRaw("TRIM(tim_kerja) NOT IN ('', '-')")
                ->distinct()
                ->orderBy('tim_kerja')
                ->pluck('tim_kerja')
                ->values();

            // Opsi "Tanpa Tim Kerja" (nilai '-') hanya muncul bila di kelompok ini memang
            // ada pegawai tanpa tim. Data impor bisa tersimpan sebagai NULL, '' atau '-'.
            $adaTanpaTim = Pegawai::where('kelompok_substansi', $user->kelompok_substansi)
                ->where(function ($q) {
                    $q->whereNull('tim_kerja')
                        ->orWhereRaw("TRIM(tim_kerja) IN ('', '-')");
                })
                ->exists();

            if ($adaTanpaTim) {
                $listTimKerja->push('-'); // paling bawah
            }
        } elseif (in_array((int) $user->role_id, [4, 6], true)) {
            $query = PengajuanCuti::with($pegawaiSelect)
                ->whereHas('pegawai', function ($q) use ($user) {
                    $q->where('id', '!=', $user->id);
                });

            $listKelompok = Pegawai::whereNotNull('kelompok_substansi')
                ->distinct()
                ->orderBy('kelompok_substansi')
                ->pluck('kelompok_substansi')
                ->values();
        } else {
            $query = PengajuanCuti::with($pegawaiSelect)
                ->whereHas('pegawai', function ($q) use ($user) {
                    $q->where('kelompok_substansi', $user->kelompok_substansi)
                        ->where('id', '!=', $user->id);
                });
        }

        $cutiRekan = $query
            ->where('status', 'disetujui')
            ->whereDate('tanggal_mulai', '<=', $akhirBulan)
            ->whereDate('tanggal_selesai', '>=', $awalBulan)
            ->orderBy('tanggal_mulai', 'asc')
            ->get();

        $cutiSaya = PengajuanCuti::with($pegawaiSelect)
            ->where('pegawai_id', $user->id)
            ->where('status', 'disetujui')
            ->whereDate('tanggal_mulai', '<=', $akhirBulan)
            ->whereDate('tanggal_selesai', '>=', $awalBulan)
            ->get();

        $cutiTim = $cutiRekan
            ->concat($cutiSaya)
            ->sortBy('tanggal_mulai')
            ->values();

        // Sembunyikan kolom privat/internal yang tidak dipakai KalenderTim.vue.
        $cutiTim->each->makeHidden([
            'keterangan',
            'alamat_cuti',
            'no_telp',
            'lampiran',
            'anak_ke',
            'atasan_l1_id',
            'atasan_l3_id',
            'atasan_l4_id',
            'level_saat_ini',
        ]);

        $hariLiburs = \App\Models\HariLibur::all();

        $disetujuiBulanIni = $cutiTim->count();

        return Inertia::render('Karyawan/KalenderTim', [
            'cutiTim' => $cutiTim,
            'bulan' => $bulan,
            'kelompok_substansi' => $user->kelompok_substansi,
            'hariLiburs' => $hariLiburs,
            'userRoleId' => $user->role_id,
            'userTimKerja' => $user->tim_kerja,
            'userPegawaiId' => $user->id,
            'listTimKerja' => $listTimKerja,
            'listKelompok' => $listKelompok,
            'disetujuiBulanIni' => $disetujuiBulanIni,
        ]);
    }

    // 5. Unduh PDF Bukti Cuti (hanya pemilik, hanya status disetujui)
    public function downloadPdf(int $id)
    {
        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();

        $pengajuan = PengajuanCuti::with(['pegawai', 'atasanL1', 'atasanL3', 'atasanL4', 'approvalLogs'])->findOrFail($id);

        if ($pengajuan->pegawai_id !== $user->id || $pengajuan->status !== 'disetujui') {
            abort(403, 'Anda tidak memiliki akses, atau cuti belum disetujui sepenuhnya.');
        }

        $tanggalMasuk = $this->tanggalMasukEfektif($pengajuan->pegawai) ?? Carbon::now();
        $masaKerja = $tanggalMasuk->diff(Carbon::now())->format('%y Tahun / %m Bulan');

        $tahunCuti = (int) date('Y', strtotime($pengajuan->tanggal_mulai));

        $saldoInfo = $this->hitungSaldoCuti(
            (int) $pengajuan->pegawai_id,
            $tahunCuti,
            (int) ($pengajuan->pegawai->jatah_cuti ?? 12)
        );
        $sisaCutiAsli = $saldoInfo['total_cuti_tersedia'];

        $kasubag = Pegawai::where('role_id', 4)
            ->where(fn($q) => $q->where('status_aktif', 'Aktif')->orWhereNull('status_aktif'))
            ->orderBy('id')
            ->first();
        $kabiro = Pegawai::where('role_id', 6)
            ->where(fn($q) => $q->where('status_aktif', 'Aktif')->orWhereNull('status_aktif'))
            ->orderBy('id')
            ->first();

        $data = [
            'pengajuan' => $pengajuan,
            'pegawai' => $pengajuan->pegawai,
            'masaKerja' => $masaKerja,
            'sisaCuti' => (int) $sisaCutiAsli,
            'kasubag' => $kasubag,
            'kabiro' => $kabiro,
            'pemohonKabiro' => (int) $pengajuan->pegawai->role_id === 6,
            // Pemohon Kasubag (L3): rantai approval hanya L4.
            'pemohonKasubag' => (int) $pengajuan->pegawai->role_id === 4,
        ];

        $pdf = Pdf::loadView('pdf.surat-cuti', $data)->setPaper('A4', 'portrait');
        $namaFile = 'Surat_Izin_Cuti_' . $pengajuan->pegawai->nama . '_' . $pengajuan->tanggal_mulai . '.pdf';

        return $pdf->download($namaFile);
    }

    // 6. Batalkan pengajuan yang masih antre (menunggu_l1..l4)
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

    // 7. Batalkan cuti yang sudah disetujui (kapan saja, wajib alasan).
    // Saldo dihitung dinamis dari cuti disetujui, jadi mengubah status menjadi
    // 'dibatalkan_reguler' otomatis mengembalikan hari ke SEMUA tahun yang
    // disentuh cuti. Kolom legacy `sisa` juga dikembalikan per tahun. Baris
    // pengajuan dikunci dalam transaksi agar klik ganda tidak diproses dua kali.
    public function batalkanMandiri(Request $request, int $id)
    {
        $request->validate([
            'alasan_pembatalan' => 'required|string|max:255',
        ], [
            'alasan_pembatalan.required' => 'Alasan pembatalan wajib diisi.',
        ]);

        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();

        $cuti = DB::transaction(function () use ($id, $user, $request) {
            $cuti = PengajuanCuti::lockForUpdate()->findOrFail($id);

            if ((int) $cuti->pegawai_id !== (int) $user->id || !$this->bisaDibatalkanMandiri($cuti)) {
                return null;
            }

            $cuti->status = 'dibatalkan_reguler';
            $cuti->keterangan = $cuti->keterangan . ' | Batal Mandiri: ' . $request->alasan_pembatalan;
            $cuti->save();

            // Kembalikan kolom legacy `sisa` per tahun (kebalikan dari approve L4
            // di MonitoringCutiController, yang memanggil sesuaikanSisaLegacy(-1)).
            if ($cuti->jenis_cuti === 'Cuti Tahunan') {
                SaldoCuti::sesuaikanSisaLegacy($cuti, +1);
            }

            // Hitung ulang bawaan tahun-tahun yang disentuh cuti ini dan sesudahnya.
            SaldoCuti::sinkronkanBawaanPengajuan($cuti);

            return $cuti;
        });

        if (!$cuti) {
            return redirect()->back()->with(
                'error',
                'Cuti ini tidak dapat dibatalkan (bukan milik Anda, atau statusnya sudah bukan disetujui).'
            );
        }

        $pesanSukses = $cuti->jenis_cuti === 'Cuti Tahunan'
            ? 'Cuti berhasil dibatalkan dan saldo telah dikembalikan.'
            : 'Cuti berhasil dibatalkan.';

        return redirect()->back()->with('success', $pesanSukses);
    }

    // 8. Revisi cuti (khusus status dibatalkan_ditangguhkan)
    public function revisi(Request $request, int $id)
    {
        $cuti = PengajuanCuti::findOrFail($id);

        /** @var \App\Models\Pegawai $userLogin */
        $userLogin = Auth::user();

        if ((int) $cuti->pegawai_id !== (int) $userLogin->id) {
            abort(403, 'Anda tidak diizinkan merevisi pengajuan ini.');
        }

        // Konsisten dengan store(): tanggal mulai boleh yang sudah lewat.
        $request->validate([
            'tanggal_mulai' => ['required', 'date'],
            'tanggal_selesai' => ['required', 'date', 'after_or_equal:tanggal_mulai'],
        ], [
            'tanggal_mulai.required' => 'Tanggal mulai wajib diisi.',
            'tanggal_mulai.date' => 'Tanggal mulai tidak valid.',
            'tanggal_selesai.required' => 'Tanggal selesai wajib diisi.',
            'tanggal_selesai.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
        ]);

        $this->pastikanRentangWajar($request->tanggal_mulai, $request->tanggal_selesai, 'tanggal_mulai');

        if (
            (int) $cuti->pegawai_id !== (int) $userLogin->id
            || $cuti->status !== 'dibatalkan_ditangguhkan'
        ) {
            return redirect()->back()->with('error', 'Cuti ini tidak dapat direvisi.');
        }

        if (!$cuti->bisaDirevisi()) {
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

        if ($cuti->jenis_cuti === 'Cuti Tahunan') {
            $tahunKosong = $this->tahunTanpaSaldo($userLogin->id, $request->tanggal_mulai, $request->tanggal_selesai);

            if ($tahunKosong !== null) {
                // withErrors (bukan with('error')) supaya modal revisi tidak tertutup.
                return back()->withErrors([
                    'tanggal_mulai' => "Saldo cuti tahun {$tahunKosong} belum tersedia. Revisi baru bisa diajukan setelah Admin HR membuat saldonya.",
                ]);
            }
        }

        $hariPerTahun = $this->hitungHariKerjaPerTahun($request->tanggal_mulai, $request->tanggal_selesai);
        $jumlah_hari = array_sum($hariPerTahun);

        if ($jumlah_hari === 0) {
            return back()->withErrors([
                'tanggal_mulai' => 'Tanggal yang dipilih jatuh pada hari libur sepenuhnya (akhir pekan dan/atau hari libur nasional/cuti bersama).',
            ]);
        }

        if ($bentrok = $this->pengajuanBentrok($userLogin->id, $request->tanggal_mulai, $request->tanggal_selesai, $cuti->id)) {
            return back()->withErrors([
                'tanggal_mulai' => $this->pesanBentrok($bentrok),
            ]);
        }

        // Pengajuan yang direvisi berstatus 'dibatalkan_ditangguhkan', jadi tidak
        // ikut terhitung sebagai "menunggu" maupun "terpakai". Namun baris Tgh
        // miliknya masih ada sampai revisi diproses, jadi dikecualikan dari saldo
        // supaya tidak memblokir dirinya sendiri.
        if ($cuti->jenis_cuti === 'Cuti Tahunan') {
            $kecualikanTangguh = $cuti->cutiDitangguhkan()
                ->get()
                ->groupBy('tahun_asal')
                ->map(fn($baris) => (int) $baris->sum('jumlah_hari'))
                ->all();

            $pesanSaldo = $this->pesanSaldoKurang(
                $userLogin->id,
                $hariPerTahun,
                (int) ($userLogin->jatah_cuti ?? 12),
                $kecualikanTangguh
            );

            if ($pesanSaldo) {
                return back()->withErrors([
                    'tanggal_mulai' => 'Revisi melebihi sisa saldo. ' . $pesanSaldo,
                ]);
            }
        }

        $levelAwal = $this->tentukanLevelAwalCuti($userLogin);

        // Kepala Biro (L4): revisi langsung disetujui. setujuiOtomatisKabiro()
        // sudah menyinkronkan bawaan.
        if ((int) $userLogin->role_id === 6) {
            DB::transaction(function () use ($cuti, $request, $jumlah_hari, $userLogin) {
                $cuti->cutiDitangguhkan()->delete();
                $cuti->update([
                    'tanggal_mulai' => $request->tanggal_mulai,
                    'tanggal_selesai' => $request->tanggal_selesai,
                    'jumlah_hari' => $jumlah_hari,
                    'keterangan' => $cuti->keterangan . ' | [DIREVISI]',
                ]);

                $this->setujuiOtomatisKabiro($cuti, $userLogin);
            });

            return redirect()->back()->with('success', 'Tanggal cuti berhasil direvisi dan disetujui otomatis.');
        }

        DB::transaction(function () use ($cuti, $request, $jumlah_hari, $levelAwal, $userLogin) {
            $cuti->cutiDitangguhkan()->delete();
            $cuti->update([
                'tanggal_mulai' => $request->tanggal_mulai,
                'tanggal_selesai' => $request->tanggal_selesai,
                'jumlah_hari' => $jumlah_hari,
                'status' => $levelAwal['status'],
                'level_saat_ini' => $levelAwal['level'],
                'keterangan' => $cuti->keterangan . ' | [DIREVISI]',
            ]);

            $atasanTarget = $this->cariAtasanTujuan($userLogin, (int) $levelAwal['target_role']);

            if ($atasanTarget) {
                Notifikasi::create([
                    'pegawai_id' => $atasanTarget->id,
                    'judul'      => 'Revisi Pengajuan Cuti',
                    'pesan'      => 'Ada revisi tanggal cuti dari ' . $userLogin->nama . ' yang butuh persetujuan Anda.',
                    'tautan'     => route('atasan.approval'),
                    'is_read'    => false,
                ]);
            }
        });

        return redirect()->back()->with('success', 'Tanggal cuti berhasil direvisi dan diajukan ulang.');
    }
}