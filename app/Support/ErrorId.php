<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * A short reference tying one user-visible error to one log line.
 *
 * The reported bug was not only that an over-length field 500'd — it was that the 500 page said
 * "Something went wrong on our end" and gave the user nothing to quote. Six hex characters are
 * enough to find the request in storage/logs/laravel.log and short enough to read out loud.
 *
 * Memoised in the container, so every surface of one request (log context, response header, JSON
 * body, error page) shows the same value. The container is rebuilt per request under FPM, so ids
 * do not leak between requests. Within a single Pest test the app instance persists, which is what
 * makes the header and the log line assertable against each other.
 *
 * @see specs/features/error-handling.md REQ-7
 */
final class ErrorId
{
    private const KEY = 'orca.error_id';

    public static function current(): string
    {
        if (! app()->bound(self::KEY)) {
            // Hex only, upper-cased: unambiguous when a user reads it over the phone, no 0/O or
            // 1/l/I confusion. random_bytes rather than uniqid()/mt_rand() — those are banned
            // outright by tests/Security/ArchitectureTest.php and there is no exemption to claim.
            app()->instance(self::KEY, Str::upper(bin2hex(random_bytes(3))));
        }

        return app(self::KEY);
    }
}
