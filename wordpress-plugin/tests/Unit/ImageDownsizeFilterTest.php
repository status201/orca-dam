<?php

declare(strict_types=1);

namespace OrcaDam\Tests\Unit;

use Brain\Monkey\Functions;
use OrcaDam\Attachments\ImageDownsizeFilter;
use OrcaDam\Attachments\ShellFactory;
use OrcaDam\Tests\Support\WpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ImageDownsizeFilterTest extends WpTestCase
{
    private const SIZES = [
        'thumbnail' => ['source_url' => 'https://cdn.test/s.jpg', 'width' => 250, 'height' => 0],
        'medium'    => ['source_url' => 'https://cdn.test/m.jpg', 'width' => 600, 'height' => 0],
        'large'     => ['source_url' => 'https://cdn.test/l.jpg', 'width' => 1200, 'height' => 0],
        'custom'    => ['file' => 'https://cdn.test/custom.jpg', 'width' => 300, 'height' => 300],
        'full'      => ['source_url' => 'https://cdn.test/full.png', 'width' => 2000, 'height' => 1000],
    ];

    private function orcaAttachment(array $sizes, string $assetId = '5'): void
    {
        Functions\when('get_post_meta')->alias(fn (int $id, string $key) => $key === ShellFactory::META_ASSET_ID ? $assetId : '');
        Functions\when('wp_get_attachment_metadata')->justReturn(['sizes' => $sizes]);
    }

    /**
     * @return iterable<string, array{0: mixed, 1: string, 2: int}>
     */
    public static function requestedSizes(): iterable
    {
        yield 'thumbnail'             => ['thumbnail', 'https://cdn.test/s.jpg', 250];
        yield 'thumb alias'           => ['thumb', 'https://cdn.test/s.jpg', 250];
        yield 'medium'                => ['medium', 'https://cdn.test/m.jpg', 600];
        yield 'medium_large → medium' => ['medium_large', 'https://cdn.test/m.jpg', 600];
        yield 'large'                 => ['large', 'https://cdn.test/l.jpg', 1200];
        yield '1536x1536 → large'     => ['1536x1536', 'https://cdn.test/l.jpg', 1200];
        yield '2048x2048 → large'     => ['2048x2048', 'https://cdn.test/l.jpg', 1200];
        yield 'registered custom'     => ['custom', 'https://cdn.test/custom.jpg', 300];
        yield 'unknown name → full'   => ['post-thumbnail', 'https://cdn.test/full.png', 2000];
        yield 'width/height array'    => [[300, 300], 'https://cdn.test/full.png', 2000];
        yield 'full'                  => ['full', 'https://cdn.test/full.png', 2000];
    }

    #[DataProvider('requestedSizes')]
    public function test_returns_the_orca_url_for_the_requested_size(mixed $size, string $url, int $width): void
    {
        $this->orcaAttachment(self::SIZES);

        $result = (new ImageDownsizeFilter())->filter(false, 12, $size);

        $this->assertSame($url, $result[0]);
        $this->assertSame($width, $result[1]);
        $this->assertTrue($result[3], 'ORCA URLs are pre-rendered, so is_intermediate must be true');
    }

    public function test_falls_back_to_full_when_the_size_was_never_rendered(): void
    {
        $this->orcaAttachment(['full' => self::SIZES['full']]);

        $result = (new ImageDownsizeFilter())->filter(false, 12, 'large');

        $this->assertSame('https://cdn.test/full.png', $result[0]);
    }

    public function test_leaves_non_orca_attachments_to_core(): void
    {
        $this->orcaAttachment(self::SIZES, assetId: '');
        Functions\expect('wp_get_attachment_metadata')->never();

        $this->assertFalse((new ImageDownsizeFilter())->filter(false, 12, 'medium'));
    }

    public function test_leaves_an_orca_attachment_without_metadata_to_core(): void
    {
        Functions\when('get_post_meta')->justReturn('5');
        Functions\when('wp_get_attachment_metadata')->justReturn(false);

        $this->assertFalse((new ImageDownsizeFilter())->filter(false, 12, 'medium'));
    }

    public function test_attachment_url_is_the_orca_url_when_one_is_stored(): void
    {
        Functions\when('get_post_meta')->alias(fn (int $id, string $key) => $key === ShellFactory::META_URL ? 'https://cdn.test/a.jpg' : '');

        $filter = new ImageDownsizeFilter();

        $this->assertSame('https://cdn.test/a.jpg', $filter->filterUrl('https://site.test/wp-content/uploads/a.jpg', 12));
    }

    public function test_attachment_url_is_untouched_for_local_media(): void
    {
        Functions\when('get_post_meta')->justReturn('');

        $local = 'https://site.test/wp-content/uploads/2026/10/a.jpg';
        $this->assertSame($local, (new ImageDownsizeFilter())->filterUrl($local, 12));
    }
}
