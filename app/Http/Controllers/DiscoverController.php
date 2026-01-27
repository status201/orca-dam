<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessDiscoveredAsset;
use App\Models\Asset;
use App\Services\RekognitionService;
use App\Services\S3Service;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class DiscoverController extends Controller
{
    use AuthorizesRequests;

    protected S3Service $s3Service;

    protected RekognitionService $rekognitionService;

    public function __construct(S3Service $s3Service, RekognitionService $rekognitionService)
    {
        $this->s3Service = $s3Service;
        $this->rekognitionService = $rekognitionService;
    }

    /**
     * Show the discover page
     */
    public function index()
    {
        $this->authorize('discover', Asset::class);

        $rootFolder = S3Service::getRootFolder();
        $folders = \App\Models\Setting::get('s3_folders', $rootFolder !== '' ? [$rootFolder] : []);
        if (empty($folders) && $rootFolder !== '') {
            $folders = [$rootFolder];
        }
        if ($rootFolder === '' && ! in_array('', $folders)) {
            array_unshift($folders, '');
        }

        return view('discover.index', compact('folders', 'rootFolder'));
    }

    /**
     * Scan S3 bucket for unmapped objects
     */
    public function scan(Request $request)
    {
        $this->authorize('discover', Asset::class);

        $folder = $request->input('folder', S3Service::getRootFolder());
        $prefix = $folder !== '' ? $folder.'/' : null;
        $unmappedObjects = $this->s3Service->findUnmappedObjects($prefix);

        // Enrich with metadata and check for soft-deleted assets
        $enrichedObjects = collect($unmappedObjects)->map(function ($object) {
            $metadata = $this->s3Service->getObjectMetadata($object['key']);

            // Check if this S3 key belongs to a soft-deleted asset
            $deletedAsset = Asset::onlyTrashed()->where('s3_key', $object['key'])->first();

            return [
                'key' => $object['key'],
                'filename' => basename($object['key']),
                'size' => $object['size'],
                'last_modified' => $object['last_modified'],
                'mime_type' => $metadata['mime_type'] ?? 'unknown',
                'url' => $this->s3Service->getUrl($object['key']),
                'is_deleted' => $deletedAsset !== null,
                'deleted_at' => $deletedAsset ? $deletedAsset->deleted_at->toDateTimeString() : null,
            ];
        })->toArray();

        return response()->json([
            'count' => count($enrichedObjects),
            'objects' => $enrichedObjects,
        ]);
    }

    /**
     * Import selected unmapped objects into the database
     * Creates asset records immediately and queues background jobs for processing
     */
    public function import(Request $request)
    {
        $this->authorize('discover', Asset::class);

        $request->validate([
            'keys' => 'required|array',
            'keys.*' => 'required|string',
        ]);

        $imported = 0;
        $skipped = 0;
        $queued = [];

        foreach ($request->keys as $s3Key) {
            // Check if asset already exists by s3_key (quick database query, including trashed)
            if (Asset::withTrashed()->where('s3_key', $s3Key)->exists()) {
                $skipped++;

                continue;
            }

            try {
                // Get minimal S3 metadata only (fast operation)
                $metadata = $this->s3Service->getObjectMetadata($s3Key);

                if (! $metadata) {
                    Log::warning("Could not fetch metadata for S3 key: {$s3Key}");
                    $skipped++;

                    continue;
                }

                // Quick ETag duplicate check (including trashed)
                if (! empty($metadata['etag'])) {
                    $existingAsset = Asset::withTrashed()->where('etag', $metadata['etag'])->first();
                    if ($existingAsset) {
                        Log::info("Asset with ETag {$metadata['etag']} already exists (ID: {$existingAsset->id})");
                        $skipped++;

                        continue;
                    }
                }

                // Extract filename from S3 key
                $filename = basename($s3Key);

                // Create asset record immediately (no thumbnail yet, dimensions will be set by job)
                $asset = Asset::create([
                    's3_key' => $s3Key,
                    'etag' => $metadata['etag'] ?? null,
                    'filename' => $filename,
                    'mime_type' => $metadata['mime_type'],
                    'size' => $metadata['size'],
                    'user_id' => Auth::id(),
                    // width, height, thumbnail_s3_key will be set by background job
                ]);

                // Dispatch background job to process thumbnails and AI tagging
                ProcessDiscoveredAsset::dispatch($asset->id);

                $queued[] = $asset->id;
                $imported++;

            } catch (\Exception $e) {
                Log::error("Failed to import {$s3Key}: ".$e->getMessage());
                $skipped++;
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Import initiated: {$imported} assets queued for processing, {$skipped} skipped.",
            'imported' => $imported,
            'skipped' => $skipped,
            'queued_asset_ids' => $queued,
        ]);
    }
}
