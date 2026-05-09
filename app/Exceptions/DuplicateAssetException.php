<?php

namespace App\Exceptions;

use App\Models\Asset;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

class DuplicateAssetException extends RuntimeException
{
    public Asset $existingAsset;

    public function __construct(Asset $existingAsset)
    {
        $this->existingAsset = $existingAsset;
        parent::__construct("Duplicate of existing asset (ID: {$existingAsset->id})");
    }

    /**
     * Build the JSON payload describing a duplicate. Used by both direct
     * (AssetController::store) and chunked (ChunkedUploadController::complete)
     * upload flows so the frontend receives an identical shape regardless of path.
     */
    public static function formatDuplicate(Asset $existing, ?string $attemptedFilename = null): array
    {
        $isTrashed = $existing->trashed();
        $showUrl = $isTrashed ? null : route('assets.show', $existing);

        return [
            // Attempted upload's filename. Named `filename` (not `attempted_filename`)
            // so legacy code paths reading `array_column($duplicates, 'filename')` keep working.
            'filename' => $attemptedFilename ?? $existing->filename,
            'existing_asset_id' => $existing->id,
            'existing_filename' => $existing->filename,
            'existing_folder' => $existing->folder,
            'mime_type' => $existing->mime_type,
            'size' => $existing->size,
            'thumbnail_url' => $existing->thumbnail_url,
            'public_url' => $existing->url,
            'show_url' => $showUrl,
            'is_trashed' => $isTrashed,
            'can_restore' => $isTrashed && Gate::allows('restore', $existing),
            'uploaded_at' => optional($existing->created_at)->toIso8601String(),
        ];
    }
}
