<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageManagerInterface;

class ImageProcessingService
{
    protected ImageManagerInterface $imageManager;

    public function __construct()
    {
        $this->imageManager = ImageManager::usingDriver(Driver::class);
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

        $image = $this->imageManager->decodeBinary($imageContent);
        $image->scale(width: 300, height: 300);

        return (string) $image->encode(new JpegEncoder(quality: 80));
    }

    /**
     * Resize image content to fit within the given dimensions.
     * Returns ['content' => string, 'mime_type' => string, 'extension' => string].
     */
    public function createResizedContent(string $imageContent, string $extension, ?int $width, ?int $height): array
    {
        $image = $this->imageManager->decodeBinary($imageContent);
        $image->scaleDown(width: $width, height: $height);

        if ($extension === 'gif') {
            return [
                'content' => (string) $image->encode(new JpegEncoder(quality: 85)),
                'mime_type' => 'image/jpeg',
                'extension' => 'jpg',
            ];
        }

        if ($extension === 'png') {
            return [
                'content' => (string) $image->encode(new PngEncoder),
                'mime_type' => 'image/png',
                'extension' => 'png',
            ];
        }

        if ($extension === 'webp') {
            return [
                'content' => (string) $image->encode(new WebpEncoder(quality: 85)),
                'mime_type' => 'image/webp',
                'extension' => 'webp',
            ];
        }

        $outputExtension = in_array($extension, ['jpg', 'jpeg']) ? $extension : 'jpg';

        return [
            'content' => (string) $image->encode(new JpegEncoder(quality: 85)),
            'mime_type' => 'image/jpeg',
            'extension' => $outputExtension,
        ];
    }

    /**
     * Extract image dimensions from an uploaded file.
     */
    public function getImageDimensions(UploadedFile $file): array
    {
        if (! str_starts_with((string) $file->getMimeType(), 'image/')) {
            return [];
        }

        return $this->getImageDimensionsFromPath($file->getRealPath(), $file->getClientOriginalName());
    }

    /**
     * Whether a file of this name has pixel dimensions worth recording. EPS: nice-to-have, skipped;
     * SVG: a vector has no meaningful pixel size.
     */
    public function hasRasterDimensions(string $filename): bool
    {
        return ! Str::endsWith(strtolower($filename), ['.eps', '.svg']);
    }

    /**
     * Dimensions of a local image file as displayed — the one implementation behind every upload
     * path (image-processing REQ-4). Reads only the header, never decodes the pixels, so the cost
     * doesn't grow with the file. $filename decides the EPS/SVG skip, since $path is often a
     * temp file without the original extension.
     *
     * @return array{width?: int, height?: int}
     */
    public function getImageDimensionsFromPath(string $path, string $filename): array
    {
        if (! $this->hasRasterDimensions($filename)) {
            return [];
        }

        $size = @getimagesize($path);
        if ($size === false) {
            return [];
        }

        [$width, $height, $type] = $size;

        // EXIF orientations 5–8 store the image rotated a quarter turn, so it displays (and the
        // auto-oriented thumbnails render) with width and height swapped.
        if (in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_TIFF_II, IMAGETYPE_TIFF_MM], true)
            && function_exists('exif_read_data')) {
            $orientation = (int) (@exif_read_data($path)['Orientation'] ?? 1);
            if ($orientation >= 5 && $orientation <= 8) {
                [$width, $height] = [$height, $width];
            }
        }

        return ['width' => $width, 'height' => $height];
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
                // Image Descriptor: 10 bytes total (introducer + left/top/width/height u16s + packed byte).
                $frameCount++;
                if ($frameCount >= 2) {
                    return true;
                }
                if ($offset + 10 > $len) {
                    break;
                }
                $localPacked = ord($imageData[$offset + 9]);
                $offset += 10;
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
