<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaldoCuti extends Model
{
    protected $guarded = ['id'];

    /**
     * Hitung estimasi sisa cuti yang bisa dibawa ke tahun depan (Carry Forward).
     * Maksimal 6 hari.
     */
    public static function hitungPreviewCarryOver($sisaCutiTahunBerjalan)
    {
        // Pastikan tidak minus, lalu ambil nilai terkecil antara sisa cuti dan 6
        return min(max($sisaCutiTahunBerjalan, 0), 6);
    }
}
