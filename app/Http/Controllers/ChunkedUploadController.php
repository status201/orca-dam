<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\UploadSession;
use App\Services\AssetProcessingService;
use App\Services\ChunkedUploadService;
use App\Services\S3Service;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

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
        $this->authorize('create', Asset::class);

        $request->validate([
            'filename' => 'required|string|max:255',
            'mime_type' => 'required|string',
            'file_size' => 'required|integer|min:1|max:524288000', // 500MB in bytes
            'folder' => 'nullable|string|max:255',
        ]);

        $folder = $request->input('folder', S3Service::getRootFolder());

        try {
            $session = $this->chunkedUploadService->initiateUpload(
                $request->filename,
                $request->mime_type,
                $request->file_size,
                Auth::id(),
                $folder
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
        ]);

        try {
            $session = UploadSession::where('session_token', $request->session_token)
                ->where('user_id', Auth::id())
                ->firstOrFail();

            // Complete the multipart upload and create Asset
            $asset = $this->chunkedUploadService->completeUpload($session);

            // Generate thumbnail, resized images, and AI tags
            $this->assetProcessingService->processImageAsset($asset);

            return response()->json([
                'message' => 'Upload completed successfully',
                'asset' => $asset->load('tags'),
            ]);

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
