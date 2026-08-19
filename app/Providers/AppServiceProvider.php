<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Otomatis: Hanya paksa HTTPS jika diakses melalui link Ngrok
        if (str_contains(request()->getHost(), 'ngrok')) {
            URL::forceScheme('https');
        }
    }
}
