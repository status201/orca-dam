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

    /*
    |--------------------------------------------------------------------------
    | PHP CLI binary
    |--------------------------------------------------------------------------
    |
    | Absolute path to a PHP *CLI* binary, for the web test runner
    | (app/Services/TestRunnerService.php) — see
    | specs/features/system-admin.md REQ-6. Null means "work it out from
    | PHP_BINARY", which is right almost everywhere.
    |
    | It exists because PHP_BINARY under a web request can point at php-fpm or
    | CGI, neither of which can run `artisan test`. On Plesk, for example:
    | PHP_CLI_PATH=/opt/plesk/php/8.2/bin/php
    |
    | Same reason as the block above for living in config rather than being read
    | with env() at the call site: `config:cache` makes env() return null, and
    | the service used to read it that way — so the override was inert in
    | precisely the deployment that needs it.
    |
    */

    'php_cli_path' => env('PHP_CLI_PATH'),

];
