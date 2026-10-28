<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaldoCuti extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'koreksi_saldo' => 'integer', // nullable: NULL tetap NULL, tidak jadi 0
        'koreksi_ditangguhkan' => 'integer', // nullable: NULL = ikut sistem, 0 = koreksi sah
    ];

    /**
     * Hitung estimasi sisa cuti yang bisa dibawa ke tahun depan (Carry Forward).
     * Maksimal 6 hari.
     */
    public static function hitungPreviewCarryOver($sisaCutiTahunBerjalan)
    {
        // Pastikan tidak minus, lalu ambil nilai terkecil antara sisa cuti dan 6
        return min(max($sisaCutiTahunBerjalan, 0), 6);
    }

    /**
     * Cuti Tahunan berstatus disetujui yang benar-benar terpakai untuk satu pegawai & tahun.
     */
    public static function terpakaiMurni(int $pegawaiId, int $tahun): int
    {
        return (int) PengajuanCuti::where('pegawai_id', $pegawaiId)
            ->where('jenis_cuti', 'Cuti Tahunan')
            ->whereYear('tanggal_mulai', $tahun)
            ->where('status', 'disetujui')
            ->sum('jumlah_hari');
    }

    /**
     * Cuti Tahunan terpakai EFEKTIF untuk satu pegawai & tahun.
     * koreksi_saldo NULL  -> ikut hitungan sistem berdasarkan terpakaiMurni()
     * koreksi_saldo 0..n  -> angka koreksi Admin HR dipakai apa adanya (termasuk 0)
     */
    public static function terpakaiEfektif(
        int $pegawaiId,
        int $tahun,
        ?self $saldo = null
    ): int {
        $saldo ??= static::where('pegawai_id', $pegawaiId)->where('tahun', $tahun)->first();

        if ($saldo && $saldo->koreksi_saldo !== null) {
            return max(0, (int) $saldo->koreksi_saldo);
        }

        return static::terpakaiMurni($pegawaiId, $tahun);
    }

    public static function hitungBawaan(int $sisaDuaTahunLalu, int $sisaTahunLalu, int $kuota): int
    {
        $normal = ($sisaDuaTahunLalu === 12 && $sisaTahunLalu === 12)
            ? 12
            : min(6, max(0, $sisaTahunLalu));

        return max(0, min($normal, 24 - $kuota));
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
}
