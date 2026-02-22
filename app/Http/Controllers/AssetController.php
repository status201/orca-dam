<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Tag;
use App\Services\AssetProcessingService;
use App\Services\RekognitionService;
use App\Services\S3Service;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
        $tags = Tag::orderBy('name')->get();
        $folders = S3Service::getConfiguredFolders();

        $missingCount = Asset::missing()->count();

        return view('assets.index', compact('assets', 'tags', 'perPage', 'folders', 'rootFolder', 'folder', 'missingCount'));
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
    public function store(Request $request)
    {
        $this->authorize('create', Asset::class);

        try {
            $request->validate([
                'files.*' => 'required|file|max:512000', // 500MB max
                'folder' => 'nullable|string|max:255',
            ]);

            $folder = $request->input('folder', S3Service::getRootFolder());
            $uploadedAssets = [];

            foreach ($request->file('files') as $file) {
                try {
                    // Upload to S3 with folder support
                    $fileData = $this->s3Service->uploadFile($file, $folder);

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

                    // Generate thumbnail, resized images, and AI tags
                    $this->assetProcessingService->processImageAsset($asset);

                    $uploadedAssets[] = $asset;
                } catch (\Exception $e) {
                    \Log::error("Failed to upload {$file->getClientOriginalName()}: ".$e->getMessage());
                    // Continue with other files
                }
            }

            if (empty($uploadedAssets)) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'All uploads failed. Please check the logs for details.',
                    ], 500);
                }

                return redirect()->back()
                    ->with('error', 'All uploads failed. Please check the file formats and try again.');
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => count($uploadedAssets).' file(s) uploaded successfully',
                    'assets' => $uploadedAssets,
                ]);
            }

            return redirect()->route('assets.index')
                ->with('success', count($uploadedAssets).' file(s) uploaded successfully');
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
    public function update(Request $request, Asset $asset)
    {
        $this->authorize('update', $asset);

        $request->validate([
            'filename' => 'sometimes|required|string|max:255',
            'alt_text' => 'nullable|string|max:500',
            'caption' => 'nullable|string|max:1000',
            'license_type' => 'nullable|string|max:255',
            'license_expiry_date' => 'nullable|date',
            'copyright' => 'nullable|string|max:500',
            'copyright_source' => 'nullable|string|max:500',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
        ]);

        // Update metadata
        $asset->update(array_merge(
            $request->only(['filename', 'alt_text', 'caption', 'license_type', 'license_expiry_date', 'copyright', 'copyright_source']),
            ['last_modified_by' => Auth::id()]
        ));

        // Handle tags only if explicitly included in request
        if ($request->has('tags')) {
            $tagIds = Tag::resolveUserTagIds($request->input('tags', []));

            // Keep AI tags, replace user tags
            $aiTagIds = $asset->aiTags()->pluck('tags.id')->toArray();
            $asset->tags()->sync(array_merge($aiTagIds, $tagIds));
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Asset updated successfully',
                'asset' => $asset->fresh(['tags']),
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
    public function restore($id)
    {
        $asset = Asset::onlyTrashed()->findOrFail($id);
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
    public function forceDelete($id)
    {
        $asset = Asset::onlyTrashed()->findOrFail($id);
        $this->authorize('forceDelete', $asset);

        // Delete from S3
        $this->s3Service->deleteFile($asset->s3_key);

        if ($asset->thumbnail_s3_key) {
            $this->s3Service->deleteFile($asset->thumbnail_s3_key);
        }

        // Delete resized images
        $this->s3Service->deleteResizedImages($asset);

        // Permanently delete from database
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

        $asset->tags()->syncWithoutDetaching($tagIds);
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
