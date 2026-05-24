<?php

declare(strict_types=1);

namespace OrcaDam\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OrcaDam\Attachments\ShellFactory;
use OrcaDam\Usage\ContentScanner;
use PHPUnit\Framework\TestCase;

final class ContentScannerTest extends TestCase
{
    protected function setUp(): void
    {
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    public function test_extracts_asset_ids_from_data_attribute(): void
    {
        Functions\when('parse_blocks')->justReturn([]);
        Functions\when('get_post_meta')->justReturn('');

        $scanner = new ContentScanner();
        $ids = $scanner->extract(
            '<p><img src="https://cdn.test/a.jpg" data-orca-asset-id="123" /></p>' .
            '<img src="https://cdn.test/b.jpg" data-orca-asset-id="456" />'
        );

        $this->assertEqualsCanonicalizing([123, 456], $ids);
    }

    public function test_extracts_from_core_image_block_via_attachment_meta(): void
    {
        Functions\when('parse_blocks')->justReturn([
            ['blockName' => 'core/image', 'attrs' => ['id' => 99], 'innerBlocks' => []],
        ]);
        Functions\when('get_post_meta')->alias(function (int $postId, string $key) {
            return ($postId === 99 && $key === ShellFactory::META_ASSET_ID) ? '777' : '';
        });

        $scanner = new ContentScanner();
        $this->assertSame([777], $scanner->extract('<!-- wp:image {"id":99} /-->'));
    }

    public function test_deduplicates_ids_across_passes(): void
    {
        Functions\when('parse_blocks')->justReturn([
            ['blockName' => 'core/image', 'attrs' => ['id' => 1], 'innerBlocks' => []],
        ]);
        Functions\when('get_post_meta')->alias(function (int $postId) {
            return $postId === 1 ? '500' : '';
        });

        $scanner = new ContentScanner();
        $ids = $scanner->extract('<img data-orca-asset-id="500" />');
        $this->assertSame([500], $ids);
    }
}
