<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Services\S3Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class VerifyAssetIntegrity implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 60;

    public $tries = 2;

    public function __construct(
        public int $assetId
    ) {}

    public function handle(S3Service $s3Service): void
    {
        $asset = Asset::find($this->assetId);

        if (! $asset) {
            Log::warning("VerifyAssetIntegrity: Asset {$this->assetId} not found");

            return;
        }

        try {
            $metadata = $s3Service->getObjectMetadata($asset->s3_key);

            if ($metadata === null) {
                // Object is missing — only set timestamp if not already set
                if (! $asset->s3_missing_at) {
                    $asset->update(['s3_missing_at' => now()]);
                    Cache::forget('assets:missing_count');
                    Log::warning("VerifyAssetIntegrity: Asset {$asset->id} ({$asset->s3_key}) is missing from S3");
                }
            } else {
                // Object exists — clear missing flag if it was set
                if ($asset->s3_missing_at) {
                    $asset->update(['s3_missing_at' => null]);
                    Cache::forget('assets:missing_count');
                    Log::info("VerifyAssetIntegrity: Asset {$asset->id} ({$asset->s3_key}) recovered on S3");
                }
            }
        } catch (\Exception $e) {
            Log::error("VerifyAssetIntegrity: Failed for asset {$asset->id}: ".$e->getMessage());
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("VerifyAssetIntegrity: Job permanently failed for asset {$this->assetId}: ".$exception->getMessage());
    }
}
