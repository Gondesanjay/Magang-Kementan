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

    // Token perangkat FCM (satu pegawai bisa punya banyak perangkat).
    // Kolom di tabel fcm_tokens bernama user_id, merujuk ke pegawais.id.
    public function fcmTokens()
    {
        return $this->hasMany(FcmToken::class, 'user_id');
    }

    // ================= PENERIMA APPROVAL (SATU ATURAN UNTUK SEMUA) =================

    /**
     * Pejabat AKTIF (status_aktif 'Aktif' / NULL) untuk satu level approval.
     * Dipakai store, cancel, process, dan command pengingat, supaya pemilihan
     * penerima notifikasi tidak bisa berbeda antar tempat.
     *
     *   - role 2 (L1)  : per tim_kerja pemohon ('-' / kosong = tidak ada penerima)
     *   - role 3 (L2)  : per kelompok_substansi pemohon (kosong = tidak ada penerima)
     *   - role 4, 5, 6 : semua pejabat aktif pada role itu
     *
     * Hasil urut id terkecil. Pegawai yang di-soft-delete otomatis tersaring.
     */
    public static function penerimaApproval(
        int $roleId,
        ?string $timKerja = null,
        ?string $kelompok = null
    ): \Illuminate\Support\Collection {
        $q = static::where('role_id', $roleId)
            ->where(fn($s) => $s->where('status_aktif', 'Aktif')->orWhereNull('status_aktif'));

        if ($roleId === 2) {
            if (empty($timKerja) || $timKerja === '-') {
                return collect();
            }
            $q->where('tim_kerja', $timKerja);
        } elseif ($roleId === 3) {
            if (empty($kelompok)) {
                return collect();
            }
            $q->where('kelompok_substansi', $kelompok);
        }

        return $q->orderBy('id')->get();
    }

    // ================= APPROVER DINAMIS (L1 & L2) =================
    // Nama atasan L1/L2 di Modal Detail (RiwayatPengajuan / Dashboard).
    // L3/L4 biasanya satu orang per biro; L1/L2 per tim / kelompok.
    //
    // Accessor di bawah ini menjalankan 1 query per pemanggilan. Untuk DAFTAR
    // pegawai, pakai Pegawai::petaKetua() (maksimal 2 query untuk semuanya).

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

    /**
     * [BATCH] Nama Ketua Tim Kerja (L1) & Ketua Kelompok (L2) untuk banyak
     * pegawai sekaligus (maksimal 2 query, bukan 2 query per pegawai).
     * Aturan SAMA dengan accessor ketua_tim_kerja / ketua_kelompok:
     * jabatan LIKE '%KETUA TIM KERJA%' / '%KETUA KELOMPOK%', dan bukan
     * pegawai itu sendiri. Bila ada lebih dari satu kandidat, yang ber-id
     * terkecil dipakai.
     *
     * @param  iterable<int, Pegawai>  $daftarPegawai
     * @return array<int, array{nama_l1: ?string, nama_l2: ?string}>  kunci: id pegawai
     */
    public static function petaKetua(iterable $daftarPegawai): array
    {
        $daftar = collect($daftarPegawai)->filter()->unique('id')->values();
        $norm   = fn($v) => mb_strtolower(trim((string) $v));

        $tims = $daftar->pluck('tim_kerja')->filter(fn($v) => !empty($v))->unique()->values()->all();
        $kels = $daftar->pluck('kelompok_substansi')->filter(fn($v) => !empty($v))->unique()->values()->all();

        $ketuaTim = empty($tims) ? collect() : static::whereIn('tim_kerja', $tims)
            ->where('jabatan', 'like', '%KETUA TIM KERJA%')
            ->orderBy('id')
            ->get(['id', 'nama', 'tim_kerja'])
            ->groupBy(fn($p) => $norm($p->tim_kerja));

        $ketuaKel = empty($kels) ? collect() : static::whereIn('kelompok_substansi', $kels)
            ->where('jabatan', 'like', '%KETUA KELOMPOK%')
            ->orderBy('id')
            ->get(['id', 'nama', 'kelompok_substansi'])
            ->groupBy(fn($p) => $norm($p->kelompok_substansi));

        $hasil = [];
        foreach ($daftar as $p) {
            $l1 = empty($p->tim_kerja)
                ? null
                : $ketuaTim->get($norm($p->tim_kerja), collect())
                ->first(fn($k) => (int) $k->id !== (int) $p->id);

            $l2 = empty($p->kelompok_substansi)
                ? null
                : $ketuaKel->get($norm($p->kelompok_substansi), collect())
                ->first(fn($k) => (int) $k->id !== (int) $p->id);

            $hasil[(int) $p->id] = [
                'nama_l1' => $l1?->nama,
                'nama_l2' => $l2?->nama,
            ];
        }

        return $hasil;
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
