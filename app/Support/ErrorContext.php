<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * The log context attached to every reported exception.
 *
 * For a QueryException this is what answers ADR-010's objection to a global handler ("loses the
 * per-operation context for logging"): SQLSTATE, driver code, classification, table, column, route
 * and the parameterised SQL is strictly more than a per-service `Log::error($e->getMessage())` gave.
 *
 * Binding **lengths** are logged, never binding values. Lengths are exactly what a truncation bug
 * needs, and values are what must not end up in `storage/logs/laravel.log` — which /system's log
 * viewer renders to any admin, and which gets shipped off the box in backups.
 *
 * @see specs/features/error-handling.md REQ-8
 */
final class ErrorContext
{
    public static function for(Throwable $e): array
    {
        $context = ['error_id' => ErrorId::current()];

        if (! $e instanceof QueryException) {
            return $context;
        }

        [$state, $code] = DatabaseError::info($e);
        $hint = DatabaseError::classify($e);

        return $context + [
            'sqlstate' => $state,
            'driver_code' => $code,
            'kind' => $hint->kind ?? 'unknown',
            'table' => DatabaseError::tableFor($e),
            'column' => $hint->column ?? null,
            'sql' => $e->getSql(),
            'binding_lengths' => array_map(
                fn ($binding) => is_string($binding) ? mb_strlen($binding) : null,
                $e->getBindings()
            ),
            'route' => request()?->route()?->getName(),
        ];
    }
}
