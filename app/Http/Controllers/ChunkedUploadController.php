<?php

namespace App\Http\Controllers;

use App\Exceptions\DuplicateAssetException;
use App\Models\Asset;
use App\Models\Setting;
use App\Models\UploadSession;
use App\Services\AssetProcessingService;
use App\Services\ChunkedUploadService;
use App\Services\S3Service;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ChunkedUploadController extends Controller
{
    use AuthorizesRequests;

    protected ChunkedUploadService $chunkedUploadService;

    protected AssetProcessingService $assetProcessingService;

    public function __construct(
        ChunkedUploadService $chunkedUploadService,
        AssetProcessingService $assetProcessingService
    ) {
        $this->chunkedUploadService = $chunkedUploadService;
        $this->assetProcessingService = $assetProcessingService;
    }

    /**
     * Initialize chunked upload session
     * POST /api/chunked-upload/init
     */
    public function initiate(Request $request)
    {
        if (! Auth::guard('web')->check() && ! Setting::get('api_upload_enabled', true)) {
            return response()->json(['message' => 'Upload endpoints are disabled.'], 403);
        }

        $this->authorize('create', Asset::class);

        $request->validate([
            'filename' => 'required|string|max:255',
            'mime_type' => 'required|string',
            'file_size' => 'required|integer|min:1|max:524288000', // 500MB in bytes
            'folder' => 'nullable|string|max:255',
            'keep_original_filename' => 'nullable|boolean',
        ]);

        $folder = $request->input('folder', S3Service::getRootFolder());
        $keepOriginalFilename = $request->boolean('keep_original_filename');

        try {
            $session = $this->chunkedUploadService->initiateUpload(
                $request->filename,
                $request->mime_type,
                $request->file_size,
                Auth::id(),
                $folder,
                $keepOriginalFilename
            );

            return response()->json([
                'session_token' => $session->session_token,
                'upload_id' => $session->upload_id,
                'chunk_size' => $session->chunk_size,
                'total_chunks' => $session->total_chunks,
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to initiate chunked upload', [
                'filename' => $request->filename,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to initialize upload: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload a single chunk
     * POST /api/chunked-upload/chunk
     */
    public function uploadChunk(Request $request)
    {
        $this->authorize('create', Asset::class);

        $request->validate([
            'session_token' => 'required|string',
            'chunk_number' => 'required|integer|min:1',
            'chunk' => 'required|file|max:15360', // 15MB (safety margin under 16MB)
        ]);

        try {
            $session = UploadSession::where('session_token', $request->session_token)
                ->where('user_id', Auth::id())
                ->firstOrFail();

            // Prevent duplicate chunk uploads (idempotent)
            $existingParts = collect($session->part_etags ?? []);
            if ($existingParts->where('PartNumber', $request->chunk_number)->isNotEmpty()) {
                return response()->json([
                    'message' => 'Chunk already uploaded',
                    'uploaded_chunks' => $session->uploaded_chunks,
                    'total_chunks' => $session->total_chunks,
                ]);
            }

            $result = $this->chunkedUploadService->uploadChunk(
                $session,
                $request->file('chunk'),
                $request->chunk_number
            );

            return response()->json([
                'message' => 'Chunk uploaded successfully',
                'part_number' => $result['PartNumber'],
                'etag' => $result['ETag'],
                'uploaded_chunks' => $session->fresh()->uploaded_chunks,
                'total_chunks' => $session->total_chunks,
            ]);

        } catch (\Exception $e) {
            Log::error('Chunk upload failed', [
                'session_token' => $request->session_token,
                'chunk_number' => $request->chunk_number,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Chunk upload failed: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Complete the chunked upload
     * POST /api/chunked-upload/complete
     */
    public function complete(Request $request)
    {
        $this->authorize('create', Asset::class);

        $request->validate([
            'session_token' => 'required|string',
            'metadata_tags' => 'nullable|array',
            'metadata_tags.*' => 'string|max:100',
            'metadata_reference_tag_ids' => 'nullable|array',
            'metadata_reference_tag_ids.*' => [
                'integer',
                Rule::exists('tags', 'id')->where(fn ($q) => $q->where('type', 'reference')),
            ],
            'metadata_license_type' => ['nullable', 'string', Rule::in(array_keys(Asset::licenseTypes()))],
            'metadata_copyright' => 'nullable|string|max:500',
            'metadata_copyright_source' => 'nullable|string|max:500',
        ]);

        try {
            $session = UploadSession::where('session_token', $request->session_token)
                ->where('user_id', Auth::id())
                ->firstOrFail();

            // Complete the multipart upload and create Asset
            $asset = $this->chunkedUploadService->completeUpload($session);

            // Generate thumbnail, resized images, and AI tags
            $this->assetProcessingService->processImageAsset($asset);

            // Apply batch upload metadata
            $this->assetProcessingService->applyUploadMetadata(
                $asset,
                $request->input('metadata_tags'),
                $request->input('metadata_license_type'),
                $request->input('metadata_copyright'),
                $request->input('metadata_copyright_source'),
                $request->input('metadata_reference_tag_ids'),
            );

            return response()->json([
                'message' => 'Upload completed successfully',
                'asset' => $asset->load('tags'),
            ]);

        } catch (DuplicateAssetException $e) {
            return response()->json([
                'message' => 'Duplicate file detected. This file already exists in the library.',
                'duplicates' => [[
                    'filename' => $session->filename ?? null,
                    'existing_asset_id' => $e->existingAsset->id,
                    'existing_asset_url' => $e->existingAsset->trashed() ? null : $e->existingAsset->url,
                ]],
            ], 409);

        } catch (\Exception $e) {
            Log::error('Upload completion failed', [
                'session_token' => $request->session_token,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Upload completion failed: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Abort chunked upload
     * POST /api/chunked-upload/abort
     */
    public function abort(Request $request)
    {
        $this->authorize('create', Asset::class);

        $request->validate([
            'session_token' => 'required|string',
        ]);

        try {
            $session = UploadSession::where('session_token', $request->session_token)
                ->where('user_id', Auth::id())
                ->firstOrFail();

            $this->chunkedUploadService->abortUpload($session);

            return response()->json([
                'message' => 'Upload aborted successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Upload abort failed', [
                'session_token' => $request->session_token,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to abort upload: '.$e->getMessage(),
            ], 500);
        }
    }
}
