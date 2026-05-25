<?php

declare(strict_types=1);

namespace OrcaDam\Usage;

use WP_Post;

/**
 * Watches post lifecycle hooks and produces an asset-id diff (added/removed)
 * which it hands to TagSyncJob.
 *
 * Per-post previous-content cache prevents redundant DB reads inside a single
 * request (e.g. save_post + rest_after_insert can both fire).
 */
final class PostObserver
{
    private const PREVIOUS_TRANSIENT_PREFIX = 'orca_dam_prev_assets_';

    /** @var array<int, list<int>> Previously-seen asset IDs per post id (request-scoped) */
    private array $previousCache = [];

    public function __construct(private readonly ContentScanner $scanner) {}

    public function register(): void
    {
        add_action('pre_post_update', [$this, 'capturePrevious'], 10, 2);
        add_action('save_post', [$this, 'onSave'], 20, 3);
        add_action('rest_after_insert_post', [$this, 'onRestInsert'], 10, 2);
        add_action('before_delete_post', [$this, 'onDelete']);
        add_action('wp_trash_post', [$this, 'onDelete']);
    }

    public function capturePrevious(int $postId, array $data): void
    {
        if (! isset($this->previousCache[$postId])) {
            $existing = get_post($postId);
            if ($existing instanceof WP_Post) {
                $this->previousCache[$postId] = $this->scanner->extract($existing->post_content);
            }
        }
    }

    public function onSave(int $postId, WP_Post $post, bool $update): void
    {
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }
        if (! in_array($post->post_status, ['publish', 'private', 'future', 'draft'], true)) {
            return;
        }

        $current = $this->scanner->extract($post->post_content);
        $previous = $this->previousCache[$postId] ?? [];

        $this->dispatchDiff($postId, $previous, $current);
        $this->previousCache[$postId] = $current;
    }

    public function onRestInsert(WP_Post $post, $request): void
    {
        $this->onSave($post->ID, $post, true);
    }

    public function onDelete(int $postId): void
    {
        $post = get_post($postId);
        if (! $post instanceof WP_Post) {
            return;
        }
        $previous = $this->scanner->extract($post->post_content);
        $this->dispatchDiff($postId, $previous, []);
    }

    /**
     * @param list<int> $previous
     * @param list<int> $current
     */
    private function dispatchDiff(int $postId, array $previous, array $current): void
    {
        $added = array_values(array_diff($current, $previous));
        $removed = array_values(array_diff($previous, $current));
        if ($added === [] && $removed === []) {
            return;
        }

        $tag = $this->tagFor($postId);

        wp_schedule_single_event(time(), TagSyncJob::HOOK, [$tag, $added, $removed]);
    }

    private function tagFor(int $postId): string
    {
        $host = wp_parse_url(home_url(), PHP_URL_HOST) ?: 'unknown';
        return sprintf('wp:%s/post/%d', $host, $postId);
    }
}
