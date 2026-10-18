<?php

namespace App\Services;

use App\Exceptions\DuplicateAssetException;
use App\Models\Asset;
use App\Models\UploadSession;
use App\Support\UploadPolicy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Session bookkeeping for S3 multipart uploads. Every S3 call goes through S3Service, so the
 * configured endpoint, timeouts and upload hardening apply here too (s3-storage
 * REQ-sole-s3-gateway).
 */
class ChunkedUploadService
{
    /** The part size the server dictates; init returns it and the uploader slices by it. */
    public const CHUNK_SIZE = 10 * 1024 * 1024;

    public function __construct(
        protected S3Service $s3Service,
        protected AssetIngestionService $ingestion,
    ) {}

    /**
     * Initialize multipart upload session
     */
    public function initiateUpload(
        string $filename,
        int $fileSize,
        int $userId,
        ?string $folder = null,
        bool $keepOriginalFilename = false
    ): UploadSession {
        // Generate S3 key with folder support
        $folder = $folder ?? S3Service::getRootFolder();
        $folder = rtrim($folder, '/');

        if ($keepOriginalFilename) {
            $safeName = S3Service::sanitizeFilename($filename);
        } else {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $safeName = Str::uuid().($extension ? '.'.$extension : '');
        }

        $s3Key = $folder !== '' ? $folder.'/'.$safeName : $safeName;
        $sessionToken = (string) Str::uuid();

        // An SVG assembles on a staging key and only its sanitized content ever reaches $s3Key,
        // so a sanitization failure can't cost an existing asset (overwrite mode) its original.
        $stagingKey = UploadPolicy::isSvg($s3Key) ? S3Service::CHUNKED_STAGING_PREFIX.$sessionToken.'.svg' : null;

        // The staging key keeps the extension, so S3Service derives the same Content-Type and
        // Content-Disposition as it would for $s3Key. No client-declared type is involved.
        $uploadId = $this->s3Service->createMultipartUpload($stagingKey ?? $s3Key);

        $chunkSize = self::CHUNK_SIZE;
        $totalChunks = (int) ceil($fileSize / $chunkSize);

        // Create database record
        $session = UploadSession::create([
            'upload_id' => $uploadId,
            'session_token' => $sessionToken,
            'filename' => $filename,
            'mime_type' => UploadPolicy::contentType($filename),
            'file_size' => $fileSize,
            's3_key' => $s3Key,
            'staging_s3_key' => $stagingKey,
            'chunk_size' => $chunkSize,
            'total_chunks' => $totalChunks,
            'user_id' => $userId,
            'status' => 'pending',
            'last_activity_at' => now(),
        ]);

        Log::info('Chunked upload initiated', [
            'session_token' => $session->session_token,
            'filename' => $filename,
            'file_size' => $fileSize,
            'total_chunks' => $totalChunks,
        ]);

        return $session;
    }

    /**
     * Upload a single chunk. Idempotent: a part the session already recorded is not re-sent
     * to S3, and the result carries `skipped => true`.
     *
     * @return array{PartNumber: int, ETag: string, skipped: bool}
     */
    public function uploadChunk(
        UploadSession $session,
        UploadedFile $chunk,
        int $chunkNumber
    ): array {
        // Validate chunk number
        if ($chunkNumber < 1 || $chunkNumber > $session->total_chunks) {
            throw new \InvalidArgumentException("Invalid chunk number: {$chunkNumber}");
        }

        $recorded = collect($session->part_etags ?? [])->firstWhere('PartNumber', $chunkNumber);
        if ($recorded !== null) {
            return ['PartNumber' => $chunkNumber, 'ETag' => $recorded['ETag'], 'skipped' => true];
        }

        try {
            // Deliberately outside the transaction below: holding the row lock through a 10MB
            // PUT would serialize every chunk, and re-uploading a PartNumber is idempotent in S3.
            $etag = $this->s3Service->uploadPart(
                $session->multipartKey(),
                $session->upload_id,
                $chunkNumber,
                $chunk->getRealPath()
            );

            // Merge under a row lock, keyed by PartNumber, and derive the count from the merged
            // parts. Appending to the in-memory copy and writing `uploaded_chunks + 1` lost a
            // part (or an increment) whenever two chunks for one session landed concurrently.
            $locked = DB::transaction(function () use ($session, $chunkNumber, $etag) {
                $locked = UploadSession::whereKey($session->getKey())->lockForUpdate()->firstOrFail();

                $parts = collect($locked->part_etags ?? [])
                    ->keyBy('PartNumber')
                    ->put($chunkNumber, ['PartNumber' => $chunkNumber, 'ETag' => $etag])
                    ->sortKeys()
                    ->values()
                    ->all();

                $locked->update([
                    'part_etags' => $parts,
                    'uploaded_chunks' => count($parts),
                    'status' => 'uploading',
                    'last_activity_at' => now(),
                ]);

                return $locked;
            });

            $session->setRawAttributes($locked->getAttributes(), true);

            return ['PartNumber' => $chunkNumber, 'ETag' => $etag, 'skipped' => false];

        } catch (\Exception $e) {
            Log::error('Chunk upload failed', [
                'session_token' => $session->session_token,
                'chunk_number' => $chunkNumber,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Complete the multipart upload and create Asset
     *
     * The @throws is load-bearing, not decoration: ChunkedUploadController::complete() catches
     * DuplicateAssetException specifically, and without this declaration static analysis concludes
     * nothing in that try block can throw it — so it treats the handler as reachable only from
     * before the UploadSession lookup, and every read of $session there as a read of null.
     *
     * @throws DuplicateAssetException when an asset with the same etag already exists
     */
    public function completeUpload(UploadSession $session): Asset
    {
        if ($session->uploaded_chunks !== $session->total_chunks) {
            throw new \RuntimeException(
                "Not all chunks uploaded: {$session->uploaded_chunks}/{$session->total_chunks}"
            );
        }

        try {
            $etag = $this->s3Service->completeMultipartUpload(
                $session->multipartKey(),
                $session->upload_id,
                $session->part_etags ?? []
            );

            // SVGs are sanitized after assembly (the >=10MB chunked path; smaller
            // SVGs take the direct path). Re-puts the cleaned content, which
            // changes the ETag, so this must run before the dedup check below.
            if (UploadPolicy::isSvg($session->s3_key)) {
                $assembledKey = $session->multipartKey();

                // From the staging key onto the asset key: s3_key is only ever written with
                // sanitized content. A session from before staging keys sanitizes in place.
                $newEtag = $this->s3Service->sanitizeStoredSvg($assembledKey, $session->s3_key);

                // The staging object has served its purpose either way. On failure it is the raw
                // upload, which must not survive; for a legacy session it is s3_key itself, and
                // in overwrite mode that raw upload has already replaced the existing original.
                if ($newEtag === null || $assembledKey !== $session->s3_key) {
                    $this->s3Service->deleteFile($assembledKey);
                }

                if ($newEtag === null) {
                    $session->update(['status' => 'failed']);

                    throw new \RuntimeException('SVG could not be sanitized.');
                }
                $etag = $newEtag;
            }

            // An existing row at this key is an overwrite (original filename mode), which also
            // switches the duplicate check off — duplicate-detection.md REQ-2.
            $existingByKey = $this->ingestion->existingAtKey($session->s3_key);

            $existing = $this->ingestion->findDuplicate($etag, $existingByKey);
            if ($existing) {
                $this->ingestion->discardDuplicate($session->s3_key, $existing, $session->filename);
                $session->update(['status' => 'failed']);

                throw new DuplicateAssetException($existing);
            }

            $dimensions = $this->s3Service->extractImageDimensions($session->s3_key, $session->mime_type) ?? [];

            if ($existingByKey) {
                // Overwrite: the old derived files describe the old bytes
                $this->s3Service->deleteAssetFiles($existingByKey, keepOriginal: true);
            }

            $asset = $this->ingestion->persist([
                's3_key' => $session->s3_key,
                'filename' => $session->filename,
                'mime_type' => $session->mime_type,
                'size' => $session->file_size,
                'etag' => $etag,
                'width' => $dimensions['width'] ?? null,
                'height' => $dimensions['height'] ?? null,
                'user_id' => $session->user_id,
            ], $existingByKey);

            // Mark session as completed
            $session->update(['status' => 'completed']);

            Log::info('Chunked upload completed', [
                'session_token' => $session->session_token,
                'asset_id' => $asset->id,
            ]);

            return $asset;

        } catch (\Exception $e) {
            Log::error('Upload completion failed', [
                'session_token' => $session->session_token,
                'error' => $e->getMessage(),
            ]);

            // Try to abort the multipart upload on failure
            try {
                $this->abortUpload($session);
            } catch (\Exception $abortError) {
                Log::error('Failed to abort upload after completion error', [
                    'session_token' => $session->session_token,
                    'error' => $abortError->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    /**
     * Abort multipart upload and cleanup
     */
    public function abortUpload(UploadSession $session): void
    {
        try {
            $this->s3Service->abortMultipartUpload($session->multipartKey(), $session->upload_id);

            $session->update(['status' => 'aborted']);

            Log::info('Upload aborted', [
                'session_token' => $session->session_token,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to abort upload', [
                'session_token' => $session->session_token,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
