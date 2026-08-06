<?php

use App\Models\User;
use App\Support\DatabaseError;
use App\Support\ErrorAudience;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The classifier that turns a driver rejection into an actionable message.
 *
 * @see specs/features/error-handling.md REQ-1, REQ-3, REQ-4, REQ-9
 */

/**
 * A QueryException carrying a specific driver error.
 *
 * SQLite cannot produce a 22001 at all (it does not enforce varchar length), and CI provisions no
 * MariaDB, so the MySQL/Postgres phrasings have to be synthesised. PDOException::$errorInfo is a
 * public property and QueryException copies it from the previous exception, so no reflection is
 * needed. The feature test alongside this one exercises the same pipeline against *real* SQLite
 * 23000 errors, so the plumbing is never proven by synthetic exceptions alone.
 */
function driverError(string $state, int $code, string $message, string $sql = 'update `assets` set `copyright` = ? where `id` = ?', array $bindings = ['x', 1]): QueryException
{
    $pdo = new PDOException("SQLSTATE[{$state}]: {$message}");
    $pdo->errorInfo = [$state, $code, $message];

    return new QueryException('mysql', $sql, $bindings, $pdo);
}

test('a driver rejection is classified into a kind, a status and a column', function (
    string $state,
    int $code,
    string $message,
    string $sql,
    string $expectedKind,
    ?string $expectedColumn,
    int $expectedStatus,
) {
    $hint = DatabaseError::classify(driverError($state, $code, $message, $sql));

    expect($hint)->not->toBeNull()
        ->and($hint->kind)->toBe($expectedKind)
        ->and($hint->column)->toBe($expectedColumn)
        ->and($hint->status)->toBe($expectedStatus);

    // Nothing the driver said about the query or its values may reach the user.
    expect($hint->message)
        ->not->toContain('SQLSTATE')
        ->not->toContain('select ')
        ->not->toContain('update ')
        ->not->toContain('insert ')
        ->not->toContain('Connection:');
})->with([
    // MySQL / MariaDB — the family that produced the reported bug.
    'mysql data too long' => ['22001', 1406, "Data too long for column 'copyright' at row 1", 'update `assets` set `copyright` = ?', 'too_long', 'copyright', 422],
    'mysql data truncated' => ['01000', 1265, "Data truncated for column 'copyright_source' at row 1", 'update `assets` set `copyright_source` = ?', 'too_long', 'copyright_source', 422],
    'mysql duplicate entry' => ['23000', 1062, "Duplicate entry 'assets/x.jpg' for key 'assets.assets_s3_key_unique'", 'insert into `assets` (`s3_key`) values (?)', 'duplicate', 's3_key', 422],
    'mysql duplicate on primary' => ['23000', 1062, "Duplicate entry '7' for key 'PRIMARY'", 'insert into `assets` (`id`) values (?)', 'duplicate', null, 422],
    'mysql column cannot be null' => ['23000', 1048, "Column 'filename' cannot be null", 'insert into `assets` (`filename`) values (?)', 'missing_required', 'filename', 422],
    'mysql parent row still referenced' => ['23000', 1451, 'Cannot delete or update a parent row: a foreign key constraint fails', 'delete from `users` where `id` = ?', 'still_referenced', null, 409],
    'mysql child row stale reference' => ['23000', 1452, 'Cannot add or update a child row: a foreign key constraint fails', 'insert into `assets` (`user_id`) values (?)', 'stale_reference', null, 422],
    'mysql deadlock' => ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction', 'update `assets` set `filename` = ?', 'busy', null, 409],
    'mysql lock wait timeout' => ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction', 'update `assets` set `filename` = ?', 'busy', null, 409],
    'mysql server gone away' => ['HY000', 2006, 'MySQL server has gone away', 'update `assets` set `filename` = ?', 'unavailable', null, 503],
    'mysql connection refused' => ['08006', 2002, 'Connection refused', 'update `assets` set `filename` = ?', 'unavailable', null, 503],

    // SQLite — what the suite itself raises.
    'sqlite unique' => ['23000', 19, 'UNIQUE constraint failed: tags.name', 'insert into "tags" ("name") values (?)', 'duplicate', 'name', 422],
    'sqlite not null' => ['23000', 19, 'NOT NULL constraint failed: assets.s3_key', 'insert into "assets" ("s3_key") values (?)', 'missing_required', 's3_key', 422],
    'sqlite foreign key' => ['23000', 19, 'FOREIGN KEY constraint failed', 'insert into "assets" ("user_id") values (?)', 'related_conflict', null, 409],

    // PostgreSQL — not deployed, but the phrasings are cheap to support and free to pin.
    'postgres value too long' => ['22001', 7, 'value too long for type character varying(255)', 'update "assets" set "copyright" = ?', 'too_long', null, 422],
    'postgres duplicate key' => ['23505', 7, 'duplicate key value violates unique constraint "assets_s3_key_unique"', 'insert into "assets" ("s3_key") values (?)', 'duplicate', 's3_key', 422],
    'postgres not null' => ['23502', 7, 'null value in column "filename" violates not-null constraint', 'insert into "assets" ("filename") values (?)', 'missing_required', 'filename', 422],
]);

test('an unrecognised driver error is not classified', function () {
    // Returning null is the signal that keeps the debug page alive in development and produces a
    // referenced friendly failure in production. A classifier that guessed here would swallow a
    // genuine bug behind a validation message.
    expect(DatabaseError::classify(driverError('HY000', 1030, 'Got error 28 from storage engine')))->toBeNull();
});

test('the too-long message counts the characters when the column is known', function () {
    $hint = DatabaseError::classify(driverError('22001', 1406, "Data too long for column 'copyright' at row 1"));

    // The framework's own max-length string, so the backstop reads word-for-word like the
    // validation rule that should have caught it — already translated in both locales.
    expect($hint->limit)->toBe(500)
        ->and($hint->message)->toContain('500')
        ->and($hint->message)->toContain('copyright')
        ->and($hint->isKeyed())->toBeTrue();
});

test('the message degrades instead of guessing when the column is unknown', function () {
    // Postgres names the type, not the column.
    $noColumn = DatabaseError::classify(driverError('22001', 7, 'value too long for type character varying(255)'));

    expect($noColumn->column)->toBeNull()
        ->and($noColumn->isKeyed())->toBeFalse()
        ->and($noColumn->message)->toBe(__('One of the values you entered is too long. Shorten it and try again.'));
});

test('an index name that does not resolve to a real column is dropped, not invented', function () {
    // A keyed error naming a field no form has would render nowhere at all — worse than unkeyed.
    $hint = DatabaseError::classify(driverError(
        '23000',
        1062,
        "Duplicate entry 'x' for key 'assets.assets_not_a_column_unique'",
        'insert into `assets` (`s3_key`) values (?)',
    ));

    expect($hint->kind)->toBe('duplicate')
        ->and($hint->column)->toBeNull();
});

test('the driver message never carries the SQL or the bindings', function () {
    // getMessage() appends Laravel's " (Connection: …, SQL: …)" tail with the bindings substituted
    // in. Reading errorInfo instead is the single most important detail in this class.
    $e = driverError(
        '22001',
        1406,
        "Data too long for column 'copyright' at row 1",
        'update `assets` set `copyright` = ? where `id` = ?',
        ['a-secret-copyright-value', 1],
    );

    expect($e->getMessage())->toContain('a-secret-copyright-value')
        ->and(DatabaseError::driverMessage($e))
        ->toBe("Data too long for column 'copyright' at row 1")
        ->and(DatabaseError::driverMessage($e))->not->toContain('a-secret-copyright-value');
});

test('the target table is read from the SQL, not from the message', function () {
    expect(DatabaseError::tableFor(driverError('22001', 1406, 'x', 'update `assets` set `copyright` = ?')))->toBe('assets')
        ->and(DatabaseError::tableFor(driverError('23000', 19, 'x', 'insert into "tags" ("name") values (?)')))->toBe('tags')
        ->and(DatabaseError::tableFor(driverError('23000', 19, 'x', 'insert or ignore into `asset_tag` (`tag_id`) values (?)')))->toBe('asset_tag')
        // A read has no target table to name.
        ->and(DatabaseError::tableFor(driverError('HY000', 1, 'x', 'select * from `assets`')))->toBeNull();
});

test('ErrorAudience gives an api-role caller no exception detail', function () {
    $this->actingAs(User::factory()->create(['role' => 'api']));

    expect(ErrorAudience::detail(new RuntimeException('s3://internal-bucket/secret')))->toBeNull();
});

test('ErrorAudience gives a trusted operator the driver sentence but never the SQL', function () {
    $this->actingAs(User::factory()->create(['role' => 'editor']));

    expect(ErrorAudience::detail(new RuntimeException('the real failure')))->toBe('the real failure');

    $detail = ErrorAudience::detail(driverError(
        '22001',
        1406,
        "Data too long for column 'copyright' at row 1",
        'update `assets` set `copyright` = ? where `id` = ?',
        ['a-secret-copyright-value', 1],
    ));

    expect($detail)->toBe("Data too long for column 'copyright' at row 1")
        ->and($detail)->not->toContain('a-secret-copyright-value')
        ->and($detail)->not->toContain('Connection:');
});
