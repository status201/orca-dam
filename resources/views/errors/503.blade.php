<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Service Unavailable') }} - ORCA</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link rel="preload" href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" as="style">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css'])
    <style>
        @keyframes orca-bob {
            0%   { transform: translateY(0) rotate(0deg); }
            30%  { transform: translateY(-6px) rotate(1.5deg); }
            60%  { transform: translateY(2px) rotate(-1deg); }
            100% { transform: translateY(0) rotate(0deg); }
        }
        @keyframes orca-blink {
            0%   { transform: scaleY(1); }
            48%  { transform: scaleY(1); }
            50%  { transform: scaleY(0.1); }
            52%  { transform: scaleY(1); }
            100% { transform: scaleY(1); }
        }
        .orca-floating {
            animation: orca-bob 4s ease-in-out infinite;
        }
        .orca-eye-blink {
            transform-origin: 68px 48px;
            animation: orca-blink 5s ease-in-out infinite;
        }
    </style>
</head>
<body class="font-sans antialiased bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="text-center px-6">
        <div class="flex justify-center mb-6">
            <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" class="h-24 w-24 opacity-30 orca-floating">
                <!-- Body -->
                <ellipse cx="50" cy="55" rx="35" ry="25" fill="#1a1a1a"/>
                <!-- Tail -->
                <path d="M 15 60 Q 5 50, 8 42 Q 16 48, 16 50 Z" fill="#1a1a1a"/>
                <path d="M 15 50 Q 5 60, 8 68 Q 16 62, 16 60 Z" fill="#1a1a1a"/>
                <!-- Dorsal fin -->
                <path d="M 44 40 L 42 15 L 48 30 Z" fill="#1a1a1a"/>
                <!-- White belly patch -->
                <ellipse cx="60" cy="58" rx="15" ry="10" fill="white"/>
                <!-- White eye patch -->
                <ellipse cx="68" cy="48" rx="8" ry="10" fill="white" transform="rotate(-20 68 48)"/>
                <!-- Eye (with blink) -->
                <g class="orca-eye-blink">
                    <circle cx="68" cy="48" r="3" fill="#1a1a1a"/>
                    <circle cx="69" cy="47" r="1" fill="white"/>
                </g>
                <!-- Smile -->
                <path d="M 72 55 Q 78 58, 82 55" stroke="#1a1a1a" stroke-width="2" fill="none" stroke-linecap="round"/>
                <!-- Pectoral fin -->
                <ellipse cx="48" cy="70" rx="7" ry="15" fill="#1a1a1a" transform="rotate(30 48 70)"/>
            </svg>
        </div>
        <h1 class="text-6xl font-bold text-gray-300 mb-4">503</h1>
        <p class="text-lg text-gray-600 mb-2">{{ __('Service Unavailable') }}</p>
        <p class="text-sm text-gray-400 mb-8">{{ __('ORCA is currently undergoing maintenance. We\'ll be back shortly.') }}</p>
        <a href="{{ url('/') }}" class="inline-flex items-center px-4 py-2 bg-gray-800 text-white text-sm font-medium rounded-md hover:bg-gray-700 transition-colors">
            {{ __('Try Again') }}
        </a>
    </div>
</body>
</html>
