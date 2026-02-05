<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Two-Factor Authentication Settings
    |--------------------------------------------------------------------------
    |
    | Configuration options for TOTP-based two-factor authentication.
    |
    */

    // Number of recovery codes to generate
    'recovery_codes_count' => env('TWO_FACTOR_RECOVERY_CODES', 8),

    // Length of each recovery code
    'recovery_code_length' => 10,

    // Challenge session lifetime in seconds (how long user has to enter 2FA code)
    'challenge_ttl' => env('TWO_FACTOR_CHALLENGE_TTL', 300), // 5 minutes

    // Rate limit for challenge attempts (per minute)
    'challenge_rate_limit' => 5,

    // Application name shown in authenticator apps
    'issuer' => env('TWO_FACTOR_ISSUER', env('APP_NAME', 'ORCA DAM')),

    // QR code size in pixels
    'qr_code_size' => 200,

];
