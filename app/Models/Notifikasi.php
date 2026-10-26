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
        'judul',
        'pesan',
        'tautan',
        'is_read',
    ];

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
    //   - "Saldo Cuti Diperbarui" harus dicek SEBELUM kata "diperbarui" yang umum,
    //     kalau tidak ia ikut mendapat teks status pengajuan.
    //   - "Pengingat: Pengajuan Cuti" & "Pengajuan Cuti Kedaluwarsa" harus dicek
    //     SEBELUM kata "pengajuan" yang umum.
    private static function bodyPushAman(string $judul): string
    {
        $j = mb_strtolower($judul);

        return match (true) {
            // --- Spesifik ---
            str_contains($j, 'saldo')       => 'Saldo cuti Anda diperbarui. Buka aplikasi untuk detail.',
            str_contains($j, 'kedaluwarsa') => 'Ada pengajuan cuti yang kedaluwarsa. Buka aplikasi untuk detail.',
            str_contains($j, 'menggantung') => 'Ada pengajuan cuti yang belum diproses. Buka aplikasi untuk detail.',
            str_contains($j, 'pengingat')   => 'Ada pengajuan cuti yang menunggu tindakan Anda.',

            // --- Status pengajuan milik pegawai ---
            str_contains($j, 'ditolak'),
            str_contains($j, 'ditangguhkan'),
            str_contains($j, 'diperbarui')  => 'Status pengajuan cuti Anda diperbarui. Buka aplikasi untuk detail.',

            // --- Pengajuan masuk ke atasan ---
            str_contains($j, 'pengajuan'),
            str_contains($j, 'revisi')      => 'Ada pengajuan cuti yang menunggu tindakan Anda.',

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
}
