<?php

declare(strict_types=1);

namespace OrcaDam\Api\Endpoints;

use OrcaDam\Api\OrcaClient;
use OrcaDam\Api\Transport\TransportResponse;

final class ListTags
{
    public function __construct(private readonly OrcaClient $client) {}

    /**
     * @param array{type?: string, sort?: string, search?: string, per_page?: int} $params
     */
    public function send(array $params = []): TransportResponse
    {
        $query = array_filter([
            'type'     => $params['type']     ?? 'user',
            'sort'     => $params['sort']     ?? 'most_used',
            'search'   => $params['search']   ?? null,
            'per_page' => $params['per_page'] ?? 100,
        ], static fn ($v) => $v !== null && $v !== '');

        $cacheKey = 'tags_' . md5(serialize($query));

        return $this->client->cache()->remember(
            $cacheKey,
            5 * MINUTE_IN_SECONDS,
            fn () => $this->client->dispatch('GET', '/tags', $query),
        );
    }
}
