<!DOCTYPE html>
@php
    $darkModeClass = '';
    if (auth()->check()) {
        $dm = auth()->user()->getPreference('dark_mode');
        if ($dm === 'force_dark') $darkModeClass = 'dark-mode';
        elseif ($dm === 'force_light') $darkModeClass = 'light-mode';
    }
    $envBg = match (app()->environment()) {
        'local'   => 'bg-sky-50',
        'staging' => 'bg-amber-50',
        default   => 'bg-gray-100',
    };
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
    <body class="font-sans antialiased">
        <div class="min-h-screen {{ $envBg }} flex flex-col">
            @include('layouts.navigation')
            <div class="h-16 shrink-0"></div>

            <!-- Page Content -->
            <main class="flex-grow py-8 px-4 sm:px-6 lg:px-8">
                @yield('content')
            </main>

            @include('components.footer')

        </div>

        <!-- Guided demo overlay — renders nothing unless ?demo= arms one -->
        @include('layouts.guided-demo')

        <script>
        window.appTranslations = {
            urlCopied: @js(__('URL copied to clipboard!')),
            copyFailed: @js(__('Failed to copy URL')),
            sessionExpired: @js(__('Your session expired. Reload the page and try again.')),
            payloadTooLarge: @js(__('That upload is too large for the server to accept.')),
            requestFailed: @js(__('The request could not be completed. Try again.')),
        };
        {{--
            window.showToast lives in resources/js/app.js — one definition, using textContent.
            There used to be a second copy here (and in embed.blade.php) that interpolated the
            message into innerHTML. It was dead only because @vite's deferred module overwrote it
            after this inline script ran; now that server-supplied messages reach toasts
            (specs/features/error-handling.md REQ-11), that ordering accident was the only thing
            standing between us and an HTML-injection sink. Both copies are gone, along with the
            #toast-container they appended to — app.js appends to <body>.
        --}}
        </script>

        @stack('scripts')
    </body>
</html>
