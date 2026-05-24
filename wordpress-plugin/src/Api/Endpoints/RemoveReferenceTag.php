<?php

declare(strict_types=1);

namespace OrcaDam\Api\Endpoints;

use OrcaDam\Api\OrcaClient;
use OrcaDam\Api\Transport\TransportResponse;

final class RemoveReferenceTag
{
    public function __construct(private readonly OrcaClient $client) {}

    public function send(int $assetId, string $tagName): TransportResponse
    {
        return $this->client->dispatch('DELETE', '/reference-tags', [], [
            'asset_id' => $assetId,
            'tag_name' => $tagName,
        ]);
    }
}
