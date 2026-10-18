<?php

namespace App\Services;

use App\Models\Asset;
use Illuminate\Support\Facades\Log;

/**
 * The one place an ingestion path decides "duplicate, overwrite, or new" and writes the Asset row.
 *
 * Direct upload, chunked completion, the REST API and discovery import all used to open-code the
 * etag query and the create-or-overwrite arrays; one copy being subtly wrong is how
 * duplicate-detection.md REQ-2 shipped broken. The S3 transfer itself stays with each path.
 *
 * @see specs/features/duplicate-detection.md
 */
class AssetIngestionService
{
    public function __construct(protected S3Service $s3Service) {}

    /**
     * The row (trashed or not) already stored at this key. It is an overwrite target, not a
     * duplicate — and its presence is what switches the etag check off (REQ-2).
     */
    public function existingAtKey(string $s3Key): ?Asset
    {
        return Asset::withTrashed()->where('s3_key', $s3Key)->first();
    }

    /**
     * The asset these bytes duplicate, trashed ones included (REQ-1), or null.
     *
     * Skipped when the upload collided with $existingAtKey: on an overwrite that row already
     * carries the incoming etag, and without the skip the upload would be its own duplicate.
     */
    public function findDuplicate(?string $etag, ?Asset $existingAtKey = null): ?Asset
    {
        if ($existingAtKey !== null) {
            return null;
        }

        return Asset::withTrashed()->duplicateOf($etag)->first();
    }

    /**
     * Throw away the object just written for a duplicate, so nothing is orphaned (REQ-3).
     */
    public function discardDuplicate(string $s3Key, Asset $existing, string $filename): void
    {
        $this->s3Service->deleteFile($s3Key);

        Log::warning('Duplicate upload detected', [
            'filename' => $filename,
            'existing_asset_id' => $existing->id,
            'etag' => $existing->etag,
        ]);
    }

    /**
     * Create the Asset, or overwrite $overwrite in place.
     *
     * An overwrite deletes the row's derived S3 objects (they describe the old bytes) and forgets
     * their keys, brings the row back from the trash, clears s3_missing_at (the object was just
     * written), and hands it to the uploading user.
     *
     * @param  array<string, mixed>  $attributes  s3_key, filename, mime_type, size, etag, width,
     *                                            height and user_id, plus any extra columns
     */
    public function persist(array $attributes, ?Asset $overwrite = null): Asset
    {
        if ($overwrite === null) {
            return Asset::create($attributes);
        }

        $this->s3Service->deleteAssetFiles($overwrite, keepOriginal: true);

        $overwrite->fill(array_merge(
            $attributes,
            Asset::derivedKeyResetAttributes(),
            [
                's3_missing_at' => null,
                'last_modified_by' => $attributes['user_id'] ?? null,
            ],
        ));

        // Set directly, not mass-assigned: deleted_at is not fillable, and passing it to update()
        // was silently dropped — an upload onto a trashed asset's key stayed in the trash.
        $overwrite->deleted_at = null;
        $overwrite->save();

        return $overwrite;
    }
}
