<?php

namespace App\Support;

/**
 * What DatabaseError::classify() managed to work out about a driver rejection.
 *
 * `column` is null whenever it could not be established *and verified* — the classifier degrades
 * rather than guessing, because a keyed validation error pointing at a field that does not exist is
 * worse than an unkeyed one.
 *
 * @see specs/features/error-handling.md
 */
final class DatabaseErrorHint
{
    public function __construct(
        public readonly string $kind,
        public readonly int $status,
        public readonly string $message,
        public readonly ?string $column = null,
        public readonly ?int $limit = null,
    ) {}

    /**
     * Whether this rejection can be presented as a validation failure on a named field.
     */
    public function isKeyed(): bool
    {
        return $this->column !== null && $this->status === 422;
    }
}
