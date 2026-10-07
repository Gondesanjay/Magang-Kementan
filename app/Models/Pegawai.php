<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pegawai extends Authenticatable
{
    // SoftDeletes: hapus pegawai hanya mengisi deleted_at (bukan hapus permanen).
    // Prasyarat: kolom deleted_at di tabel pegawais (migration softDeletes).
    use Notifiable, SoftDeletes;

    /**
     * Kolom mass-assignment (create/update Admin HR + import).
     * Pastikan nama kolom sama dengan database
     * (no_telepon / alamat_domisili, bukan no_telp / alamat).
     */
    protected $fillable = [
        'nama',
        'nip',
        'departemen',
        'divisi',
        'jabatan',
        'kelompok_substansi',
        'tim_kerja',
        'status_aktif',
        'role_id',
        'password',
        'is_first_login',
        'tanggal_masuk',
        'no_telepon',
        'alamat_domisili',
        'email',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'role_id' => 'integer',
        'tanggal_masuk' => 'date',
        'deleted_at' => 'datetime',
    ];

    // ================= RELASI =================

    public function saldoCuti()
    {
        return $this->hasMany(SaldoCuti::class, 'pegawai_id');
    }

    public function saldoCutiTahunIni()
    {
        return $this->hasOne(SaldoCuti::class, 'pegawai_id')
            ->where('tahun', date('Y'));
    }

    public function pengajuanCuti()
    {
        return $this->hasMany(PengajuanCuti::class, 'pegawai_id');
    }

    // Histori cuti ditangguhkan resmi.
    // Dipakai RekapKuotaDetailController (eager load di buildQuery).
    public function cutiDitangguhkan()
    {
        return $this->hasMany(CutiDitangguhkan::class, 'pegawai_id');
    }

    // ================= APPROVER DINAMIS (L1 & L2) =================
    // Nama atasan L1/L2 di Modal Detail (RiwayatPengajuan / Dashboard).
    // L3/L4 biasanya satu orang per biro; L1/L2 per tim / kelompok.

    public function getKetuaTimKerjaAttribute()
    {
        if (empty($this->tim_kerja)) {
            return null;
        }

        return static::where('tim_kerja', $this->tim_kerja)
            ->where('jabatan', 'like', '%KETUA TIM KERJA%')
            ->where('id', '!=', $this->id)
            ->first();
    }

    public function getKetuaKelompokAttribute()
    {
        if (empty($this->kelompok_substansi)) {
            return null;
        }

        return static::where('kelompok_substansi', $this->kelompok_substansi)
            ->where('jabatan', 'like', '%KETUA KELOMPOK%')
            ->where('id', '!=', $this->id)
            ->first();
    }
}
