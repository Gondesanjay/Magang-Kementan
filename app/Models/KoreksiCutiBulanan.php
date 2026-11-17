<?php


namespace App\Models;


use Illuminate\Database\Eloquent\Model;


// Koreksi manual Admin HR untuk jumlah hari Cuti Tahunan per bulan (Rekap Laporan).
// Satu baris = satu sel (pegawai, tahun, bulan). Tidak ada baris = ikut hitungan sistem.
// `nilai_sistem` menyimpan hitungan sistem saat koreksi dibuat, untuk mendeteksi
// koreksi "basi" (ada cuti baru disetujui/dibatalkan sesudahnya).
class KoreksiCutiBulanan extends Model
{
    protected $table = 'koreksi_cuti_bulanan';


    protected $guarded = ['id'];


    protected $casts = [
        'tahun' => 'integer',
        'bulan' => 'integer',
        'nilai' => 'integer',
        'nilai_sistem' => 'integer',
    ];


    public function pegawai()
    {
        return $this->belongsTo(Pegawai::class, 'pegawai_id');
    }


    public function pengubah()
    {
        return $this->belongsTo(Pegawai::class, 'diubah_oleh');
    }


    /**
     * Peta koreksi satu pegawai & tahun: [bulan (1-12) => nilai].
     * Bulan yang tidak ada di peta = ikut hitungan sistem.
     */
    public static function petaTahun(int $pegawaiId, int $tahun): array
    {
        return static::where('pegawai_id', $pegawaiId)
            ->where('tahun', $tahun)
            ->pluck('nilai', 'bulan')
            ->map(fn($v) => (int) $v)
            ->all();
    }
}

