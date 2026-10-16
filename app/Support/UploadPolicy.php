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
     * Types a browser renders as a document or executes when served inline — under an asset's
     * extension, that is stored XSS on the asset domain.
     */
    private const ACTIVE_TYPES = [
        'text/html',
        'application/xhtml+xml',
        'text/xml',
        'application/xml',
        'image/svg+xml',
        'text/javascript',
        'application/javascript',
        'application/x-javascript',
        'text/ecmascript',
        'application/ecmascript',
    ];

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
     * How a stored object's served headers differ from what an upload sets today, or null when
     * they match (maintenance-commands REQ-audit-content-types). Only the media type is compared —
     * a "; charset=utf-8" parameter doesn't change how it is served. A mismatch is dangerous when
     * the object renders inline under an active type; a sanitized SVG under .svg is expected to.
     *
     * @return array{type: string, attachment: bool, expected_type: string, expected_attachment: bool, dangerous: bool}|null
     */
    public static function servedHeaderMismatch(string $filename, ?string $contentType, ?string $contentDisposition): ?array
    {
        $expectedType = self::contentType($filename);
        $expectedAttachment = ! self::isInline($filename);

        $type = strtolower(trim(explode(';', (string) $contentType)[0]));
        $attachment = str_starts_with(strtolower(trim((string) $contentDisposition)), 'attachment');

        if ($type === $expectedType && $attachment === $expectedAttachment) {
            return null;
        }

        return [
            'type' => $type,
            'attachment' => $attachment,
            'expected_type' => $expectedType,
            'expected_attachment' => $expectedAttachment,
            'dangerous' => ! $attachment
                && in_array($type, self::ACTIVE_TYPES, true)
                && ! ($type === 'image/svg+xml' && self::isSvg($filename)),
        ];
    }

    /**
     * Whether the file is an SVG (requires sanitization before storage).
     */
    public static function isSvg(string $filename): bool
    {
        return self::extension($filename) === 'svg';
    }
}
