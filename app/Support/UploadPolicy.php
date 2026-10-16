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
        return array_keys((array) config('uploads.allowed_extensions', []));
    }

    /**
     * The Content-Type an object with this name/key is stored and served with: the one its
     * allowlisted extension maps to — never the client's claim, never sniffed from the bytes
     * (upload-policy REQ-content-type-from-extension). Off-list names get a type no browser renders.
     */
    public static function contentType(string $filename): string
    {
        $types = (array) config('uploads.allowed_extensions', []);

        return $types[self::extension($filename)] ?? 'application/octet-stream';
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
