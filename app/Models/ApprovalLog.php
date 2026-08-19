<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalLog extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'tanggal_keputusan' => 'datetime',
    ];

    public function pengajuan()
    {
        return $this->belongsTo(PengajuanCuti::class, 'pengajuan_id');
    }

    public function approver()
    {
        return $this->belongsTo(Pegawai::class, 'approver_id');
    }
}
