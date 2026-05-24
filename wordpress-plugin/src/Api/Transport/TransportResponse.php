<?php

declare(strict_types=1);

namespace OrcaDam\Api\Transport;

final class TransportResponse
{
    /**
     * @param array<string, mixed> $body  Decoded JSON body (empty array if not JSON)
     */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly string $rawBody = '',
    ) {}

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
