<?php

declare(strict_types=1);

namespace OrcaDam\Api;

use OrcaDam\Api\Endpoints\AddReferenceTag;
use OrcaDam\Api\Endpoints\GetAsset;
use OrcaDam\Api\Endpoints\GetHealth;
use OrcaDam\Api\Endpoints\GetMeta;
use OrcaDam\Api\Endpoints\ListTags;
use OrcaDam\Api\Endpoints\RemoveReferenceTag;
use OrcaDam\Api\Endpoints\SearchAssets;
use OrcaDam\Api\Transport\Transport;
use OrcaDam\Api\Transport\TransportResponse;
use OrcaDam\Settings\CredentialStore;

/**
 * Facade over individual endpoint request objects. Each public method delegates
 * to a single-responsibility Endpoint class so behaviour can be tested in
 * isolation and new endpoints can be added without modifying this class.
 */
final class OrcaClient
{
    public function __construct(
        private readonly Transport $transport,
        private readonly CredentialStore $credentials,
        private readonly Cache $cache,
    ) {}

    /**
     * @param array{q?: string, type?: string, tags?: string, folder?: string, sort?: string, page?: int, per_page?: int} $params
     */
    public function searchAssets(array $params): TransportResponse
    {
        return (new SearchAssets($this))->send($params);
    }

    public function getAsset(int $id): TransportResponse
    {
        return (new GetAsset($this))->send($id);
    }

    public function getMeta(string $url): TransportResponse
    {
        return (new GetMeta($this))->send($url);
    }

    /**
     * @param array{type?: string, sort?: string, search?: string, per_page?: int} $params
     */
    public function listTags(array $params = []): TransportResponse
    {
        return (new ListTags($this))->send($params);
    }

    /**
     * @param list<string> $tagNames
     */
    public function addReferenceTags(int $assetId, array $tagNames): TransportResponse
    {
        return (new AddReferenceTag($this))->send($assetId, $tagNames);
    }

    public function removeReferenceTagByName(int $assetId, string $tagName): TransportResponse
    {
        return (new RemoveReferenceTag($this))->send($assetId, $tagName);
    }

    public function health(): TransportResponse
    {
        return (new GetHealth($this))->send();
    }

    /** Internal: used by Endpoint classes. */
    public function dispatch(string $method, string $path, array $query = [], ?array $body = null): TransportResponse
    {
        $base = $this->credentials->baseUrl();
        if ($base === '') {
            return new TransportResponse(0, ['message' => 'ORCA base URL is not configured.']);
        }

        $headers = [];
        if ($token = $this->credentials->token()) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $this->transport->request(
            $method,
            $base . '/api' . $path,
            $query,
            $body,
            $headers,
        );
    }

    public function cache(): Cache
    {
        return $this->cache;
    }
}
