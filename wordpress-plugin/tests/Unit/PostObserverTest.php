<?php

declare(strict_types=1);

namespace OrcaDam\Tests\Unit;

use Brain\Monkey\Functions;
use OrcaDam\Tests\Support\WpTestCase;
use OrcaDam\Usage\ContentScanner;
use OrcaDam\Usage\PostObserver;
use OrcaDam\Usage\TagSyncJob;
use WP_Post;

final class PostObserverTest extends WpTestCase
{
    /** @var list<array{0: string, 1: list<int>, 2: list<int>}> [tag, added, removed] per scheduled sync */
    private array $syncs = [];

    private PostObserver $observer;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('parse_blocks')->justReturn([]);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);
        Functions\when('home_url')->justReturn('https://site.test');
        Functions\when('wp_parse_url')->alias('parse_url');
        Functions\when('wp_schedule_single_event')->alias(function (int $at, string $hook, array $args): bool {
            $this->assertSame(TagSyncJob::HOOK, $hook);
            $this->syncs[] = $args;
            return true;
        });

        $this->observer = new PostObserver(new ContentScanner());
    }

    /** @param list<int> $assetIds */
    private static function post(int $id, array $assetIds, string $status = 'publish'): WP_Post
    {
        $content = implode('', array_map(
            static fn (int $assetId) => "<img src=\"https://cdn.test/{$assetId}.jpg\" data-orca-asset-id=\"{$assetId}\" />",
            $assetIds,
        ));
        return new WP_Post(['ID' => $id, 'post_content' => $content, 'post_status' => $status]);
    }

    private function storedPost(WP_Post $post): void
    {
        Functions\when('get_post')->justReturn($post);
    }

    public function test_saving_an_edit_syncs_only_the_added_and_removed_assets(): void
    {
        $this->storedPost(self::post(5, [1, 2]));
        $this->observer->capturePrevious(5, []);

        $this->observer->onSave(5, self::post(5, [2, 3]), true);

        $this->assertSame([['wp:site.test/post/5', [3], [1]]], $this->syncs);
    }

    public function test_a_first_save_adds_every_asset(): void
    {
        $this->observer->onSave(5, self::post(5, [7, 8]), false);

        $this->assertSame([['wp:site.test/post/5', [7, 8], []]], $this->syncs);
    }

    public function test_an_unchanged_post_schedules_nothing(): void
    {
        $this->storedPost(self::post(5, [1, 2]));
        $this->observer->capturePrevious(5, []);

        $this->observer->onSave(5, self::post(5, [2, 1]), true);

        $this->assertSame([], $this->syncs);
    }

    public function test_save_post_then_rest_insert_in_one_request_syncs_once(): void
    {
        $this->storedPost(self::post(5, [1]));
        $this->observer->capturePrevious(5, []);
        $saved = self::post(5, [1, 2]);

        $this->observer->onSave(5, $saved, true);
        $this->observer->onRestInsert($saved, null);

        $this->assertSame([['wp:site.test/post/5', [2], []]], $this->syncs);
    }

    public function test_capture_previous_keeps_the_first_snapshot_of_the_request(): void
    {
        $this->storedPost(self::post(5, [1]));
        $this->observer->capturePrevious(5, []);
        $this->storedPost(self::post(5, [9]));
        $this->observer->capturePrevious(5, []);

        $this->observer->onSave(5, self::post(5, []), true);

        $this->assertSame([['wp:site.test/post/5', [], [1]]], $this->syncs);
    }

    public function test_revisions_and_autosaves_are_ignored(): void
    {
        Functions\when('wp_is_post_revision')->alias(fn (int $id) => $id === 6);
        Functions\when('wp_is_post_autosave')->alias(fn (int $id) => $id === 7);

        $this->observer->onSave(6, self::post(6, [1]), true);
        $this->observer->onSave(7, self::post(7, [1]), true);

        $this->assertSame([], $this->syncs);
    }

    public function test_only_live_statuses_are_synced(): void
    {
        foreach (['publish', 'private', 'future', 'draft'] as $i => $status) {
            $this->observer->onSave(10 + $i, self::post(10 + $i, [1], $status), false);
        }
        foreach (['auto-draft', 'trash', 'inherit', 'pending'] as $i => $status) {
            $this->observer->onSave(20 + $i, self::post(20 + $i, [1], $status), false);
        }

        $this->assertSame(
            ['wp:site.test/post/10', 'wp:site.test/post/11', 'wp:site.test/post/12', 'wp:site.test/post/13'],
            array_column($this->syncs, 0),
        );
    }

    public function test_deleting_or_trashing_a_post_removes_all_its_assets(): void
    {
        $this->storedPost(self::post(5, [1, 2]));

        $this->observer->onDelete(5);

        $this->assertSame([['wp:site.test/post/5', [], [1, 2]]], $this->syncs);
    }

    public function test_deleting_a_post_that_no_longer_exists_does_nothing(): void
    {
        Functions\when('get_post')->justReturn(null);

        $this->observer->onDelete(5);

        $this->assertSame([], $this->syncs);
    }
}
