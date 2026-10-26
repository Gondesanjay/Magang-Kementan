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

    private static function hariIni(): string
    {
        return now('Asia/Jakarta')->toDateString();
    }

    public function scopeBisaDitangguhkan(Builder $q): Builder
    {
        return $q->whereIn('status', self::STATUS_BISA_DITANGGUHKAN)
            ->whereDate('tanggal_mulai', '>', self::hariIni());
    }

    public function alasanTidakBisaDitangguhkan(): ?string
    {
        if (!in_array($this->status, self::STATUS_BISA_DITANGGUHKAN, true)) {
            return 'Cuti ini sudah ditolak atau sudah dibatalkan/ditangguhkan sebelumnya.';
        }
        if (Carbon::parse($this->tanggal_mulai)->toDateString() <= self::hariIni()) {
            return 'Cuti sudah dimulai atau sudah lewat, tidak dapat ditangguhkan.';
        }
        return null;
    }

    public function bisaDitangguhkan(): bool
    {
        return $this->alasanTidakBisaDitangguhkan() === null;
    }

    public function bisaDirevisi(): bool
    {
        return $this->status === 'dibatalkan_ditangguhkan';
    }

    // ================= [DIUBAH] URL LAMPIRAN LEWAT ROUTE TERPROTEKSI =================
    // SEBELUMNYA: accessor ini mengubah path relatif (mis. "lampiran-cuti/x.jpg")
    // menjadi URL publik "/storage/lampiran-cuti/x.jpg" lewat Storage::url().
    // Alamat itu bisa dibuka SIAPA SAJA yang tahu URL-nya, tanpa login — tidak
    // aman untuk dokumen sensitif (mis. surat dokter).
    //
    // SEKARANG: accessor mengembalikan alamat route 'lampiran.unduh'
    // (LampiranController@show) yang mengecek login & hak akses (pemilik
    // cuti, atasan yang berwenang, atau Admin HR) sebelum mengirim file.
    //
    // Karena SEMUA halaman (Dashboard, Riwayat, Monitoring Cuti, Rekap Kuota,
    // Penangguhan) sudah memakai field `lampiran` hasil accessor ini sebagai
    // href, perubahan satu accessor ini otomatis berlaku ke seluruh halaman
    // tanpa mengubah file Vue.
    //
    // Alamat dibuat RELATIF (route(..., false)) supaya tetap jalan walau
    // APP_URL berbeda dengan alamat yang dipakai di browser (cookie login
    // terikat ke host).
    //
    // CATATAN: untuk membaca path asli di database (mis. saat mengunduh atau
    // menghapus file) pakai $pengajuan->getRawOriginal('lampiran'), BUKAN
    // $pengajuan->lampiran (yang kini berisi alamat route).
    public function getLampiranAttribute(mixed $value): ?string
    {
        if (!$value || !$this->getKey()) {
            return null;
        }

        return route('lampiran.unduh', ['id' => $this->getKey()], false);
    }
    // ================= [END DIUBAH] =================

    // Relasi ke tabel pegawais
    public function pegawai(): BelongsTo
    {
        return $this->belongsTo(Pegawai::class, 'pegawai_id');
    }

    public function atasanL1(): BelongsTo
    {
        return $this->belongsTo(Pegawai::class, 'atasan_l1_id');
    }

    // ================= [BARU] PERBAIKAN RELASI atasanL2 =================
    // Menambahkan method relasi untuk atasanL2 yang sebelumnya terlewat.
    // Ini memperbaiki error RelationNotFoundException di DashboardController
    // dan memastikan data nama atasan L2 bisa di-load dan ditampilkan
    // secara konsisten di modal Vue frontend.
    public function atasanL2(): BelongsTo
    {
        return $this->belongsTo(Pegawai::class, 'atasan_l2_id');
    }
    // ================= [END BARU] =================

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

    // This record is retained only for reporting suspended approved annual leave.
    public function cutiDitangguhkan(): HasOne
    {
        return $this->hasOne(CutiDitangguhkan::class, 'pengajuan_asal_id');
    }
}
