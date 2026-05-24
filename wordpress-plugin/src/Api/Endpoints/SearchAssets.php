<?php

declare(strict_types=1);

namespace OrcaDam\Api\Endpoints;

use OrcaDam\Api\OrcaClient;
use OrcaDam\Api\Transport\TransportResponse;

final class SearchAssets
{
    public function __construct(private readonly OrcaClient $client) {}

    /**
     * @param array{q?: string, type?: string, tags?: string, folder?: string, sort?: string, page?: int, per_page?: int} $params
     */
    public function send(array $params): TransportResponse
    {
        $query = array_filter([
            'q'        => $params['q']        ?? null,
            'type'     => $params['type']     ?? 'image',
            'tags'     => $params['tags']     ?? null,
            'folder'   => $params['folder']   ?? null,
            'sort'     => $params['sort']     ?? 'date_desc',
            'page'     => $params['page']     ?? 1,
            'per_page' => $params['per_page'] ?? 24,
        ], static fn ($v) => $v !== null && $v !== '');

        return $this->client->dispatch('GET', '/assets/search', $query);
    }
}
