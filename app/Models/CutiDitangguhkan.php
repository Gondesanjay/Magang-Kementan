<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Reporting record for suspended approved annual leave; it is not a carry-over source.
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
}
