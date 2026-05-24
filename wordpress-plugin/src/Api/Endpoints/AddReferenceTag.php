<?php

declare(strict_types=1);

namespace OrcaDam\Api\Endpoints;

use OrcaDam\Api\OrcaClient;
use OrcaDam\Api\Transport\TransportResponse;

final class AddReferenceTag
{
    public function __construct(private readonly OrcaClient $client) {}

    /**
     * @param list<string> $tagNames
     */
    public function send(int $assetId, array $tagNames): TransportResponse
    {
        return $this->client->dispatch('POST', '/reference-tags', [], [
            'asset_id' => $assetId,
            'tags'     => array_values($tagNames),
        ]);
    }
}
