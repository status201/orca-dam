<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DuplicateAssetException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssetRequest;
use App\Http\Requests\UpdateAssetRequest;
use App\Models\Asset;
use App\Models\Setting;
use App\Models\Tag;
use App\Services\AssetIngestionService;
use App\Services\AssetProcessingService;
use App\Services\S3Service;
use App\Support\AssetFilter;
use App\Support\ColumnLimits;
use App\Support\TagInputParser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class AssetApiController extends Controller
{
    public function __construct(
        protected S3Service $s3Service,
        protected AssetProcessingService $assetProcessingService,
        protected AssetIngestionService $ingestion,
    ) {}

    /**
     * Get paginated list of assets
     */
    public function index(Request $request)
    {
        $filter = new AssetFilter(
            search: $request->input('search'),
            tagIds: AssetFilter::tagIds($request->input('tags')),
            type: $request->input('type'),
            folder: $request->input('folder'),
            // Admins only. Unlike the web grid, the REST API offers no "my uploads" filter.
            userId: Auth::user()->isAdmin() ? ((int) $request->input('user') ?: null) : null,
            sort: $request->input('sort'),
        );

        return $this->paginatedJson($filter->apply(Asset::with(['tags', 'user'])), $request);
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

            // The key is always a fresh UUID here, so there is no overwrite target and the etag
            // check always applies.
            $existing = $this->ingestion->findDuplicate($fileData['etag'] ?? null);
            if ($existing) {
                $this->ingestion->discardDuplicate($fileData['s3_key'], $existing, $fileData['filename']);
                // The shared payload (duplicate-detection.md REQ-4), not a hand-rolled subset:
                // this used to return 3 of the 12 documented fields, so an API caller could not
                // render the duplicates panel the web paths produce.
                //
                // Additive on purpose. existing_asset_url pre-dates formatDuplicate() and is not
                // in it — and its `public_url` is NOT a substitute, since that stays non-null for
                // a trashed asset where this is null. Dropping it would break existing consumers.
                $duplicates[] = DuplicateAssetException::formatDuplicate($existing, $fileData['filename']) + [
                    'existing_asset_url' => $existing->trashed() ? null : $existing->url,
                ];

                continue;
            }

            $asset = $this->ingestion->persist([
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

        // The same metadata + tag handling as the web AssetController::update, through the same
        // request helpers, so the two contracts can't drift. s3_key is never touched (ADR-006).
        $asset->update(array_merge($request->metadataAttributes(), ['last_modified_by' => Auth::id()]));

        if ($request->touchesTags()) {
            $asset->syncTagsPreservingAi($request->userTagIds(), $request->referenceTagIds());
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
        $this->authorize('delete', $asset);

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
        $filter = new AssetFilter(
            search: $request->input('q'),
            tagIds: AssetFilter::tagIds($request->input('tags')),
            type: $request->input('type'),
            folder: $request->input('folder'),
            sort: $request->input('sort'),
        );

        return $this->paginatedJson($filter->apply(Asset::with(['tags'])), $request);
    }

    /**
     * One page of assets with their computed URL fields, as both list endpoints return it.
     */
    private function paginatedJson(Builder $query, Request $request): JsonResponse
    {
        $assets = $query->paginate(min($request->input('per_page', 24), 100));
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

        $tagIds = Tag::resolveReferenceTagIds(TagInputParser::parse($request->input('tags')));

        foreach ($assets as $asset) {
            $asset->syncTagsWithAttribution($tagIds, 'reference');
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
     * Remove reference tags by name from one or more assets.
     *
     * Accepts singular (tag_name) or batch (tag_names) tag names plus asset identifiers.
     */
    public function removeReferenceTagsByName(Request $request)
    {
        $validator = validator($request->all(), array_merge($this->assetIdentifierRules(), [
            'tag_name' => 'string|max:100',
            'tag_names' => 'array|min:1|max:100',
            'tag_names.*' => 'string|max:100',
        ]));

        $validator->after(function ($validator) use ($request) {
            if (! $request->hasAny(['tag_name', 'tag_names'])) {
                $validator->errors()->add('tags', 'At least one of tag_name or tag_names is required.');
            }
            if (! $request->hasAny(['asset_id', 'asset_ids', 's3_key', 's3_keys'])) {
                $validator->errors()->add('identifiers', 'At least one of asset_id, asset_ids, s3_key, or s3_keys is required.');
            }
        });

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $names = [];
        if ($request->has('tag_name')) {
            $names[] = $request->input('tag_name');
        }
        if ($request->has('tag_names')) {
            $names = array_merge($names, $request->input('tag_names'));
        }

        $requestedNames = array_values(array_unique(
            array_map(fn ($n) => strtolower(trim($n)), $names)
        ));

        $foundTags = Tag::referenceTags()->whereIn('name', $requestedNames)->get();
        $notFoundNames = array_values(array_diff($requestedNames, $foundTags->pluck('name')->toArray()));

        if ($foundTags->isEmpty()) {
            return response()->json([
                'message' => 'No matching reference tags found',
                'not_found_tags' => $notFoundNames,
            ], 404);
        }

        [$assets, $notFoundS3Keys] = $this->collectAssetsFromRequest($request);

        if ($assets->isEmpty()) {
            return response()->json(['message' => 'No assets found'], 404);
        }

        $tagIds = $foundTags->pluck('id')->toArray();
        foreach ($assets as $asset) {
            $asset->tags()->detach($tagIds);
        }

        $response = [
            'message' => 'Reference tag(s) removed from '.$assets->count().' asset(s)',
        ];

        if (! empty($notFoundNames)) {
            $response['not_found_tags'] = $notFoundNames;
        }

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
            // The column width, never a literal: a key longer than assets.s3_key can never match
            // a row. That width is now 1024, matching S3's own key limit — this rule briefly read
            // 255 because the column was too narrow, which was the bug, not the contract.
            's3_key' => 'string|max:'.ColumnLimits::for('assets', 's3_key'),
            's3_keys' => 'array|max:500',
            's3_keys.*' => 'string|max:'.ColumnLimits::for('assets', 's3_key'),
        ];
    }

    /**
     * Collect Asset models from the four identifier types in the request.
     *
     * @return array{0: Collection, 1: array} [$assets, $notFoundS3Keys]
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
