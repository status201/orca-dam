<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * The declared character width of every varchar column the application validates into.
 *
 * This array is the SINGLE SOURCE OF TRUTH: the migration that declares a column reads it,
 * every validation rule that writes into that column reads it, and the view that renders
 * the input reads it for its `maxlength`. It exists because those three used to be written
 * independently — `assets.copyright` shipped as varchar(255) while four separate rule sites
 * allowed 500 and the form promised 500, so 256-500 characters passed validation and reached
 * MariaDB (strict mode, config/database.php) as SQLSTATE 22001. The user saw a bare 500.
 *
 * The suite cannot catch that by writing to the database: tests run in-memory SQLite
 * (ADR-008), which does not enforce varchar length. tests/Feature/ValidationLimitsTest.php
 * therefore compares the *rules* against this array instead. A rule may be deliberately
 * tighter than its column; it may never be looser.
 *
 * Adding a column here is not optional bookkeeping — that test fails on a character-capped
 * rule that is neither mapped to a column nor explicitly declared column-free.
 *
 * @see specs/features/input-validation.md
 */
final class ColumnLimits
{
    /**
     * varchar widths, in characters — the unit a `max:` rule counts for a string.
     *
     * @var array<string, array<string, int>>
     */
    public const CHARS = [
        'assets' => [
            'filename' => 255,
            'license_type' => 255,
            'copyright' => 500,
            'copyright_source' => 500,
            's3_key' => 255,
            'thumbnail_s3_key' => 255,
        ],
        'tags' => [
            // The column is wider than any rule: names are capped at Tag::MAX_NAME_LENGTH
            // (100) everywhere. That is a product decision, and legal — tighter, not looser.
            'name' => 255,
        ],
        'users' => [
            'name' => 255,
            'email' => 255,
        ],
    ];

    /**
     * TEXT-family capacity in BYTES, for columns a rule caps in characters.
     *
     * Listed separately because the two units are not interchangeable: one utf8mb4
     * character costs up to 4 bytes, so a `max:20000` rule can overflow a 65 535-byte
     * TEXT column while looking comfortably inside it. Compare via fitsText().
     *
     * @var array<string, array<string, int>>
     */
    public const TEXT_BYTES = [
        'assets' => [
            'alt_text' => 65535,
            'caption' => 65535,
        ],
    ];

    /**
     * The declared width of a varchar column.
     *
     * Throws rather than defaulting to 255: a column nobody declared here is a gap in
     * the source of truth, and a silent default would be exactly the wrong number in
     * exactly the case that caused the original bug.
     */
    public static function for(string $table, string $column): int
    {
        if (! isset(self::CHARS[$table][$column])) {
            throw new InvalidArgumentException("No declared column limit for {$table}.{$column}. Add it to ColumnLimits::CHARS.");
        }

        return self::CHARS[$table][$column];
    }

    /**
     * Whether a character cap of $chars can never overflow a TEXT column's byte capacity.
     *
     * Assumes the worst case (4 bytes per character) deliberately — a check that passes
     * only for ASCII input is not a check.
     */
    public static function fitsText(string $table, string $column, int $chars): bool
    {
        if (! isset(self::TEXT_BYTES[$table][$column])) {
            throw new InvalidArgumentException("No declared TEXT capacity for {$table}.{$column}. Add it to ColumnLimits::TEXT_BYTES.");
        }

        return $chars * 4 <= self::TEXT_BYTES[$table][$column];
    }
}
