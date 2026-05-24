<?php

declare(strict_types=1);

namespace OrcaDam\Api\Transport;

/**
 * Minimal HTTP transport abstraction so OrcaClient can be unit-tested without WP.
 */
interface Transport
{
    /**
     * @param 'GET'|'POST'|'PATCH'|'PUT'|'DELETE' $method
     * @param array<string, scalar|array<int|string, scalar>|null> $query
     * @param array<string, mixed>|null $body  null = no body. Array is JSON-encoded.
     * @param array<string, string> $headers
     * @return TransportResponse
     */
    public function request(string $method, string $url, array $query = [], ?array $body = null, array $headers = []): TransportResponse;
}
