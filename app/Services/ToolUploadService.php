<?php

namespace App\Services;

use App\Models\Asset;
use Illuminate\Http\UploadedFile;

/**
 * Shared upload pipeline for the /tools endpoints (MathML, TikZ SVG/PNG, GIF,
 * TeX templates). Each tool produces raw string/binary content in-memory; this
 * service owns the temp-file lifecycle, the S3 upload, the Asset row, and the
 * optional post-processing + metadata steps so the controller no longer repeats
 * the same block per tool.
 */
class ToolUploadService
{
    public function __construct(
        protected S3Service $s3Service,
        protected AssetProcessingService $assetProcessingService,
    ) {}

    /**
     * Upload raw content to S3 and create the backing Asset.
     *
     * @param  string  $content  Raw file contents (text or binary).
     * @param  string  $filename  Final (already sanitised + extensioned) filename.
     * @param  array<string, mixed>  $attributes  Extra Asset columns (user_id, width, height, parent_id, alt_text, caption).
     * @param  bool  $process  Run AssetProcessingService::processImageAsset() (thumbnail/resizes/AI tagging).
     * @param  array<string, mixed>|null  $metadata  Batch metadata for applyUploadMetadata(), or null to skip.
     */
    public function store(
        string $content,
        string $filename,
        string $mimeType,
        string $folder,
        array $attributes = [],
        bool $process = true,
        ?array $metadata = null,
    ): Asset {
        $tmpPath = tempnam(sys_get_temp_dir(), 'orca_tool_');
        file_put_contents($tmpPath, $content);

        try {
            $uploadedFile = new UploadedFile($tmpPath, $filename, $mimeType, null, true);
            $fileData = $this->s3Service->uploadFile($uploadedFile, $folder, keepOriginalFilename: false);

            $asset = Asset::create(array_merge([
                's3_key' => $fileData['s3_key'],
                'filename' => $filename,
                'mime_type' => $mimeType,
                'size' => $fileData['size'],
                'etag' => $fileData['etag'] ?? null,
            ], $attributes));

            if ($process) {
                $this->assetProcessingService->processImageAsset($asset, dispatchAiTagging: true);
            }

            if ($metadata !== null) {
                $this->assetProcessingService->applyUploadMetadata(
                    $asset,
                    $metadata['tags'] ?? null,
                    $metadata['license_type'] ?? null,
                    $metadata['copyright'] ?? null,
                    $metadata['copyright_source'] ?? null,
                    $metadata['reference_tag_ids'] ?? null,
                );
            }

            return $asset;
        } finally {
            @unlink($tmpPath);
        }
    }
}
