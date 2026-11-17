<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Jejak audit perubahan saldo cuti oleh Admin HR (lihat migration
// create_log_perubahan_saldos_table). Hanya ditulis, tidak diedit.
class LogPerubahanSaldo extends Model
{
    protected $table = 'log_perubahan_saldos';

    protected $guarded = ['id'];

    public function pegawai()
    {
        return $this->belongsTo(Pegawai::class, 'pegawai_id');
    }

    public function pengubah()
    {
        return $this->belongsTo(Pegawai::class, 'diubah_oleh');
    }
}
