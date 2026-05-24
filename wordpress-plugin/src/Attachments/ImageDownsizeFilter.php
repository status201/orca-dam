<?php

declare(strict_types=1);

namespace OrcaDam\Attachments;

/**
 * Short-circuits WP's image_downsize() pipeline for ORCA-backed attachments so
 * core never tries to compute a filesystem path. Instead we return the
 * pre-rendered ORCA resize URL for the requested size.
 */
final class ImageDownsizeFilter
{
    public function register(): void
    {
        add_filter('image_downsize', [$this, 'filter'], 10, 3);
        add_filter('wp_get_attachment_url', [$this, 'filterUrl'], 10, 2);
    }

    /**
     * @param false|array{0: string, 1: int, 2: int, 3: bool} $out
     * @return false|array{0: string, 1: int, 2: int, 3: bool}
     */
    public function filter(mixed $out, int $attachmentId, mixed $size): mixed
    {
        $assetId = (int) get_post_meta($attachmentId, ShellFactory::META_ASSET_ID, true);
        if ($assetId === 0) {
            return $out;
        }

        [$url, $width, $height] = $this->pickUrl($attachmentId, $size);
        if ($url === '') {
            return $out;
        }
        return [$url, $width, $height, true];
    }

    public function filterUrl(string $url, int $attachmentId): string
    {
        $orcaUrl = (string) get_post_meta($attachmentId, ShellFactory::META_URL, true);
        return $orcaUrl !== '' ? $orcaUrl : $url;
    }

    /**
     * @return array{0: string, 1: int, 2: int}
     */
    private function pickUrl(int $attachmentId, mixed $size): array
    {
        $meta = wp_get_attachment_metadata($attachmentId);
        $sizes = is_array($meta['sizes'] ?? null) ? $meta['sizes'] : [];

        $sizeKey = 'full';
        if (is_string($size)) {
            $sizeKey = match ($size) {
                'thumbnail', 'thumb'            => 'thumbnail',
                'medium', 'medium_large'        => 'medium',
                'large', '1536x1536', '2048x2048' => 'large',
                default                         => isset($sizes[$size]) ? $size : 'full',
            };
        }

        $info = $sizes[$sizeKey] ?? $sizes['full'] ?? null;
        if ($info === null) {
            return ['', 0, 0];
        }
        return [
            (string) ($info['source_url'] ?? $info['file'] ?? ''),
            (int) ($info['width'] ?? 0),
            (int) ($info['height'] ?? 0),
        ];
    }
}
