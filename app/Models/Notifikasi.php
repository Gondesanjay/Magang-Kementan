<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notifikasi extends Model
{
    use HasFactory;

    // Menentukan nama tabel secara eksplisit sesuai dengan yang ada di phpMyAdmin
    protected $table = 'notifikasis';

    // Kolom-kolom yang diizinkan untuk diisi sesuai dengan struktur database aslimu
    protected $fillable = [
        'pegawai_id',
        'judul',
        'pesan',
        'tautan',
        'is_read',
    ];

    // Relasi balik ke model Pegawai/User (Karyawan penerima notifikasi)
    public function pegawai()
    {
        // Sesuaikan 'User::class' atau 'Pegawai::class' dengan model user milikmu
        return $this->belongsTo(User::class, 'pegawai_id');
    }
}
