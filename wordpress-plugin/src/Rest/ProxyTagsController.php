<?php

declare(strict_types=1);

namespace OrcaDam\Rest;

use OrcaDam\Api\OrcaClient;
use WP_REST_Request;
use WP_REST_Response;

final class ProxyTagsController
{
    public function __construct(private readonly OrcaClient $client) {}

    public function register(): void
    {
        register_rest_route('orca/v1', '/tags', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle'],
            'permission_callback' => static fn () => current_user_can('upload_files'),
            'args'                => [
                'type'     => ['type' => 'string'],
                'sort'     => ['type' => 'string'],
                'search'   => ['type' => 'string'],
                'per_page' => ['type' => 'integer'],
            ],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $response = $this->client->listTags(array_filter([
            'type'     => (string) $request->get_param('type'),
            'sort'     => (string) $request->get_param('sort'),
            'search'   => (string) $request->get_param('search'),
            'per_page' => (int) $request->get_param('per_page'),
        ]));

        return new WP_REST_Response($response->body, $response->status ?: 502);
    }
}
