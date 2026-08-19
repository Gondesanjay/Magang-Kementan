<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PengajuanCuti extends Model
{
    protected $guarded = ['id'];

    // Tambahkan relasi ke tabel pegawais
    public function pegawai()
    {
        return $this->belongsTo(Pegawai::class, 'pegawai_id');
    }
    // Tambahkan 2 fungsi ini di dalam class PengajuanCuti
    public function atasanL1()
    {
        return $this->belongsTo(Pegawai::class, 'atasan_l1_id');
    }

    public function atasanL3()
    {
        return $this->belongsTo(Pegawai::class, 'atasan_l3_id');
    }

    public function atasanL4()
    {
        return $this->belongsTo(Pegawai::class, 'atasan_l4_id');
    }

    public function approvalLogs()
    {
        return $this->hasMany(ApprovalLog::class, 'pengajuan_id')
            ->with('approver')
            ->orderBy('level_approval');
    }
}
