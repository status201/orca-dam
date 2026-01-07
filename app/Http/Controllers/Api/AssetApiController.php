<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Tag;
use App\Services\S3Service;
use App\Services\RekognitionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AssetApiController extends Controller
{
    protected S3Service $s3Service;
    protected RekognitionService $rekognitionService;

    public function __construct(S3Service $s3Service, RekognitionService $rekognitionService)
    {
        $this->s3Service = $s3Service;
        $this->rekognitionService = $rekognitionService;
    }

    /**
     * Get paginated list of assets
     */
    public function index(Request $request)
    {
        $query = Asset::with(['tags', 'user'])
            ->latest();

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
            'files.*' => 'required|file|max:102400',
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

            if ($asset->isImage()) {
                $thumbnailKey = $this->s3Service->generateThumbnail($asset->s3_key);
                if ($thumbnailKey) {
                    $asset->update(['thumbnail_s3_key' => $thumbnailKey]);
                }

                if ($this->rekognitionService->isEnabled()) {
                    dispatch(function () use ($asset) {
                        $this->rekognitionService->autoTagAsset($asset);
                    })->afterResponse();
                }
            }

            $uploadedAssets[] = $asset->fresh(['tags']);
        }

        return response()->json([
            'message' => count($uploadedAssets) . ' file(s) uploaded successfully',
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
        if (!Auth::user()->isAdmin() && $asset->user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'alt_text' => 'nullable|string|max:500',
            'caption' => 'nullable|string|max:1000',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
        ]);

        $asset->update($request->only(['alt_text', 'caption']));

        if ($request->has('tags')) {
            $tagIds = [];
            foreach ($request->tags as $tagName) {
                $tag = Tag::firstOrCreate(
                    ['name' => strtolower(trim($tagName))],
                    ['type' => 'user']
                );
                $tagIds[] = $tag->id;
            }
            
            $aiTagIds = $asset->aiTags()->pluck('tags.id')->toArray();
            $asset->tags()->sync(array_merge($aiTagIds, $tagIds));
        }

        return response()->json([
            'message' => 'Asset updated successfully',
            'data' => $asset->fresh(['tags']),
        ]);
    }

    /**
     * Delete asset
     */
    public function destroy(Asset $asset)
    {
        if (!Auth::user()->isAdmin() && $asset->user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $this->s3Service->deleteFile($asset->s3_key);
        
        if ($asset->thumbnail_s3_key) {
            $this->s3Service->deleteFile($asset->thumbnail_s3_key);
        }

        $asset->delete();

        return response()->json([
            'message' => 'Asset deleted successfully',
        ]);
    }

    /**
     * Search assets (optimized for asset picker)
     */
    public function search(Request $request)
    {
        $query = Asset::with(['tags'])
            ->latest();

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

        $perPage = min($request->input('per_page', 24), 100);
        $assets = $query->paginate($perPage);

        return response()->json($assets);
    }
}
