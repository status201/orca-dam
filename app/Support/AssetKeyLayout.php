<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Where an asset's derived objects (thumbnail, S/M/L resizes) live in the bucket, in one place.
 *
 * The folder under thumbnails/ mirrors the original's folder relative to the configured root:
 * assets/photos/abc.png → thumbnails/photos/abc_thumb.png and thumbnails/S/photos/abc.png.
 * This logic used to be copied into four S3Service methods, and the copies had drifted; a stored
 * key's name must never change once written, so these outputs are pinned by tests.
 *
 * @see specs/features/s3-storage.md REQ-1, REQ-6
 */
final class AssetKeyLayout
{
    /** Source extensions a resize is encoded back into; everything else becomes a JPEG. */
    private const RESIZE_KEEPS_EXTENSION = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * @param  string  $rootPrefix  the configured root folder with a trailing slash, or '' for bucket root
     */
    public function __construct(private readonly string $rootPrefix) {}

    /**
     * The original's folder relative to the root, with a trailing slash; '' directly under the root.
     * A key outside the root keeps its full folder.
     */
    public function relativeFolder(string $s3Key): string
    {
        $relativePath = ($this->rootPrefix !== '' && str_starts_with($s3Key, $this->rootPrefix))
            ? substr($s3Key, strlen($this->rootPrefix))
            : $s3Key;
        $folder = dirname($relativePath);

        return ($folder === '.' || $folder === '') ? '' : $folder.'/';
    }

    /**
     * A generated thumbnail's key. It keeps the original's extension even though its bytes are
     * always JPEG (abc.png → abc_thumb.png, served as image/jpeg).
     */
    public function thumbnailKey(string $s3Key): string
    {
        return $this->base(null).$this->relativeFolder($s3Key).Str::replaceLast('.', '_thumb.', basename($s3Key));
    }

    /**
     * A browser-generated thumbnail's key (video/PDF previews), always .jpg.
     */
    public function uploadedThumbnailKey(string $s3Key): string
    {
        return (string) preg_replace('/\.[^.]+$/', '.jpg', $this->thumbnailKey($s3Key));
    }

    /**
     * A resize variant's key: thumbnails/{SIZE}/{folder}{filename}.{extension}.
     *
     * @param  string  $size  's', 'm' or 'l'
     */
    public function resizeKey(string $s3Key, string $size, string $extension): string
    {
        return $this->base($size).$this->relativeFolder($s3Key).pathinfo(basename($s3Key), PATHINFO_FILENAME).'.'.$extension;
    }

    /**
     * The extension a resize of a file with this (lower-cased) extension is encoded and stored as.
     */
    public static function resizeOutputExtension(string $sourceExtension): string
    {
        return in_array($sourceExtension, self::RESIZE_KEEPS_EXTENSION, true) ? $sourceExtension : 'jpg';
    }

    /**
     * Where a derived key goes when its original moves from $oldS3Key to $newS3Key: the same name
     * under the new folder. The name is never recomputed, so whatever was stored survives.
     *
     * Null when the key does not sit directly in the folder its original implies (a legacy
     * layout); the caller leaves such an object where it is rather than guessing.
     *
     * @param  string|null  $size  null for the thumbnail, otherwise 's', 'm' or 'l'
     */
    public function relocate(string $derivedKey, ?string $size, string $oldS3Key, string $newS3Key): ?string
    {
        $oldPrefix = $this->base($size).$this->relativeFolder($oldS3Key);
        $name = substr($derivedKey, strlen($oldPrefix));

        if (! str_starts_with($derivedKey, $oldPrefix) || $name === '' || str_contains($name, '/')) {
            return null;
        }

        return $this->base($size).$this->relativeFolder($newS3Key).$name;
    }

    private function base(?string $size): string
    {
        return $size === null ? 'thumbnails/' : 'thumbnails/'.strtoupper($size).'/';
    }
}
