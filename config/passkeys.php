<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Relying Party ID
    |--------------------------------------------------------------------------
    |
    | The relying party ID represents the application in the WebAuthn protocol.
    | Defaults to the host of APP_URL. PASSKEYS_RELYING_PARTY_ID can override;
    | WEBAUTHN_ID is honored as a fallback during the laragear→laravel/passkeys
    | migration and will be retired once that rollover is complete.
    |
    */

    'relying_party_id' => env(
        'PASSKEYS_RELYING_PARTY_ID',
        env('WEBAUTHN_ID', parse_url((string) config('app.url'), PHP_URL_HOST))
    ),

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins
    |--------------------------------------------------------------------------
    |
    | Origins permitted to complete WebAuthn ceremonies. Defaults to APP_URL.
    | PASSKEYS_ORIGINS may set a comma-separated list; WEBAUTHN_ORIGINS is the
    | legacy fallback (kept for one release).
    |
    */

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(
        ',',
        (string) env(
            'PASSKEYS_ORIGINS',
            env('WEBAUTHN_ORIGINS', (string) config('app.url'))
        )
    )))),

    /*
    |--------------------------------------------------------------------------
    | User Handle Secret
    |--------------------------------------------------------------------------
    |
    | Secret used to derive a stable WebAuthn user handle from each user model.
    | Defaults to APP_KEY; set explicitly only if the application key rotates.
    |
    */

    'user_handle_secret' => env('PASSKEYS_USER_HANDLE_SECRET', config('app.key')),

    /*
    |--------------------------------------------------------------------------
    | WebAuthn Timeout (milliseconds)
    |--------------------------------------------------------------------------
    */

    'timeout' => 60000,

    /*
    |--------------------------------------------------------------------------
    | Authentication Guard
    |--------------------------------------------------------------------------
    */

    'guard' => 'web',

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | ORCA registers its own routes (/passkey/*, /profile/passkeys/*) via
    | routes/auth.php. The package's auto-registered routes are disabled in
    | AppServiceProvider::boot() via Passkeys::ignoreRoutes(). The middleware
    | settings below remain in case package routes are re-enabled later.
    |
    */

    'middleware' => ['web'],

    // No password confirmation step on passkey add/rename/delete — matches the
    // current (laragear) UX where session auth is sufficient.
    'management_middleware' => [],

    // ORCA enforces its own per-IP rate limit (10/min) inside PasskeyLoginController.
    'throttle' => null,

    /*
    |--------------------------------------------------------------------------
    | Redirect after successful passkey login
    |--------------------------------------------------------------------------
    */

    'redirect' => '/dashboard',

];
