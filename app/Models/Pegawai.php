<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Pegawai extends Authenticatable
{
    use Notifiable;

    /**
     * Kolom yang boleh diisi secara mass-assignment
     */
    protected $fillable = [
        'nama',
        'nip',
        'departemen',
        'divisi',
        'jabatan',
        'role_id',
        'tanggal_masuk',
        'password',
        'is_first_login',
    ];

    protected $hidden = [
        'password',
        'remember_token', // Pastikan remember_token di-hide jika tidak ada kolomnya
    ];

    protected $casts = [
        'role_id' => 'integer',
        'is_first_login' => 'boolean',
    ];

    // ================= AUTENTIKASI NIP =================
    //
    // ---> PERBAIKAN PENTING <---
    // Method getAuthIdentifierName() SEBELUMNYA di-override untuk
    // mengembalikan 'nip'. Ini adalah BUG SERIUS: method ini dipakai
    // Laravel secara internal oleh getAuthIdentifier(), yang menjadi
    // dasar nilai auth()->id() DI SELURUH APLIKASI.
    //
    // Akibatnya, auth()->id() yang seharusnya mengembalikan kolom `id`
    // (contoh: 5) malah mengembalikan `nip` (contoh: "199004042015041004")
    // di MANA PUN dipakai — termasuk di CutiController.php pada fungsi
    // history(), cancel(), batalkanMandiri(), revisi(), dan downloadPdf(),
    // yang semuanya membandingkan `pegawai_id` (angka) dengan auth()->id()
    // (yang ternyata teks NIP) — sehingga SELALU tidak cocok / selalu 0
    // hasil, tanpa memunculkan error apa pun.
    //
    // Login TETAP AMAN setelah override ini dihapus, karena proses login
    // (LoginRequest::authenticate()) memakai Auth::attempt(['nip' => ...,
    // 'password' => ...]), yang mencocokkan kredensial dari array secara
    // eksplisit — TIDAK bergantung pada getAuthIdentifierName() sama sekali.
    //
    // CATATAN: setelah perubahan ini disimpan, semua akun yang sedang
    // login akan otomatis ter-logout (format identitas session berubah).
    // Ini normal, cukup login ulang seperti biasa.
    //
    // public function getAuthIdentifierName()
    // {
    //     return 'nip';
    // }

    /**
     * Menonaktifkan pencarian kolom email bawaan Laravel pada beberapa driver auth.
     */
    public function getEmailForPasswordReset()
    {
        return $this->nip;
    }

    // ================= RELASI =================
    public function saldoCuti()
    {
        return $this->hasMany(SaldoCuti::class, 'pegawai_id');
    }

    public function saldoCutiTahunIni()
    {
        return $this->hasOne(SaldoCuti::class, 'pegawai_id')->where('tahun', date('Y'));
    }

    public function pengajuanCuti()
    {
        return $this->hasMany(PengajuanCuti::class, 'pegawai_id');
    }
}
