<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// ================= [BARU] MODEL CUTI DITANGGUHKAN =================
// Merepresentasikan tabel `cuti_ditangguhkans` yang sudah lama dirancang
// di migration (2026_08_04_030013_create_cuti_ditangguhkans_table.php)
// tapi baru sekarang "dihidupkan" penggunaannya.
//
// Satu baris = satu pengajuan cuti yang RESMI ditangguhkan oleh L4
// (lewat PembatalanController@process), lengkap dengan berapa hari yang
// ditangguhkan, tahun asalnya, dan status apakah sudah dipakai lagi di
// tahun berikutnya atau belum.
//
// Dipakai sebagai SUMBER KEBENARAN untuk "Jalur Khusus (b)" pada
// perhitungan carry-over saldo cuti tahunan (lihat
// GenerateSaldoCutiTahunBaru::hitungHariDitangguhkanBelumDipakai()) —
// hari yang tercatat di sini SELALU dibawa penuh ke tahun berikutnya,
// tidak kena potongan maksimal 6 hari seperti sisa saldo biasa, karena
// memang bukan "pegawai tidak sempat pakai" melainkan "pegawai dilarang
// pakai oleh institusi".
class CutiDitangguhkan extends Model
{
    protected $guarded = ['id'];

    public function pegawai()
    {
        return $this->belongsTo(Pegawai::class, 'pegawai_id');
    }

    public function pengajuanAsal()
    {
        return $this->belongsTo(PengajuanCuti::class, 'pengajuan_asal_id');
    }

    // Scope: baris penangguhan yang BELUM DIPAKAI dari pegawai tertentu,
    // pada tahun asal tertentu. Dipakai saat menghitung carry-over ke
    // tahun berikutnya.
    public function scopeBelumDipakai($query, int $pegawaiId, int $tahunAsal)
    {
        return $query->where('pegawai_id', $pegawaiId)
            ->where('tahun_asal', $tahunAsal)
            ->where('status_pakai', 'belum_dipakai');
    }
}
// ================= [END BARU] =================