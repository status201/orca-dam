<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Setting;
use App\Support\UploadPolicy;
use Aws\Result;
use Aws\S3\S3Client;
use enshrined\svgSanitize\Sanitizer;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class S3Service
{
    /**
     * Bucket-root prefix where a chunked SVG assembles before it is sanitized onto its asset key
     * (chunked-upload REQ-4).
     */
    public const CHUNKED_STAGING_PREFIX = 'chunked-staging/';

    /**
     * Bucket-root prefixes ORCA writes for its own purposes, never asset originals: discovery
     * doesn't import from them and the folder list doesn't offer them (REQ-staging-prefix-hidden).
     */
    private const RESERVED_PREFIXES = ['thumbnails/', self::CHUNKED_STAGING_PREFIX];

    protected S3Client $s3Client;

    protected string $bucket;

    protected string $region;

    protected ImageProcessingService $imageProcessingService;

    public function __construct(ImageProcessingService $imageProcessingService)
    {
        $this->bucket = config('filesystems.disks.s3.bucket');
        $this->region = config('filesystems.disks.s3.region');
        $this->imageProcessingService = $imageProcessingService;

        $clientConfig = [
            'version' => 'latest',
            'region' => $this->region,
            'credentials' => [
                'key' => config('filesystems.disks.s3.key'),
                'secret' => config('filesystems.disks.s3.secret'),
            ],
            // Bounded waits (REQ-8). The SDK's default is to wait indefinitely for
            // a response, so an endpoint that completes the handshake and then goes
            // quiet — a half-open proxy, an unrelated service on the configured
            // port — holds the request until PHP's max_execution_time ends it.
            // Failing in seconds is what lets the callers below log and return null.
            'http' => [
                'connect_timeout' => (float) config('filesystems.disks.s3.connect_timeout', 5),
                'timeout' => (float) config('filesystems.disks.s3.timeout', 120),
            ],
        ];

        // An explicit endpoint points the client at an S3-compatible service
        // (the RustFS bucket the E2E suite runs against, an R2/Wasabi-style
        // provider) instead of AWS. Left unset, addressing is unchanged.
        $endpoint = config('filesystems.disks.s3.endpoint');
        if (! empty($endpoint)) {
            $clientConfig['endpoint'] = $endpoint;
            $clientConfig['use_path_style_endpoint'] = (bool) config('filesystems.disks.s3.use_path_style_endpoint', false);
        }

        $this->s3Client = new S3Client($clientConfig);
    }

    /**
     * Swap the underlying S3 client. Primarily a test seam for mocking AWS calls.
     */
    public function setS3Client(S3Client $client): static
    {
        $this->s3Client = $client;

        return $this;
    }

    /**
     * Get the configured root folder name (trimmed, no slashes).
     * Returns empty string for bucket root.
     */
    public static function getRootFolder(): string
    {
        return trim((string) Setting::get('s3_root_folder', 'assets'), " \t\n\r\0\x0B/");
    }

    /**
     * Get the list of configured S3 folders, normalized with root folder logic.
     */
    public static function getConfiguredFolders(): array
    {
        $rootFolder = static::getRootFolder();
        $folders = Setting::get('s3_folders', $rootFolder !== '' ? [$rootFolder] : []);

        if (empty($folders) && $rootFolder !== '') {
            $folders = [$rootFolder];
        }
        if ($rootFolder === '' && ! in_array('', $folders)) {
            array_unshift($folders, '');
        }

        return $folders;
    }

    /**
     * Get the root folder as a prefix (with trailing slash), or empty string for bucket root.
     */
    public static function getRootPrefix(): string
    {
        $root = self::getRootFolder();

        return $root === '' ? '' : $root.'/';
    }

    /**
     * Upload a file to S3
     */
    public function uploadFile(UploadedFile $file, ?string $directory = null, bool $keepOriginalFilename = false): array
    {
        $directory = $directory ?? self::getRootFolder();
        $filename = $keepOriginalFilename
            ? self::sanitizeFilename($file->getClientOriginalName())
            : $this->generateUniqueFilename($file);
        $s3Key = $directory !== '' ? "{$directory}/{$filename}" : $filename;

        // Get image dimensions before upload if it's an image (to avoid memory issues later)
        $dimensions = $this->getImageDimensions($file);

        $result = $this->putUploadedFile($s3Key, $file);

        return [
            's3_key' => $s3Key,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'etag' => self::etag($result),
            'width' => $dimensions['width'] ?? null,
            'height' => $dimensions['height'] ?? null,
        ];
    }

    /**
     * Stream an uploaded file to S3, applying security hardening:
     *   - Content-Type from the extension (uploadHeaders()),
     *   - Content-Disposition: attachment for non-inline types,
     *   - in-memory SVG sanitization to strip active content.
     *
     * @return Result
     */
    protected function putUploadedFile(string $s3Key, UploadedFile $file)
    {
        $params = $this->uploadHeaders($s3Key);

        // SVGs are sanitized in-memory before storage. They are small enough to
        // load fully; large files (>=10MB) take the chunked path instead.
        if (UploadPolicy::isSvg($s3Key)) {
            $params['Body'] = $this->sanitizeSvg((string) file_get_contents($file->getRealPath()));

            return $this->s3Client->putObject($params);
        }

        // Everything else is streamed to avoid memory issues.
        $stream = fopen($file->getRealPath(), 'r');
        if ($stream === false) {
            throw new \RuntimeException("Failed to open file for upload: {$file->getClientOriginalName()}");
        }

        try {
            $params['Body'] = $stream;

            return $this->s3Client->putObject($params);
        } finally {
            // Always close the stream
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Sanitize SVG markup, stripping scripts, event handlers, and remote
     * references. Throws if the SVG cannot be parsed so the upload fails loudly
     * rather than storing unsanitized content.
     */
    public function sanitizeSvg(string $svg): string
    {
        $sanitizer = new Sanitizer;
        $sanitizer->removeRemoteReferences(true);

        $clean = $sanitizer->sanitize($svg);

        if ($clean === false) {
            throw new \RuntimeException('SVG could not be sanitized.');
        }

        return $clean;
    }

    /**
     * Download an already-stored SVG, sanitize it, and upload the result to $destinationKey
     * (default: back in place). Used by the chunked-upload path, where the object is assembled
     * in S3 — on a staging key — before we can inspect it. Returns the new ETag, or null on
     * failure, in which case nothing has been written to the destination.
     */
    public function sanitizeStoredSvg(string $s3Key, ?string $destinationKey = null): ?string
    {
        try {
            $original = $this->getObjectContent($s3Key);
            if ($original === null) {
                return null;
            }

            $result = $this->s3Client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $destinationKey ?? $s3Key,
                'Body' => $this->sanitizeSvg($original),
                'ContentType' => 'image/svg+xml',
            ]);

            return self::etag($result);
        } catch (\Exception $e) {
            \Log::error('Stored SVG sanitization failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Replace an existing S3 object with a new file (same key, overwrites)
     */
    public function replaceFile(UploadedFile $file, string $existingS3Key): array
    {
        // Get image dimensions before upload if it's an image
        $dimensions = $this->getImageDimensions($file);

        // Upload to S3 (overwrites existing object), with the same hardening as uploadFile().
        $result = $this->putUploadedFile($existingS3Key, $file);

        return [
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'etag' => self::etag($result),
            'width' => $dimensions['width'] ?? null,
            'height' => $dimensions['height'] ?? null,
        ];
    }

    /**
     * Generate a thumbnail and upload to S3
     */
    public function generateThumbnail(string $s3Key): ?string
    {
        try {
            // Skip thumbnail generation for EPS (no reliable cross-server rendering)
            if (str_ends_with(strtolower($s3Key), '.eps')) {
                return null;
            }
            // Skip thumbnail generation for SVG (browsers render them natively)
            if (str_ends_with(strtolower($s3Key), '.svg')) {
                return null;
            }

            // Download original from S3
            $result = $this->s3Client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $s3Key,
            ]);

            $imageContent = (string) $result['Body'];

            $thumbnailContent = $this->imageProcessingService->createThumbnailContent($imageContent, basename($s3Key));

            // Skip animated GIFs (createThumbnailContent returns null)
            if ($thumbnailContent === null) {
                \Log::info("Skipping thumbnail generation for animated GIF: $s3Key");

                return null;
            }

            // Extract folder from s3_key and mirror it for thumbnails
            // e.g., assets/marketing/abc.jpg -> thumbnails/marketing/abc_thumb.jpg
            $rootPrefix = self::getRootPrefix();
            $relativePath = ($rootPrefix !== '' && str_starts_with($s3Key, $rootPrefix))
                ? substr($s3Key, strlen($rootPrefix))
                : $s3Key;
            $folder = dirname($relativePath);
            $folder = ($folder === '.' || $folder === '') ? '' : $folder.'/';
            $thumbnailKey = 'thumbnails/'.$folder.Str::replaceLast('.', '_thumb.', basename($s3Key));

            $this->s3Client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $thumbnailKey,
                'Body' => $thumbnailContent,
                'ContentType' => 'image/jpeg',
            ]);

            return $thumbnailKey;
        } catch (\Exception $e) {
            \Log::error('Thumbnail generation failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Generate resized image variants (S, M, L) and upload to S3
     */
    public function generateResizedImages(string $s3Key): array
    {
        try {
            // Skip non-image extensions
            $extension = strtolower(pathinfo($s3Key, PATHINFO_EXTENSION));
            $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'tif'];
            if (! in_array($extension, $imageExtensions)) {
                return [];
            }

            // Read dimension settings
            $sizes = [
                's' => [
                    'width' => Setting::get('resize_s_width', 250),
                    'height' => Setting::get('resize_s_height', ''),
                ],
                'm' => [
                    'width' => Setting::get('resize_m_width', 600),
                    'height' => Setting::get('resize_m_height', ''),
                ],
                'l' => [
                    'width' => Setting::get('resize_l_width', 1200),
                    'height' => Setting::get('resize_l_height', ''),
                ],
            ];

            // Download original from S3 once
            $result = $this->s3Client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $s3Key,
            ]);
            $imageContent = (string) $result['Body'];

            // Skip animated GIFs to avoid memory issues (static GIFs are fine)
            if ($extension === 'gif' && $this->imageProcessingService->isAnimatedGif($imageContent)) {
                \Log::info("Skipping resize generation for animated GIF: $s3Key");

                return [];
            }

            // Build relative path for S3 key generation (same logic as generateThumbnail)
            $rootPrefix = self::getRootPrefix();
            $relativePath = ($rootPrefix !== '' && str_starts_with($s3Key, $rootPrefix))
                ? substr($s3Key, strlen($rootPrefix))
                : $s3Key;
            $folder = dirname($relativePath);
            $folder = ($folder === '.' || $folder === '') ? '' : $folder.'/';
            $basename = pathinfo(basename($s3Key), PATHINFO_FILENAME);

            $resizedKeys = [];

            foreach ($sizes as $sizeKey => $dimensions) {
                $w = ! empty($dimensions['width']) && is_numeric($dimensions['width']) ? (int) $dimensions['width'] : null;
                $h = ! empty($dimensions['height']) && is_numeric($dimensions['height']) ? (int) $dimensions['height'] : null;

                // Skip if no width AND no height configured
                if ($w === null && $h === null) {
                    continue;
                }

                $result = $this->imageProcessingService->createResizedContent($imageContent, $extension, $w, $h);
                $sizeLabel = strtoupper($sizeKey);
                $resizedKey = "thumbnails/{$sizeLabel}/{$folder}{$basename}.{$result['extension']}";

                $this->s3Client->putObject([
                    'Bucket' => $this->bucket,
                    'Key' => $resizedKey,
                    'Body' => $result['content'],
                    'ContentType' => $result['mime_type'],
                ]);

                $resizedKeys[$sizeKey] = $resizedKey;
            }

            return $resizedKeys;
        } catch (\Exception $e) {
            \Log::error('Resize image generation failed: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Upload raw image data as a thumbnail for a given asset s3_key
     */
    public function uploadThumbnail(string $s3Key, string $imageData): ?string
    {
        try {
            $rootPrefix = self::getRootPrefix();
            $relativePath = ($rootPrefix !== '' && str_starts_with($s3Key, $rootPrefix))
                ? substr($s3Key, strlen($rootPrefix))
                : $s3Key;
            $folder = dirname($relativePath);
            $folder = ($folder === '.' || $folder === '') ? '' : $folder.'/';
            $thumbnailKey = 'thumbnails/'.$folder.Str::replaceLast('.', '_thumb.', basename($s3Key));
            // Ensure thumbnail extension is .jpg
            $thumbnailKey = preg_replace('/\.[^.]+$/', '.jpg', $thumbnailKey);

            $this->s3Client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $thumbnailKey,
                'Body' => $imageData,
                'ContentType' => 'image/jpeg',
            ]);

            return $thumbnailKey;
        } catch (\Exception $e) {
            \Log::error('Thumbnail upload failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Delete resized image variants from S3
     */
    public function deleteResizedImages(Asset $asset): void
    {
        foreach (['resize_s_s3_key', 'resize_m_s3_key', 'resize_l_s3_key'] as $field) {
            if ($asset->{$field}) {
                $this->deleteFile($asset->{$field});
            }
        }
    }

    /**
     * Delete all S3 files belonging to an asset (original, thumbnail, resize variants).
     * Use this instead of repeating the three-step deletion pattern in callers.
     */
    public function deleteAssetFiles(Asset $asset, bool $keepOriginal = false): void
    {
        if (! $keepOriginal) {
            $this->deleteFile($asset->s3_key);
        }

        if ($asset->thumbnail_s3_key) {
            $this->deleteFile($asset->thumbnail_s3_key);
        }

        $this->deleteResizedImages($asset);
    }

    /**
     * Compute the expected thumbnail and resize S3 keys for a given s3_key,
     * mirroring the naming conventions used by uploadThumbnail() and generateResizedImages().
     * Used when moving assets so derived keys can be updated to their new paths.
     *
     * @return array{thumbnail_s3_key: string, resize_s_s3_key: string, resize_m_s3_key: string, resize_l_s3_key: string}
     */
    public function computeDerivedKeys(string $s3Key): array
    {
        $rootPrefix = self::getRootPrefix();
        $relativePath = ($rootPrefix !== '' && str_starts_with($s3Key, $rootPrefix))
            ? substr($s3Key, strlen($rootPrefix))
            : $s3Key;
        $folder = dirname($relativePath);
        $relativeFolder = ($folder === '.' || $folder === '') ? '' : $folder.'/';

        $basename = pathinfo(basename($s3Key), PATHINFO_FILENAME);
        $extension = strtolower(pathinfo($s3Key, PATHINFO_EXTENSION));

        $thumbnailKey = 'thumbnails/'.$relativeFolder.Str::replaceLast('.', '_thumb.', basename($s3Key));
        $thumbnailKey = preg_replace('/\.[^.]+$/', '.jpg', $thumbnailKey);

        $outputExtension = in_array($extension, ['jpg', 'jpeg', 'png', 'webp']) ? $extension : 'jpg';

        return [
            'thumbnail_s3_key' => $thumbnailKey,
            'resize_s_s3_key' => "thumbnails/S/{$relativeFolder}{$basename}.{$outputExtension}",
            'resize_m_s3_key' => "thumbnails/M/{$relativeFolder}{$basename}.{$outputExtension}",
            'resize_l_s3_key' => "thumbnails/L/{$relativeFolder}{$basename}.{$outputExtension}",
        ];
    }

    /**
     * Move (copy + delete) an S3 object to a new key
     */
    public function moveObject(string $sourceKey, string $destinationKey): bool
    {
        try {
            $this->s3Client->copyObject([
                'Bucket' => $this->bucket,
                'CopySource' => "{$this->bucket}/{$sourceKey}",
                'Key' => $destinationKey,
            ]);
        } catch (\Exception $e) {
            \Log::error("S3 copy failed ({$sourceKey} -> {$destinationKey}): ".$e->getMessage());

            return false;
        }

        try {
            $this->s3Client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $sourceKey,
            ]);
        } catch (\Exception $e) {
            \Log::warning("S3 delete after copy failed ({$sourceKey}): ".$e->getMessage());
        }

        return true;
    }

    /**
     * Open a multipart upload (chunked-upload's only way into S3 — REQ-sole-s3-gateway) and return
     * its UploadId, with the same headers as the direct path (uploadHeaders(), REQ-3).
     *
     * The four multipart methods throw instead of logging and returning null (ADR-010's exception):
     * ChunkedUploadService orchestrates the abort and needs to know which step failed.
     */
    public function createMultipartUpload(string $s3Key): string
    {
        return $this->s3Client->createMultipartUpload($this->uploadHeaders($s3Key))['UploadId'];
    }

    /**
     * Bucket, key and serving headers for an object an uploader supplied, shared by the direct and
     * multipart paths. The Content-Type is the extension's (upload-policy
     * REQ-content-type-from-extension), not the client's claim or the sniffed bytes: an uploader
     * controls both, and either once let text/html sit behind an inline extension. Types that
     * should never render inline are forced to download (upload-policy REQ-5).
     *
     * @return array{Bucket: string, Key: string, ContentType: string, ContentDisposition?: string}
     */
    private function uploadHeaders(string $s3Key): array
    {
        $params = [
            'Bucket' => $this->bucket,
            'Key' => $s3Key,
            'ContentType' => UploadPolicy::contentType($s3Key),
        ];
        if (! UploadPolicy::isInline($s3Key)) {
            $params['ContentDisposition'] = 'attachment';
        }

        return $params;
    }

    /**
     * Stream one local file into a multipart upload as part $partNumber; returns the part's ETag.
     */
    public function uploadPart(string $s3Key, string $uploadId, int $partNumber, string $path): string
    {
        $stream = fopen($path, 'r');
        if ($stream === false) {
            throw new \RuntimeException("Failed to open chunk for upload: {$path}");
        }

        try {
            return (string) self::etag($this->s3Client->uploadPart([
                'Bucket' => $this->bucket,
                'Key' => $s3Key,
                'UploadId' => $uploadId,
                'PartNumber' => $partNumber,
                'Body' => $stream,
            ]));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Assemble the uploaded parts into the object; returns its ETag. Parts may arrive in any order —
     * S3 requires them ascending by PartNumber, so they are sorted here.
     *
     * @param  list<array{PartNumber: int, ETag: string}>  $parts
     */
    public function completeMultipartUpload(string $s3Key, string $uploadId, array $parts): ?string
    {
        return self::etag($this->s3Client->completeMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $s3Key,
            'UploadId' => $uploadId,
            'MultipartUpload' => [
                'Parts' => collect($parts)->sortBy('PartNumber')->values()->all(),
            ],
        ]));
    }

    /**
     * Abort a multipart upload so S3 discards its parts.
     */
    public function abortMultipartUpload(string $s3Key, string $uploadId): void
    {
        $this->s3Client->abortMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $s3Key,
            'UploadId' => $uploadId,
        ]);
    }

    /**
     * Delete a file from S3
     */
    public function deleteFile(string $s3Key): bool
    {
        try {
            $this->s3Client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $s3Key,
            ]);

            return true;
        } catch (\Exception $e) {
            \Log::error('S3 deletion failed: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Dimensions of a stored image, as displayed (image-processing REQ-4). The object is streamed
     * to a temp file and sized from its header there, never decoded or held in memory whole —
     * chunked uploads and discovery both land here, with objects up to 500MB.
     *
     * @return array{width?: int, height?: int}|null null for a non-image or an image without
     *                                               raster dimensions (EPS, SVG, unreadable)
     */
    public function extractImageDimensions(string $s3Key, string $mimeType): ?array
    {
        // Decided before the download: neither a non-image nor a vector is worth fetching.
        if (! str_starts_with($mimeType, 'image/') || ! $this->imageProcessingService->hasRasterDimensions($s3Key)) {
            return null;
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'orca_dim_');

        try {
            $body = Utils::streamFor($this->s3Client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $s3Key,
            ])['Body']);

            $out = fopen($tempPath, 'wb');
            while (! $body->eof()) {
                fwrite($out, $body->read(1024 * 1024));
            }
            fclose($out);

            return $this->imageProcessingService->getImageDimensionsFromPath($tempPath, $s3Key) ?: null;
        } catch (\Exception $e) {
            \Log::warning("Failed to extract image dimensions for {$s3Key}: ".$e->getMessage());

            return null;
        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * List all objects in the bucket with optional prefix
     */
    public function listObjects(string $prefix = '', ?int $maxKeys = null): array
    {
        try {
            $objects = [];
            $continuationToken = null;

            do {
                $params = [
                    'Bucket' => $this->bucket,
                    'Prefix' => $prefix,
                    'MaxKeys' => $maxKeys ?? 1000,
                ];
                if ($continuationToken !== null) {
                    $params['ContinuationToken'] = $continuationToken;
                }

                $result = $this->s3Client->listObjectsV2($params);

                foreach ($result['Contents'] ?? [] as $object) {
                    $objects[] = [
                        'key' => $object['Key'],
                        'size' => $object['Size'],
                        'last_modified' => $object['LastModified'],
                    ];
                }

                // A maxKeys cap means "bounded probe" — return just the first page.
                // Otherwise follow ContinuationToken until the listing is exhausted.
                $continuationToken = ($maxKeys === null && ($result['IsTruncated'] ?? false))
                    ? ($result['NextContinuationToken'] ?? null)
                    : null;
            } while ($continuationToken !== null);

            return $objects;
        } catch (\Exception $e) {
            \Log::error('S3 list objects failed: '.$e->getMessage());

            return [];
        }
    }

    /**
     * The ETag of an S3 response, without the surrounding quotes S3 sends — the form stored in
     * assets.etag and compared for duplicate detection.
     *
     * @param  Result|array<string, mixed>  $result
     */
    private static function etag(Result|array $result): ?string
    {
        return isset($result['ETag']) ? trim($result['ETag'], '"') : null;
    }

    /**
     * Whether a key or prefix sits under one of ORCA's own bucket-root prefixes.
     */
    private static function isReservedKey(string $key): bool
    {
        return Str::startsWith($key, self::RESERVED_PREFIXES);
    }

    /**
     * Find objects in S3 that are not in the database
     *
     * @param  string|null  $prefix  Optional prefix to scan (defaults to root prefix)
     */
    public function findUnmappedObjects(?string $prefix = null): array
    {
        $prefix = $prefix ?? self::getRootPrefix();
        $s3Objects = $this->listObjects($prefix);
        $mappedKeys = Asset::pluck('s3_key')->toArray();

        return collect($s3Objects)
            ->filter(function ($object) use ($mappedKeys) {
                return ! in_array($object['key'], $mappedKeys)
                    && ! str_contains($object['key'], '/thumbnails/')
                    && ! self::isReservedKey($object['key'])
                    && $object['size'] > 0;  // Exclude zero-byte folder markers
            })
            ->values()
            ->toArray();
    }

    /**
     * List all folder prefixes in S3 under the given prefix
     */
    public function listFolders(?string $prefix = null): array
    {
        $rootFolder = self::getRootFolder();
        $rootPrefix = self::getRootPrefix();
        $prefix = $prefix ?? $rootPrefix;

        try {
            $folders = $rootFolder !== '' ? [$rootFolder] : [];
            $continuationToken = null;

            do {
                // Use delimiter to get common prefixes (folders)
                $params = [
                    'Bucket' => $this->bucket,
                    'Prefix' => $prefix,
                    'Delimiter' => '/',
                ];
                if ($continuationToken !== null) {
                    $params['ContinuationToken'] = $continuationToken;
                }

                $result = $this->s3Client->listObjectsV2($params);

                // Get subfolders from common prefixes
                foreach ($result['CommonPrefixes'] ?? [] as $commonPrefix) {
                    $folderPath = rtrim($commonPrefix['Prefix'], '/');
                    if ($folderPath !== $rootFolder && ! empty($folderPath) && ! self::isReservedKey($commonPrefix['Prefix'])) {
                        if (! in_array($folderPath, $folders)) {
                            $folders[] = $folderPath;
                        }

                        // Recursively get nested folders
                        $nestedFolders = $this->listFolders($commonPrefix['Prefix']);
                        foreach ($nestedFolders as $nested) {
                            if (! in_array($nested, $folders)) {
                                $folders[] = $nested;
                            }
                        }
                    }
                }

                $continuationToken = ($result['IsTruncated'] ?? false)
                    ? ($result['NextContinuationToken'] ?? null)
                    : null;
            } while ($continuationToken !== null);

            sort($folders);

            return array_values(array_unique($folders));
        } catch (\Exception $e) {
            \Log::error('S3 list folders failed: '.$e->getMessage());

            return $rootFolder !== '' ? [$rootFolder] : [];
        }
    }

    /**
     * Create a folder marker in S3
     */
    public function createFolder(string $folderPath): bool
    {
        try {
            $folderKey = rtrim($folderPath, '/').'/';

            $this->s3Client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $folderKey,
                'Body' => '',
                'ContentType' => 'application/x-directory',
            ]);

            return true;
        } catch (\Exception $e) {
            \Log::error('S3 create folder failed: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Get object metadata from S3
     */
    public function getObjectMetadata(string $s3Key): ?array
    {
        try {
            $result = $this->s3Client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $s3Key,
            ]);

            return [
                'size' => $result['ContentLength'],
                'mime_type' => $result['ContentType'],
                'last_modified' => $result['LastModified'],
                'etag' => self::etag($result),
            ];
        } catch (\Exception $e) {
            \Log::error('Get S3 metadata failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Generate a unique filename
     */
    protected function generateUniqueFilename(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();

        return Str::uuid().'.'.$extension;
    }

    /**
     * Sanitize a filename for use as an S3 key.
     * Keeps the original name readable but URL-safe.
     */
    public static function sanitizeFilename(string $filename): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $name = pathinfo($filename, PATHINFO_FILENAME);

        // Replace spaces and underscores with hyphens
        $name = preg_replace('/[\s_]+/', '-', $name);

        // Remove anything that's not alphanumeric, hyphens, or dots
        $name = preg_replace('/[^a-zA-Z0-9\-.]/', '', $name);

        // Collapse multiple hyphens
        $name = preg_replace('/-{2,}/', '-', $name);

        // Trim hyphens from ends
        $name = trim($name, '-');

        // Ensure we have a name
        if ($name === '') {
            $name = Str::uuid();
        }

        return $extension !== '' ? "{$name}.{$extension}" : $name;
    }

    /**
     * Sanitise a filename and force it to carry the given extension, falling
     * back to $fallback when sanitising leaves nothing but the extension.
     */
    public static function ensureExtension(string $filename, string $ext, string $fallback): string
    {
        $filename = self::sanitizeFilename($filename);

        if (! str_ends_with(strtolower($filename), '.'.$ext)) {
            $filename = pathinfo($filename, PATHINFO_FILENAME).'.'.$ext;

            if ($filename === '.'.$ext) {
                $filename = $fallback;
            }
        }

        return $filename;
    }

    /**
     * Get image dimensions — delegates to ImageProcessingService
     */
    protected function getImageDimensions(UploadedFile $file): array
    {
        return $this->imageProcessingService->getImageDimensions($file);
    }

    /**
     * Get the public base URL for asset URLs.
     * Uses custom domain setting if configured, otherwise falls back to S3 bucket URL.
     */
    public static function getPublicBaseUrl(): string
    {
        $customDomain = Setting::get('custom_domain', '');
        if ($customDomain !== '' && $customDomain !== null) {
            return rtrim($customDomain, '/');
        }

        return config('filesystems.disks.s3.url');
    }

    /**
     * Get the public URL for an S3 key
     */
    public function getUrl(string $s3Key): string
    {
        return self::getPublicBaseUrl().'/'.$s3Key;
    }

    /**
     * Get object content from S3
     */
    public function getObjectContent(string $s3Key): ?string
    {
        try {
            $result = $this->s3Client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $s3Key,
            ]);

            return (string) $result['Body'];
        } catch (\Exception $e) {
            \Log::error('Get S3 object content failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Get the S3 bucket endpoint address.
     */
    public function getBucketEndpoint(): string
    {
        return "{$this->bucket}.s3.{$this->region}.amazonaws.com";
    }

    /**
     * Check if bucket versioning is enabled.
     *
     * @return string|null 'Enabled', 'Suspended', or null (not configured / error)
     */
    public function getBucketVersioning(): ?string
    {
        try {
            $result = $this->s3Client->getBucketVersioning([
                'Bucket' => $this->bucket,
            ]);

            return $result['Status'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
