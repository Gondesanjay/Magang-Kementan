<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// ================= [BARU] MODEL HISTORI SALDO =================
// Merepresentasikan tabel `histori_saldos` yang sudah lama dirancang di
// migration (2026_08_04_025942_create_histori_saldos_table.php).
//
// Satu baris = satu kali Admin/HR melakukan PENYESUAIAN MANUAL terhadap
// saldo cuti seorang pegawai (baik menambah maupun mengurangi,
// `jumlah_penyesuaian` bisa plus/minus), wajib disertai alasan. Berbeda
// dari `CutiDitangguhkan` (yang mencatat penangguhan RESMI oleh L4 lewat
// alur approval), tabel ini adalah JEJAK AUDIT untuk koreksi manual yang
// dilakukan Admin HR langsung dari halaman Kelola Saldo Cuti — misalnya
// saat generate saldo tahun baru menghasilkan angka yang perlu dikoreksi,
// atau ada kesalahan input yang perlu diperbaiki dengan tetap tercatat
// jejaknya (bukan ditimpa diam-diam).
//
// Dipanggil dari AdminController::updateSaldo() setiap kali Admin
// mengubah `sisa` secara manual di tabel Kelola Saldo Cuti — lihat
// komentar di method tersebut untuk detail kapan baris histori dibuat.
class HistoriSaldo extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'tanggal_perubahan' => 'datetime',
    ];

    public function saldoCuti()
    {
        return $this->belongsTo(SaldoCuti::class, 'saldo_cuti_id');
    }

    public function pegawai()
    {
        return $this->belongsTo(Pegawai::class, 'pegawai_id');
    }

    public function diubahOleh()
    {
        return $this->belongsTo(Pegawai::class, 'diubah_oleh');
    }
}
// ================= [END BARU] =================