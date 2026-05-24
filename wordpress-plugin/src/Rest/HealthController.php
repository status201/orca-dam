<?php

declare(strict_types=1);

namespace OrcaDam\Rest;

use OrcaDam\Api\OrcaClient;
use WP_REST_Response;

final class HealthController
{
    public function __construct(private readonly OrcaClient $client) {}

    public function register(): void
    {
        register_rest_route('orca/v1', '/health', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle'],
            'permission_callback' => static fn () => current_user_can('manage_options'),
        ]);
    }

    public function handle(): WP_REST_Response
    {
        $response = $this->client->health();
        return new WP_REST_Response([
            'orca_status' => $response->status,
            'orca_body'   => $response->body,
            'reachable'   => $response->ok(),
        ], 200);
    }
}
