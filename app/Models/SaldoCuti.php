<?php

namespace App\Models;

use App\Services\CarryOverSaldoService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

// Ringkasan perilaku penting model ini:
//
// 1) CUTI LINTAS TAHUN dihitung PER TAHUN: hari kerja di Desember memotong
//    saldo tahun itu, hari kerja di Januari memotong saldo tahun berikutnya.
//    Cuti yang seluruhnya dalam satu tahun memakai jumlah_hari apa adanya.
//    (hariCutiDiTahun, hariPerTahunDariPengajuan, terpakaiMurni,
//     sesuaikanSisaLegacy)
//
// 2) KOREKSI BULANAN (Rekap Laporan, tabel koreksi_cuti_bulanan) berlaku
//    sebagai SELISIH terhadap hitungan sistem, bukan angka mutlak:
//      selisih = nilai - nilai_sistem   (nilai_sistem = hitungan sistem saat koreksi dibuat)
//      tampil  = hitungan sistem SEKARANG + selisih
//    Contoh: sistem 3, koreksi jadi 4 (selisih +1); ada cuti baru -> sistem 4,
//    tampil 5. (terpakaiMurniPerBulan, petaSelisihTahun, terpakaiEfektif)
//
// 3) VERSI BATCH (anti N+1): muatBahanTerpakai() + terpakaiEfektifDariBahan()
//    menghitung Terpakai Efektif untuk BANYAK pegawai dari 2 query tetap,
//    dengan aturan prioritas yang SAMA dengan terpakaiEfektif(). Dipakai
//    App\Support\RincianSaldo::lengkapi().
class SaldoCuti extends Model
{
    /**
     * role_id Admin HR. Akun Admin HR tidak punya jatah cuti, sehingga tidak
     * dihitung sebagai "pegawai yang belum punya dompet" (lihat
     * jumlahPegawaiBelumPunyaDompet()). Harus sama dengan role route 'role:5'.
     */
    public const ROLE_ADMIN_HR = 5;

    /** Batas atas hak + bawaan (dipakai hanya bila TERAPKAN_BATAS_ATAS_TOTAL_HAK = true). */
    public const BATAS_ATAS_TOTAL_HAK = 24;

    /**
     * Sakelar batas atas Hak + Bawaan.
     *   false : tidak ada batas 24; mengubah Hak hanya mengubah Hak itu sendiri.
     *   true  : Hak + Bawaan dibatasi BATAS_ATAS_TOTAL_HAK (24).
     * Dipakai oleh hitungBawaan(), jadi berlaku sama untuk tampilan,
     * sinkronkanBawaan(), dan Command GenerateSaldoCutiTahunBaru.
     */
    public const TERAPKAN_BATAS_ATAS_TOTAL_HAK = false;

    /** Batas rantai tahun yang ikut disinkronkan sekali jalan (pengaman). */
    private const BATAS_RANTAI_SINKRON = 5;

    protected $guarded = ['id'];

    protected $casts = [
        'koreksi_saldo' => 'integer', // nullable: NULL tetap NULL, tidak jadi 0
    ];

    // Cache daftar tanggal libur selama satu request (satu query saja).
    private static ?array $cacheLibur = null;

    /**
     * Hitung estimasi sisa cuti yang bisa dibawa ke tahun depan (Carry Forward).
     * Maksimal 6 hari.
     *
     * @param int|float $sisaCutiTahunBerjalan
     */
    public static function hitungPreviewCarryOver($sisaCutiTahunBerjalan): int
    {
        // Pastikan tidak minus, lalu ambil nilai terkecil antara sisa cuti dan 6
        return (int) min(max($sisaCutiTahunBerjalan, 0), 6);
    }

    // ================= HELPER: PEMBAGIAN HARI PER TAHUN =================

    /** Daftar tanggal libur (Y-m-d), di-cache per request. */
    public static function tanggalLibur(): array
    {
        if (self::$cacheLibur === null) {
            self::$cacheLibur = HariLibur::pluck('tanggal')
                ->map(fn($tgl) => Carbon::parse($tgl)->toDateString())
                ->all();
        }

        return self::$cacheLibur;
    }

    /** Kosongkan cache libur (mis. setelah libur diubah di request yang sama / saat pengujian). */
    public static function lupakanCacheLibur(): void
    {
        self::$cacheLibur = null;
    }

    /**
     * Hari kerja (tanpa akhir pekan & hari libur) dari rentang [$mulai, $selesai]
     * yang jatuh DI DALAM tahun $tahun.
     */
    public static function hariKerjaDiTahun(
        \DateTimeInterface|string $mulai,
        \DateTimeInterface|string $selesai,
        int $tahun
    ): int {
        $awal = Carbon::parse($mulai)->startOfDay();
        $akhir = Carbon::parse($selesai)->startOfDay();

        $batasAwal = Carbon::create($tahun, 1, 1)->startOfDay();
        $batasAkhir = Carbon::create($tahun, 12, 31)->startOfDay();

        if ($awal->lt($batasAwal)) {
            $awal = $batasAwal;
        }
        if ($akhir->gt($batasAkhir)) {
            $akhir = $batasAkhir;
        }
        if ($awal->gt($akhir)) {
            return 0;
        }

        $libur = array_flip(self::tanggalLibur());
        $jumlah = 0;

        for ($tgl = $awal->copy(); $tgl->lte($akhir); $tgl->addDay()) {
            if (!$tgl->isWeekend() && !isset($libur[$tgl->toDateString()])) {
                $jumlah++;
            }
        }

        return $jumlah;
    }

    /**
     * Berapa hari cuti (jumlah_hari) yang menjadi beban tahun $tahun.
     *   - Cuti satu tahun  : seluruh $jumlahHari bila tahunnya sama, selain itu 0.
     *   - Cuti lintas tahun: hari kerja yang jatuh di tahun itu.
     */
    public static function hariCutiDiTahun(
        \DateTimeInterface|string $mulai,
        \DateTimeInterface|string $selesai,
        int $jumlahHari,
        int $tahun
    ): int {
        $tahunMulai = Carbon::parse($mulai)->year;
        $tahunSelesai = Carbon::parse($selesai)->year;

        if ($tahunMulai === $tahunSelesai) {
            return $tahunMulai === $tahun ? $jumlahHari : 0;
        }

        return self::hariKerjaDiTahun($mulai, $selesai, $tahun);
    }

    /**
     * Rincian [tahun => hari] untuk satu pengajuan, mis. [2026 => 3, 2027 => 2].
     */
    public static function hariPerTahunDariPengajuan(
        \DateTimeInterface|string $mulai,
        \DateTimeInterface|string $selesai,
        int $jumlahHari
    ): array {
        $tahunMulai = Carbon::parse($mulai)->year;
        $tahunSelesai = Carbon::parse($selesai)->year;

        $hasil = [];
        for ($tahun = $tahunMulai; $tahun <= $tahunSelesai; $tahun++) {
            $hasil[$tahun] = self::hariCutiDiTahun($mulai, $selesai, $jumlahHari, $tahun);
        }

        return $hasil;
    }

    /**
     * Tambah (arah = +1) atau kurangi (arah = -1) kolom legacy `sisa` pada
     * baris saldo SETIAP tahun yang disentuh pengajuan, sesuai hari di tahun
     * itu. Tidak pernah di bawah 0. Hanya untuk Cuti Tahunan.
     * Panggil DI DALAM DB::transaction.
     */
    public static function sesuaikanSisaLegacy(PengajuanCuti $pengajuan, int $arah): void
    {
        $rincian = self::hariPerTahunDariPengajuan(
            $pengajuan->tanggal_mulai,
            $pengajuan->tanggal_selesai,
            (int) $pengajuan->jumlah_hari
        );

        // Urutan tahun selalu menaik, sehingga urutan lock konsisten (aman dari deadlock).
        foreach ($rincian as $tahun => $hari) {
            if ($hari <= 0) {
                continue;
            }

            $saldo = static::where('pegawai_id', $pengajuan->pegawai_id)
                ->where('tahun', $tahun)
                ->lockForUpdate()
                ->first();

            if ($saldo) {
                $saldo->update(['sisa' => max(0, (int) $saldo->sisa + ($arah * $hari))]);
            }
        }
    }
    // ================= END HELPER PEMBAGIAN HARI PER TAHUN =================

    // ================= AUTO-SYNC BAWAAN TAHUN BERIKUTNYA =================
    // Dipanggil setiap kali saldo / pemakaian cuti suatu tahun berubah (edit
    // pegawai, ubah saldo, approve / batal / tangguhkan / revisi cuti), supaya
    // dompet tahun BERIKUTNYA yang sudah ada tetap sesuai. Command
    // GenerateSaldoCutiTahunBaru melewati dompet yang sudah ada karena
    // angkanya dijaga oleh method ini.
    //
    // Rumus SAMA dengan Command Generate (lewat CarryOverSaldoService):
    //   carry_forward_normal = Hak - Terpakai - Tgh tahun sebelumnya
    //                          (carryDariSaldoBiasa)
    //   bawaan eligible      = hitungBawaan(sisa N-2, sisa N-1, kuota)
    //   total hak            = kuota_tahunan + bawaan eligible
    //   sisa                 = max(0, total hak - terpakaiEfektif)
    //   terpakai             = terpakaiMurni
    // kuota_tahunan & koreksi_saldo tidak diubah.
    //
    // Hanya dompet ASLI (kuota_tahunan > 0) yang disinkronkan. Baris bayangan
    // (kuota_tahunan = 0) tidak disentuh, dan pegawai yang belum punya dompet
    // tahun berikutnya dilewati (itu tugas Generate / pembuatan pegawai baru).
    // Perubahan menjalar ke tahun-tahun sesudahnya selama dompetnya ada
    // (maksimal BATAS_RANTAI_SINKRON tahun).

    /**
     * Hitung ulang dompet tahun ($tahun + 1) milik pegawai, lalu lanjut ke
     * tahun-tahun berikutnya selama dompetnya ada.
     */
    public static function sinkronkanBawaan(int $pegawaiId, int $tahun): void
    {
        $service = app(CarryOverSaldoService::class);

        // Sumber ($tahun) harus dompet ASLI. Baris bayangan (kuota_tahunan = 0)
        // hanya menyimpan carry-forward manual dari buku lama; menghitung dari
        // sana akan menimpa Sisa tahun berikutnya dengan 0.
        $sumber = static::where('pegawai_id', $pegawaiId)->where('tahun', $tahun)->first();

        if (!$sumber || (int) $sumber->kuota_tahunan <= 0) {
            return;
        }

        for ($i = 0; $i < self::BATAS_RANTAI_SINKRON; $i++) {
            $tahunTarget = $tahun + 1 + $i;

            $dompet = static::where('pegawai_id', $pegawaiId)
                ->where('tahun', $tahunTarget)
                ->first();

            if (!$dompet || (int) $dompet->kuota_tahunan <= 0) {
                break;
            }

            // Sisa mentah tahun sebelumnya (Hak - Terpakai - Tgh tahun itu).
            $carry = $service->carryDariSaldoBiasa($pegawaiId, $tahunTarget);
            $kuota = (int) $dompet->kuota_tahunan;

            // Sisa N-2 untuk aturan akumulasi 12 + 12.
            $duaTahunLalu = (int) (static::where('pegawai_id', $pegawaiId)
                ->where('tahun', $tahunTarget - 1)
                ->value('carry_forward_normal') ?? 0);

            // hitungBawaan() sudah menangani aturan 6/12 dan batas 24 (jika aktif).
            $totalHak = $kuota + static::hitungBawaan($duaTahunLalu, $carry, $kuota);

            $terpakaiMurni = static::terpakaiMurni($pegawaiId, $tahunTarget);
            $terpakaiEfektif = static::terpakaiEfektif($pegawaiId, $tahunTarget, $dompet);

            $dompet->update([
                'carry_forward_normal' => $carry,
                'sisa' => max(0, $totalHak - $terpakaiEfektif),
                'terpakai' => $terpakaiMurni,
            ]);
        }
    }

    /**
     * Sinkronkan bawaan untuk setiap tahun yang disentuh sebuah pengajuan
     * Cuti Tahunan (termasuk cuti lintas tahun). Pengajuan jenis lain
     * diabaikan karena tidak memengaruhi saldo tahunan.
     * Panggil SETELAH status pengajuan & saldo legacy diperbarui.
     */
    public static function sinkronkanBawaanPengajuan(PengajuanCuti $pengajuan): void
    {
        if (strtolower(trim((string) $pengajuan->jenis_cuti)) !== 'cuti tahunan') {
            return;
        }

        $tahunMulai = Carbon::parse($pengajuan->tanggal_mulai)->year;
        $tahunSelesai = Carbon::parse($pengajuan->tanggal_selesai)->year;

        for ($tahun = $tahunMulai; $tahun <= $tahunSelesai; $tahun++) {
            static::sinkronkanBawaan((int) $pengajuan->pegawai_id, $tahun);
        }
    }
    // ================= END AUTO-SYNC BAWAAN =================

    /**
     * Cuti Tahunan berstatus disetujui yang benar-benar terpakai untuk satu pegawai & tahun.
     * Cuti lintas tahun hanya dihitung bagian yang jatuh di tahun tersebut.
     */
    public static function terpakaiMurni(int $pegawaiId, int $tahun): int
    {
        $cutis = PengajuanCuti::where('pegawai_id', $pegawaiId)
            ->where('jenis_cuti', 'Cuti Tahunan')
            ->where('status', 'disetujui')
            ->whereDate('tanggal_mulai', '<=', "{$tahun}-12-31")
            ->whereDate('tanggal_selesai', '>=', "{$tahun}-01-01")
            ->get(['tanggal_mulai', 'tanggal_selesai', 'jumlah_hari']);

        $total = 0;
        foreach ($cutis as $cuti) {
            $total += self::hariCutiDiTahun(
                $cuti->tanggal_mulai,
                $cuti->tanggal_selesai,
                (int) $cuti->jumlah_hari,
                $tahun
            );
        }

        return (int) $total;
    }

    /**
     * [KOREKSI BULANAN] Hitungan sistem murni per bulan (tanpa koreksi Admin HR),
     * hanya hari kerja (Senin-Jumat di luar hari_liburs) yang jatuh di $tahun.
     * Kunci hasil: 1..12 (integer), selalu lengkap (bulan tanpa cuti = 0).
     * Pembagian hari SAMA dengan hitungHariCutiPerBulan() di AdminController.
     *
     * @return array<int,int>
     */
    public static function terpakaiMurniPerBulan(int $pegawaiId, int $tahun): array
    {
        $hasil = array_fill(1, 12, 0);
        $libur = array_flip(self::tanggalLibur());

        $cutis = PengajuanCuti::where('pegawai_id', $pegawaiId)
            ->where('jenis_cuti', 'Cuti Tahunan')
            ->where('status', 'disetujui')
            ->whereDate('tanggal_mulai', '<=', "{$tahun}-12-31")
            ->whereDate('tanggal_selesai', '>=', "{$tahun}-01-01")
            ->get(['tanggal_mulai', 'tanggal_selesai']);

        foreach ($cutis as $cuti) {
            $mulai = Carbon::parse($cuti->tanggal_mulai)->startOfDay();
            $selesai = Carbon::parse($cuti->tanggal_selesai ?? $cuti->tanggal_mulai)->startOfDay();

            for ($d = $mulai->copy(); $d->lte($selesai); $d->addDay()) {
                if ($d->year !== $tahun || $d->isWeekend() || isset($libur[$d->toDateString()])) {
                    continue;
                }

                $hasil[$d->month]++;
            }
        }

        return $hasil;
    }

    /**
     * [KOREKSI BULANAN] Selisih koreksi Admin HR per bulan:
     * [bulan => nilai - nilai_sistem]. Tetap berlaku walau hitungan sistem
     * berubah sesudahnya (koreksi bersifat TAMBAH/KURANG, bukan angka mutlak).
     *
     * @return array<int,int>
     */
    public static function petaSelisihTahun(int $pegawaiId, int $tahun): array
    {
        return KoreksiCutiBulanan::where('pegawai_id', $pegawaiId)
            ->where('tahun', $tahun)
            ->get(['bulan', 'nilai', 'nilai_sistem'])
            ->mapWithKeys(fn($b) => [(int) $b->bulan => (int) $b->nilai - (int) $b->nilai_sistem])
            ->all();
    }

    /**
     * Cuti Tahunan terpakai EFEKTIF untuk satu pegawai & tahun.
     *
     * Urutan prioritas:
     *   1) Koreksi BULANAN Admin HR, bila ada: terpakaiMurni + SUM(selisih).
     *      Rumus SAMA dengan buildLaporanData() & updateKoreksiLaporan().
     *   2) koreksi_saldo TAHUNAN lama: dipakai apa adanya (termasuk 0).
     *   3) Hitungan sistem: terpakaiMurni().
     *
     * Koreksi bulanan pertama pada suatu tahun menghapus koreksi_saldo tahunan
     * (lihat updateKoreksiLaporan()), jadi keduanya tidak berjalan bersamaan.
     */
    public static function terpakaiEfektif(
        int $pegawaiId,
        int $tahun,
        ?self $saldo = null
    ): int {
        // 1) Koreksi bulanan (sebagai SELISIH terhadap hitungan sistem)
        $selisihPerBulan = static::petaSelisihTahun($pegawaiId, $tahun);

        if (!empty($selisihPerBulan)) {
            return max(0, static::terpakaiMurni($pegawaiId, $tahun) + array_sum($selisihPerBulan));
        }

        // 2) Koreksi tahunan lama
        $saldo ??= static::where('pegawai_id', $pegawaiId)->where('tahun', $tahun)->first();

        if ($saldo && $saldo->koreksi_saldo !== null) {
            return max(0, (int) $saldo->koreksi_saldo);
        }

        // 3) Hitungan sistem
        return static::terpakaiMurni($pegawaiId, $tahun);
    }

    // ================= VERSI BATCH (ANTI N+1) =================

    /**
     * [BATCH] Muat bahan hitung Terpakai untuk banyak pegawai & tahun sekaligus
     * (2 query tetap, bukan 2-3 query per pegawai).
     *   'selisih' : ["{pegawai_id}-{tahun}" => SUM(nilai - nilai_sistem)]
     *               (ada kuncinya = ada koreksi bulanan, walau jumlahnya 0)
     *   'cuti'    : Cuti Tahunan disetujui, dikelompokkan per pegawai_id
     *
     * Hasilnya dipakai terpakaiEfektifDariBahan().
     *
     * @param  array<int, int>  $pegawaiIds
     * @param  array<int, int>  $tahunList
     */
    public static function muatBahanTerpakai(array $pegawaiIds, array $tahunList): array
    {
        $pegawaiIds = array_values(array_unique(array_map('intval', $pegawaiIds)));
        $tahunList  = array_values(array_unique(array_map('intval', $tahunList)));

        if (empty($pegawaiIds) || empty($tahunList)) {
            return ['selisih' => [], 'cuti' => collect()];
        }

        $selisih = [];
        $koreksi = KoreksiCutiBulanan::whereIn('pegawai_id', $pegawaiIds)
            ->whereIn('tahun', $tahunList)
            ->get(['pegawai_id', 'tahun', 'nilai', 'nilai_sistem']);

        foreach ($koreksi as $b) {
            $kunci = (int) $b->pegawai_id . '-' . (int) $b->tahun;
            $selisih[$kunci] = ($selisih[$kunci] ?? 0) + (int) $b->nilai - (int) $b->nilai_sistem;
        }

        $awal  = min($tahunList);
        $akhir = max($tahunList);

        $cuti = PengajuanCuti::whereIn('pegawai_id', $pegawaiIds)
            ->where('jenis_cuti', 'Cuti Tahunan')
            ->where('status', 'disetujui')
            ->whereDate('tanggal_mulai', '<=', "{$akhir}-12-31")
            ->whereDate('tanggal_selesai', '>=', "{$awal}-01-01")
            ->get(['pegawai_id', 'tanggal_mulai', 'tanggal_selesai', 'jumlah_hari'])
            ->groupBy('pegawai_id');

        return ['selisih' => $selisih, 'cuti' => $cuti];
    }

    /**
     * [BATCH] Versi memori dari terpakaiEfektif(): aturan prioritas SAMA
     * (koreksi bulanan -> koreksi_saldo tahunan -> hitungan sistem), tetapi
     * tanpa query. $bahan berasal dari muatBahanTerpakai().
     */
    public static function terpakaiEfektifDariBahan(
        int $pegawaiId,
        int $tahun,
        ?self $saldo,
        array $bahan
    ): int {
        $kunci = $pegawaiId . '-' . $tahun;

        // Terpakai murni dari cuti yang sudah dimuat (sama dengan terpakaiMurni()).
        $murni = 0;
        foreach ($bahan['cuti']->get($pegawaiId, collect()) as $cuti) {
            if ($cuti->tanggal_selesai === null) {
                continue;
            }

            if (
                Carbon::parse($cuti->tanggal_mulai)->year > $tahun
                || Carbon::parse($cuti->tanggal_selesai)->year < $tahun
            ) {
                continue;
            }

            $murni += self::hariCutiDiTahun(
                $cuti->tanggal_mulai,
                $cuti->tanggal_selesai,
                (int) $cuti->jumlah_hari,
                $tahun
            );
        }

        // 1) Koreksi bulanan (selisih terhadap hitungan sistem)
        if (isset($bahan['selisih'][$kunci])) {
            return max(0, $murni + $bahan['selisih'][$kunci]);
        }

        // 2) Koreksi tahunan lama
        if ($saldo && $saldo->koreksi_saldo !== null) {
            return max(0, (int) $saldo->koreksi_saldo);
        }

        // 3) Hitungan sistem
        return (int) $murni;
    }
    // ================= END VERSI BATCH =================

    /**
     * Saldo bawaan yang eligible.
     *   - Normal          : min(6, sisa tahun lalu).
     *   - Akumulasi penuh : sisa 2 tahun lalu = 12 DAN sisa tahun lalu = 12 -> 12.
     *   - Batas 24        : hanya bila TERAPKAN_BATAS_ATAS_TOTAL_HAK = true.
     *                       Bila false, Bawaan TIDAK bergantung pada Hak ($kuota).
     *
     * $sisaDuaTahunLalu & $sisaTahunLalu adalah SISA MENTAH
     * (Hak - Terpakai - Tgh tahun itu).
     */
    public static function hitungBawaan(int $sisaDuaTahunLalu, int $sisaTahunLalu, int $kuota): int
    {
        $normal = ($sisaDuaTahunLalu === 12 && $sisaTahunLalu === 12)
            ? 12
            : min(6, max(0, $sisaTahunLalu));

        if (self::TERAPKAN_BATAS_ATAS_TOTAL_HAK) {
            return max(0, min($normal, self::BATAS_ATAS_TOTAL_HAK - $kuota));
        }

        return max(0, $normal);
    }

    /**
     * Apakah baris saldo untuk pegawai & tahun ini sudah dibuat
     * (lewat Generate, tambah pegawai, atau impor pegawai)?
     * Dipakai CutiController untuk menolak pengajuan Cuti Tahunan pada
     * tahun yang saldonya belum disiapkan Admin HR.
     */
    public static function tersediaUntukTahun(int $pegawaiId, int $tahun): bool
    {
        return static::where('pegawai_id', $pegawaiId)->where('tahun', $tahun)->exists();
    }

    /**
     * Jumlah pegawai aktif yang belum punya baris saldo untuk tahun tertentu.
     * 0 berarti semua pegawai aktif sudah punya "dompet" tahun itu. Dipakai
     * RekapKuotaDetailController untuk mengunci tombol Generate.
     *
     * Akun Admin HR (role 5) DIKECUALIKAN: tidak punya jatah cuti dan tidak
     * pernah dibuatkan dompet, sehingga tanpa pengecualian angkanya tidak
     * akan pernah menjadi 0.
     */
    public static function jumlahPegawaiBelumPunyaDompet(int $tahun): int
    {
        return Pegawai::query()
            ->where('role_id', '!=', self::ROLE_ADMIN_HR)
            ->where(fn($q) => $q->where('status_aktif', 'Aktif')->orWhereNull('status_aktif'))
            ->whereNotExists(function ($q) use ($tahun) {
                $q->select(DB::raw(1))
                    ->from('saldo_cutis')
                    ->whereColumn('saldo_cutis.pegawai_id', 'pegawais.id')
                    ->where('saldo_cutis.tahun', $tahun);
            })
            ->count();
    }
}
