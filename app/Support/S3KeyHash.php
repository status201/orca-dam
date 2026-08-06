<?php

namespace App\Support;

/**
 * The uniqueness surrogate for assets.s3_key.
 *
 * assets.s3_key is varchar(1024) utf8mb4 = 4096 bytes, and InnoDB will not index a key longer
 * than 3072, so the column cannot carry its own UNIQUE constraint any more. The invariant lives
 * on assets.s3_key_hash instead, and everything that writes the column — the model hook, the
 * backfill in the migration that introduced it — must agree on the algorithm exactly. That is
 * why this is a named function and not an inline hash() call at each site.
 *
 * sha256 hex: lowercase, always 64 characters, which is exactly the char(64) column.
 *
 * Deliberately computed in PHP rather than as a MySQL STORED generated column. SQLite has no
 * SHA2(), so a generated column would mean two structurally different schemas and the suite
 * (SQLite, ADR-008) would be exercising an invariant that does not exist in production.
 *
 * @see specs/features/input-validation.md REQ-10
 */
final class S3KeyHash
{
    public static function of(string $s3Key): string
    {
        return hash('sha256', $s3Key);
    }
}
