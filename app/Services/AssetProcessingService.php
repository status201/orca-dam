<?php

namespace App\Services;

use App\Jobs\GenerateAiTags;
use App\Models\Asset;
use App\Models\Tag;
use App\Support\TagInputParser;
use App\Support\UploadMetadata;
use Illuminate\Support\Facades\Log;

class AssetProcessingService
{
    public function __construct(
        protected S3Service $s3Service,
        protected RekognitionService $rekognitionService
    ) {}

    /**
     * Process a newly uploaded/replaced image asset:
     * generate thumbnail, resized variants, and optionally dispatch AI tagging.
     */
    public function processImageAsset(Asset $asset, bool $dispatchAiTagging = true): void
    {
        if (! $asset->isImage()) {
            return;
        }

        // Generate thumbnail
        try {
            $thumbnailKey = $this->s3Service->generateThumbnail($asset->s3_key);
            if ($thumbnailKey) {
                $asset->update(['thumbnail_s3_key' => $thumbnailKey]);
            }
        } catch (\Exception $e) {
            Log::error("Thumbnail generation failed for {$asset->filename}: ".$e->getMessage());
        }

        // Generate resized images
        try {
            $resizedKeys = $this->s3Service->generateResizedImages($asset->s3_key);
            if (! empty($resizedKeys)) {
                $asset->update(Asset::resizeKeyAttributes($resizedKeys));
            }
        } catch (\Exception $e) {
            Log::error("Resize generation failed for {$asset->filename}: ".$e->getMessage());
        }

        // Dispatch AI tagging
        if ($dispatchAiTagging && $this->rekognitionService->isEnabled()) {
            try {
                GenerateAiTags::dispatch($asset)->afterResponse();
            } catch (\Exception $e) {
                Log::error("AI tagging dispatch failed for {$asset->filename}: ".$e->getMessage());
            }
        }
    }

    /**
     * Apply batch upload metadata (tags, license, copyright, date obtained) to a newly created asset.
     */
    public function applyUploadMetadata(Asset $asset, UploadMetadata $metadata): void
    {
        // The '' arm is load-bearing, not defensive: a cleared <input type="date"> submits an empty
        // string, and '' must not reach the 'date' cast or overwrite a stored value with nothing.
        $updates = array_filter([
            'license_type' => $metadata->licenseType,
            'copyright' => $metadata->copyright,
            'copyright_source' => $metadata->copyrightSource,
            'date_obtained' => $metadata->dateObtained,
        ], fn ($v) => $v !== null && $v !== '');

        if (! empty($updates)) {
            $asset->update($updates);
        }

        // Split any comma-separated entries so a single "a,b,c" value still works.
        $tagNames = TagInputParser::parse($metadata->tags);
        if (! empty($tagNames)) {
            $tagIds = Tag::resolveUserTagIds($tagNames);
            $asset->syncTagsWithAttribution($tagIds, 'user');
        }

        if (! empty($metadata->referenceTagIds)) {
            $asset->syncTagsWithAttribution(array_map('intval', $metadata->referenceTagIds), 'reference');
        }
    }
}
