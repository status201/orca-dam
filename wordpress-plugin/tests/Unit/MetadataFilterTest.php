<?php

declare(strict_types=1);

namespace OrcaDam\Tests\Unit;

use Brain\Monkey\Functions;
use OrcaDam\Attachments\MetadataFilter;
use OrcaDam\Attachments\ShellFactory;
use OrcaDam\Tests\Support\WpTestCase;

final class MetadataFilterTest extends WpTestCase
{
    private const UPLOADS = 'https://site.test/wp-content/uploads';

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('trailingslashit')->alias(fn (string $s) => rtrim($s, '/\\') . '/');
        Functions\when('wp_get_upload_dir')->justReturn(['baseurl' => self::UPLOADS]);
    }

    private function isOrcaAttachment(bool $orca): void
    {
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $key) => ($orca && $key === ShellFactory::META_ASSET_ID) ? '5' : ''
        );
    }

    public function test_srcset_meta_points_each_size_file_at_its_orca_url(): void
    {
        $this->isOrcaAttachment(true);
        $meta = ['sizes' => [
            'medium' => ['file' => 'm.jpg', 'source_url' => 'https://cdn.test/m.jpg'],
            'legacy' => ['file' => 'legacy.jpg'],
        ]];

        $filtered = (new MetadataFilter())->filterSrcsetMeta($meta, [600, 0], 'https://cdn.test/full.jpg', 12);

        $this->assertSame('https://cdn.test/m.jpg', $filtered['sizes']['medium']['file']);
        $this->assertSame('legacy.jpg', $filtered['sizes']['legacy']['file'], 'a size without source_url is left alone');
    }

    public function test_srcset_meta_is_untouched_for_local_media(): void
    {
        $this->isOrcaAttachment(false);
        $meta = ['sizes' => ['medium' => ['file' => 'm.jpg', 'source_url' => 'https://cdn.test/m.jpg']]];

        $this->assertSame($meta, (new MetadataFilter())->filterSrcsetMeta($meta, [600, 0], 'x', 12));
    }

    public function test_srcset_strips_the_uploads_prefix_core_put_in_front_of_orca_urls(): void
    {
        $this->isOrcaAttachment(true);
        $sources = [
            600  => ['url' => self::UPLOADS . '/https://cdn.test/m.jpg', 'descriptor' => 'w', 'value' => 600],
            1200 => ['url' => 'https://cdn.test/l.jpg', 'descriptor' => 'w', 'value' => 1200],
        ];

        $filtered = (new MetadataFilter())->filterSrcset($sources, [600, 0], 'x', [], 12);

        $this->assertSame('https://cdn.test/m.jpg', $filtered[600]['url']);
        $this->assertSame('https://cdn.test/l.jpg', $filtered[1200]['url']);
    }

    public function test_srcset_is_untouched_for_local_media(): void
    {
        $this->isOrcaAttachment(false);
        $sources = [600 => ['url' => self::UPLOADS . '/2026/10/m.jpg', 'descriptor' => 'w', 'value' => 600]];

        $this->assertSame($sources, (new MetadataFilter())->filterSrcset($sources, [600, 0], 'x', [], 12));
    }

    public function test_srcset_passes_through_when_core_disabled_it(): void
    {
        $this->isOrcaAttachment(true);

        $this->assertFalse((new MetadataFilter())->filterSrcset(false, [600, 0], 'x', [], 12));
    }
}
