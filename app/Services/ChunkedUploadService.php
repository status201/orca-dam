<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\UploadSession;
use Aws\S3\S3Client;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ChunkedUploadService
{
    protected S3Client $s3Client;
    protected string $bucket;
    protected S3Service $s3Service;

    public function __construct(S3Service $s3Service)
    {
        $this->s3Service = $s3Service;
        $this->bucket = config('filesystems.disks.s3.bucket');

        $this->s3Client = new S3Client([
            'version' => 'latest',
            'region' => config('filesystems.disks.s3.region'),
            'credentials' => [
                'key' => config('filesystems.disks.s3.key'),
                'secret' => config('filesystems.disks.s3.secret'),
            ],
        ]);
    }

    /**
     * Determine if file should use chunked upload
     */
    public static function shouldUseChunkedUpload(int $fileSize): bool
    {
        // Use chunked upload for files >= 10MB
        return $fileSize >= (10 * 1024 * 1024);
    }

    /**
     * Initialize multipart upload session
     */
    public function initiateUpload(
        string $filename,
        string $mimeType,
        int $fileSize,
        int $userId
    ): UploadSession {
        // Generate unique S3 key
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $s3Key = 'assets/' . Str::uuid() . ($extension ? '.' . $extension : '');

        // Initiate S3 multipart upload
        $result = $this->s3Client->createMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $s3Key,
            'ContentType' => $mimeType,
        ]);

        $uploadId = $result['UploadId'];

        // Calculate chunks
        $chunkSize = 10 * 1024 * 1024; // 10MB
        $totalChunks = (int) ceil($fileSize / $chunkSize);

        // Create database record
        $session = UploadSession::create([
            'upload_id' => $uploadId,
            'session_token' => Str::uuid(),
            'filename' => $filename,
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
            's3_key' => $s3Key,
            'chunk_size' => $chunkSize,
            'total_chunks' => $totalChunks,
            'user_id' => $userId,
            'status' => 'pending',
            'last_activity_at' => now(),
        ]);

        Log::info("Chunked upload initiated", [
            'session_token' => $session->session_token,
            'filename' => $filename,
            'file_size' => $fileSize,
            'total_chunks' => $totalChunks,
        ]);

        return $session;
    }

    /**
     * Upload a single chunk
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

        // Stream chunk to S3
        $stream = fopen($chunk->getRealPath(), 'r');

        try {
            $result = $this->s3Client->uploadPart([
                'Bucket' => $this->bucket,
                'Key' => $session->s3_key,
                'UploadId' => $session->upload_id,
                'PartNumber' => $chunkNumber,
                'Body' => $stream,
            ]);

            $etag = trim($result['ETag'], '"');

            // Update session with part ETag
            $partEtags = $session->part_etags ?? [];
            $partEtags[] = [
                'PartNumber' => $chunkNumber,
                'ETag' => $etag,
            ];

            $session->update([
                'part_etags' => $partEtags,
                'uploaded_chunks' => $session->uploaded_chunks + 1,
                'status' => 'uploading',
                'last_activity_at' => now(),
            ]);

            return ['PartNumber' => $chunkNumber, 'ETag' => $etag];

        } catch (\Exception $e) {
            Log::error("Chunk upload failed", [
                'session_token' => $session->session_token,
                'chunk_number' => $chunkNumber,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Complete the multipart upload and create Asset
     */
    public function completeUpload(UploadSession $session): Asset
    {
        if ($session->uploaded_chunks !== $session->total_chunks) {
            throw new \RuntimeException(
                "Not all chunks uploaded: {$session->uploaded_chunks}/{$session->total_chunks}"
            );
        }

        // Sort parts by PartNumber (required by S3)
        $parts = collect($session->part_etags)
            ->sortBy('PartNumber')
            ->values()
            ->toArray();

        try {
            // Complete multipart upload
            $result = $this->s3Client->completeMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $session->s3_key,
                'UploadId' => $session->upload_id,
                'MultipartUpload' => [
                    'Parts' => $parts,
                ],
            ]);

            $etag = trim($result['ETag'], '"');

            // Get dimensions for images (if applicable)
            $dimensions = $this->extractDimensions($session);

            // Create Asset record
            $asset = Asset::create([
                's3_key' => $session->s3_key,
                'filename' => $session->filename,
                'mime_type' => $session->mime_type,
                'size' => $session->file_size,
                'etag' => $etag,
                'width' => $dimensions['width'] ?? null,
                'height' => $dimensions['height'] ?? null,
                'user_id' => $session->user_id,
            ]);

            // Mark session as completed
            $session->update(['status' => 'completed']);

            Log::info("Chunked upload completed", [
                'session_token' => $session->session_token,
                'asset_id' => $asset->id,
            ]);

            return $asset;

        } catch (\Exception $e) {
            Log::error("Upload completion failed", [
                'session_token' => $session->session_token,
                'error' => $e->getMessage(),
            ]);

            // Try to abort the multipart upload on failure
            try {
                $this->abortUpload($session);
            } catch (\Exception $abortError) {
                Log::error("Failed to abort upload after completion error", [
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
            $this->s3Client->abortMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $session->s3_key,
                'UploadId' => $session->upload_id,
            ]);

            $session->update(['status' => 'aborted']);

            Log::info("Upload aborted", [
                'session_token' => $session->session_token,
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to abort upload", [
                'session_token' => $session->session_token,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Extract image dimensions from uploaded S3 object
     */
    protected function extractDimensions(UploadSession $session): array
    {
        // Only extract dimensions for images
        if (!str_starts_with($session->mime_type, 'image/')) {
            return [];
        }

        try {
            // Get object from S3
            $result = $this->s3Client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $session->s3_key,
            ]);

            // Save temporarily to extract dimensions
            $tempPath = tempnam(sys_get_temp_dir(), 'orca_dim_');
            file_put_contents($tempPath, (string) $result['Body']);

            // Use getimagesize for memory efficiency
            $size = @getimagesize($tempPath);
            unlink($tempPath);

            if ($size !== false) {
                return ['width' => $size[0], 'height' => $size[1]];
            }
        } catch (\Exception $e) {
            Log::warning("Failed to extract dimensions", [
                'session_token' => $session->session_token,
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }
}
