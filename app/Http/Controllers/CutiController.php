<?php

namespace App\Http\Controllers;

use App\Http\Requests\BatalkanMandiriRequest;
use App\Http\Requests\StoreCutiRequest;
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

/**
 * Controller sisi karyawan: ajukan cuti, riwayat, kalender tim, unduh PDF, dan batal.
 * Approve/reject ada di MonitoringCutiController.
 */
class CutiController extends Controller
{
    private const MASA_KERJA_MINIMAL_CUTI_BESAR = 5;
    private const STATUS_AKTIF = ['menunggu_l1', 'menunggu_l2', 'menunggu_l3', 'menunggu_l4', 'disetujui'];
    private const MAKS_RENTANG_HARI = 92;

    /** Cuti Alasan Penting hanya untuk PNS. */
    private function bolehCutiAlasanPenting(Pegawai $pegawai): bool
    {
        return strtoupper(trim((string) $pegawai->status_kepegawaian)) === 'PNS';
    }

    /** Cuti Melahirkan hanya untuk perempuan (digit ke-15 NIP = 2). */
    private function bolehCutiMelahirkan(Pegawai $pegawai): bool
    {
        $nip = preg_replace('/\D/', '', (string) $pegawai->nip);
        return strlen($nip) === 18 && $nip[14] === '2';
    }

    /** Validasi rentang tanggal (backdate boleh dalam jendela tahun). */
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

    /**
     * Hitung saldo cuti (satu sumber dengan Dashboard & Rekap Kuota).
     * $kecualikanTangguh opsional (sisa dari fitur revisi yang sudah dihapus).
     */
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
        $terpakai = (int) SaldoCuti::terpakaiEfektif($pegawaiId, $tahun, $saldo);
        $bawaanEligible = SaldoCuti::hitungBawaan($sisaDuaTahunLalu, $sisaTahunLalu, $kuota);

        $dikembalikan = (int) CutiDitangguhkan::where('pegawai_id', $pegawaiId)
            ->where('tahun_asal', $tahun - 1)
            ->sum('jumlah_hari');

        $tangguh = max(
            0,
            (int) CutiDitangguhkan::where('pegawai_id', $pegawaiId)
                ->where('tahun_asal', $tahun)
                ->sum('jumlah_hari') - (int) ($kecualikanTangguh[$tahun] ?? 0)
        );

        $menunggu = $this->hariMenungguDiTahun($pegawaiId, $tahun);

        // Tgh diterapkan sesuai flag SaldoCuti (satu sumber dengan halaman lain).
        $hasil = RincianSaldo::hitungDenganTgh(
            $kuota,
            (int) $bawaanEligible,
            $dikembalikan,
            $terpakai,
            $tangguh
        );
        $totalTersedia = $hasil['total_cuti_tersedia'];

        return [
            'kuota_tahunan'           => $kuota,
            'carry_forward_normal'    => $sisaTahunLalu,
            'sisa_dua_tahun_lalu'     => $sisaDuaTahunLalu,
            'saldo_bawaan_eligible'   => $bawaanEligible,
            'cuti_dikembalikan'       => $dikembalikan,
            'cuti_terpakai'           => $terpakai,
            'cuti_ditangguhkan'       => $tangguh,
            'cuti_menunggu'           => $menunggu,
            'sisa_hak_tahun_berjalan' => $hasil['sisa_hak_tahun_berjalan'],
            'total_cuti_tersedia'     => $totalTersedia,
            'sisa_cuti'               => max($totalTersedia - $menunggu, 0),
        ];
    }

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

    /** Pecah hari kerja per tahun kalender (untuk cuti lintas tahun). */
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

    /** Cuti disetujui boleh dibatalkan pemiliknya kapan saja. */
    private function bisaDibatalkanMandiri(PengajuanCuti $cuti): bool
    {
        return $cuti->status === 'disetujui';
    }

    private function tahunTanpaSaldo(int $pegawaiId, string $mulai, string $selesai): ?int
    {
        $tahunAwal = Carbon::parse($mulai)->year;
        $tahunAkhir = Carbon::parse($selesai)->year;

        for ($tahun = $tahunAwal; $tahun <= $tahunAkhir; $tahun++) {
            $ada = SaldoCuti::where('pegawai_id', $pegawaiId)->where('tahun', $tahun)->exists();
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
        return Pegawai::where('role_id', 2)->where('tim_kerja', $timKerja)->exists();
    }

    /**
     * Penerima notifikasi untuk level tujuan: semua pejabat AKTIF (kecuali pemohon).
     * L1 per tim kerja, L2 per kelompok substansi, L3/L4 semua pejabat aktif role itu.
     */
    private function penerimaAtasan(Pegawai $pemohon, int $targetRole): \Illuminate\Support\Collection
    {
        return Pegawai::penerimaApproval($targetRole, $pemohon->tim_kerja, $pemohon->kelompok_substansi)
            ->reject(fn($p) => (int) $p->id === (int) $pemohon->id)
            ->values();
    }

    private function tentukanLevelAwalCuti(Pegawai $user): array
    {
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

        if ($this->timKerjaPunyaAtasanL1($user->tim_kerja)) {
            return ['status' => 'menunggu_l1', 'level' => 1, 'target_role' => 2];
        }
        if ($this->departemenPunyaAtasanLevel(3, $user->kelompok_substansi)) {
            return ['status' => 'menunggu_l2', 'level' => 2, 'target_role' => 3];
        }

        return ['status' => 'menunggu_l3', 'level' => 3, 'target_role' => 4];
    }

    /** Persetujuan otomatis Kepala Biro (dipanggil di dalam transaksi). */
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

        foreach (Pegawai::penerimaApproval(5) as $hr) {
            Notifikasi::create([
                'pegawai_id'   => $hr->id,
                'pengajuan_id' => $pengajuan->id,
                'judul'        => 'Info Cuti Kepala Biro',
                'pesan'        => $user->nama . ' (Kepala Biro) mengajukan cuti ' . $pengajuan->jenis_cuti
                    . ' dan disetujui otomatis. Info saja, tidak perlu tindakan.',
                'tautan'       => route('admin.rekap'),
                'is_read'      => false,
            ]);
        }
    }

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

    // 1. Form pengajuan
    public function create()
    {
        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();
        $tahunCuti = (int) date('Y');
        $saldoInfo = $this->hitungSaldoCuti($user->id, $tahunCuti, (int) ($user->jatah_cuti ?? 12));

        $hariLiburs = HariLibur::orderBy('tanggal')
            ->get(['tanggal', 'keterangan', 'is_cuti_bersama'])
            ->map(fn($h) => [
                'tanggal' => Carbon::parse($h->tanggal)->toDateString(),
                'keterangan' => $h->keterangan,
                'is_cuti_bersama' => (bool) $h->is_cuti_bersama,
            ]);

        $masaKerjaTahun = $this->hitungMasaKerjaTahun($user);
        $bolehCutiBesar = $masaKerjaTahun >= self::MASA_KERJA_MINIMAL_CUTI_BESAR;

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
            'cuti_menunggu' => (int) $saldoInfo['cuti_menunggu'],
            'hariLiburs' => $hariLiburs,
            'masa_kerja_tahun' => $masaKerjaTahun,
            'boleh_cuti_besar' => $bolehCutiBesar,
            'boleh_cuti_alasan_penting' => $this->bolehCutiAlasanPenting($user),
            'boleh_cuti_melahirkan' => $this->bolehCutiMelahirkan($user),
            'masa_kerja_minimal_cuti_besar' => self::MASA_KERJA_MINIMAL_CUTI_BESAR,
            'tahun_saldo_tersedia' => $this->tahunSaldoTersedia($user->id),
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

    // 2. Simpan pengajuan
    public function store(StoreCutiRequest $request)
    {
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

        if ($jenisCuti === 'Cuti Alasan Penting' && !$this->bolehCutiAlasanPenting($user)) {
            return back()->withErrors([
                'jenis_cuti' => 'Cuti Alasan Penting hanya dapat diajukan oleh pegawai berstatus PNS.',
            ])->withInput();
        }

        if ($jenisCuti === 'Cuti Melahirkan' && !$this->bolehCutiMelahirkan($user)) {
            return back()->withErrors([
                'jenis_cuti' => 'Cuti Melahirkan hanya dapat diajukan oleh pegawai perempuan.',
            ])->withInput();
        }

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

        $kuotaDefault = (int) ($user->jatah_cuti ?? 12);
        if ($jenisCuti === 'Cuti Tahunan') {
            if ($pesan = $this->pesanSaldoKurang($user->id, $hariPerTahun, $kuotaDefault)) {
                return back()->withErrors(['jenis_cuti' => $pesan])->withInput();
            }
        }

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
            $pengajuan = DB::transaction(function () use (
                $user,
                $jenisCuti,
                $request,
                $jumlah_hari,
                $hariPerTahun,
                $lampiranPath,
                $statusAwal,
                $levelSaatIni,
                $isKabiro,
                $kuotaDefault
            ) {
                Pegawai::where('id', $user->id)->lockForUpdate()->first();

                if ($bentrok = $this->pengajuanBentrok($user->id, $request->tanggal_mulai, $request->tanggal_selesai)) {
                    throw ValidationException::withMessages([
                        'tanggal_mulai' => $this->pesanBentrok($bentrok),
                    ]);
                }

                if ($jenisCuti === 'Cuti Tahunan') {
                    if ($pesan = $this->pesanSaldoKurang($user->id, $hariPerTahun, $kuotaDefault)) {
                        throw ValidationException::withMessages(['jenis_cuti' => $pesan]);
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

        // Susulan = cuti sudah dimulai sebelum pengajuan dibuat. Kalimat
        // "dari {nama} yang butuh persetujuan Anda." sengaja dipertahankan.
        $susulan = $pengajuan->isSusulan();
        foreach ($this->penerimaAtasan($user, (int) $targetRoleNotifikasi) as $atasan) {
            Notifikasi::create([
                'pegawai_id'   => $atasan->id,
                'pengajuan_id' => $pengajuan->id,
                'judul'        => 'Pengajuan Cuti Baru',
                'pesan'        => 'Ada pengajuan cuti ' . ($susulan ? 'susulan' : 'baru')
                    . ' dari ' . $user->nama . ' yang butuh persetujuan Anda.'
                    . ($susulan ? ' Cuti sudah dijalani sebelum diajukan.' : ''),
                'tautan'       => route('atasan.approval'),
                'is_read'      => false,
            ]);
        }

        return redirect()->route('karyawan.riwayat')->with('success', 'Pengajuan cuti berhasil dikirim.');
    }

    // 3. Riwayat pengajuan (dibatasi jendela tahun)
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
        ])
            ->where('pegawai_id', $user->id)
            ->where('tanggal_mulai', '<=', $akhirTahun->toDateTimeString())
            ->where('tanggal_selesai', '>=', $awalTahun->toDateTimeString())
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');

        if ($request->filled('search')) {
            $query->where('keterangan', 'like', '%' . $request->search . '%');
        }

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

        RincianSaldo::lengkapi($riwayat->getCollection(), $tahun);

        foreach ($riwayat->getCollection() as $item) {
            if ($item->pegawai) {
                $item->pegawai->nama_l1 = $namaL1;
                $item->pegawai->nama_l2 = $namaL2;
                $item->pegawai->nama_l3 = $namaL3;
                $item->pegawai->nama_l4 = $namaL4;
            }
            $item->bisa_batal_mandiri = $this->bisaDibatalkanMandiri($item);
        }

        $saldoInfo = $this->hitungSaldoCuti($user->id, (int) date('Y'), (int) ($user->jatah_cuti ?? 12));

        $stats = [
            'kuota_tahunan' => $saldoInfo['kuota_tahunan'],
            'carry_forward_normal' => $saldoInfo['carry_forward_normal'],
            'saldo_bawaan_eligible' => $saldoInfo['saldo_bawaan_eligible'],
            'cuti_dikembalikan' => $saldoInfo['cuti_dikembalikan'],
            'cuti_terpakai' => $saldoInfo['cuti_terpakai'],
            'cuti_ditangguhkan' => $saldoInfo['cuti_ditangguhkan'],
            'sisa_kuota_tahun_ini' => $saldoInfo['sisa_hak_tahun_berjalan'],
            'total_cuti_tersedia' => $saldoInfo['total_cuti_tersedia'],
        ];

        return Inertia::render('Karyawan/RiwayatPengajuan', [
            'riwayat' => $riwayat,
            'filters' => $request->only(['search', 'status', 'jenis_cuti']),
            'tahun_dipilih' => $tahun,
            'daftar_tahun' => LeaveYearWindow::years(),
            'sisa_cuti' => (int) $saldoInfo['sisa_cuti'],
            'stats' => $stats,
            'hariLiburs' => HariLibur::orderBy('tanggal')->get(),
            'ada_atasan_l1' => $this->timKerjaPunyaAtasanL1($user->tim_kerja),
            'rute_level' => $this->hitungRuteLevel($user),
            'tahun_saldo_tersedia' => $this->tahunSaldoTersedia($user->id),
        ]);
    }

    // 4. Kalender Tim
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
                ->whereHas('pegawai', fn($q) => $q->where('kelompok_substansi', $user->kelompok_substansi)->where('id', '!=', $user->id));

            $listTimKerja = Pegawai::where('kelompok_substansi', $user->kelompok_substansi)
                ->whereNotNull('tim_kerja')
                ->whereRaw("TRIM(tim_kerja) NOT IN ('', '-')")
                ->distinct()
                ->orderBy('tim_kerja')
                ->pluck('tim_kerja')
                ->values();

            $adaTanpaTim = Pegawai::where('kelompok_substansi', $user->kelompok_substansi)
                ->where(fn($q) => $q->whereNull('tim_kerja')->orWhereRaw("TRIM(tim_kerja) IN ('', '-')"))
                ->exists();
            if ($adaTanpaTim) {
                $listTimKerja->push('-');
            }
        } elseif (in_array((int) $user->role_id, [4, 6], true)) {
            $query = PengajuanCuti::with($pegawaiSelect)
                ->whereHas('pegawai', fn($q) => $q->where('id', '!=', $user->id));
            $listKelompok = Pegawai::whereNotNull('kelompok_substansi')
                ->distinct()
                ->orderBy('kelompok_substansi')
                ->pluck('kelompok_substansi')
                ->values();
        } else {
            $query = PengajuanCuti::with($pegawaiSelect)
                ->whereHas('pegawai', fn($q) => $q->where('kelompok_substansi', $user->kelompok_substansi)->where('id', '!=', $user->id));
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

        $cutiTim = $cutiRekan->concat($cutiSaya)->sortBy('tanggal_mulai')->values();
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

        return Inertia::render('Karyawan/KalenderTim', [
            'cutiTim' => $cutiTim,
            'bulan' => $bulan,
            'kelompok_substansi' => $user->kelompok_substansi,
            'hariLiburs' => HariLibur::all(),
            'userRoleId' => $user->role_id,
            'userTimKerja' => $user->tim_kerja,
            'userPegawaiId' => $user->id,
            'listTimKerja' => $listTimKerja,
            'listKelompok' => $listKelompok,
            'disetujuiBulanIni' => $cutiTim->count(),
        ]);
    }

    // 5. Unduh PDF
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

        $kasubag = Pegawai::where('role_id', 4)
            ->where(fn($q) => $q->where('status_aktif', 'Aktif')->orWhereNull('status_aktif'))
            ->orderBy('id')->first();
        $kabiro = Pegawai::where('role_id', 6)
            ->where(fn($q) => $q->where('status_aktif', 'Aktif')->orWhereNull('status_aktif'))
            ->orderBy('id')->first();

        $pdf = Pdf::loadView('pdf.surat-cuti', [
            'pengajuan' => $pengajuan,
            'pegawai' => $pengajuan->pegawai,
            'masaKerja' => $masaKerja,
            'sisaCuti' => (int) $saldoInfo['total_cuti_tersedia'],
            'kasubag' => $kasubag,
            'kabiro' => $kabiro,
            'pemohonKabiro' => (int) $pengajuan->pegawai->role_id === 6,
            'pemohonKasubag' => (int) $pengajuan->pegawai->role_id === 4,
        ])->setPaper('A4', 'portrait');

        $namaFile = 'Surat_Izin_Cuti_' . $pengajuan->pegawai->nama . '_' . $pengajuan->tanggal_mulai . '.pdf';
        return $pdf->download($namaFile);
    }

    // 6. Batalkan pengajuan yang masih menunggu
    public function cancel(int $id)
    {
        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();

        $berhasil = DB::transaction(function () use ($id, $user) {
            // lockForUpdate: mencegah bentrok dengan atasan yang menyetujui di detik yang sama.
            $pengajuan = PengajuanCuti::lockForUpdate()->findOrFail($id);

            if ((int) $pengajuan->pegawai_id !== (int) $user->id) {
                abort(403, 'Anda tidak diizinkan membatalkan pengajuan ini.');
            }

            // Role yang sedang menunggu, dibaca SEBELUM status diubah.
            $roleMenunggu = [
                'menunggu_l1' => 2,
                'menunggu_l2' => 3,
                'menunggu_l3' => 4,
                'menunggu_l4' => 6,
            ][$pengajuan->status] ?? null;

            if ($roleMenunggu === null) {
                return false;
            }

            $pengajuan->update(['status' => 'dibatalkan_reguler']);

            // Bersihkan notifikasi antrean di semua penerima, lalu kabari
            // atasan yang sedang menunggu bahwa pengajuan dibatalkan.
            Notifikasi::hapusAntreanPengajuan($pengajuan->id);

            foreach ($this->penerimaAtasan($user, $roleMenunggu) as $atasan) {
                Notifikasi::create([
                    'pegawai_id'   => $atasan->id,
                    'pengajuan_id' => $pengajuan->id,
                    'judul'        => 'Pengajuan Cuti Dibatalkan',
                    'pesan'        => "{$user->nama} membatalkan pengajuan cuti {$pengajuan->jenis_cuti} ("
                        . Carbon::parse($pengajuan->tanggal_mulai)->format('d/m/Y') . ' - '
                        . Carbon::parse($pengajuan->tanggal_selesai)->format('d/m/Y') . ').',
                    'tautan'       => route('atasan.approval'),
                    'is_read'      => false,
                ]);
            }

            return true;
        });

        if (!$berhasil) {
            return back()->with('error', 'Cuti ini sudah tidak dapat dibatalkan.');
        }

        return back()->with('success', 'Pengajuan cuti berhasil dibatalkan.');
    }

    // 7. Batalkan cuti yang sudah disetujui (kapan saja, wajib alasan)
    public function batalkanMandiri(BatalkanMandiriRequest $request, int $id)
    {
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

            if ($cuti->jenis_cuti === 'Cuti Tahunan') {
                SaldoCuti::sesuaikanSisaLegacy($cuti, +1);
            }
            SaldoCuti::sinkronkanBawaanPengajuan($cuti);

            return $cuti;
        });

        if (!$cuti) {
            return redirect()->back()->with(
                'error',
                'Cuti ini tidak dapat dibatalkan (bukan milik Anda, atau statusnya sudah bukan disetujui).'
            );
        }

        $pesan = $cuti->jenis_cuti === 'Cuti Tahunan'
            ? 'Cuti berhasil dibatalkan dan saldo telah dikembalikan.'
            : 'Cuti berhasil dibatalkan.';

        return redirect()->back()->with('success', $pesan);
    }
}
