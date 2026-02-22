<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Services\AssetProcessingService;
use App\Services\RekognitionService;
use App\Services\S3Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessDiscoveredAsset implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes per asset

    public $tries = 3;

    public function __construct(
        public int $assetId
    ) {}

    public function handle(S3Service $s3Service, RekognitionService $rekognitionService, AssetProcessingService $assetProcessingService): void
    {
        $asset = Asset::find($this->assetId);

        if (! $asset) {
            Log::error("ProcessDiscoveredAsset: Asset {$this->assetId} not found");

            return;
        }

        try {
            // Step 1: Extract image dimensions if not set
            if ($asset->isImage() && (! $asset->width || ! $asset->height)) {
                $dimensions = $s3Service->extractImageDimensions($asset->s3_key, $asset->mime_type);
                if ($dimensions) {
                    $asset->update($dimensions);
                }
            }

            // Step 2: Generate thumbnail and resized images (skip AI dispatch, handled below)
            $assetProcessingService->processImageAsset($asset, dispatchAiTagging: false);

            // Step 3: Run AI tagging directly if enabled (already in a queue job, so run synchronously)
            if (config('services.aws.rekognition_enabled') && $asset->isImage()) {
                Log::info("ProcessDiscoveredAsset: Running AI tagging for asset {$asset->id}");
                $labels = $rekognitionService->autoTagAsset($asset);
                Log::info('ProcessDiscoveredAsset: Generated '.count($labels)." AI tags for asset {$asset->id}");
            }

            Log::info("ProcessDiscoveredAsset: Successfully processed asset {$asset->id}");

        } catch (\Exception $e) {
            Log::error("ProcessDiscoveredAsset: Failed for asset {$asset->id}: ".$e->getMessage());
            throw $e; // Re-throw to trigger job retry
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("ProcessDiscoveredAsset: Job permanently failed for asset {$this->assetId}: ".$exception->getMessage());
    }
}
