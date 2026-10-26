<?php

namespace App\Jobs;

use App\Models\Pegawai;
use App\Services\PushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

// Mengirim SATU push notification ke semua perangkat milik satu pegawai.
// Dijalankan di latar belakang oleh `php artisan queue:work`, sehingga proses
// utama (ajukan cuti, setujui, impor kuota, dsb.) tidak menunggu Firebase.
class KirimPushNotifikasi implements ShouldQueue
{
    use Queueable;

    // Coba ulang kalau Firebase / jaringan gagal sesaat.
    public int $tries = 3;

    // Jeda sebelum percobaan ke-2 dan ke-3 (detik).
    public array $backoff = [10, 60];

    // Batas waktu satu pengiriman (detik).
    public int $timeout = 30;

    public function __construct(
        public int $pegawaiId,
        public string $judul,
        public string $pesan,
        public string $path = '/dashboard',
    ) {}

    public function handle(PushService $push): void
    {
        $pegawai = Pegawai::find($this->pegawaiId);

        // Pegawai sudah dihapus / tidak ada: tidak ada yang dikirimi.
        if (!$pegawai) {
            return;
        }

        $push->sendToUser($pegawai, $this->judul, $this->pesan, $this->path);
    }
}
