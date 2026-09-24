<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes; // <-- aktifkan soft delete

class Pegawai extends Authenticatable
{
    // ---> SoftDeletes <---
    // Ditambahkan supaya method AdminController::destroyPegawai() tidak
    // langsung menghapus baris pegawai secara permanen dari database,
    // melainkan hanya mengisi kolom `deleted_at` (soft delete). Baris
    // pegawai jadi tersembunyi dari semua query normal (termasuk
    // kelolaPegawai(), buildLaporanData(), dsb — karena Eloquent otomatis
    // menambahkan `WHERE deleted_at IS NULL` di balik layar), tapi
    // datanya TETAP ADA di database untuk keperluan arsip/audit dan bisa
    // dipulihkan lagi kalau perlu (lewat Pegawai::withTrashed() /
    // ->restore()).
    //
    // PENTING — PRASYARAT MIGRATION: trait ini WAJIB disertai kolom
    // `deleted_at` (nullable timestamp) di tabel `pegawais`. Kalau kolom
    // itu belum ada, tambahkan migration baru, misalnya:
    //
    //   Schema::table('pegawais', function (Blueprint $table) {
    //       $table->softDeletes();
    //   });
    //
    // lalu jalankan `php artisan migrate`. Tanpa kolom ini, query apa pun
    // ke tabel pegawais (termasuk login) akan error karena kolom
    // `deleted_at` tidak ditemukan.
    use Notifiable, SoftDeletes;

    /**
     * Kolom yang boleh diisi secara mass-assignment
     * (penting untuk fitur Admin HR: create / update data pegawai)
     *
     * ---> PERBAIKAN <---
     * Ditambahkan 'jabatan' karena sebelumnya TIDAK ada di daftar ini.
     * Akibatnya, setiap kali Admin HR menambah/mengedit pegawai lewat
     * modal Tambah/Edit, field "Jabatan" yang diisi di form SELALU
     * diam-diam diabaikan oleh Eloquent (tidak error, tapi datanya
     * tidak pernah tersimpan ke database).
     *
     * Ditambahkan juga 'is_first_login' supaya controller bisa langsung
     * men-set status ini saat pegawai baru dibuat (lihat AdminController::
     * storePegawai()), sehingga pegawai baru otomatis diarahkan untuk
     * mengganti password default saat login pertama kali.
     *
     * ---> PENAMBAHAN BARU <---
     * 'no_telp' dan 'alamat' ditambahkan supaya modal Edit Pegawai bisa
     * menyimpan Nomor Telepon & Alamat Domisili (menyesuaikan referensi
     * tampilan SiCuti BRMP). Kolomnya dibuat lewat migration
     * 2026_08_29_000000_add_kontak_fields_to_pegawais_table.php
     *
     * ---> PERBAIKAN TERBARU (tanggal_masuk hilang diam-diam) <---
     * 'tanggal_masuk' ditambahkan karena sebelumnya TIDAK ada di daftar
     * ini. Akibatnya, sama persis seperti kasus 'jabatan' di atas:
     * AdminController::storePegawai() sudah menghitung & mengirim
     * $tanggalMasuk ke Pegawai::create([...]), tapi karena kolom ini
     * tidak fillable, Eloquent membuangnya diam-diam SEBELUM query INSERT
     * dibentuk (lihat query di error log yang sama sekali tidak menyertakan
     * kolom tanggal_masuk). Karena kolom tanggal_masuk di database bersifat
     * NOT NULL tanpa default value, hasilnya baru error saat MySQL
     * menjalankan insert-nya: "Field 'tanggal_masuk' doesn't have a
     * default value".
     *
     * ---> PENAMBAHAN TERBARU (tim_kerja hilang diam-diam saat import) <---
     * 'tim_kerja' ditambahkan karena AdminController::importPegawai()
     * (fitur import Excel/CSV pegawai) sudah mengirim kolom ini ke
     * Pegawai::create([...]), tapi sebelumnya belum terdaftar di sini.
     * Tanpa baris ini, kolom "Tim Kerja" pegawai hasil import akan selalu
     * kosong/null meski di file Excel/CSV sumbernya terisi. Pastikan
     * kolom `tim_kerja` (nullable string) sudah ada di tabel `pegawais`
     * lewat migration sebelum fillable ini dipakai.
     */
    protected $fillable = [
        'nama',
        'nip',
        'departemen',
        'divisi',
        'jabatan',        // <-- PENAMBAHAN: field ini sebelumnya hilang
        'tim_kerja',      // <-- PENAMBAHAN TERBARU: dipakai oleh importPegawai()
        'role_id',
        'password',
        'is_first_login', // <-- PENAMBAHAN: dipakai saat create pegawai baru
        'tanggal_masuk',  // <-- PENAMBAHAN TERBARU: field ini sebelumnya hilang
        'no_telepon',      // <-- PERBAIKAN: sebelumnya salah ketik 'no_telp', nama kolom asli di DB adalah 'no_telepon'
        'alamat_domisili', // <-- PERBAIKAN: sebelumnya salah ketik 'alamat', nama kolom asli di DB adalah 'alamat_domisili' (opsional, boleh dilengkapi pegawai sendiri nanti)
        'email',           // jika dipakai
        // tambahkan kolom lain yang memang ada di tabel pegawais di sini
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'role_id' => 'integer',
        'tanggal_masuk' => 'date',
        'deleted_at' => 'datetime', // <-- cast kolom soft delete
    ];

    // ================= RELASI YANG SUDAH ADA =================

    // Relasi untuk modul Admin HR (melihat semua riwayat saldo cuti)
    // Jangan dihapus – dipakai di kelolaSaldo() dan modul cuti
    public function saldoCuti()
    {
        return $this->hasMany(SaldoCuti::class, 'pegawai_id');
    }

    // Relasi tambahan khusus untuk mengambil saldo cuti tahun berjalan (untuk Approval)
    public function saldoCutiTahunIni()
    {
        return $this->hasOne(SaldoCuti::class, 'pegawai_id')->where('tahun', date('Y'));
    }

    // Relasi tambahan yang sering dibutuhkan (opsional tapi sangat berguna)
    public function pengajuanCuti()
    {
        return $this->hasMany(PengajuanCuti::class, 'pegawai_id');
    }

    // ================= APPROVER DINAMIS (L1 & L2) =================
    // Dipakai untuk menampilkan NAMA atasan L1/L2 di Modal Detail
    // (RiwayatPengajuan.vue / Dashboard.vue), sama seperti L3/L4 yang
    // sudah tetap (satu orang untuk seluruh biro, boleh hardcode di
    // Vue). Berbeda dengan L3/L4, L1 & L2 berbeda-beda per Tim Kerja /
    // Kelompok Substansi, sehingga harus dicari dinamis dari tabel
    // pegawais itu sendiri, bukan hardcode.

    // L1 = pegawai berjabatan "KETUA TIM KERJA" pada tim_kerja yang SAMA
    // dengan pegawai ini.
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

    // L2 = pegawai berjabatan "KETUA KELOMPOK" pada kelompok_substansi
    // yang SAMA dengan pegawai ini.
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
    // ================= END APPROVER DINAMIS (L1 & L2) =================
}
