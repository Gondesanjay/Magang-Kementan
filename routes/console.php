<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pengingat bertahap ke atasan + kedaluwarsa otomatis pengajuan cuti yang
// menggantung (lihat app/Console/Commands/ProsesCutiMenunggu.php).
Schedule::command('cuti:proses-menunggu')
    ->dailyAt('07:00')
    ->withoutOverlapping();
