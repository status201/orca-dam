<?php

declare(strict_types=1);

namespace OrcaDam\Rest;

use OrcaDam\Api\OrcaClient;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Proxies asset search from the browser through to ORCA. The Sanctum token
 * never leaves the server — the browser authenticates against WP only.
 */
final class ProxySearchController
{
    public function __construct(private readonly OrcaClient $client) {}

    public function register(): void
    {
        register_rest_route('orca/v1', '/assets/search', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle'],
            'permission_callback' => static fn () => current_user_can('upload_files'),
            'args'                => [
                'q'        => ['type' => 'string'],
                'type'     => ['type' => 'string'],
                'tags'     => ['type' => 'string'],
                'folder'   => ['type' => 'string'],
                'sort'     => ['type' => 'string'],
                'page'     => ['type' => 'integer', 'default' => 1],
                'per_page' => ['type' => 'integer', 'default' => 24],
            ],
        ]);

        register_rest_route('orca/v1', '/assets/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handleShow'],
            'permission_callback' => static fn () => current_user_can('upload_files'),
            'args'                => ['id' => ['type' => 'integer', 'required' => true]],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $response = $this->client->searchAssets([
            'q'        => (string) $request->get_param('q'),
            'type'     => (string) ($request->get_param('type') ?: 'image'),
            'tags'     => (string) $request->get_param('tags'),
            'folder'   => (string) $request->get_param('folder'),
            'sort'     => (string) ($request->get_param('sort') ?: 'date_desc'),
            'page'     => (int) ($request->get_param('page') ?: 1),
            'per_page' => (int) ($request->get_param('per_page') ?: 24),
        ]);

        return new WP_REST_Response($response->body, $response->status ?: 502);
    }

    public function handleShow(WP_REST_Request $request): WP_REST_Response
    {
        $response = $this->client->getAsset((int) $request->get_param('id'));
        return new WP_REST_Response($response->body, $response->status ?: 502);
    }
}
