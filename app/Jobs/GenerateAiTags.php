<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Services\RekognitionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateAiTags implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Asset $asset
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(RekognitionService $rekognitionService): void
    {
        if (! $this->asset->isImage()) {
            return;
        }

        // Rekognition does not support GIF format
        if ($this->asset->mime_type === 'image/gif') {
            return;
        }

        try {
            $rekognitionService->autoTagAsset($this->asset);
        } catch (\Exception $e) {
            \Log::error("AI tagging failed for asset {$this->asset->id}: ".$e->getMessage());
        }
    }
}
