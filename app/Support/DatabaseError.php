<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Turns a driver rejection into something a user can act on.
 *
 * Most QueryExceptions are user errors wearing a server error's clothes: a value wider than its
 * column, a name that is already taken, a required field left empty. Presented as a 500 they are
 * unactionable — which is exactly what the reported bug was. Classified here, they become the
 * validation error that should have caught them.
 *
 * Two rules govern everything below:
 *
 *  1. **Read `errorInfo`, never `getMessage()`.** Laravel appends " (Connection: …, SQL: …)" to a
 *     QueryException's message with the bindings substituted into the SQL, so `getMessage()` carries
 *     the user's data. `errorInfo[2]` is the driver's own sentence and carries none.
 *  2. **Degrade, never guess.** A column derived from an index name is verified against the schema
 *     before it is used. An unverifiable one becomes null and takes the unkeyed message.
 *
 * @see specs/features/error-handling.md
 * @see specs/decisions/adr-016-database-errors-are-user-errors.md
 */
final class DatabaseError
{
    /**
     * SQLSTATE, driver-specific code, driver message.
     *
     * @return array{0: string, 1: int|string|null, 2: string}
     */
    public static function info(QueryException $e): array
    {
        $info = $e->errorInfo ?? [];

        return [
            (string) ($info[0] ?? $e->getCode()),
            $info[1] ?? null,
            (string) ($info[2] ?? Str::before($e->getMessage(), ' (Connection: ')),
        ];
    }

    /**
     * The driver's own sentence, free of the SQL-with-bindings tail.
     */
    public static function driverMessage(QueryException $e): string
    {
        return self::info($e)[2];
    }

    /**
     * The table a failed write targeted, read from the (parameterised) SQL rather than the message —
     * only MySQL names a column, and none of the drivers name the table.
     */
    public static function tableFor(QueryException $e): ?string
    {
        $matched = preg_match(
            '/^\s*(?:insert(?:\s+or\s+\w+)?\s+into|update|replace\s+into)\s+[`"\[]?([\w.]+)/i',
            $e->getSql(),
            $m
        );

        if (! $matched) {
            return null;
        }

        // MySQL 8 schema-qualifies nothing here, but SQLite/Postgres may: `main.assets`.
        return Str::afterLast(trim($m[1], '`"[]'), '.');
    }

    /**
     * Classify a rejection, or return null when it is not recognised.
     *
     * A null return is the signal for "this is genuinely ours" — the caller keeps the debug page in
     * development and shows a friendly failure with a reference in production.
     */
    public static function classify(QueryException $e): ?DatabaseErrorHint
    {
        [$state, $code, $message] = self::info($e);
        $table = self::tableFor($e);

        // --- value too long -------------------------------------------------------------------
        // MySQL/MariaDB 1406 (22001) and 1265 (01000, "Data truncated"); SQL Server 2628/8152.
        if (preg_match('/Data (?:too long|truncated) for column \'([^\']+)\'/i', $message, $m)
            || preg_match('/String or binary data would be truncated.*column \'([^\']+)\'/i', $message, $m)) {
            return self::tooLong($table, $m[1]);
        }

        // PostgreSQL 22001 names the type, not the column: "value too long for type character varying(255)".
        if (preg_match('/value too long for type character(?: varying)?\((\d+)\)/i', $message, $m)) {
            return self::tooLong($table, null, (int) $m[1]);
        }

        // A bare 22001 from a driver we do not have a phrasing for is still unambiguously "too long".
        if ($state === '22001') {
            return self::tooLong($table, null);
        }

        // --- duplicate / unique ---------------------------------------------------------------
        // MySQL 1062 gives the index name; SQLite gives table.column; PostgreSQL the constraint name.
        if (preg_match('/Duplicate entry \'.*\' for key \'([^\']+)\'/', $message, $m)
            || preg_match('/duplicate key value violates unique constraint "([^"]+)"/i', $message, $m)) {
            return self::duplicate($table, self::columnFromIndexName($table, $m[1]));
        }

        if (preg_match('/UNIQUE constraint failed: ([\w.]+)/', $message, $m)) {
            return self::duplicate($table, self::columnFromQualifiedName($m[1]));
        }

        // --- missing required -----------------------------------------------------------------
        if (preg_match('/Column \'([^\']+)\' cannot be null/i', $message, $m)
            || preg_match('/null value in column "([^"]+)" violates not-null/i', $message, $m)) {
            return self::missingRequired($table, $m[1]);
        }

        if (preg_match('/NOT NULL constraint failed: ([\w.]+)/', $message, $m)) {
            return self::missingRequired($table, self::columnFromQualifiedName($m[1]));
        }

        // --- foreign keys ---------------------------------------------------------------------
        // 1451: the row is still referenced (deleting a parent). 1452: the reference is stale
        // (inserting a child). SQLite and Postgres do not distinguish, so those fall through to
        // the conflict message, which is true in both directions.
        if (preg_match('/Cannot delete or update a parent row/i', $message)) {
            return new DatabaseErrorHint(
                kind: 'still_referenced',
                status: 409,
                message: __('This record is still in use elsewhere. Remove those references first.'),
            );
        }

        if (preg_match('/Cannot add or update a child row/i', $message)) {
            return new DatabaseErrorHint(
                kind: 'stale_reference',
                status: 422,
                message: __('One of the linked records no longer exists. Reload the page and try again.'),
            );
        }

        if (preg_match('/FOREIGN KEY constraint failed|violates foreign key constraint/i', $message)) {
            return new DatabaseErrorHint(
                kind: 'related_conflict',
                status: 409,
                message: __('This change conflicts with a linked record. Reload the page and try again.'),
            );
        }

        // --- transient ------------------------------------------------------------------------
        if (preg_match('/Deadlock found|Lock wait timeout exceeded/i', $message) || $state === '40001') {
            return new DatabaseErrorHint(
                kind: 'busy',
                status: 409,
                message: __('The system was busy saving another change. Try again.'),
            );
        }

        if (str_starts_with($state, '08')
            || preg_match('/server has gone away|Connection refused|getaddrinfo|too many connections/i', $message)) {
            return new DatabaseErrorHint(
                kind: 'unavailable',
                status: 503,
                message: __('The database is temporarily unavailable. Try again in a moment.'),
            );
        }

        unset($code);

        return null;
    }

    /**
     * The "too long" hint, with the strongest message the known facts support.
     *
     * The degradation ladder is the point of this method: a counted message when the column is
     * known, a named-but-uncounted one when only the field is known, and a generic one when neither
     * is. All three tell the user the same corrective action — shorten it.
     */
    private static function tooLong(?string $table, ?string $column, ?int $limit = null): DatabaseErrorHint
    {
        $column = self::verifyColumn($table, $column);

        if ($column === null) {
            return new DatabaseErrorHint(
                kind: 'too_long',
                status: 422,
                message: __('One of the values you entered is too long. Shorten it and try again.'),
            );
        }

        $limit ??= self::declaredLimit($table, $column);

        // With a limit known, use the framework's own max-length string: the message is then
        // word-for-word what the validation rule would have produced, already translated in both
        // locales, so a driver rejection and a rule rejection are indistinguishable to the user.
        $message = $limit !== null
            ? __('validation.max.string', ['attribute' => self::attributeLabel($column), 'max' => $limit])
            : __(':Attribute is too long for this field. Shorten it and try again.', ['Attribute' => self::attributeLabel($column)]);

        return new DatabaseErrorHint('too_long', 422, $message, $column, $limit);
    }

    private static function duplicate(?string $table, ?string $column): DatabaseErrorHint
    {
        $column = self::verifyColumn($table, $column);

        $message = $column !== null
            ? __('validation.unique', ['attribute' => self::attributeLabel($column)])
            : __('That change conflicts with a record that already exists.');

        return new DatabaseErrorHint('duplicate', 422, $message, $column);
    }

    private static function missingRequired(?string $table, ?string $column): DatabaseErrorHint
    {
        $column = self::verifyColumn($table, $column);

        $message = $column !== null
            ? __('validation.required', ['attribute' => self::attributeLabel($column)])
            : __('A required value is missing. Fill in every required field and try again.');

        return new DatabaseErrorHint('missing_required', 422, $message, $column);
    }

    /**
     * Derive a column from an index name — the only thing MySQL 1062 gives us.
     *
     * Laravel names indexes `{table}_{column}_unique`, and MySQL 8.0.19+ schema-qualifies the name
     * (`assets.assets_s3_key_unique`). The result is a guess by construction, which is why every
     * caller runs it through verifyColumn().
     */
    private static function columnFromIndexName(?string $table, string $rawKey): ?string
    {
        $key = Str::afterLast($rawKey, '.');

        // MySQL calls the primary key "PRIMARY"; there is no column to derive.
        if (strcasecmp($key, 'PRIMARY') === 0) {
            return null;
        }

        if ($table === null || ! str_starts_with($key, $table.'_')) {
            return null;
        }

        return Str::of($key)->after($table.'_')->beforeLast('_')->toString() ?: null;
    }

    /**
     * SQLite reports `table.column` (and, for a composite index, a comma-separated list).
     */
    private static function columnFromQualifiedName(string $qualified): ?string
    {
        return Str::afterLast(Str::before($qualified, ','), '.') ?: null;
    }

    /**
     * A column name is only usable if it really exists — otherwise the error bag would key on a
     * field no form has, and the user would see nothing at all.
     */
    private static function verifyColumn(?string $table, ?string $column): ?string
    {
        if ($table === null || $column === null || $column === '') {
            return null;
        }

        try {
            return Schema::hasColumn($table, $column) ? $column : null;
        } catch (\Throwable) {
            // Schema introspection needs the connection that just failed. If it is unavailable
            // (the `unavailable` kind), fall back to the unkeyed message rather than erroring
            // from inside the exception handler.
            return null;
        }
    }

    /**
     * The declared width, when we own the declaration. Deliberately not read from the live schema:
     * SQLite reports a bare `varchar` with no length, so introspection would return nothing in
     * tests and give a false sense of coverage.
     */
    private static function declaredLimit(string $table, string $column): ?int
    {
        return ColumnLimits::CHARS[$table][$column] ?? null;
    }

    /**
     * The human name for a column, following Laravel's own attribute resolution so the backstop's
     * label matches the one a validation error would use.
     */
    private static function attributeLabel(string $column): string
    {
        $key = 'validation.attributes.'.$column;
        $translated = __($key);

        return $translated === $key ? str_replace('_', ' ', Str::snake($column)) : $translated;
    }
}
