<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Who is allowed to see exception detail.
 *
 * The rule is ADR-010's: an api-role token gets the generic message only; an admin/editor
 * debugging their own instance sees the underlying failure. It used to live inside
 * `Controller::clientError()`, which the global exception handler cannot reach (a protected
 * method on an abstract controller), and was hand-copied once in AssetReplaceController. It lives
 * here so both paths apply one rule.
 *
 * @see specs/features/error-handling.md REQ-9
 * @see specs/decisions/adr-016-database-errors-are-user-errors.md
 */
final class ErrorAudience
{
    /**
     * Exception detail for a trusted operator, or null when the caller may see none.
     */
    public static function detail(Throwable $e): ?string
    {
        if (optional(Auth::user())->isApiUser()) {
            return null;
        }

        // A QueryException's getMessage() appends Laravel's " (Connection: …, SQL: …)" tail with
        // the BINDINGS SUBSTITUTED INTO the SQL. Even a trusted operator gets the driver's own
        // sentence instead — a truncation error should not echo the row's data back at anyone,
        // and the same string is what would land in a log the /system viewer renders.
        if ($e instanceof QueryException) {
            return DatabaseError::driverMessage($e);
        }

        return $e->getMessage();
    }
}
