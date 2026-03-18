<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAssetRequest;
use App\Http\Requests\UpdateAssetRequest;
use App\Models\Asset;
use App\Models\Tag;
use App\Services\AssetProcessingService;
use App\Services\RekognitionService;
use App\Services\S3Service;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AssetController extends Controller
{
    use AuthorizesRequests;

    protected S3Service $s3Service;

    protected RekognitionService $rekognitionService;

    protected AssetProcessingService $assetProcessingService;

    public function __construct(S3Service $s3Service, RekognitionService $rekognitionService, AssetProcessingService $assetProcessingService)
    {
        $this->s3Service = $s3Service;
        $this->rekognitionService = $rekognitionService;
        $this->assetProcessingService = $assetProcessingService;
    }

    /**
     * Display a listing of assets
     */
    public function index(Request $request)
    {
        $data = $this->buildIndexData($request);
        $data['indexRoute'] = 'assets.index';

        session(['assets_return_url' => $request->fullUrl()]);

        return view('assets.index', $data);
    }

    /**
     * Display assets in an embeddable layout (no header/footer)
     */
    public function embed(Request $request)
    {
        $data = $this->buildIndexData($request);
        $data['indexRoute'] = 'assets.embed';

        session(['assets_return_url' => $request->fullUrl()]);

        return view('assets.embed', $data);
    }

    /**
     * Build shared data for the asset index/embed views
     */
    private function buildIndexData(Request $request): array
    {
        $query = Asset::with(['tags', 'user']);

        // Apply search
        if ($search = $request->input('search')) {
            $query->search($search);
        }

        // Apply tag filter
        if ($tagIds = $request->input('tags')) {
            $query->withTags(is_array($tagIds) ? $tagIds : explode(',', $tagIds));
        }

        // Apply type filter
        if ($type = $request->input('type')) {
            $query->ofType($type);
        }

        // Apply folder filter: URL param > user preference > global root
        $rootFolder = S3Service::getRootFolder();
        $userHomeFolder = Auth::user()->getHomeFolder();
        $folder = $request->input('folder', $userHomeFolder);
        if ($folder !== '') {
            $query->inFolder($folder);
        }

        // Apply user filter (admins only)
        if (Auth::user()->isAdmin() && $userId = $request->input('user')) {
            $query->byUser($userId);
        }

        // Apply missing filter
        if ($request->boolean('missing')) {
            $query->missing();
        }

        // Apply sorting
        $sort = $request->input('sort', 'date_desc');
        $query->applySort($sort);

        // Items per page: URL param > user preference > global setting
        $allowedPerPage = [12, 24, 36, 48, 60, 72, 96];
        $perPage = $request->input('per_page');
        if (! $perPage || ! in_array((int) $perPage, $allowedPerPage)) {
            $perPage = Auth::user()->getItemsPerPage();
        }
        $assets = $query->paginate((int) $perPage)->onEachSide(2)->withQueryString();
        $folders = S3Service::getConfiguredFolders();

        $missingCount = Asset::missing()->count();

        return compact('assets', 'perPage', 'folders', 'rootFolder', 'folder', 'missingCount');
    }

    /**
     * Show the form for creating a new asset
     */
    public function create()
    {
        $this->authorize('create', Asset::class);

        $rootFolder = S3Service::getRootFolder();
        $folders = S3Service::getConfiguredFolders();

        return view('assets.create', compact('folders', 'rootFolder'));
    }

    /**
     * Store newly uploaded assets
     */
    public function store(StoreAssetRequest $request)
    {
        $this->authorize('create', Asset::class);

        try {

            $folder = $request->input('folder', S3Service::getRootFolder());
            $keepOriginalFilename = $request->boolean('keep_original_filename');
            $uploadedAssets = [];
            $duplicates = [];

            foreach ($request->file('files') as $file) {
                try {
                    // Upload to S3 with folder support
                    $fileData = $this->s3Service->uploadFile($file, $folder, $keepOriginalFilename);

                    // Check for duplicate by etag (skip when keeping original filename, as overwrite is intentional)
                    if (! $keepOriginalFilename && ! empty($fileData['etag'])) {
                        $existing = Asset::withTrashed()->where('etag', $fileData['etag'])->first();
                        if ($existing) {
                            // Clean up the just-uploaded S3 object
                            $this->s3Service->deleteFile($fileData['s3_key']);
                            Log::warning('Duplicate upload detected', [
                                'filename' => $fileData['filename'],
                                'existing_asset_id' => $existing->id,
                                'etag' => $fileData['etag'],
                            ]);

                            $duplicates[] = [
                                'filename' => $fileData['filename'],
                                'existing_asset_id' => $existing->id,
                                'existing_asset_url' => $existing->trashed() ? null : route('assets.show', $existing),
                            ];

                            continue;
                        }
                    }

                    // Handle s3_key collision when keeping original filename
                    $existingByKey = Asset::withTrashed()->where('s3_key', $fileData['s3_key'])->first();
                    if ($existingByKey) {
                        // Clean up old thumbnails and resized images
                        $this->s3Service->deleteAssetFiles($existingByKey, keepOriginal: true);

                        // Update existing asset record
                        $existingByKey->update([
                            'filename' => $fileData['filename'],
                            'mime_type' => $fileData['mime_type'],
                            'size' => $fileData['size'],
                            'etag' => $fileData['etag'] ?? null,
                            'width' => $fileData['width'],
                            'height' => $fileData['height'],
                            'thumbnail_s3_key' => null,
                            'resize_s_s3_key' => null,
                            'resize_m_s3_key' => null,
                            'resize_l_s3_key' => null,
                            'user_id' => Auth::id(),
                            'deleted_at' => null,
                            'last_modified_by' => Auth::id(),
                        ]);

                        $asset = $existingByKey;
                    } else {
                        // Create asset record
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
                    }

                    // Generate thumbnail, resized images, and AI tags
                    $this->assetProcessingService->processImageAsset($asset);

                    $uploadedAssets[] = $asset;
                } catch (\Exception $e) {
                    \Log::error("Failed to upload {$file->getClientOriginalName()}: ".$e->getMessage());
                    // Continue with other files
                }
            }

            if (empty($uploadedAssets)) {
                if (! empty($duplicates)) {
                    $dupeMessage = __('Duplicate file(s) detected. These files already exist in the library.');
                    if ($request->expectsJson()) {
                        return response()->json([
                            'message' => $dupeMessage,
                            'duplicates' => $duplicates,
                        ], 409);
                    }

                    return redirect()->back()->with('error', $dupeMessage);
                }

                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'All uploads failed. Please check the logs for details.',
                    ], 500);
                }

                return redirect()->back()
                    ->with('error', 'All uploads failed. Please check the file formats and try again.');
            }

            if ($request->expectsJson()) {
                foreach ($uploadedAssets as $a) {
                    $a->append(Asset::APPEND_FIELDS);
                }

                $response = [
                    'message' => count($uploadedAssets).' file(s) uploaded successfully',
                    'assets' => $uploadedAssets,
                ];
                if (! empty($duplicates)) {
                    $response['duplicates'] = $duplicates;
                }

                return response()->json($response);
            }

            $successMessage = count($uploadedAssets).' file(s) uploaded successfully';
            if (! empty($duplicates)) {
                $dupeNames = array_column($duplicates, 'filename');
                $successMessage .= '. '.__('Skipped :count duplicate(s): :names', [
                    'count' => count($duplicates),
                    'names' => implode(', ', $dupeNames),
                ]);
            }

            return redirect()->route('assets.index')
                ->with('success', $successMessage);
        } catch (\Exception $e) {
            \Log::error('Upload process failed: '.$e->getMessage());

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Upload failed: '.$e->getMessage(),
                ], 500);
            }

            return redirect()->back()
                ->with('error', 'Upload failed: '.$e->getMessage());
        }
    }

    /**
     * Display the specified asset
     */
    public function show(Asset $asset)
    {
        $this->authorize('view', $asset);
        $asset->load(['tags' => function ($query) {
            $query->withCount('assets');
        }, 'user']);

        return view('assets.show', compact('asset'));
    }

    /**
     * Show the form for editing the specified asset
     */
    public function edit(Asset $asset)
    {
        $this->authorize('update', $asset);
        $asset->load('tags');
        $tags = Tag::orderBy('name')->get();

        return view('assets.edit', compact('asset', 'tags'));
    }

    /**
     * Update the specified asset
     */
    public function update(UpdateAssetRequest $request, Asset $asset)
    {
        $this->authorize('update', $asset);

        // Update metadata
        $asset->update(array_merge(
            $request->only(['filename', 'alt_text', 'caption', 'license_type', 'license_expiry_date', 'copyright', 'copyright_source']),
            ['last_modified_by' => Auth::id()]
        ));

        // Handle tags only if explicitly included in request
        if ($request->has('tags')) {
            $tagIds = Tag::resolveUserTagIds($request->input('tags', []));

            // Keep AI tags with their current attached_by, replace user tags
            $aiPivotData = [];
            foreach ($asset->aiTags as $aiTag) {
                $aiPivotData[$aiTag->id] = ['attached_by' => $aiTag->pivot->attached_by];
            }
            $userPivotData = array_fill_keys($tagIds, ['attached_by' => 'user']);
            $asset->tags()->sync($aiPivotData + $userPivotData);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Asset updated successfully',
                'asset' => $asset->fresh(['tags'])->append(Asset::APPEND_FIELDS),
            ]);
        }

        return redirect()->route('assets.show', $asset)
            ->with('success', 'Asset updated successfully');
    }

    /**
     * Soft delete the specified asset (does NOT delete S3 objects)
     */
    public function destroy(Asset $asset)
    {
        $this->authorize('delete', $asset);

        // Soft delete from database only - keep S3 objects
        $asset->delete();

        if (request()->expectsJson()) {
            return response()->json([
                'message' => 'Asset moved to trash successfully',
            ]);
        }

        return redirect()->route('assets.index')
            ->with('success', 'Asset moved to trash successfully');
    }

    /**
     * Show trash page with soft-deleted assets
     */
    public function trash()
    {
        $this->authorize('restore', Asset::class);

        $perPage = Auth::user()->getItemsPerPage();
        $assets = Asset::onlyTrashed()
            ->with(['user', 'tags'])
            ->orderBy('deleted_at', 'desc')
            ->paginate($perPage);

        return view('assets.trash', compact('assets'));
    }

    /**
     * Restore a soft-deleted asset
     */
    public function restore(Asset $asset)
    {
        $this->authorize('restore', $asset);

        $asset->restore();

        if (request()->expectsJson()) {
            return response()->json([
                'message' => 'Asset restored successfully',
            ]);
        }

        return redirect()->route('assets.trash')
            ->with('success', 'Asset restored successfully');
    }

    /**
     * Permanently delete asset and remove S3 objects
     */
    public function forceDelete(Asset $asset)
    {
        $this->authorize('forceDelete', $asset);

        $this->s3Service->deleteAssetFiles($asset);
        $asset->forceDelete();

        if (request()->expectsJson()) {
            return response()->json([
                'message' => 'Asset permanently deleted successfully',
            ]);
        }

        return redirect()->route('assets.trash')
            ->with('success', 'Asset permanently deleted successfully');
    }

    /**
     * Download the asset file
     */
    public function download(Asset $asset)
    {
        // Get the file content from S3
        $fileContent = $this->s3Service->getObjectContent($asset->s3_key);

        // Return as download
        return response($fileContent)
            ->header('Content-Type', $asset->mime_type)
            ->header('Content-Disposition', 'attachment; filename="'.$asset->filename.'"');
    }

    /**
     * Show the form for replacing the asset file
     */
    public function showReplace(Asset $asset)
    {
        $this->authorize('update', $asset);
        $asset->load('tags');

        return view('assets.replace', compact('asset'));
    }

    /**
     * Replace the asset file while preserving metadata
     */
    public function replace(Request $request, Asset $asset)
    {
        $this->authorize('update', $asset);

        // Get original extension
        $originalExtension = strtolower(pathinfo($asset->s3_key, PATHINFO_EXTENSION));

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:512000', // 500MB
                function ($attribute, $value, $fail) use ($originalExtension) {
                    $newExtension = strtolower($value->getClientOriginalExtension());
                    if ($newExtension !== $originalExtension) {
                        $fail("The file must have the same extension (.{$originalExtension}).");
                    }
                },
            ],
        ]);

        try {
            // Replace file in S3 using the same key
            $fileData = $this->s3Service->replaceFile(
                $request->file('file'),
                $asset->s3_key
            );

            // Delete old thumbnail if exists
            if ($asset->thumbnail_s3_key) {
                $this->s3Service->deleteFile($asset->thumbnail_s3_key);
            }

            // Delete old resized images
            $this->s3Service->deleteResizedImages($asset);

            // Update asset record (only file-related fields)
            $asset->update([
                'filename' => $fileData['filename'],
                'mime_type' => $fileData['mime_type'],
                'size' => $fileData['size'],
                'etag' => $fileData['etag'],
                'width' => $fileData['width'],
                'height' => $fileData['height'],
                'thumbnail_s3_key' => null,
                'resize_s_s3_key' => null,
                'resize_m_s3_key' => null,
                'resize_l_s3_key' => null,
                'last_modified_by' => Auth::id(),
            ]);

            // Regenerate thumbnail, resized images, and AI tags
            $this->assetProcessingService->processImageAsset($asset);

            return response()->json([
                'message' => 'Asset replaced successfully',
                'asset' => $asset->fresh(),
            ]);

        } catch (\Exception $e) {
            \Log::error('Asset replacement failed: '.$e->getMessage());

            return response()->json([
                'message' => 'Failed to replace asset: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a browser-generated thumbnail for a video asset
     */
    public function storeThumbnail(Request $request, Asset $asset)
    {
        $this->authorize('update', $asset);

        $request->validate([
            'thumbnail' => 'required|string',
        ]);

        $imageData = base64_decode($request->input('thumbnail'), true);
        if ($imageData === false) {
            return response()->json(['message' => 'Invalid base64 data'], 422);
        }

        // Delete old thumbnail if exists
        if ($asset->thumbnail_s3_key) {
            $this->s3Service->deleteFile($asset->thumbnail_s3_key);
        }

        $thumbnailKey = $this->s3Service->uploadThumbnail($asset->s3_key, $imageData);
        if (! $thumbnailKey) {
            return response()->json(['message' => 'Failed to upload thumbnail'], 500);
        }

        $asset->update(['thumbnail_s3_key' => $thumbnailKey]);

        return response()->json([
            'message' => __('Video preview generated successfully.'),
            'thumbnail_url' => $asset->thumbnail_url,
        ]);
    }

    /**
     * Generate AI tags for an asset using AWS Rekognition
     */
    public function generateAiTags(Asset $asset)
    {
        \Log::info("generateAiTags called for asset ID: {$asset->id}");

        $this->authorize('update', $asset);

        if (! $asset->isImage()) {
            \Log::info('Asset is not an image, redirecting to edit');

            return redirect()->route('assets.edit', $asset)
                ->with('error', __('AI tagging is only available for images'));
        }

        if (! $this->rekognitionService->isEnabled()) {
            \Log::info('Rekognition is not enabled, redirecting to edit');

            return redirect()->route('assets.edit', $asset)
                ->with('error', __('AWS Rekognition is not enabled'));
        }

        try {
            \Log::info("Starting AI tag generation for asset {$asset->id}");
            $labels = $this->rekognitionService->autoTagAsset($asset);

            if (empty($labels)) {
                \Log::info('No labels detected, redirecting to edit');

                return redirect()->route('assets.edit', $asset)
                    ->with('warning', __('No labels detected for this image'));
            }

            \Log::info('Generated '.count($labels).' AI tags, redirecting to edit');

            return redirect()->route('assets.edit', $asset)
                // ->with('success', 'Generated '.count($labels).' AI tag(s) successfully');
                ->with('success', __('Generated :count AI tag(s) successfully', ['count' => count($labels)]));
        } catch (\Exception $e) {
            \Log::error("Manual AI tagging failed for {$asset->filename}: ".$e->getMessage());

            return redirect()->route('assets.edit', $asset)
                ->with('error', __('Failed to generate AI tags: ').$e->getMessage());
        }
    }

    /**
     * Add tags to multiple assets at once
     */
    public function bulkAddTags(Request $request)
    {
        $request->validate([
            'asset_ids' => 'required|array|max:500',
            'asset_ids.*' => 'integer|exists:assets,id',
            'tags' => 'required|array',
            'tags.*' => 'string|max:50',
        ]);

        $tagIds = Tag::resolveUserTagIds($request->tags);
        $assets = Asset::whereIn('id', $request->asset_ids)->get();

        foreach ($assets as $asset) {
            $this->authorize('update', $asset);
            $asset->syncTagsWithAttribution($tagIds, 'user');
        }

        Asset::whereIn('id', $request->asset_ids)->update(['last_modified_by' => Auth::id()]);

        return response()->json([
            'message' => __(':count asset(s) updated', ['count' => $assets->count()]),
        ]);
    }

    /**
     * Remove tags from multiple assets at once
     */
    public function bulkRemoveTags(Request $request)
    {
        $request->validate([
            'asset_ids' => 'required|array|max:500',
            'asset_ids.*' => 'integer|exists:assets,id',
            'tag_ids' => 'required|array',
            'tag_ids.*' => 'integer|exists:tags,id',
        ]);

        $assets = Asset::whereIn('id', $request->asset_ids)->get();

        foreach ($assets as $asset) {
            $this->authorize('update', $asset);
            $asset->tags()->detach($request->tag_ids);
        }

        Asset::whereIn('id', $request->asset_ids)->update(['last_modified_by' => Auth::id()]);

        return response()->json([
            'message' => __(':count asset(s) updated', ['count' => $assets->count()]),
        ]);
    }

    /**
     * Get all tags across multiple assets with per-tag count
     */
    public function bulkGetTags(Request $request)
    {
        $request->validate([
            'asset_ids' => 'required|array|max:500',
            'asset_ids.*' => 'integer|exists:assets,id',
        ]);

        $assets = Asset::with('tags')->whereIn('id', $request->asset_ids)->get();

        // Count how many of the selected assets have each tag
        $tagCounts = [];
        foreach ($assets as $asset) {
            foreach ($asset->tags as $tag) {
                if (! isset($tagCounts[$tag->id])) {
                    $tagCounts[$tag->id] = [
                        'id' => $tag->id,
                        'name' => $tag->name,
                        'type' => $tag->type,
                        'count' => 0,
                    ];
                }
                $tagCounts[$tag->id]['count']++;
            }
        }

        $tags = array_values($tagCounts);
        usort($tags, function ($a, $b) {
            return $b['count'] <=> $a['count'] ?: strcmp($a['name'], $b['name']);
        });

        return response()->json([
            'tags' => $tags,
            'total_assets' => $assets->count(),
        ]);
    }

    /**
     * Permanently delete multiple assets and their S3 objects
     */
    public function bulkForceDelete(Request $request)
    {
        $this->authorize('bulkForceDelete', Asset::class);

        $request->validate([
            'asset_ids' => 'required|array|max:500',
            'asset_ids.*' => 'integer|exists:assets,id',
        ]);

        $assets = Asset::whereIn('id', $request->asset_ids)->get();
        $deleted = 0;
        $failed = 0;
        $deletedKeys = [];

        foreach ($assets as $asset) {
            try {
                $this->s3Service->deleteAssetFiles($asset);
                $deletedKeys[] = $asset->s3_key;
                $asset->forceDelete();
                $deleted++;
            } catch (\Exception $e) {
                Log::error("Bulk force delete failed for asset {$asset->id}: ".$e->getMessage());
                $failed++;
            }
        }

        return response()->json([
            'message' => __(':deleted asset(s) permanently deleted', ['deleted' => $deleted]),
            'deleted' => $deleted,
            'failed' => $failed,
            'deleted_keys' => $deletedKeys,
        ]);
    }

    /**
     * Move multiple assets to a different S3 folder
     */
    public function bulkMoveAssets(Request $request)
    {
        $this->authorize('move', Asset::class);

        $configuredFolders = S3Service::getConfiguredFolders();

        $request->validate([
            'asset_ids' => 'required|array|max:500',
            'asset_ids.*' => 'integer|exists:assets,id',
            'destination_folder' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9\/_-]+$/', function ($attribute, $value, $fail) use ($configuredFolders) {
                $folder = rtrim($value, '/');
                $isConfigured = collect($configuredFolders)->contains(function ($configured) use ($folder) {
                    return $folder === $configured || str_starts_with($folder.'/', $configured.'/');
                });
                if (! $isConfigured) {
                    $fail(__('The destination folder must be within a configured S3 folder.'));
                }
            }],
        ]);

        $destinationFolder = rtrim($request->input('destination_folder'), '/');
        $assets = Asset::whereIn('id', $request->asset_ids)->get();
        $moved = 0;
        $failed = 0;
        $moves = [];

        foreach ($assets as $asset) {
            $oldS3Key = $asset->s3_key;
            $oldDir = dirname($oldS3Key);

            // Skip if already in target folder
            if ($oldDir === $destinationFolder) {
                continue;
            }

            // Compute new s3_key
            $newS3Key = $destinationFolder.'/'.basename($oldS3Key);

            // Move main file
            if (! $this->s3Service->moveObject($oldS3Key, $newS3Key)) {
                $failed++;

                continue;
            }

            $updateData = ['s3_key' => $newS3Key];

            // Move associated keys (thumbnail, resize variants)
            $derivedKeys = $this->s3Service->computeDerivedKeys($newS3Key);
            foreach ($derivedKeys as $column => $newKey) {
                if ($asset->{$column} && $newKey !== $asset->{$column}) {
                    $this->s3Service->moveObject($asset->{$column}, $newKey);
                    $updateData[$column] = $newKey;
                }
            }

            $asset->update($updateData);

            Log::info("Asset moved: {$oldS3Key} -> {$newS3Key}");
            $moves[] = ['old' => $oldS3Key, 'new' => $newS3Key];
            $moved++;
        }

        return response()->json([
            'message' => __(':moved asset(s) moved successfully', ['moved' => $moved]),
            'moved' => $moved,
            'failed' => $failed,
            'moves' => $moves,
        ]);
    }

    /**
     * Bulk restore trashed assets
     */
    public function bulkRestore(Request $request)
    {
        $this->authorize('restore', Asset::class);

        $request->validate([
            'asset_ids' => 'required|array|max:500',
            'asset_ids.*' => 'integer',
        ]);

        $assets = Asset::onlyTrashed()->whereIn('id', $request->asset_ids)->get();
        $restored = 0;
        $failed = 0;
        $restoredFilenames = [];

        foreach ($assets as $asset) {
            try {
                $asset->restore();
                $restoredFilenames[] = $asset->filename;
                $restored++;
            } catch (\Exception $e) {
                Log::error("Bulk restore failed for asset {$asset->id}: ".$e->getMessage());
                $failed++;
            }
        }

        return response()->json([
            'message' => __(':restored asset(s) restored', ['restored' => $restored]),
            'restored' => $restored,
            'failed' => $failed,
            'restored_filenames' => $restoredFilenames,
        ]);
    }

    /**
     * Bulk permanently delete trashed assets and their S3 objects
     */
    public function bulkForceDeleteTrashed(Request $request)
    {
        $this->authorize('forceDelete', Asset::class);

        $request->validate([
            'asset_ids' => 'required|array|max:500',
            'asset_ids.*' => 'integer',
        ]);

        $assets = Asset::onlyTrashed()->whereIn('id', $request->asset_ids)->get();
        $deleted = 0;
        $failed = 0;
        $deletedKeys = [];

        foreach ($assets as $asset) {
            try {
                $this->s3Service->deleteAssetFiles($asset);
                $deletedKeys[] = $asset->s3_key;
                $asset->forceDelete();
                $deleted++;
            } catch (\Exception $e) {
                Log::error("Bulk force delete (trash) failed for asset {$asset->id}: ".$e->getMessage());
                $failed++;
            }
        }

        return response()->json([
            'message' => __(':deleted asset(s) permanently deleted', ['deleted' => $deleted]),
            'deleted' => $deleted,
            'failed' => $failed,
            'deleted_keys' => $deletedKeys,
        ]);
    }

    /**
     * Add tags to an asset
     */
    public function addTags(Request $request, Asset $asset)
    {
        $this->authorize('update', $asset);

        $request->validate([
            'tags' => 'required|array',
            'tags.*' => 'string|max:50',
        ]);

        $tagIds = Tag::resolveUserTagIds($request->tags);

        $asset->syncTagsWithAttribution($tagIds, 'user');
        $asset->update(['last_modified_by' => Auth::id()]);

        return response()->json([
            'message' => 'Tags added successfully',
            'tags' => $asset->fresh()->tags,
        ]);
    }

    /**
     * Remove a tag from an asset
     */
    public function removeTag(Asset $asset, Tag $tag)
    {
        $this->authorize('update', $asset);

        $asset->tags()->detach($tag->id);
        $asset->update(['last_modified_by' => Auth::id()]);

        return response()->json([
            'message' => 'Tag removed successfully',
        ]);
    }
}
