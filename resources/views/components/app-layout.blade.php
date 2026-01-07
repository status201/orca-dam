<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="view-transition" content="same-origin">

        <title>{{ config('app.name', 'Laravel') }}</title>

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
                {{ $slot }}
            </main>

            <!-- Footer with waves -->
            <footer class="wave-footer mt-auto pt-24 pb-8">
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
                    <div class="flex items-center justify-center mb-4">
                        <div class="footer-orca-bg rounded-full p-2 shadow-lg footer-logo-container"
                             onclick="this.querySelector('svg').classList.add('orca-jump'); setTimeout(() => this.querySelector('svg').classList.remove('orca-jump'), 1100);">
                            <x-application-logo class="h-12 w-12 fill-current text-gray-800" />
                        </div>
                    </div>
                    <h3 class="text-white font-semibold text-lg mb-1">ORCA DAM</h3>
                    <p class="text-gray-400 text-sm italic mb-3">ORCA Retrieves Cloud Assets</p>
                    <p class="text-gray-500 text-xs">
                        &copy; {{ date('Y') }} - Digital Asset Management
                    </p>
                </div>
            </footer>
        </div>

        @stack('scripts')
    </body>
</html>
