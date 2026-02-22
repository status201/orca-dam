<?php

namespace App\Services;

use App\Jobs\GenerateAiTags;
use App\Models\Asset;
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
                $asset->update([
                    'resize_s_s3_key' => $resizedKeys['s'] ?? null,
                    'resize_m_s3_key' => $resizedKeys['m'] ?? null,
                    'resize_l_s3_key' => $resizedKeys['l'] ?? null,
                ]);
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
}
