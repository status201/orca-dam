<?php

declare(strict_types=1);

namespace OrcaDam\Api\Endpoints;

use OrcaDam\Api\OrcaClient;
use OrcaDam\Api\Transport\TransportResponse;

final class GetHealth
{
    public function __construct(private readonly OrcaClient $client) {}

    public function send(): TransportResponse
    {
        return $this->client->dispatch('GET', '/health');
    }
}
