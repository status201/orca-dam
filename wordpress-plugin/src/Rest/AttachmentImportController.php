<?php

declare(strict_types=1);

namespace OrcaDam\Rest;

use OrcaDam\Attachments\ShellFactory;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /orca/v1/import — given an ORCA asset id, returns the matching WP
 * attachment shell (creating it lazily). Response shape mirrors what
 * wp.media.model.Attachment expects so the picker can hand the object straight
 * back to the editor's media frame.
 */
final class AttachmentImportController
{
    public function __construct(private readonly ShellFactory $shells) {}

    public function register(): void
    {
        register_rest_route('orca/v1', '/import', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle'],
            'permission_callback' => static fn () => current_user_can('upload_files'),
            'args'                => [
                'asset_id' => ['type' => 'integer', 'required' => true],
            ],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $assetId = (int) $request->get_param('asset_id');
        if ($assetId <= 0) {
            return new WP_Error('invalid_asset_id', 'asset_id must be a positive integer.', ['status' => 400]);
        }

        try {
            $attachmentId = $this->shells->findOrCreate($assetId);
        } catch (\Throwable $e) {
            return new WP_Error('import_failed', $e->getMessage(), ['status' => 502]);
        }

        return new WP_REST_Response($this->shells->present($attachmentId), 200);
    }
}
