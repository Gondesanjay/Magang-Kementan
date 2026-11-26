<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PengajuanCuti extends Model
{
    protected $guarded = ['id'];

    public const STATUS_BISA_DITANGGUHKAN = [
        'menunggu_l1',
        'menunggu_l2',
        'menunggu_l3',
        'menunggu_l4',
        'disetujui',
    ];

    // Status pengajuan yang sudah ditangguhkan (baris tetap tampil, tombol nonaktif).
    public const STATUS_DITANGGUHKAN = 'dibatalkan_ditangguhkan';

    // Satu-satunya jenis cuti yang boleh ditangguhkan.
    public const JENIS_BISA_DITANGGUHKAN = 'Cuti Tahunan';

    // Penangguhan TIDAK dibatasi tanggal (belum mulai, sedang berjalan, atau
    // sudah lewat tetap bisa), selama status termasuk STATUS_BISA_DITANGGUHKAN.
    // Hanya untuk jenis Cuti Tahunan.
    public function scopeBisaDitangguhkan(Builder $q): Builder
    {
        return $q->whereIn('status', self::STATUS_BISA_DITANGGUHKAN)
            ->where('jenis_cuti', self::JENIS_BISA_DITANGGUHKAN);
    }

    // Daftar halaman Penangguhan: yang bisa ditangguhkan DAN yang sudah
    // ditangguhkan (yang sudah ditangguhkan tampil dengan tombol nonaktif).
    public function scopeTampilDiPenangguhan(Builder $q): Builder
    {
        return $q->where('jenis_cuti', self::JENIS_BISA_DITANGGUHKAN)
            ->whereIn('status', array_merge(
                self::STATUS_BISA_DITANGGUHKAN,
                [self::STATUS_DITANGGUHKAN]
            ));
    }

    // True kalau jenis cuti ini Cuti Tahunan (abaikan huruf besar/kecil & spasi).
    public function adalahCutiTahunan(): bool
    {
        return strtolower(trim((string) $this->jenis_cuti))
            === strtolower(self::JENIS_BISA_DITANGGUHKAN);
    }

    public function alasanTidakBisaDitangguhkan(): ?string
    {
        // Cek jenis cuti lebih dulu.
        if (!$this->adalahCutiTahunan()) {
            return 'Hanya Cuti Tahunan yang dapat ditangguhkan.';
        }

        // Mencegah penangguhan ganda.
        if ($this->status === self::STATUS_DITANGGUHKAN) {
            return 'Cuti ini sudah ditangguhkan.';
        }

        if (!in_array($this->status, self::STATUS_BISA_DITANGGUHKAN, true)) {
            return 'Cuti ini sudah ditolak atau sudah dibatalkan sebelumnya.';
        }

        return null;
    }

    public function bisaDitangguhkan(): bool
    {
        return $this->alasanTidakBisaDitangguhkan() === null;
    }

    // Susulan = pengajuan dibuat SETELAH cuti dimulai (mis. sakit dulu, mengajukan
    // setelah sehat). Dibandingkan dengan created_at, bukan hari ini, supaya
    // pengajuan yang dibuat sebelum cuti tidak berubah jadi "susulan" seiring waktu.
    public function isSusulan(): bool
    {
        if (!$this->tanggal_mulai || !$this->created_at) {
            return false;
        }

        return Carbon::parse($this->tanggal_mulai)->startOfDay()
            ->lt($this->created_at->copy()->startOfDay());
    }

    // ================= URL LAMPIRAN LEWAT ROUTE TERPROTEKSI =================
    // Accessor mengembalikan alamat route 'lampiran.unduh' (bukan URL publik
    // /storage/...), yang mengecek login & hak akses (pemilik cuti, atasan
    // berwenang, atau Admin HR) sebelum mengirim file. Semua halaman yang
    // memakai field `lampiran` otomatis ikut tanpa mengubah file Vue.
    //
    // Alamat dibuat RELATIF (route(..., false)) supaya tetap jalan walau APP_URL
    // berbeda dengan alamat di browser (cookie login terikat ke host).
    //
    // CATATAN: untuk path asli di database (mengunduh/menghapus file) pakai
    // $pengajuan->getRawOriginal('lampiran'), BUKAN $pengajuan->lampiran.
    public function getLampiranAttribute(mixed $value): ?string
    {
        if (!$value || !$this->getKey()) {
            return null;
        }

        return route('lampiran.unduh', ['id' => $this->getKey()], false);
    }
    // ================= END URL LAMPIRAN =================

    // Relasi ke tabel pegawais
    public function pegawai(): BelongsTo
    {
        return $this->belongsTo(Pegawai::class, 'pegawai_id');
    }

    public function atasanL1(): BelongsTo
    {
        return $this->belongsTo(Pegawai::class, 'atasan_l1_id');
    }

    // Relasi atasanL2 (memperbaiki RelationNotFoundException di DashboardController).
    public function atasanL2(): BelongsTo
    {
        return $this->belongsTo(Pegawai::class, 'atasan_l2_id');
    }

    public function atasanL3(): BelongsTo
    {
        return $this->belongsTo(Pegawai::class, 'atasan_l3_id');
    }

    public function atasanL4(): BelongsTo
    {
        return $this->belongsTo(Pegawai::class, 'atasan_l4_id');
    }

    public function approvalLogs(): HasMany
    {
        return $this->hasMany(ApprovalLog::class, 'pengajuan_id')
            ->with('approver')
            ->orderBy('level_approval');
    }

    // Notifikasi yang terkait pengajuan ini (lewat kolom notifikasis.pengajuan_id).
    // Tanpa foreign key: cuti:arsipkan menghapus baris pengajuan, dan
    // notifikasinya dibersihkan di sana.
    public function notifikasis(): HasMany
    {
        return $this->hasMany(Notifikasi::class, 'pengajuan_id');
    }

    // Catatan khusus pelaporan Cuti Tahunan disetujui yang ditangguhkan.
    // JANGAN dihapus: masih dipakai whereHas dan eager load ('cutiDitangguhkan').
    // Hanya mengambil SATU catatan; untuk cuti lintas tahun pakai cutiDitangguhkanSemua().
    public function cutiDitangguhkan(): HasOne
    {
        return $this->hasOne(CutiDitangguhkan::class, 'pengajuan_asal_id');
    }

    // Semua catatan penangguhan satu pengajuan (cuti lintas tahun bisa punya 2).
    public function cutiDitangguhkanSemua(): HasMany
    {
        return $this->hasMany(CutiDitangguhkan::class, 'pengajuan_asal_id');
    }
}
