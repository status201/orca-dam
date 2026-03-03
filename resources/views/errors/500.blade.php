<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Server Error') }} - ORCA</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link rel="preload" href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" as="style">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css'])
    <style>
        @keyframes dizzy-eye {
            0%   { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        @keyframes dizzy-pupil {
            0%   { transform: translate(0, 0); }
            25%  { transform: translate(2px, -1px); }
            50%  { transform: translate(0, 1px); }
            75%  { transform: translate(-2px, -1px); }
            100% { transform: translate(0, 0); }
        }
        @keyframes orca-bob {
            0%   { transform: scaleX(-1) translateY(0) rotate(180deg); }
            30%  { transform: scaleX(-1) translateY(3px) rotate(181.5deg); }
            60%  { transform: scaleX(-1) translateY(-3px) rotate(179deg); }
            100% { transform: scaleX(-1) translateY(0) rotate(180deg); }
        }
        .orca-floating {
            animation: orca-bob 6s ease-in-out infinite;
        }
        .orca-eye-spiral {
            transform-origin: 68px 48px;
            animation: dizzy-eye 2s linear infinite;
        }
        .orca-pupil {
            animation: dizzy-pupil 1s ease-in-out infinite;
        }
    </style>
</head>
<body class="font-sans antialiased bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="text-center px-6">
        <div class="flex justify-center mb-6">
            <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" class="orca-floating h-24 w-24 opacity-30" style="transform: scaleX(-1) rotate(180deg)">
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
                <!-- Dizzy spiral eye -->
                <g class="orca-eye-spiral">
                    <path d="M 68 45 Q 71 45, 71 48 Q 71 51, 68 51 Q 65 51, 65 48 Q 65 46, 67 45.5" stroke="#1a1a1a" stroke-width="1" fill="none"/>
                    <path d="M 68 46 Q 70 46, 70 48 Q 70 50, 68 50" stroke="#1a1a1a" stroke-width="1" fill="none"/>
                </g>
                <!-- Pupil wobbling -->
                <circle class="orca-pupil" cx="68" cy="48" r="1" fill="#1a1a1a"/>
                <!-- Dizzy smile (wobbly) -->
                <path d="M 72 55 Q 78 58, 82 55" stroke="#1a1a1a" stroke-width="2" fill="none" stroke-linecap="round"/>
                <!-- Pectoral fin -->
                <ellipse cx="48" cy="70" rx="7" ry="15" fill="#1a1a1a" transform="rotate(30 48 70)"/>
            </svg>
        </div>
        <h1 class="text-6xl font-bold text-gray-300 mb-4">500</h1>
        <p class="text-lg text-gray-600 mb-2">{{ __('Server Error') }}</p>
        <p class="text-sm text-gray-400 mb-8">{{ __('Something went wrong on our end. Please try again later.') }}</p>
        <a href="{{ url('/') }}" class="inline-flex items-center px-4 py-2 bg-gray-800 text-white text-sm font-medium rounded-md hover:bg-gray-700 transition-colors">
            {{ __('Back to Home') }}
        </a>
    </div>
</body>
</html>
