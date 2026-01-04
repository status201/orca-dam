<?php

namespace App\Services;

use Aws\S3\S3Client;
use Aws\Rekognition\RekognitionClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use Illuminate\Support\Str;

class S3Service
{
    protected S3Client $s3Client;
    protected string $bucket;
    protected string $region;

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
    }

    /**
     * Upload a file to S3
     */
    public function uploadFile(UploadedFile $file, string $directory = 'assets'): array
    {
        $filename = $this->generateUniqueFilename($file);
        $s3Key = "{$directory}/{$filename}";
        
        // Upload to S3
        $this->s3Client->putObject([
            'Bucket' => $this->bucket,
            'Key' => $s3Key,
            'Body' => fopen($file->getRealPath(), 'r'),
            'ContentType' => $file->getMimeType(),
            'ACL' => 'public-read',
        ]);

        // Get image dimensions if it's an image
        $dimensions = $this->getImageDimensions($file);

        return [
            's3_key' => $s3Key,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
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
            // Download original from S3
            $result = $this->s3Client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $s3Key,
            ]);

            $imageContent = (string) $result['Body'];

            // Generate thumbnail (300x300 max, maintain aspect ratio)
            $image = Image::make($imageContent);
            $image->resize(300, 300, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });

            // Convert to JPEG for consistency
            $thumbnailContent = $image->encode('jpg', 80);

            // Upload thumbnail
            $thumbnailKey = 'thumbnails/' . Str::replaceLast('.', '_thumb.', basename($s3Key));
            
            $this->s3Client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $thumbnailKey,
                'Body' => $thumbnailContent,
                'ContentType' => 'image/jpeg',
                'ACL' => 'public-read',
            ]);

            return $thumbnailKey;
        } catch (\Exception $e) {
            \Log::error('Thumbnail generation failed: ' . $e->getMessage());
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
            \Log::error('S3 deletion failed: ' . $e->getMessage());
            return false;
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

            if (!isset($result['Contents'])) {
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
            \Log::error('S3 list objects failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Find objects in S3 that are not in the database
     */
    public function findUnmappedObjects(): array
    {
        $s3Objects = $this->listObjects('assets/');
        $mappedKeys = \App\Models\Asset::pluck('s3_key')->toArray();

        return collect($s3Objects)
            ->filter(function ($object) use ($mappedKeys) {
                return !in_array($object['key'], $mappedKeys) 
                    && !str_contains($object['key'], '/thumbnails/');
            })
            ->values()
            ->toArray();
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
            ];
        } catch (\Exception $e) {
            \Log::error('Get S3 metadata failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate a unique filename
     */
    protected function generateUniqueFilename(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();
        return Str::uuid() . '.' . $extension;
    }

    /**
     * Get image dimensions
     */
    protected function getImageDimensions(UploadedFile $file): array
    {
        if (!str_starts_with($file->getMimeType(), 'image/')) {
            return [];
        }

        try {
            $image = Image::make($file->getRealPath());
            return [
                'width' => $image->width(),
                'height' => $image->height(),
            ];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get the public URL for an S3 key
     */
    public function getUrl(string $s3Key): string
    {
        return config('filesystems.disks.s3.url') . '/' . $s3Key;
    }
}
