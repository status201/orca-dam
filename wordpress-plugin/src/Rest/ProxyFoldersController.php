<?php

declare(strict_types=1);

namespace OrcaDam\Rest;

use OrcaDam\Api\OrcaClient;
use WP_REST_Response;

final class ProxyFoldersController
{
    public function __construct(private readonly OrcaClient $client) {}

    public function register(): void
    {
        register_rest_route('orca/v1', '/folders', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle'],
            'permission_callback' => static fn () => current_user_can('upload_files'),
        ]);
    }

    public function handle(): WP_REST_Response
    {
        $response = $this->client->getFolders();

        return new WP_REST_Response($response->body, $response->status ?: 502);
    }
}
