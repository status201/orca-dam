<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssetRequest;
use App\Http\Requests\UpdateAssetRequest;
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
        $assets->through(fn ($a) => $a->append(Asset::APPEND_FIELDS));

        return response()->json($assets);
    }

    /**
     * Upload new assets
     */
    public function store(StoreAssetRequest $request)
    {
        if (! Setting::get('api_upload_enabled', true)) {
            return response()->json(['message' => 'Upload endpoints are disabled.'], 403);
        }

        $uploadedAssets = [];
        $duplicates = [];

        foreach ($request->file('files') as $file) {
            $fileData = $this->s3Service->uploadFile($file);

            // Check for duplicate by etag
            if (! empty($fileData['etag'])) {
                $existing = Asset::withTrashed()->where('etag', $fileData['etag'])->first();
                if ($existing) {
                    // Clean up the just-uploaded S3 object
                    $this->s3Service->deleteFile($fileData['s3_key']);
                    $duplicates[] = [
                        'filename' => $fileData['filename'],
                        'existing_asset_id' => $existing->id,
                        'existing_asset_url' => $existing->trashed() ? null : $existing->url,
                    ];

                    continue;
                }
            }

            $asset = Asset::create([
                's3_key' => $fileData['s3_key'],
                'filename' => $fileData['filename'],
                'mime_type' => $fileData['mime_type'],
                'size' => $fileData['size'],
                'etag' => $fileData['etag'] ?? null,
                'width' => $fileData['width'],
                'height' => $fileData['height'],
                'user_id' => Auth::id(),
            ]);

            // Generate thumbnail, resized images, and AI tags
            $this->assetProcessingService->processImageAsset($asset);

            $uploadedAssets[] = $asset->fresh(['tags'])->append(Asset::APPEND_FIELDS);
        }

        if (empty($uploadedAssets) && ! empty($duplicates)) {
            return response()->json([
                'message' => 'All files are duplicates of existing assets.',
                'duplicates' => $duplicates,
            ], 409);
        }

        return response()->json(array_filter([
            'message' => count($uploadedAssets).' file(s) uploaded successfully',
            'data' => $uploadedAssets,
            'duplicates' => $duplicates ?: null,
        ]), 201);
    }

    /**
     * Get single asset details
     */
    public function show(Asset $asset)
    {
        $asset->load(['tags', 'user']);

        return response()->json($asset->append(Asset::APPEND_FIELDS));
    }

    /**
     * Update asset metadata
     */
    public function update(UpdateAssetRequest $request, Asset $asset)
    {
        if (! Auth::user()->isAdmin() && $asset->user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

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
            'data' => $asset->fresh(['tags'])->append(Asset::APPEND_FIELDS),
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
        $assets->through(fn ($a) => $a->append(Asset::APPEND_FIELDS));

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
     * Add reference tags to one or more assets
     *
     * Accepts singular (asset_id, s3_key) or batch (asset_ids, s3_keys) identifiers.
     */
    public function addReferenceTags(Request $request)
    {
        $validator = validator($request->all(), array_merge($this->assetIdentifierRules(), [
            'tags' => 'required|array|min:1|max:100',
            'tags.*' => 'string|max:100',
        ]));

        $validator->after(function ($validator) use ($request) {
            if (! $request->hasAny(['asset_id', 'asset_ids', 's3_key', 's3_keys'])) {
                $validator->errors()->add('identifiers', 'At least one of asset_id, asset_ids, s3_key, or s3_keys is required.');
            }
        });

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        [$assets, $notFoundS3Keys] = $this->collectAssetsFromRequest($request);

        if ($assets->isEmpty()) {
            return response()->json(['message' => 'No assets found'], 404);
        }

        $tagIds = Tag::resolveReferenceTagIds($request->input('tags'));

        foreach ($assets as $asset) {
            $asset->tags()->syncWithoutDetaching($tagIds);
        }

        $response = [
            'message' => 'Reference tags added to '.$assets->count().' asset(s)',
            'data' => Asset::with('tags')->whereIn('id', $assets->pluck('id'))->get(),
        ];

        if (! empty($notFoundS3Keys)) {
            $response['not_found_s3_keys'] = array_values($notFoundS3Keys);
        }

        return response()->json($response);
    }

    /**
     * Remove a reference tag from one or more assets
     *
     * Accepts singular (asset_id, s3_key) or batch (asset_ids, s3_keys) identifiers.
     */
    public function removeReferenceTag(Request $request, Tag $tag)
    {
        $validator = validator($request->all(), $this->assetIdentifierRules());

        $validator->after(function ($validator) use ($request) {
            if (! $request->hasAny(['asset_id', 'asset_ids', 's3_key', 's3_keys'])) {
                $validator->errors()->add('identifiers', 'At least one of asset_id, asset_ids, s3_key, or s3_keys is required.');
            }
        });

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        if ($tag->type !== 'reference') {
            return response()->json(['message' => 'Only reference tags can be removed via this endpoint'], 422);
        }

        [$assets, $notFoundS3Keys] = $this->collectAssetsFromRequest($request);

        if ($assets->isEmpty()) {
            return response()->json(['message' => 'No assets found'], 404);
        }

        foreach ($assets as $asset) {
            $asset->tags()->detach($tag->id);
        }

        $response = [
            'message' => 'Reference tag removed from '.$assets->count().' asset(s)',
        ];

        if (! empty($notFoundS3Keys)) {
            $response['not_found_s3_keys'] = array_values($notFoundS3Keys);
        }

        return response()->json($response);
    }

    /**
     * Validation rules for asset identifier fields (asset_id, asset_ids, s3_key, s3_keys).
     */
    private function assetIdentifierRules(): array
    {
        return [
            'asset_id' => 'integer|exists:assets,id',
            'asset_ids' => 'array|max:500',
            'asset_ids.*' => 'integer|exists:assets,id',
            's3_key' => 'string|max:1024',
            's3_keys' => 'array|max:500',
            's3_keys.*' => 'string|max:1024',
        ];
    }

    /**
     * Collect Asset models from the four identifier types in the request.
     *
     * @return array{0: \Illuminate\Support\Collection, 1: array} [$assets, $notFoundS3Keys]
     */
    private function collectAssetsFromRequest(Request $request): array
    {
        $assets = collect();
        $notFoundS3Keys = [];

        if ($request->has('asset_id')) {
            $asset = Asset::find($request->input('asset_id'));
            if ($asset) {
                $assets->push($asset);
            }
        }

        if ($request->has('asset_ids')) {
            $assets = $assets->merge(Asset::whereIn('id', $request->input('asset_ids'))->get());
        }

        if ($request->has('s3_key')) {
            $asset = Asset::where('s3_key', $request->input('s3_key'))->first();
            if ($asset) {
                $assets->push($asset);
            } else {
                $notFoundS3Keys[] = $request->input('s3_key');
            }
        }

        if ($request->has('s3_keys')) {
            $found = Asset::whereIn('s3_key', $request->input('s3_keys'))->get();
            $assets = $assets->merge($found);
            $notFoundS3Keys = array_merge(
                $notFoundS3Keys,
                array_diff($request->input('s3_keys'), $found->pluck('s3_key')->toArray())
            );
        }

        return [$assets->unique('id'), $notFoundS3Keys];
    }
}
