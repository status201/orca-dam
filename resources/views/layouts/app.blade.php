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
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Font Awesome -->
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @stack('styles')
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen bg-gray-100 flex flex-col">
            @include('layouts.navigation')

            <!-- Page Heading -->
            @isset($header)
                <header class="bg-white shadow">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <!-- Page Content -->
            <main class="flex-grow py-8 px-4 sm:px-6 lg:px-8">
                @yield('content')
            </main>

            @include('components.footer')

        </div>

        <!-- Toast Container -->
        <div id="toast-container" class="fixed top-4 right-4 z-50 space-y-2"></div>

        <script>
        // Toast notification system
        window.showToast = function(message, type = 'success') {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');

            const bgColor = type === 'error' ? 'bg-red-500' : 'bg-green-500';
            const icon = type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle';

            toast.className = `${bgColor} attention text-white px-6 py-3 rounded-lg shadow-lg flex items-center space-x-3 transform transition-all duration-300 translate-x-full opacity-0`;
            toast.innerHTML = `
                <i class="fas ${icon}"></i>
                <span>${message}</span>
            `;

            container.appendChild(toast);

            // Animate in
            setTimeout(() => {
                toast.classList.remove('translate-x-full', 'opacity-0');
            }, 10);

            // Remove after 5 seconds
            setTimeout(() => {
                toast.classList.add('translate-x-full', 'opacity-0');
                setTimeout(() => toast.remove(), 300);
            }, 5000);
        };
        </script>

        @stack('scripts')
    </body>
</html>
