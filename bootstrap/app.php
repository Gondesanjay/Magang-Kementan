<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            // Logout otomatis pegawai yang statusnya bukan 'Aktif' (lihat CekStatusAktif)
            \App\Http\Middleware\CekStatusAktif::class,
        ]);

        $middleware->alias([
            'force.password' => \App\Http\Middleware\ForceChangePassword::class,
            // Pembatas akses per role, dipakai di routes/web.php: role:5, role:2,3,4,6
            'role' => \App\Http\Middleware\EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
