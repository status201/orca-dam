<?php

namespace App\Services;

use App\Models\Setting;
use Aws\S3\S3Client;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class S3Service
{
    protected S3Client $s3Client;

    protected string $bucket;

    protected string $region;

    protected ImageManager $imageManager;

    public function __construct()
    {
        $this->bucket = config('filesystems.disks.s3.bucket');
        $this->region = config('filesystems.disks.s3.region');

        $this->s3Client = new S3Client([
            'version' => 'latest',
            'region' => $this->region,
            'credentials' => [
                'key' => config('filesystems.disks.s3.key'),
                'secret' => config('filesystems.disks.s3.secret'),
            ],
        ]);

        // Initialize Intervention Image 3.x
        $this->imageManager = new ImageManager(new Driver);
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
    public function uploadFile(UploadedFile $file, ?string $directory = null): array
    {
        $directory = $directory ?? self::getRootFolder();
        $filename = $this->generateUniqueFilename($file);
        $s3Key = $directory !== '' ? "{$directory}/{$filename}" : $filename;

        // Get image dimensions before upload if it's an image (to avoid memory issues later)
        $dimensions = $this->getImageDimensions($file);

        // Upload to S3 using streaming to avoid memory issues
        $stream = fopen($file->getRealPath(), 'r');
        if ($stream === false) {
            throw new \RuntimeException("Failed to open file for upload: {$file->getClientOriginalName()}");
        }

        try {
            $result = $this->s3Client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $s3Key,
                'Body' => $stream,
                'ContentType' => $file->getMimeType(),
            ]);
        } finally {
            // Always close the stream
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return [
            's3_key' => $s3Key,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'etag' => trim($result['ETag'], '"'), // Remove quotes from ETag
            'width' => $dimensions['width'] ?? null,
            'height' => $dimensions['height'] ?? null,
        ];
    }

    /**
     * Replace an existing S3 object with a new file (same key, overwrites)
     */
    public function replaceFile(UploadedFile $file, string $existingS3Key): array
    {
        // Get image dimensions before upload if it's an image
        $dimensions = $this->getImageDimensions($file);

        // Upload to S3 using streaming (overwrites existing object)
        $stream = fopen($file->getRealPath(), 'r');
        if ($stream === false) {
            throw new \RuntimeException("Failed to open file for upload: {$file->getClientOriginalName()}");
        }

        try {
            $result = $this->s3Client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $existingS3Key,
                'Body' => $stream,
                'ContentType' => $file->getMimeType(),
            ]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return [
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'etag' => trim($result['ETag'], '"'),
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
            // Skip thumbnail generation for GIFs to avoid memory issues
            if (str_ends_with(strtolower($s3Key), '.gif')) {
                \Log::info("Skipping thumbnail generation for GIF: $s3Key");

                return null;
            }

            // Download original from S3
            $result = $this->s3Client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $s3Key,
            ]);

            $imageContent = (string) $result['Body'];

            // Generate thumbnail (300x300 max, maintain aspect ratio)
            $image = $this->imageManager->read($imageContent);
            $image->scale(width: 300, height: 300);

            // Convert to JPEG for consistency
            $thumbnailContent = $image->toJpeg(quality: 80);

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
     * Extract image dimensions from S3 object
     * More efficient than loading full image into memory for processing
     */
    public function extractImageDimensions(string $s3Key, string $mimeType): ?array
    {
        // Skip GIFs - use getimagesize approach from existing code
        if ($mimeType === 'image/gif') {
            try {
                $result = $this->s3Client->getObject([
                    'Bucket' => $this->bucket,
                    'Key' => $s3Key,
                ]);

                $imageData = (string) $result['Body'];
                $image = imagecreatefromstring($imageData);

                if ($image) {
                    $width = imagesx($image);
                    $height = imagesy($image);
                    imagedestroy($image);

                    return ['width' => $width, 'height' => $height];
                }
            } catch (\Exception $e) {
                \Log::warning('Failed to extract GIF dimensions: '.$e->getMessage());
            }

            return null;
        }

        // For other images, use Intervention Image
        try {
            $result = $this->s3Client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $s3Key,
            ]);

            $imageData = (string) $result['Body'];
            $image = $this->imageManager->read($imageData);

            return [
                'width' => $image->width(),
                'height' => $image->height(),
            ];

        } catch (\Exception $e) {
            \Log::warning("Failed to extract image dimensions for {$s3Key}: ".$e->getMessage());

            return null;
        }
    }

    /**
     * List all objects in the bucket with optional prefix
     */
    public function listObjects(string $prefix = '', int $maxKeys = 1000): array
    {
        try {
            $result = $this->s3Client->listObjectsV2([
                'Bucket' => $this->bucket,
                'Prefix' => $prefix,
                'MaxKeys' => $maxKeys,
            ]);

            if (! isset($result['Contents'])) {
                return [];
            }

            return collect($result['Contents'])->map(function ($object) {
                return [
                    'key' => $object['Key'],
                    'size' => $object['Size'],
                    'last_modified' => $object['LastModified'],
                ];
            })->toArray();
        } catch (\Exception $e) {
            \Log::error('S3 list objects failed: '.$e->getMessage());

            return [];
        }
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
        $mappedKeys = \App\Models\Asset::pluck('s3_key')->toArray();

        return collect($s3Objects)
            ->filter(function ($object) use ($mappedKeys) {
                return ! in_array($object['key'], $mappedKeys)
                    && ! str_contains($object['key'], '/thumbnails/')
                    && ! str_starts_with($object['key'], 'thumbnails/')
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

            // Use delimiter to get common prefixes (folders)
            $result = $this->s3Client->listObjectsV2([
                'Bucket' => $this->bucket,
                'Prefix' => $prefix,
                'Delimiter' => '/',
            ]);

            // Get subfolders from common prefixes
            if (isset($result['CommonPrefixes'])) {
                foreach ($result['CommonPrefixes'] as $commonPrefix) {
                    $folderPath = rtrim($commonPrefix['Prefix'], '/');
                    if ($folderPath !== $rootFolder && ! empty($folderPath) && $folderPath !== 'thumbnails') {
                        $folders[] = $folderPath;

                        // Recursively get nested folders
                        $nestedFolders = $this->listFolders($commonPrefix['Prefix']);
                        foreach ($nestedFolders as $nested) {
                            if (! in_array($nested, $folders)) {
                                $folders[] = $nested;
                            }
                        }
                    }
                }
            }

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
                'etag' => isset($result['ETag']) ? trim($result['ETag'], '"') : null,
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
     * Get image dimensions
     */
    protected function getImageDimensions(UploadedFile $file): array
    {
        if (! str_starts_with($file->getMimeType(), 'image/')) {
            return [];
        }

        // Skip dimension detection for GIFs to avoid memory issues
        if ($file->getMimeType() === 'image/gif') {
            try {
                // Use getimagesize which is much more memory efficient
                $size = @getimagesize($file->getRealPath());
                if ($size !== false) {
                    return [
                        'width' => $size[0],
                        'height' => $size[1],
                    ];
                }
            } catch (\Exception $e) {
                \Log::warning('Failed to get GIF dimensions: '.$e->getMessage());
            }

            return [];
        }

        try {
            $image = $this->imageManager->read($file->getRealPath());

            return [
                'width' => $image->width(),
                'height' => $image->height(),
            ];
        } catch (\Exception $e) {
            \Log::warning('Failed to get image dimensions: '.$e->getMessage());

            return [];
        }
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
}
