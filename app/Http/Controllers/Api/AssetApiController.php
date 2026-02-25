<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Setting;
use App\Models\Tag;
use App\Services\AssetProcessingService;
use App\Services\S3Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AssetApiController extends Controller
{
    protected S3Service $s3Service;

    protected AssetProcessingService $assetProcessingService;

    public function __construct(S3Service $s3Service, AssetProcessingService $assetProcessingService)
    {
        $this->s3Service = $s3Service;
        $this->assetProcessingService = $assetProcessingService;
    }

    /**
     * Get paginated list of assets
     */
    public function index(Request $request)
    {
        $query = Asset::with(['tags', 'user']);

        // Apply filters
        if ($search = $request->input('search')) {
            $query->search($search);
        }

        if ($tagIds = $request->input('tags')) {
            $query->withTags(is_array($tagIds) ? $tagIds : explode(',', $tagIds));
        }

        if ($type = $request->input('type')) {
            $query->ofType($type);
        }

        if (Auth::user()->isAdmin() && $userId = $request->input('user')) {
            $query->byUser($userId);
        }

        if ($folder = $request->input('folder')) {
            $query->inFolder($folder);
        }

        // Apply sorting
        $sort = $request->input('sort', 'date_desc');
        $query->applySort($sort);

        $perPage = min($request->input('per_page', 24), 100);
        $assets = $query->paginate($perPage);

        return response()->json($assets);
    }

    /**
     * Upload new assets
     */
    public function store(Request $request)
    {
        $request->validate([
            'files.*' => 'required|file|max:512000', // 500MB max
        ]);

        $uploadedAssets = [];

        foreach ($request->file('files') as $file) {
            $fileData = $this->s3Service->uploadFile($file);

            $asset = Asset::create([
                's3_key' => $fileData['s3_key'],
                'filename' => $fileData['filename'],
                'mime_type' => $fileData['mime_type'],
                'size' => $fileData['size'],
                'width' => $fileData['width'],
                'height' => $fileData['height'],
                'user_id' => Auth::id(),
            ]);

            // Generate thumbnail, resized images, and AI tags
            $this->assetProcessingService->processImageAsset($asset);

            $uploadedAssets[] = $asset->fresh(['tags']);
        }

        return response()->json([
            'message' => count($uploadedAssets).' file(s) uploaded successfully',
            'data' => $uploadedAssets,
        ], 201);
    }

    /**
     * Get single asset details
     */
    public function show(Asset $asset)
    {
        $asset->load(['tags', 'user']);

        return response()->json($asset);
    }

    /**
     * Update asset metadata
     */
    public function update(Request $request, Asset $asset)
    {
        if (! Auth::user()->isAdmin() && $asset->user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'alt_text' => 'nullable|string|max:500',
            'caption' => 'nullable|string|max:1000',
            'license_type' => 'nullable|string|max:255',
            'copyright' => 'nullable|string|max:500',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
        ]);

        $asset->update(array_merge(
            $request->only(['alt_text', 'caption', 'license_type', 'copyright']),
            ['last_modified_by' => Auth::id()]
        ));

        // Handle tags only if explicitly included in request
        if ($request->has('tags')) {
            $tagIds = Tag::resolveUserTagIds($request->input('tags', []));

            $aiTagIds = $asset->aiTags()->pluck('tags.id')->toArray();
            $referenceTagIds = $asset->referenceTags()->pluck('tags.id')->toArray();
            $asset->tags()->sync(array_merge($aiTagIds, $referenceTagIds, $tagIds));
        }

        return response()->json([
            'message' => 'Asset updated successfully',
            'data' => $asset->fresh(['tags']),
        ]);
    }

    /**
     * Soft delete asset (move to trash)
     */
    public function destroy(Asset $asset)
    {
        if (! Auth::user()->isAdmin() && $asset->user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $asset->delete();

        return response()->json([
            'message' => 'Asset moved to trash successfully',
        ]);
    }

    /**
     * Search assets (optimized for asset picker)
     */
    public function search(Request $request)
    {
        $query = Asset::with(['tags']);

        if ($search = $request->input('q')) {
            $query->search($search);
        }

        if ($tags = $request->input('tags')) {
            $tagIds = is_array($tags) ? $tags : explode(',', $tags);
            $query->withTags($tagIds);
        }

        if ($type = $request->input('type')) {
            $query->ofType($type);
        }

        if ($folder = $request->input('folder')) {
            $query->inFolder($folder);
        }

        // Apply sorting
        $sort = $request->input('sort', 'date_desc');
        $query->applySort($sort);

        $perPage = min($request->input('per_page', 24), 100);
        $assets = $query->paginate($perPage);

        return response()->json($assets);
    }

    /**
     * Get asset metadata by URL (public endpoint)
     */
    public function getMeta(Request $request)
    {
        // Check if meta endpoint is enabled
        if (! Setting::get('api_meta_endpoint_enabled', true)) {
            return response()->json(['message' => 'This endpoint is disabled.'], 403);
        }

        $request->validate([
            'url' => 'required|string|url',
        ]);

        $url = $request->input('url');
        $customBaseUrl = S3Service::getPublicBaseUrl().'/';
        $s3BaseUrl = config('filesystems.disks.s3.url').'/';

        // Extract S3 key by removing the base URL (supports both custom domain and S3 URLs)
        $s3Key = null;
        if (str_starts_with($url, $customBaseUrl)) {
            $s3Key = substr($url, strlen($customBaseUrl));
        } elseif ($s3BaseUrl !== $customBaseUrl && str_starts_with($url, $s3BaseUrl)) {
            $s3Key = substr($url, strlen($s3BaseUrl));
        }

        if ($s3Key === null) {
            return response()->json([
                'message' => 'URL does not match configured domain',
            ], 400);
        }

        // Find asset by s3_key
        $asset = Asset::where('s3_key', $s3Key)->first();

        if (! $asset) {
            return response()->json([
                'message' => 'Asset not found',
            ], 404);
        }

        // Return only metadata fields
        return response()->json([
            'alt_text' => $asset->alt_text,
            'caption' => $asset->caption,
            'license_type' => $asset->license_type,
            'copyright' => $asset->copyright,
            'filename' => $asset->filename,
            'url' => $asset->url,
        ]);
    }

    /**
     * Add reference tags to an asset
     */
    public function addReferenceTags(Request $request)
    {
        $request->validate([
            'asset_id' => 'required_without:s3_key|integer|exists:assets,id',
            's3_key' => 'required_without:asset_id|string|max:1024',
            'tags' => 'required|array|min:1|max:100',
            'tags.*' => 'string|max:100',
        ]);

        $asset = $request->has('asset_id')
            ? Asset::find($request->input('asset_id'))
            : Asset::where('s3_key', $request->input('s3_key'))->first();

        if (! $asset) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        $tagIds = Tag::resolveReferenceTagIds($request->input('tags'));
        $asset->tags()->syncWithoutDetaching($tagIds);

        return response()->json([
            'message' => 'Reference tags added successfully',
            'data' => $asset->fresh(['tags']),
        ]);
    }

    /**
     * Remove a reference tag from an asset
     */
    public function removeReferenceTag(Request $request, Tag $tag)
    {
        $request->validate([
            'asset_id' => 'required_without:s3_key|integer|exists:assets,id',
            's3_key' => 'required_without:asset_id|string|max:1024',
        ]);

        if ($tag->type !== 'reference') {
            return response()->json(['message' => 'Only reference tags can be removed via this endpoint'], 422);
        }

        $asset = $request->has('asset_id')
            ? Asset::find($request->input('asset_id'))
            : Asset::where('s3_key', $request->input('s3_key'))->first();

        if (! $asset) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        $asset->tags()->detach($tag->id);

        return response()->json([
            'message' => 'Reference tag removed successfully',
        ]);
    }
}
