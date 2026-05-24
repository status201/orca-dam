<?php

declare(strict_types=1);

namespace OrcaDam\Rest;

use OrcaDam\Api\OrcaClient;
use OrcaDam\Attachments\ShellFactory;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /orca/v1/rehydrate — given an ORCA URL, looks up metadata via the public
 * meta endpoint, finds the matching ORCA asset, creates a shell, and returns
 * the attachment id so existing posts can be retrofitted.
 */
final class RehydrateController
{
    public function __construct(
        private readonly OrcaClient $client,
        private readonly ShellFactory $shells,
    ) {}

    public function register(): void
    {
        register_rest_route('orca/v1', '/rehydrate', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle'],
            'permission_callback' => static fn () => current_user_can('edit_posts'),
            'args'                => [
                'url' => ['type' => 'string', 'required' => true, 'format' => 'uri'],
            ],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $url = (string) $request->get_param('url');
        $meta = $this->client->getMeta($url);
        if (! $meta->ok()) {
            return new WP_Error('rehydrate_failed', $meta->body['message'] ?? 'ORCA lookup failed', ['status' => $meta->status ?: 502]);
        }

        // The meta endpoint returns the asset's URL but not its id; resolve via search.
        $search = $this->client->searchAssets([
            'q'        => $meta->body['filename'] ?? '',
            'per_page' => 1,
        ]);
        $assetId = (int) ($search->body['data'][0]['id'] ?? 0);
        if ($assetId === 0) {
            return new WP_Error('rehydrate_failed', 'Could not resolve ORCA asset id from URL.', ['status' => 404]);
        }

        $attachmentId = $this->shells->findOrCreate($assetId);
        return new WP_REST_Response($this->shells->present($attachmentId), 200);
    }
}
