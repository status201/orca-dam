<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Admin bootstrap credentials
    |--------------------------------------------------------------------------
    |
    | Used only by database/seeders/AdminUserSeeder.php, which creates the very
    | first admin account on a fresh installation. See
    | specs/features/security-invariants.md REQ-9.
    |
    | These live in config rather than being read with env() inside the seeder
    | because DEPLOYMENT.md runs `php artisan config:cache` during deployment,
    | after which env() returns null and the seeder would silently fall back to
    | its development defaults.
    |
    | Both are deliberately null by default. In production the seeder refuses to
    | run unless they are set — the hardcoded development credentials are in a
    | public repository, so they are not a weak secret but a published one.
    | Outside production the seeder falls back to those defaults for convenience.
    |
    */

    'admin_bootstrap' => [
        'name' => env('ORCA_ADMIN_NAME'),
        'email' => env('ORCA_ADMIN_EMAIL'),
        'password' => env('ORCA_ADMIN_PASSWORD'),
    ],

];
