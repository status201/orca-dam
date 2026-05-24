<?php

declare(strict_types=1);

namespace OrcaDam\Api\Endpoints;

use OrcaDam\Api\OrcaClient;
use OrcaDam\Api\Transport\TransportResponse;

final class GetFolders
{
    public function __construct(private readonly OrcaClient $client) {}

    public function send(): TransportResponse
    {
        return $this->client->cache()->remember(
            'folders',
            5 * MINUTE_IN_SECONDS,
            fn () => $this->client->dispatch('GET', '/folders'),
        );
    }
}
