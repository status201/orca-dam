<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Tag;
use App\Services\S3Service;
use App\Services\RekognitionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class AssetController extends Controller
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

        // Apply user filter (admins only)
        if (Auth::user()->isAdmin() && $userId = $request->input('user')) {
            $query->byUser($userId);
        }

        // Apply sorting
        $sort = $request->input('sort', 'date_desc');
        switch ($sort) {
            case 'date_asc':
                $query->oldest('updated_at');
                break;
            case 'date_desc':
                $query->latest('updated_at');
                break;
            case 'size_asc':
                $query->orderBy('size', 'asc');
                break;
            case 'size_desc':
                $query->orderBy('size', 'desc');
                break;
            case 'name_asc':
                $query->orderBy('filename', 'asc');
                break;
            case 'name_desc':
                $query->orderBy('filename', 'desc');
                break;
            default:
                $query->latest('updated_at');
        }

        $assets = $query->paginate(24);
        $tags = Tag::orderBy('name')->get();

        return view('assets.index', compact('assets', 'tags'));
    }

    /**
     * Show the form for creating a new asset
     */
    public function create()
    {
        $this->authorize('create', Asset::class);
        return view('assets.create');
    }

    /**
     * Store newly uploaded assets
     */
    public function store(Request $request)
    {
        $this->authorize('create', Asset::class);

        try {
            $request->validate([
                'files.*' => 'required|file|max:102400', // 100MB max
            ]);

            $uploadedAssets = [];

            foreach ($request->file('files') as $file) {
                try {
                    // Upload to S3
                    $fileData = $this->s3Service->uploadFile($file);

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

                    // Generate thumbnail for images
                    if ($asset->isImage()) {
                        try {
                            $thumbnailKey = $this->s3Service->generateThumbnail($asset->s3_key);
                            if ($thumbnailKey) {
                                $asset->update(['thumbnail_s3_key' => $thumbnailKey]);
                            }
                        } catch (\Exception $e) {
                            \Log::error("Thumbnail generation failed for {$asset->filename}: " . $e->getMessage());
                            // Continue without thumbnail
                        }
                    }

                    // Auto-tag with AI if enabled
                    if ($asset->isImage() && $this->rekognitionService->isEnabled()) {
                        try {
                            dispatch(function () use ($asset) {
                                $this->rekognitionService->autoTagAsset($asset);
                            })->afterResponse();
                        } catch (\Exception $e) {
                            \Log::error("AI tagging failed for {$asset->filename}: " . $e->getMessage());
                            // Continue without AI tags
                        }
                    }

                    $uploadedAssets[] = $asset;
                } catch (\Exception $e) {
                    \Log::error("Failed to upload {$file->getClientOriginalName()}: " . $e->getMessage());
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
                    'message' => count($uploadedAssets) . ' file(s) uploaded successfully',
                    'assets' => $uploadedAssets,
                ]);
            }

            return redirect()->route('assets.index')
                ->with('success', count($uploadedAssets) . ' file(s) uploaded successfully');
        } catch (\Exception $e) {
            \Log::error("Upload process failed: " . $e->getMessage());

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Upload failed: ' . $e->getMessage(),
                ], 500);
            }

            return redirect()->back()
                ->with('error', 'Upload failed: ' . $e->getMessage());
        }
    }

    /**
     * Display the specified asset
     */
    public function show(Asset $asset)
    {
        $this->authorize('view', $asset);
        $asset->load(['tags', 'user']);
        
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
            'alt_text' => 'nullable|string|max:500',
            'caption' => 'nullable|string|max:1000',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
        ]);

        // Update metadata
        $asset->update($request->only(['alt_text', 'caption']));

        // Handle tags
        if ($request->has('tags')) {
            $tagIds = [];
            foreach ($request->tags as $tagName) {
                $tag = Tag::firstOrCreate(
                    ['name' => strtolower(trim($tagName))],
                    ['type' => 'user']
                );
                $tagIds[] = $tag->id;
            }
            
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
     * Remove the specified asset
     */
    public function destroy(Asset $asset)
    {
        $this->authorize('delete', $asset);

        // Delete from S3
        $this->s3Service->deleteFile($asset->s3_key);
        
        if ($asset->thumbnail_s3_key) {
            $this->s3Service->deleteFile($asset->thumbnail_s3_key);
        }

        // Delete from database
        $asset->delete();

        if (request()->expectsJson()) {
            return response()->json([
                'message' => 'Asset deleted successfully',
            ]);
        }

        return redirect()->route('assets.index')
            ->with('success', 'Asset deleted successfully');
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
            ->header('Content-Disposition', 'attachment; filename="' . $asset->filename . '"');
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

        $tagIds = [];
        foreach ($request->tags as $tagName) {
            $tag = Tag::firstOrCreate(
                ['name' => strtolower(trim($tagName))],
                ['type' => 'user']
            );
            $tagIds[] = $tag->id;
        }

        $asset->tags()->syncWithoutDetaching($tagIds);

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

        return response()->json([
            'message' => 'Tag removed successfully',
        ]);
    }
}
