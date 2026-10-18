<?php

namespace App\Http\Concerns;

use App\Models\Asset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Permanently delete assets one by one — S3 objects first, then the row — counting what failed
 * instead of stopping. Shared by the grid's bulk force-delete and the trash page's; the using
 * controller provides $s3Service.
 */
trait ForceDeletesAssets
{
    /**
     * @param  iterable<Asset>  $assets
     * @param  string  $context  names the action in the log line
     * @return array{deleted: int, failed: int, deleted_keys: list<string>}
     */
    protected function forceDeleteAssets(iterable $assets, string $context): array
    {
        $deleted = 0;
        $failed = 0;
        $deletedKeys = [];

        foreach ($assets as $asset) {
            try {
                $this->s3Service->deleteAssetFiles($asset);
                DB::transaction(function () use ($asset) {
                    $asset->forceDelete();
                });
                $deletedKeys[] = $asset->s3_key;
                $deleted++;
            } catch (\Exception $e) {
                Log::error("{$context} failed for asset {$asset->id}: ".$e->getMessage());
                $failed++;
            }
        }

        return ['deleted' => $deleted, 'failed' => $failed, 'deleted_keys' => $deletedKeys];
    }
}
