<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Services\S3Service;
use App\Services\RekognitionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

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
        
        return view('discover.index');
    }

    /**
     * Scan S3 bucket for unmapped objects
     */
    public function scan(Request $request)
    {
        $this->authorize('discover', Asset::class);

        $unmappedObjects = $this->s3Service->findUnmappedObjects();

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
     */
    public function import(Request $request)
    {
        $this->authorize('discover', Asset::class);

        $request->validate([
            'keys' => 'required|array',
            'keys.*' => 'required|string',
        ]);

        $imported = [];

        foreach ($request->keys as $s3Key) {
            // Check if already exists by s3_key (including trashed)
            if (Asset::withTrashed()->where('s3_key', $s3Key)->exists()) {
                continue;
            }

            // Get metadata
            $metadata = $this->s3Service->getObjectMetadata($s3Key);
            if (!$metadata) {
                continue;
            }

            // Check for duplicate content by ETag (MD5 hash) (including trashed)
            if (!empty($metadata['etag'])) {
                $duplicateAsset = Asset::withTrashed()->where('etag', $metadata['etag'])->first();
                if ($duplicateAsset) {
                    \Log::info("Skipping duplicate file: {$s3Key} - same content as asset #{$duplicateAsset->id} ({$duplicateAsset->s3_key})");
                    continue;
                }
            }

            // Create asset
            $asset = Asset::create([
                's3_key' => $s3Key,
                'filename' => basename($s3Key),
                'mime_type' => $metadata['mime_type'],
                'size' => $metadata['size'],
                'etag' => $metadata['etag'] ?? null,
                'user_id' => Auth::id(),
            ]);

            // Generate thumbnail for images
            if ($asset->isImage()) {
                // Try to get dimensions from S3 object
                try {
                    $imageContent = $this->s3Service->getObjectContent($s3Key);
                    if ($imageContent) {
                        $manager = new ImageManager(new Driver());
                        $image = $manager->read($imageContent);

                        $asset->update([
                            'width' => $image->width(),
                            'height' => $image->height(),
                        ]);
                    }
                } catch (\Exception $e) {
                    \Log::warning('Could not get image dimensions: ' . $e->getMessage());
                }

                // Generate thumbnail
                $thumbnailKey = $this->s3Service->generateThumbnail($asset->s3_key);
                if ($thumbnailKey) {
                    $asset->update(['thumbnail_s3_key' => $thumbnailKey]);
                }

                // Auto-tag with AI
                if ($this->rekognitionService->isEnabled()) {
                    $this->rekognitionService->autoTagAsset($asset);
                }
            }

            $imported[] = $asset;
        }

        return response()->json([
            'message' => count($imported) . ' object(s) imported successfully',
            'imported' => $imported,
        ]);
    }
}
