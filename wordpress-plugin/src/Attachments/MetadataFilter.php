<?php

declare(strict_types=1);

namespace OrcaDam\Attachments;

/**
 * Ensures srcset generation uses ORCA URLs verbatim by neutralising WP's
 * default behaviour of prefixing size entries with the uploads dir basename.
 */
final class MetadataFilter
{
    public function register(): void
    {
        add_filter('wp_calculate_image_srcset_meta', [$this, 'filterSrcsetMeta'], 10, 4);
        add_filter('wp_calculate_image_srcset', [$this, 'filterSrcset'], 10, 5);
    }

    /**
     * @param array<string, mixed> $imageMeta
     * @return array<string, mixed>
     */
    public function filterSrcsetMeta(array $imageMeta, array $sizeArray, string $imageSrc, int $attachmentId): array
    {
        $assetId = (int) get_post_meta($attachmentId, ShellFactory::META_ASSET_ID, true);
        if ($assetId === 0) {
            return $imageMeta;
        }

        // WP's srcset builder concatenates upload_dir + sizes[i].file. Strip any
        // directory prefix so the resulting URL == the source_url we set.
        if (! empty($imageMeta['sizes']) && is_array($imageMeta['sizes'])) {
            foreach ($imageMeta['sizes'] as $name => &$info) {
                if (isset($info['source_url'])) {
                    $info['file'] = $info['source_url'];
                }
            }
            unset($info);
        }
        return $imageMeta;
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     * @return array<int, array<string, mixed>>
     */
    public function filterSrcset(mixed $sources, array $sizeArray, string $imageSrc, array $imageMeta, int $attachmentId): mixed
    {
        $assetId = (int) get_post_meta($attachmentId, ShellFactory::META_ASSET_ID, true);
        if ($assetId === 0 || ! is_array($sources)) {
            return $sources;
        }

        $uploadBase = trailingslashit((string) (wp_get_upload_dir()['baseurl'] ?? ''));
        foreach ($sources as &$source) {
            if (isset($source['url']) && str_starts_with($source['url'], $uploadBase)) {
                $source['url'] = substr($source['url'], strlen($uploadBase));
            }
        }
        unset($source);
        return $sources;
    }
}
