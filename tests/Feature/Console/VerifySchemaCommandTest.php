<?php

use App\Console\Commands\VerifySchemaCommand;

/**
 * `db:verify-schema` exists to make the checks SQLite cannot make, so most of it cannot be tested
 * on SQLite either — that is the point of the command, not a gap in it. Two things can be, and they
 * are the two that matter.
 *
 * The first is the refusal itself: a verification tool that returns success on a driver that
 * answered none of its questions is worse than no tool, because it converts "unverified" into
 * "verified". That property is exactly what a test can pin.
 *
 * The second is the byte arithmetic, which is pure — an information_schema row in, a key size out —
 * and is the one part that can be wrong *quietly*. Every other mistake in this command shows up as
 * a SQL error the operator sees; a bad multiplier prints a comfortable number for an index that
 * MariaDB will refuse.
 *
 * @see specs/features/maintenance-commands.md
 * @see specs/features/input-validation.md
 */

/**
 * An information_schema.columns row, as the command reads it.
 */
function schemaColumn(string $dataType, ?int $chars = null, ?int $octets = null, bool $nullable = false): object
{
    return (object) [
        'data_type' => $dataType,
        'character_maximum_length' => $chars,
        'character_octet_length' => $octets,
        'is_nullable' => $nullable ? 'YES' : 'NO',
    ];
}

test('the command refuses to report success on a driver it cannot verify', function () {
    // The suite runs SQLite, so this is the real path, not a simulated one.
    expect(config('database.default'))->not->toBeIn(['mysql', 'mariadb']);

    $this->artisan('db:verify-schema')
        ->expectsOutputToContain('Cannot verify a sqlite schema.')
        // 2, not 0 and not 1: nothing is known to be wrong, but nothing was checked either. A
        // pipeline has to be able to tell those apart — see the class docblock.
        ->assertExitCode(2);
});

test('a utf8mb4 prefix index is measured at four bytes per character', function () {
    // s3_key varchar(1024) utf8mb4: character_octet_length is 4096, so the multiplier is 4.
    $s3Key = schemaColumn('varchar', chars: 1024, octets: 4096);

    // The 255-character prefix the widening migration declares.
    expect(VerifySchemaCommand::keyBytes($s3Key, 255))->toBe(1020);

    // Without a prefix the whole column is indexed — the 4096 bytes that forced the restructuring.
    expect(VerifySchemaCommand::keyBytes($s3Key, null))->toBe(4096);
});

test('the folder filter index adds up to its documented size', function () {
    // (deleted_at, s3_key(255), updated_at) — the arithmetic written into the migration docblock
    // and into input-validation.md REQ-12. If this drifts, one of the three is lying.
    $timestamp = schemaColumn('timestamp', nullable: true);
    $s3Key = schemaColumn('varchar', chars: 1024, octets: 4096);

    $total = VerifySchemaCommand::keyBytes($timestamp, null)      // deleted_at   4 + 1 null byte
        + VerifySchemaCommand::keyBytes($s3Key, 255)              // s3_key(255)  255 * 4
        + VerifySchemaCommand::keyBytes($timestamp, null);        // updated_at   4 + 1 null byte

    expect($total)->toBe(1030)
        ->and($total)->toBeLessThan(3072);
});

test('a single-byte charset is measured at one byte per character', function () {
    // ascii or latin1: octet length equals character length, so a wide column still fits an index.
    // The command must not assume utf8mb4 — that would report a false failure on such a column.
    $ascii = schemaColumn('varchar', chars: 1024, octets: 1024);

    expect(VerifySchemaCommand::keyBytes($ascii, null))->toBe(1024);
});

test('a nullable column costs one byte more than the same column not null', function () {
    $notNull = schemaColumn('bigint');
    $nullable = schemaColumn('bigint', nullable: true);

    expect(VerifySchemaCommand::keyBytes($nullable, null))
        ->toBe(VerifySchemaCommand::keyBytes($notNull, null) + 1);
});

test('an unknown column type is charged the widest fixed width rather than nothing', function () {
    // Guessing zero would let an index of unmeasured columns report as comfortably inside the
    // limit. The fallback errs upward, which is the safe direction for a budget check.
    expect(VerifySchemaCommand::keyBytes(schemaColumn('some_future_type'), null))->toBe(8);
});
