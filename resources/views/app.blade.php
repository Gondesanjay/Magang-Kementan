<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">


    <!-- Pemblokir cache browser (pelengkap middleware PreventBackHistory) -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">


    <title inertia>{{ config('app.name', 'Laravel') }}</title>


    <!-- Favicon (logo Biroren Kementan). Naikkan angka ?v= bila logo diganti lagi,
         supaya browser mengambil file baru, bukan yang tersimpan di cache. -->
    <link rel="icon" href="/favicon.ico?v=2" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/images/favicon-32.png?v=2">
    <link rel="icon" type="image/png" sizes="192x192" href="/images/favicon-192.png?v=2">
    <link rel="apple-touch-icon" href="/images/apple-touch-icon.png?v=2">


    <!-- BARU: aplikasi web (PWA). Dibutuhkan agar AgriLeave bisa dipasang di Layar Utama
         iPhone dan menerima push notification (iOS hanya mengirim push ke aplikasi
         yang dipasang di Layar Utama). File manifest: public/manifest.json -->
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#16a34a">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="AgriLeave">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">


    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />


    <!-- Scripts -->
    @routes
    @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
    @inertiaHead
</head>

<body class="font-sans antialiased">
    @inertia
</body>

</html>