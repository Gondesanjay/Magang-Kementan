<?php

namespace App\Models;

use App\Jobs\KirimPushNotifikasi;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Notifikasi extends Model
{
    use HasFactory;

    protected $table = 'notifikasis';

    protected $fillable = [
        'pegawai_id',
        'pengajuan_id',
        'judul',
        'pesan',
        'tautan',
        'is_read',
    ];

    // Judul notifikasi "antrean" (menunggu tindakan atasan / Admin HR).
    // Dihapus bersamaan saat pengajuan diproses, dibatalkan, atau ditangguhkan.
    public const JUDUL_ANTREAN = [
        'Pengajuan Cuti Baru',
        'Pengingat: Pengajuan Cuti',
        'Pengingat Kedua: Pengajuan Cuti',
        'Pengajuan Cuti Menggantung',
    ];

    // Hapus semua notifikasi antrean milik satu pengajuan, siapa pun penerimanya.
    // Notifikasi lama tanpa pengajuan_id (NULL) tidak tersentuh.
    public static function hapusAntreanPengajuan(int $pengajuanId): int
    {
        return static::where('pengajuan_id', $pengajuanId)
            ->whereIn('judul', self::JUDUL_ANTREAN)
            ->delete();
    }

    // Setiap notifikasi lonceng yang dibuat otomatis dikirim juga sebagai push
    // lewat ANTREAN (job KirimPushNotifikasi).
    //
    // KEAMANAN:
    //  - Isi push dibuat GENERIK. Alasan penolakan/penangguhan, nama atasan,
    //    dan nama pegawai TIDAK ikut ke push karena tampil di layar kunci.
    //    Detail lengkap hanya ada di lonceng dalam aplikasi (setelah login).
    //  - Tautan push SELALU path internal (diawali "/"), tidak pernah URL luar.
    protected static function booted(): void
    {
        static::created(function (Notifikasi $notif) {
            DB::afterCommit(function () use ($notif) {
                try {
                    KirimPushNotifikasi::dispatch(
                        (int) $notif->pegawai_id,
                        (string) $notif->judul,
                        self::bodyPushAman((string) $notif->judul),
                        self::pathInternalAman($notif->tautan)
                    );
                } catch (\Throwable $e) {
                    // Gagal masuk antrean tidak boleh membatalkan proses utama.
                    report($e);
                }
            });
        });
    }

    // Teks push generik berdasarkan judul notifikasi.
    //
    // URUTAN ARM PENTING: match(true) memakai arm PERTAMA yang cocok, jadi judul
    // yang spesifik harus diperiksa lebih dulu daripada yang umum. Contoh:
    //   - "Saldo Cuti Diperbarui" dicek SEBELUM kata "diperbarui" yang umum.
    //   - "Pengingat", "Menggantung", dan "Dibatalkan" dicek SEBELUM kata
    //     "pengajuan" yang umum.
    private static function bodyPushAman(string $judul): string
    {
        $j = mb_strtolower($judul);

        return match (true) {
            // --- Spesifik ---
            str_contains($j, 'tahun baru')  => 'Saldo cuti tahun baru sudah tersedia. Buka aplikasi untuk detail.',
            str_contains($j, 'saldo')       => 'Saldo cuti Anda diperbarui. Buka aplikasi untuk detail.',
            str_contains($j, 'menggantung') => 'Ada pengajuan cuti yang belum diproses. Buka aplikasi untuk detail.',
            str_contains($j, 'pengingat')   => 'Ada pengajuan cuti yang menunggu tindakan Anda.',
            str_contains($j, 'dibatalkan')  => 'Ada pengajuan cuti yang dibatalkan. Buka aplikasi untuk detail.',

            // --- Status pengajuan milik pegawai ---
            str_contains($j, 'ditolak'),
            str_contains($j, 'ditangguhkan'),
            str_contains($j, 'diperbarui')  => 'Status pengajuan cuti Anda diperbarui. Buka aplikasi untuk detail.',

            // --- Pengajuan masuk ke atasan ---
            str_contains($j, 'pengajuan')   => 'Ada pengajuan cuti yang menunggu tindakan Anda.',

            // --- Lainnya (mis. Info Cuti Kepala Biro) ---
            default                         => 'Anda memiliki notifikasi baru. Buka aplikasi untuk detail.',
        };
    }

    // Ubah tautan (absolut/relatif) jadi path internal yang aman.
    // Host dibuang, hanya path + query yang dipakai. Apa pun yang tidak
    // diawali satu "/" (mis. "//evil.com", "\\evil.com", "javascript:")
    // diganti ke /dashboard.
    private static function pathInternalAman(?string $tautan): string
    {
        $default = '/dashboard';

        if (empty($tautan)) {
            return $default;
        }

        $parts = parse_url($tautan);
        if ($parts === false) {
            return $default;
        }

        $path = ($parts['path'] ?? $default)
            . (isset($parts['query']) ? '?' . $parts['query'] : '');

        if (
            !str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_contains($path, '\\')
        ) {
            return $default;
        }

        return $path;
    }

    // Relasi balik ke model Pegawai (karyawan penerima notifikasi)
    public function pegawai()
    {
        return $this->belongsTo(Pegawai::class, 'pegawai_id');
    }

    // Pengajuan cuti yang terkait (NULL untuk notifikasi lama / non-pengajuan)
    public function pengajuan()
    {
        return $this->belongsTo(PengajuanCuti::class, 'pengajuan_id');
    }
}
