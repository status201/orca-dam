<?php

namespace App\Http\Controllers;

use App\Exceptions\DuplicateAssetException;
use App\Models\Asset;
use App\Models\Setting;
use App\Models\UploadSession;
use App\Rules\AllowedUploadExtension;
use App\Services\AssetProcessingService;
use App\Services\ChunkedUploadService;
use App\Services\S3Service;
use App\Support\ColumnLimits;
use App\Support\UploadMetadata;
use App\Support\UploadMetadataRules;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ChunkedUploadController extends Controller
{
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
            return response()->json(['message' => __('Upload endpoints are disabled.')], 403);
        }

        $this->authorize('create', Asset::class);

        $request->validate([
            'filename' => ['required', 'string', 'max:'.ColumnLimits::for('assets', 'filename'), new AllowedUploadExtension],
            // Accepted so existing clients keep working, but never trusted: the stored and served
            // type comes from the filename's extension (upload-policy REQ-content-type-from-extension).
            // Browsers also send an empty type for some extensions, which used to fail here.
            'mime_type' => 'nullable|string',
            'file_size' => 'required|integer|min:1|max:524288000', // 500MB in bytes
            // 100, matching FolderController's creation cap: folder and filename both feed s3_key,
            // now varchar(1024). The cap stays to keep the folder LIKE range inside
            // assets_folder_filter_index's 255-character prefix.
            'folder' => 'nullable|string|max:100',
            'keep_original_filename' => 'nullable|boolean',
        ]);

        $folder = $request->input('folder', S3Service::getRootFolder());
        $keepOriginalFilename = $request->boolean('keep_original_filename');

        try {
            $session = $this->chunkedUploadService->initiateUpload(
                $request->filename,
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
                'message' => $this->clientError($e, 'Failed to initialize upload.'),
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
            $session = $this->findOwnedSession($request->session_token);

            // Idempotent: the service skips S3 for a part the session already recorded.
            $result = $this->chunkedUploadService->uploadChunk(
                $session,
                $request->file('chunk'),
                (int) $request->chunk_number
            );

            if ($result['skipped']) {
                return response()->json([
                    'message' => __('Chunk already uploaded'),
                    'uploaded_chunks' => $session->uploaded_chunks,
                    'total_chunks' => $session->total_chunks,
                ]);
            }

            return response()->json([
                'message' => __('Chunk uploaded successfully'),
                'part_number' => $result['PartNumber'],
                'etag' => $result['ETag'],
                'uploaded_chunks' => $session->uploaded_chunks,
                'total_chunks' => $session->total_chunks,
            ]);

        } catch (ModelNotFoundException) {
            return $this->sessionNotFound();

        } catch (\InvalidArgumentException $e) {
            // The service's own message ("Invalid chunk number: N"), safe for every audience.
            return response()->json(['message' => $e->getMessage()], 422);

        } catch (\Exception $e) {
            Log::error('Chunk upload failed', [
                'session_token' => $request->session_token,
                'chunk_number' => $request->chunk_number,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $this->clientError($e, 'Chunk upload failed.'),
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

        // The shared rule set, not a copy of it. This array used to be a hand-copied duplicate of
        // the upload-metadata rules, which is how the copyright cap drifted past its column here
        // as well as in UpdateAssetRequest. Validating inline rather than via a FormRequest is
        // deliberate: a FormRequest validates before the controller body, which would move the
        // authorize() above it and change which failure a caller sees first.
        $request->validate(array_merge(
            ['session_token' => 'required|string'],
            UploadMetadataRules::rules(),
        ));

        // Declared before the try because the DuplicateAssetException handler below reads it, and
        // the assignment inside the try is itself a throwing call — without this, the `?? null`
        // there would be silently doubling as an undefined-variable guard. Deliberately *not*
        // hoisted out of the try: findOwnedSession()'s ModelNotFoundException is meant to land in its
        // own 404 arm, as in the sibling uploadChunk()/abort() methods.
        $session = null;

        try {
            $session = $this->findOwnedSession($request->session_token);

            // Complete the multipart upload and create Asset
            $asset = $this->chunkedUploadService->completeUpload($session);

            // Generate thumbnail, resized images, and AI tags
            $this->assetProcessingService->processImageAsset($asset);

            $this->assetProcessingService->applyUploadMetadata($asset, UploadMetadata::fromRequest($request));

            return response()->json([
                'message' => __('Upload completed successfully'),
                'asset' => $asset->load('tags'),
            ]);

        } catch (DuplicateAssetException $e) {
            return response()->json([
                'message' => __('Duplicate file detected. This file already exists in the library.'),
                'duplicates' => [DuplicateAssetException::formatDuplicate($e->existingAsset, $session?->filename)],
            ], 409);

        } catch (ModelNotFoundException) {
            return $this->sessionNotFound();

        } catch (\Exception $e) {
            Log::error('Upload completion failed', [
                'session_token' => $request->session_token,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $this->clientError($e, 'Upload completion failed.'),
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
            $session = $this->findOwnedSession($request->session_token);

            $this->chunkedUploadService->abortUpload($session);

            return response()->json([
                'message' => __('Upload aborted successfully'),
            ]);

        } catch (ModelNotFoundException) {
            return $this->sessionNotFound();

        } catch (\Exception $e) {
            Log::error('Upload abort failed', [
                'session_token' => $request->session_token,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $this->clientError($e, 'Failed to abort upload.'),
            ], 500);
        }
    }

    /**
     * The caller's own session for a token. Scoped by user, so another user's token is a
     * ModelNotFoundException exactly like an unknown one — each action maps that to
     * sessionNotFound().
     *
     * @throws ModelNotFoundException
     */
    protected function findOwnedSession(string $sessionToken): UploadSession
    {
        return UploadSession::where('session_token', $sessionToken)
            ->where('user_id', Auth::id())
            ->firstOrFail();
    }

    /**
     * An unknown token and another user's token get the same answer, so a token's existence is
     * not disclosed (chunked-upload REQ-5).
     */
    protected function sessionNotFound(): JsonResponse
    {
        return response()->json(['message' => __('Upload session not found.')], 404);
    }
}
