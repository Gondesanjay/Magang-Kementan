<?php


use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;


Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


// Pengingat bertahap ke atasan dan Admin HR untuk pengajuan yang menunggu
// (lihat app/Console/Commands/ProsesCutiMenunggu.php).
Schedule::command('cuti:proses-menunggu')
    ->dailyAt('07:00')
    ->withoutOverlapping();


// Generate Saldo Tahun Baru SENGAJA TIDAK dijadwalkan otomatis.
// Dompet tahun baru dibuat manual oleh Admin HR lewat tombol Generate
// di Rekap Kuota Detail (lihat app/Console/Commands/GenerateSaldoCutiTahunBaru.php).


// Arsip OTOMATIS pengajuan cuti final yang selesai lebih dari arsip_tahun
// (config/leave.php, minimal 5 tahun; lihat app/Console/Commands/ArsipkanCuti.php).
// Dijalankan tiap 2 Januari jam 02.00 dengan --jalankan dan --tanpa-konfirmasi
// (tanpa layar, pertanyaan yes/no dilewati). Gembok umur 5 tahun tetap berlaku
// di dalam command, jadi data yang lebih muda tidak akan ikut pindah.
// Hasil per eksekusi dicatat di storage/logs/arsip.log.
// Catatan: cuti:data-uji-arsip hanya untuk data uji lokal, JANGAN dijadwalkan.
Schedule::command('cuti:arsipkan', ['--jalankan', '--tanpa-konfirmasi'])
    ->yearlyOn(1, 2, '02:00') // 2 Januari, jam 02.00
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/arsip.log'));
