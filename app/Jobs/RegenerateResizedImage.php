<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Services\S3Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RegenerateResizedImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;

    public $tries = 3;

    public function __construct(
        public int $assetId
    ) {}

    public function handle(S3Service $s3Service): void
    {
        $asset = Asset::find($this->assetId);

        if (! $asset) {
            Log::error("RegenerateResizedImage: Asset {$this->assetId} not found");

            return;
        }

        // Skip non-images
        if (! $asset->isImage()) {
            return;
        }

        try {
            // Delete existing resized images
            $s3Service->deleteResizedImages($asset);

            // Generate new resized images
            $resizedKeys = $s3Service->generateResizedImages($asset->s3_key);

            $asset->update(Asset::resizeKeyAttributes($resizedKeys));

            Log::info("RegenerateResizedImage: Successfully processed asset {$asset->id}");
        } catch (\Exception $e) {
            Log::error("RegenerateResizedImage: Failed for asset {$asset->id}: ".$e->getMessage());
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("RegenerateResizedImage: Job permanently failed for asset {$this->assetId}: ".$exception->getMessage());
    }
}
