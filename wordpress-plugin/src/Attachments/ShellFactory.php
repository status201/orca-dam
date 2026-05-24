<?php

declare(strict_types=1);

namespace OrcaDam\Attachments;

use OrcaDam\Api\OrcaClient;

/**
 * Find-or-create a WordPress attachment "shell" that points at an ORCA asset
 * without storing any bytes locally. This is the only writer of attachment
 * rows in the plugin — keeps the invariant that an attachment has a single
 * `_orca_asset_id` and the metadata array points exclusively at ORCA URLs.
 */
final class ShellFactory
{
    public const META_ASSET_ID  = '_orca_asset_id';
    public const META_S3_KEY    = '_orca_s3_key';
    public const META_URL       = '_orca_url';
    public const META_THUMB_URL = '_orca_thumbnail_url';
    public const META_RESIZE_S  = '_orca_resize_s_url';
    public const META_RESIZE_M  = '_orca_resize_m_url';
    public const META_RESIZE_L  = '_orca_resize_l_url';

    public function __construct(private readonly OrcaClient $client) {}

    public function findOrCreate(int $orcaAssetId): int
    {
        if ($existing = $this->findByAssetId($orcaAssetId)) {
            return $existing;
        }

        $response = $this->client->getAsset($orcaAssetId);
        if (! $response->ok()) {
            throw new \RuntimeException(
                'ORCA returned ' . $response->status . ' for asset ' . $orcaAssetId
            );
        }

        return $this->createFromAssetData($response->body);
    }

    public function findByAssetId(int $orcaAssetId): ?int
    {
        $found = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_key'       => self::META_ASSET_ID,
            'meta_value'     => (string) $orcaAssetId,
        ]);

        return $found[0] ?? null;
    }

    /**
     * @param array<string, mixed> $asset
     */
    public function createFromAssetData(array $asset): int
    {
        $url = (string) ($asset['url'] ?? '');
        if ($url === '') {
            throw new \RuntimeException('ORCA asset payload is missing the "url" field.');
        }

        $width  = (int) ($asset['width']  ?? 0);
        $height = (int) ($asset['height'] ?? 0);

        $attachmentId = wp_insert_attachment([
            'post_mime_type' => (string) ($asset['mime_type'] ?? 'image/jpeg'),
            'post_title'     => (string) ($asset['filename'] ?? 'orca-asset'),
            'post_excerpt'   => (string) ($asset['caption']  ?? ''),
            'post_content'   => (string) ($asset['alt_text'] ?? ''),
            'post_status'    => 'inherit',
            'guid'           => $url,
        ]);

        if (is_wp_error($attachmentId) || $attachmentId === 0) {
            throw new \RuntimeException('wp_insert_attachment failed: ' . (is_wp_error($attachmentId) ? $attachmentId->get_error_message() : 'unknown'));
        }

        update_post_meta($attachmentId, self::META_ASSET_ID,  (string) ($asset['id'] ?? 0));
        update_post_meta($attachmentId, self::META_S3_KEY,    (string) ($asset['s3_key']        ?? ''));
        update_post_meta($attachmentId, self::META_URL,       $url);
        update_post_meta($attachmentId, self::META_THUMB_URL, (string) ($asset['thumbnail_url'] ?? $url));
        update_post_meta($attachmentId, self::META_RESIZE_S,  (string) ($asset['resize_s_url']  ?? ''));
        update_post_meta($attachmentId, self::META_RESIZE_M,  (string) ($asset['resize_m_url']  ?? ''));
        update_post_meta($attachmentId, self::META_RESIZE_L,  (string) ($asset['resize_l_url']  ?? ''));

        // Sentinel: filters intercept any code path that would touch the filesystem.
        update_post_meta($attachmentId, '_wp_attached_file', $url);
        update_post_meta($attachmentId, '_wp_attachment_image_alt', (string) ($asset['alt_text'] ?? ''));

        // Build the metadata array WP uses for srcset / image_downsize.
        $sizes = [];
        if ($s = (string) ($asset['resize_s_url'] ?? '')) {
            $sizes['thumbnail'] = ['file' => $s, 'width' => 250,  'height' => 0, 'mime-type' => 'image/jpeg', 'source_url' => $s];
        } elseif ($t = (string) ($asset['thumbnail_url'] ?? '')) {
            $sizes['thumbnail'] = ['file' => $t, 'width' => 250,  'height' => 250, 'mime-type' => 'image/jpeg', 'source_url' => $t];
        }
        if ($m = (string) ($asset['resize_m_url'] ?? '')) {
            $sizes['medium'] = ['file' => $m, 'width' => 600,  'height' => 0, 'mime-type' => 'image/jpeg', 'source_url' => $m];
        }
        if ($l = (string) ($asset['resize_l_url'] ?? '')) {
            $sizes['large']  = ['file' => $l, 'width' => 1200, 'height' => 0, 'mime-type' => 'image/jpeg', 'source_url' => $l];
        }
        $sizes['full'] = ['file' => $url, 'width' => $width, 'height' => $height, 'mime-type' => (string) ($asset['mime_type'] ?? 'image/jpeg'), 'source_url' => $url];

        wp_update_attachment_metadata($attachmentId, [
            'width'  => $width,
            'height' => $height,
            'file'   => $url,
            'sizes'  => $sizes,
        ]);

        return (int) $attachmentId;
    }

    /**
     * Shape that the picker/import REST endpoint returns to the browser. Mirrors
     * what wp.media.model.Attachment expects so it can be handed to the media
     * frame directly.
     *
     * @return array<string, mixed>
     */
    public function present(int $attachmentId): array
    {
        $post = get_post($attachmentId);
        if ($post === null) {
            return [];
        }

        $sizes = wp_get_attachment_metadata($attachmentId)['sizes'] ?? [];
        $sizeData = [];
        foreach ($sizes as $name => $info) {
            $sizeData[$name] = [
                'url'         => $info['source_url'] ?? $info['file'] ?? '',
                'width'       => (int) ($info['width'] ?? 0),
                'height'      => (int) ($info['height'] ?? 0),
                'orientation' => ($info['width'] ?? 0) >= ($info['height'] ?? 0) ? 'landscape' : 'portrait',
            ];
        }

        return [
            'id'            => $attachmentId,
            'title'         => $post->post_title,
            'filename'      => $post->post_title,
            'url'           => get_post_meta($attachmentId, self::META_URL, true),
            'link'          => get_post_meta($attachmentId, self::META_URL, true),
            'alt'           => (string) get_post_meta($attachmentId, '_wp_attachment_image_alt', true),
            'caption'       => $post->post_excerpt,
            'description'   => $post->post_content,
            'mime'          => $post->post_mime_type,
            'type'          => 'image',
            'subtype'       => preg_replace('|^.+/|', '', (string) $post->post_mime_type),
            'icon'          => '',
            'dateFormatted' => mysql2date('F j, Y', $post->post_date),
            'nonces'        => [
                'update' => '',
                'delete' => '',
                'edit'   => '',
            ],
            'editLink' => false,
            'sizes'    => $sizeData,
            'orca'     => [
                'asset_id' => (int) get_post_meta($attachmentId, self::META_ASSET_ID, true),
                's3_key'   => get_post_meta($attachmentId, self::META_S3_KEY, true),
            ],
        ];
    }
}
