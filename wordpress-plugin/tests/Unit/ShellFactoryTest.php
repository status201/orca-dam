<?php

declare(strict_types=1);

namespace OrcaDam\Tests\Unit;

use Brain\Monkey\Functions;
use OrcaDam\Api\Transport\TransportResponse;
use OrcaDam\Attachments\ShellFactory;
use OrcaDam\Tests\Support\FakeOrca;
use OrcaDam\Tests\Support\WpTestCase;
use WP_Post;

final class ShellFactoryTest extends WpTestCase
{
    /** @var array<string, mixed> post meta written, keyed by meta key */
    private array $meta = [];

    /** @var array<string, mixed>|null */
    private ?array $attachmentMetadata = null;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('update_post_meta')->alias(function (int $id, string $key, $value): bool {
            $this->meta[$key] = $value;
            return true;
        });
        Functions\when('wp_update_attachment_metadata')->alias(function (int $id, array $data): bool {
            $this->attachmentMetadata = $data;
            return true;
        });
    }

    public function test_find_or_create_reuses_an_existing_shell_without_calling_orca(): void
    {
        Functions\expect('get_posts')->once()->andReturnUsing(function (array $args): array {
            $this->assertSame(ShellFactory::META_ASSET_ID, $args['meta_key']);
            $this->assertSame('7', $args['meta_value']);
            return [42];
        });
        [$client, $orca] = FakeOrca::client(fn () => new TransportResponse(200, []));

        $this->assertSame(42, (new ShellFactory($client))->findOrCreate(7));
        $this->assertSame([], $orca->requests);
    }

    public function test_find_or_create_fetches_the_asset_and_creates_a_shell(): void
    {
        Functions\when('get_posts')->justReturn([]);
        Functions\expect('wp_insert_attachment')->once()->andReturn(99);
        [$client, $orca] = FakeOrca::client(fn () => new TransportResponse(200, ['id' => 7, 'url' => 'https://cdn.test/a.jpg']));

        $this->assertSame(99, (new ShellFactory($client))->findOrCreate(7));
        $this->assertSame('https://dam.test/api/assets/7', $orca->requests[0]['url']);
        $this->assertSame('7', $this->meta[ShellFactory::META_ASSET_ID]);
    }

    public function test_find_or_create_throws_when_orca_does_not_return_the_asset(): void
    {
        Functions\when('get_posts')->justReturn([]);
        Functions\expect('wp_insert_attachment')->never();
        [$client] = FakeOrca::client(fn () => new TransportResponse(404, ['message' => 'Not found']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ORCA returned 404 for asset 7');

        (new ShellFactory($client))->findOrCreate(7);
    }

    public function test_create_rejects_a_payload_without_a_url(): void
    {
        Functions\expect('wp_insert_attachment')->never();
        [$client] = FakeOrca::client(fn () => new TransportResponse(200, []));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing the "url" field');

        (new ShellFactory($client))->createFromAssetData(['id' => 7]);
    }

    public function test_create_throws_when_wordpress_refuses_the_attachment(): void
    {
        Functions\when('wp_insert_attachment')->justReturn(0);
        Functions\expect('update_post_meta')->never();
        [$client] = FakeOrca::client(fn () => new TransportResponse(200, []));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wp_insert_attachment failed');

        (new ShellFactory($client))->createFromAssetData(['id' => 7, 'url' => 'https://cdn.test/a.jpg']);
    }

    public function test_create_stores_orca_urls_and_maps_resizes_to_wordpress_sizes(): void
    {
        Functions\expect('wp_insert_attachment')->once()->andReturnUsing(function (array $post): int {
            $this->assertSame('image/png', $post['post_mime_type']);
            $this->assertSame('logo.png', $post['post_title']);
            $this->assertSame('A logo', $post['post_content']);
            $this->assertSame('https://cdn.test/assets/logo.png', $post['guid']);
            return 12;
        });
        [$client] = FakeOrca::client(fn () => new TransportResponse(200, []));

        $id = (new ShellFactory($client))->createFromAssetData([
            'id'            => 5,
            'url'           => 'https://cdn.test/assets/logo.png',
            'mime_type'     => 'image/png',
            'filename'      => 'logo.png',
            'alt_text'      => 'A logo',
            's3_key'        => 'assets/logo.png',
            'width'         => 2000,
            'height'        => 1000,
            'thumbnail_url' => 'https://cdn.test/thumbnails/logo_thumb.jpg',
            'resize_s_url'  => 'https://cdn.test/s/logo.jpg',
            'resize_m_url'  => 'https://cdn.test/m/logo.jpg',
            'resize_l_url'  => 'https://cdn.test/l/logo.jpg',
        ]);

        $this->assertSame(12, $id);
        $this->assertSame('5', $this->meta[ShellFactory::META_ASSET_ID]);
        $this->assertSame('assets/logo.png', $this->meta[ShellFactory::META_S3_KEY]);
        // Core reads `_wp_attached_file`; it must hold the ORCA URL, never a local path.
        $this->assertSame('https://cdn.test/assets/logo.png', $this->meta['_wp_attached_file']);
        $this->assertSame('A logo', $this->meta['_wp_attachment_image_alt']);

        $sizes = $this->attachmentMetadata['sizes'];
        $this->assertSame(['thumbnail', 'medium', 'large', 'full'], array_keys($sizes));
        $this->assertSame(['https://cdn.test/s/logo.jpg', 250, 0], [$sizes['thumbnail']['source_url'], $sizes['thumbnail']['width'], $sizes['thumbnail']['height']]);
        $this->assertSame(['https://cdn.test/m/logo.jpg', 600], [$sizes['medium']['source_url'], $sizes['medium']['width']]);
        $this->assertSame(['https://cdn.test/l/logo.jpg', 1200], [$sizes['large']['source_url'], $sizes['large']['width']]);
        $this->assertSame(['https://cdn.test/assets/logo.png', 2000, 1000, 'image/png'], [
            $sizes['full']['source_url'], $sizes['full']['width'], $sizes['full']['height'], $sizes['full']['mime-type'],
        ]);
        // Core builds srcset from `file`, so it must be the same ORCA URL.
        foreach ($sizes as $name => $info) {
            $this->assertSame($info['source_url'], $info['file'], "{$name}: file and source_url differ");
        }
    }

    public function test_create_falls_back_to_the_square_thumbnail_when_there_are_no_resizes(): void
    {
        Functions\when('wp_insert_attachment')->justReturn(12);
        [$client] = FakeOrca::client(fn () => new TransportResponse(200, []));

        (new ShellFactory($client))->createFromAssetData([
            'id'            => 5,
            'url'           => 'https://cdn.test/assets/old.jpg',
            'thumbnail_url' => 'https://cdn.test/thumbnails/old_thumb.jpg',
        ]);

        $sizes = $this->attachmentMetadata['sizes'];
        $this->assertSame(['thumbnail', 'full'], array_keys($sizes));
        $this->assertSame(['https://cdn.test/thumbnails/old_thumb.jpg', 250, 250], [
            $sizes['thumbnail']['source_url'], $sizes['thumbnail']['width'], $sizes['thumbnail']['height'],
        ]);
        $this->assertSame('image/jpeg', $sizes['full']['mime-type']);
    }

    public function test_create_without_any_thumbnail_uses_the_original_as_thumbnail_meta(): void
    {
        Functions\when('wp_insert_attachment')->justReturn(12);
        [$client] = FakeOrca::client(fn () => new TransportResponse(200, []));

        (new ShellFactory($client))->createFromAssetData(['id' => 5, 'url' => 'https://cdn.test/assets/a.gif']);

        $this->assertSame('https://cdn.test/assets/a.gif', $this->meta[ShellFactory::META_THUMB_URL]);
        $this->assertSame(['full'], array_keys($this->attachmentMetadata['sizes']));
    }

    public function test_present_returns_the_shape_wp_media_expects(): void
    {
        Functions\when('get_post')->justReturn(new WP_Post([
            'ID'             => 12,
            'post_title'     => 'logo.png',
            'post_excerpt'   => 'Caption',
            'post_content'   => 'Description',
            'post_mime_type' => 'image/png',
            'post_date'      => '2026-10-21 10:00:00',
        ]));
        Functions\when('wp_get_attachment_metadata')->justReturn(['sizes' => [
            'medium' => ['source_url' => 'https://cdn.test/m/logo.jpg', 'width' => 600, 'height' => 0],
            'full'   => ['file' => 'https://cdn.test/logo.png', 'width' => 800, 'height' => 1200],
        ]]);
        Functions\when('get_post_meta')->alias(fn (int $id, string $key) => match ($key) {
            ShellFactory::META_URL      => 'https://cdn.test/logo.png',
            ShellFactory::META_ASSET_ID => '5',
            ShellFactory::META_S3_KEY   => 'assets/logo.png',
            '_wp_attachment_image_alt'  => 'A logo',
            default                     => '',
        });
        Functions\when('mysql2date')->justReturn('October 21, 2026');
        [$client] = FakeOrca::client(fn () => new TransportResponse(200, []));

        $presented = (new ShellFactory($client))->present(12);

        $this->assertSame(12, $presented['id']);
        $this->assertSame('https://cdn.test/logo.png', $presented['url']);
        $this->assertSame('A logo', $presented['alt']);
        $this->assertSame('png', $presented['subtype']);
        $this->assertSame(['asset_id' => 5, 's3_key' => 'assets/logo.png'], $presented['orca']);
        $this->assertSame(
            ['url' => 'https://cdn.test/m/logo.jpg', 'width' => 600, 'height' => 0, 'orientation' => 'landscape'],
            $presented['sizes']['medium'],
        );
        // `file` stands in when a size has no `source_url`.
        $this->assertSame(
            ['url' => 'https://cdn.test/logo.png', 'width' => 800, 'height' => 1200, 'orientation' => 'portrait'],
            $presented['sizes']['full'],
        );
    }

    public function test_present_returns_an_empty_array_for_a_missing_post(): void
    {
        Functions\when('get_post')->justReturn(null);
        [$client] = FakeOrca::client(fn () => new TransportResponse(200, []));

        $this->assertSame([], (new ShellFactory($client))->present(404));
    }
}
