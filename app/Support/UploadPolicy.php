<?php

namespace App\Support;

/**
 * Central helper for upload type decisions. Reads the allowlist + inline rules
 * from config/uploads.php so validation (AllowedUploadExtension) and storage
 * (S3Service / ChunkedUploadService) share one source of truth.
 */
class UploadPolicy
{
    /**
     * Extensions accepted by the uploaders.
     *
     * @return array<int, string>
     */
    public static function allowedExtensions(): array
    {
        return (array) config('uploads.allowed_extensions', []);
    }

    /**
     * Lower-cased extension of a filename or S3 key (without the dot).
     */
    public static function extension(string $filename): string
    {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }

    /**
     * Whether a filename's extension is on the allowlist.
     */
    public static function isAllowed(string $filename): bool
    {
        $ext = self::extension($filename);

        return $ext !== '' && in_array($ext, self::allowedExtensions(), true);
    }

    /**
     * Whether an object with this name/key may be served inline. Non-inline
     * types are stored with Content-Disposition: attachment.
     */
    public static function isInline(string $filename): bool
    {
        return in_array(self::extension($filename), (array) config('uploads.inline_extensions', []), true);
    }

    /**
     * Whether the file is an SVG (requires sanitization before storage).
     */
    public static function isSvg(string $filename): bool
    {
        return self::extension($filename) === 'svg';
    }
}
