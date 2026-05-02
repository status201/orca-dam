<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Services\AssetProcessingService;
use App\Services\CloudflareService;
use App\Services\RekognitionService;
use App\Services\S3Service;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AssetReplaceController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected S3Service $s3Service,
        protected CloudflareService $cloudflareService,
        protected AssetProcessingService $assetProcessingService,
        protected RekognitionService $rekognitionService,
    ) {}

    /**
     * Download the asset file
     */
    public function download(Asset $asset)
    {
        $fileContent = $this->s3Service->getObjectContent($asset->s3_key);

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
            $urlsToPurge = $this->cloudflareService->collectAssetUrls($asset);

            $fileData = $this->s3Service->replaceFile(
                $request->file('file'),
                $asset->s3_key
            );

            if ($asset->thumbnail_s3_key) {
                $this->s3Service->deleteFile($asset->thumbnail_s3_key);
            }

            $this->s3Service->deleteResizedImages($asset);

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

            $this->assetProcessingService->processImageAsset($asset);

            $this->cloudflareService->purgeUrls($urlsToPurge);

            return response()->json([
                'message' => 'Asset replaced successfully',
                'asset' => $asset->fresh(),
            ]);

        } catch (\Exception $e) {
            Log::error('Asset replacement failed: '.$e->getMessage());

            return response()->json([
                'message' => 'Failed to replace asset: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a browser-generated thumbnail for a video/pdf asset
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

        $urlsToPurge = $this->cloudflareService->collectAssetUrls($asset);

        if ($asset->thumbnail_s3_key) {
            $this->s3Service->deleteFile($asset->thumbnail_s3_key);
        }

        $thumbnailKey = $this->s3Service->uploadThumbnail($asset->s3_key, $imageData);
        if (! $thumbnailKey) {
            return response()->json(['message' => 'Failed to upload thumbnail'], 500);
        }

        $asset->update(['thumbnail_s3_key' => $thumbnailKey]);

        $this->cloudflareService->purgeUrls($urlsToPurge);

        return response()->json([
            'message' => __('Preview generated successfully.'),
            'thumbnail_url' => $asset->thumbnail_url,
        ]);
    }

    /**
     * Generate AI tags for an asset using AWS Rekognition
     */
    public function generateAiTags(Asset $asset)
    {
        Log::info("generateAiTags called for asset ID: {$asset->id}");

        $this->authorize('update', $asset);

        if (! $asset->isImage()) {
            return redirect()->route('assets.edit', $asset)
                ->with('error', __('AI tagging is only available for images'));
        }

        if (! $this->rekognitionService->isEnabled()) {
            return redirect()->route('assets.edit', $asset)
                ->with('error', __('AWS Rekognition is not enabled'));
        }

        try {
            $labels = $this->rekognitionService->autoTagAsset($asset);

            if (empty($labels)) {
                return redirect()->route('assets.edit', $asset)
                    ->with('warning', __('No labels detected for this image'));
            }

            return redirect()->route('assets.edit', $asset)
                ->with('success', __('Generated :count AI tag(s) successfully', ['count' => count($labels)]));
        } catch (\Exception $e) {
            Log::error("Manual AI tagging failed for {$asset->filename}: ".$e->getMessage());

            return redirect()->route('assets.edit', $asset)
                ->with('error', __('Failed to generate AI tags: ').$e->getMessage());
        }
    }
}
