<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Builder;
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
     *
     * status_kepegawaian = jenis ASN (PNS / PPPK).
     * status_aktif       = status keaktifan (Aktif / Pensiun / dst).
     */
    protected $fillable = [
        'nama',
        'nip',
        'jabatan',
        'status_kepegawaian',
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
        'remember_token',
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

    // BARU: token perangkat FCM (satu pegawai bisa punya banyak perangkat).
    // Kolom di tabel fcm_tokens bernama user_id, merujuk ke pegawais.id.
    public function fcmTokens()
    {
        return $this->hasMany(FcmToken::class, 'user_id');
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

    // ================= SCOPE =================
    /**
     * Batasi data pegawai sesuai hierarki atasan yang sedang login.
     * Pemakaian: Pegawai::sesuaiHierarki(auth()->user())->get();
     */
    public function scopeSesuaiHierarki(Builder $query, Pegawai $user)
    {
        // Singkirkan akun Admin HR (Role 5) dari perhitungan
        $query->where('role_id', '!=', 5);

        // Filter L1 (Ketua Tim)
        if ((int) $user->role_id === 2) {
            // Jika tim kerja kosong, jangan sampai cocok dengan semua pegawai ber-tim NULL
            if (empty($user->tim_kerja)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where('tim_kerja', $user->tim_kerja)
                ->where('id', '!=', $user->id);
        }

        // Filter L2 (Ketua Kelompok)
        if ((int) $user->role_id === 3) {
            if (empty($user->kelompok_substansi)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where('kelompok_substansi', $user->kelompok_substansi)
                ->where('id', '!=', $user->id);
        }

        // L3, L4, dan Admin melihat semua
        return $query;
    }
}
