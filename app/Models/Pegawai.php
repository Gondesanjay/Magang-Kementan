<?php


namespace App\Models;


use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;


class Pegawai extends Authenticatable
{
    use Notifiable;


    protected $hidden = [
        'password',
    ];


    protected $casts = [
        'role_id' => 'integer',
    ];


    // Tambahkan relasi ini untuk modul Admin HR
    public function saldoCuti()
    {
        return $this->hasMany(SaldoCuti::class, 'pegawai_id');
    }
}
