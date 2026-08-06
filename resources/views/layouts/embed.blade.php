<!DOCTYPE html>
@php
    $darkModeClass = '';
    if (auth()->check()) {
        $dm = auth()->user()->getPreference('dark_mode');
        if ($dm === 'force_dark') $darkModeClass = 'dark-mode';
        elseif ($dm === 'force_light') $darkModeClass = 'light-mode';
    }
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ $darkModeClass }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>@yield('title', 'Home') - ORCA</title>

        @if($darkModeClass === '')
            <meta name="color-scheme" content="light" />
        @endif
        @if($darkModeClass === 'light-mode')
            <meta name="color-scheme" content="light only" />
        @endif

        <!-- Favicon -->
        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
        <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
        <link rel="preload" href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" as="style">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Font Awesome -->
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @stack('styles')
    </head>
    <body data-testid="embed-page" class="font-sans antialiased embed">
        <div class="min-h-screen bg-gray-100 flex flex-col">
            <!-- Page Content -->
            <main class="flex-grow py-4 px-4 sm:px-6">
                @yield('content')
            </main>
        </div>

        <script>
        {{-- The embedded view has no appTranslations of its own; http-errors.js guards for that. --}}
        window.appTranslations = {
            sessionExpired: @js(__('Your session expired. Reload the page and try again.')),
            payloadTooLarge: @js(__('That upload is too large for the server to accept.')),
            requestFailed: @js(__('The request could not be completed. Try again.')),
        };
        {{-- The inline showToast copy that used to live here is gone — see layouts/app.blade.php. --}}
        </script>

        @stack('scripts')
    </body>
</html>
