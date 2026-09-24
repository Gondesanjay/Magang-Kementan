<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class PengajuanCuti extends Model
{
    protected $guarded = ['id'];

    // ================= [DIUBAH] PERBAIKAN: URL LAMPIRAN =================
    // MASALAH: Kolom 'lampiran' di database menyimpan PATH RELATIF apa
    // adanya dari hasil Storage::store() di CutiController@store
    // (mis. "lampiran-cuti/namafile.jpg"), BUKAN URL yang bisa langsung
    // diakses browser.
    //
    // AKIBATNYA: Semua halaman yang menampilkan tautan lampiran (Dashboard,
    // Riwayat Pengajuan Karyawan, Monitoring Cuti Atasan, Pembatalan Cuti
    // Atasan) memakai path mentah tersebut langsung sebagai href, sehingga
    // menghasilkan URL 404 (kehilangan prefix wajib "/storage/" yang
    // dibutuhkan Laravel untuk mengakses disk 'public').
    // Contoh SEBELUM: 127.0.0.1:8000/lampiran-cuti/namafile.jpg (404)
    // Contoh SESUDAH: 127.0.0.1:8000/storage/lampiran-cuti/namafile.jpg (benar)
    //
    // PERBAIKAN: ditambahkan accessor getLampiranAttribute() yang otomatis
    // mengubah path relatif menjadi URL publik yang benar SETIAP KALI
    // atribut 'lampiran' dibaca dari model manapun — tanpa mengubah apa
    // yang tersimpan di kolom database itu sendiri. Karena semua
    // controller (CutiController, MonitoringCutiController,
    // PembatalanController, dst) memakai model PengajuanCuti yang sama,
    // perbaikan satu accessor ini otomatis berlaku ke seluruh halaman
    // tanpa perlu mengubah kode di controller atau file Vue manapun.
    //
    // CATATAN: pastikan symlink storage sudah dibuat dengan menjalankan
    // `php artisan storage:link` di root project (sekali saja), supaya
    // folder public/storage benar-benar mengarah ke storage/app/public.
    public function getLampiranAttribute($value)
    {
        return $value ? Storage::url($value) : null;
    }
    // ================= [END DIUBAH] =================

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

    // ================= [BARU] PERBAIKAN RELASI atasanL2 =================
    // Menambahkan method relasi untuk atasanL2 yang sebelumnya terlewat.
    // Ini memperbaiki error RelationNotFoundException di DashboardController
    // dan memastikan data nama atasan L2 bisa di-load dan ditampilkan 
    // secara konsisten di modal Vue frontend.
    public function atasanL2()
    {
        return $this->belongsTo(Pegawai::class, 'atasan_l2_id');
    }
    // ================= [END BARU] =================

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

    // ================= [BARU] RELASI KE CUTI DITANGGUHKAN =================
    // Satu pengajuan cuti yang ditangguhkan oleh L4 (lewat
    // PembatalanController@process) akan punya SATU baris terkait di
    // tabel `cuti_ditangguhkans` — dibuat pada saat penangguhan terjadi,
    // menyimpan berapa hari yang ditangguhkan dari pengajuan ini beserta
    // status apakah sudah dipakai lagi di tahun berikutnya atau belum.
    // Dipakai untuk perhitungan carry-over saldo cuti tahunan Jalur
    // Khusus (b) — lihat CutiDitangguhkan.php untuk detail lengkap.
    public function cutiDitangguhkan()
    {
        return $this->hasOne(CutiDitangguhkan::class, 'pengajuan_asal_id');
    }
    // ================= [END BARU] =================
}
