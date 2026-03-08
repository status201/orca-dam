<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class ImageProcessingService
{
    protected ImageManager $imageManager;

    public function __construct()
    {
        $this->imageManager = new ImageManager(new Driver);
    }

    /**
     * Generate thumbnail image content from raw image data.
     * Returns JPEG binary string, or null if the source is an animated GIF.
     */
    public function createThumbnailContent(string $imageContent, string $filename): ?string
    {
        if (str_ends_with(strtolower($filename), '.gif') && $this->isAnimatedGif($imageContent)) {
            return null;
        }

        $image = $this->imageManager->read($imageContent);
        $image->scale(width: 300, height: 300);

        return (string) $image->toJpeg(quality: 80);
    }

    /**
     * Resize image content to fit within the given dimensions.
     * Returns ['content' => string, 'mime_type' => string, 'extension' => string].
     */
    public function createResizedContent(string $imageContent, string $extension, ?int $width, ?int $height): array
    {
        $image = $this->imageManager->read($imageContent);
        $image->scaleDown(width: $width, height: $height);

        if ($extension === 'gif') {
            return [
                'content' => (string) $image->toJpeg(quality: 85),
                'mime_type' => 'image/jpeg',
                'extension' => 'jpg',
            ];
        }

        if ($extension === 'png') {
            return [
                'content' => (string) $image->toPng(),
                'mime_type' => 'image/png',
                'extension' => 'png',
            ];
        }

        if ($extension === 'webp') {
            return [
                'content' => (string) $image->toWebp(quality: 85),
                'mime_type' => 'image/webp',
                'extension' => 'webp',
            ];
        }

        $outputExtension = in_array($extension, ['jpg', 'jpeg']) ? $extension : 'jpg';

        return [
            'content' => (string) $image->toJpeg(quality: 85),
            'mime_type' => 'image/jpeg',
            'extension' => $outputExtension,
        ];
    }

    /**
     * Extract image dimensions from an uploaded file.
     */
    public function getImageDimensions(UploadedFile $file): array
    {
        if (! str_starts_with($file->getMimeType(), 'image/')) {
            return [];
        }

        // EPS: dimensions are nice-to-have, skip gracefully
        if (str_ends_with(strtolower($file->getClientOriginalName()), '.eps')) {
            return [];
        }

        // Skip dimension detection for GIFs to avoid memory issues
        if ($file->getMimeType() === 'image/gif') {
            try {
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
     * Check whether raw GIF data contains multiple frames (i.e. is animated).
     * Walks the GIF block structure to count actual image descriptor blocks.
     */
    public function isAnimatedGif(string $imageData): bool
    {
        $len = strlen($imageData);
        if ($len < 13) {
            return false;
        }

        // Skip header (6 bytes) + Logical Screen Descriptor (7 bytes)
        $offset = 10;
        // Check for Global Color Table
        $packed = ord($imageData[10]);
        if ($packed & 0x80) {
            $gctSize = 3 * (1 << (($packed & 0x07) + 1));
            $offset = 13 + $gctSize;
        } else {
            $offset = 13;
        }

        $frameCount = 0;

        while ($offset < $len) {
            $byte = ord($imageData[$offset]);

            if ($byte === 0x3B) {
                // Trailer — end of GIF
                break;
            } elseif ($byte === 0x2C) {
                // Image Descriptor
                $frameCount++;
                if ($frameCount >= 2) {
                    return true;
                }
                // Skip Image Descriptor fixed fields (9 bytes total including introducer)
                $offset += 9;
                if ($offset >= $len) {
                    break;
                }
                // Check for Local Color Table
                $localPacked = ord($imageData[$offset - 1]);
                if ($localPacked & 0x80) {
                    $lctSize = 3 * (1 << (($localPacked & 0x07) + 1));
                    $offset += $lctSize;
                }
                // Skip LZW Minimum Code Size byte
                $offset++;
                // Skip sub-blocks
                while ($offset < $len) {
                    $blockSize = ord($imageData[$offset]);
                    $offset++;
                    if ($blockSize === 0) {
                        break;
                    }
                    $offset += $blockSize;
                }
            } elseif ($byte === 0x21) {
                // Extension block
                $offset += 2; // skip introducer + label
                // Skip sub-blocks
                while ($offset < $len) {
                    $blockSize = ord($imageData[$offset]);
                    $offset++;
                    if ($blockSize === 0) {
                        break;
                    }
                    $offset += $blockSize;
                }
            } else {
                // Unknown byte, advance to avoid infinite loop
                $offset++;
            }
        }

        return false;
    }
}
