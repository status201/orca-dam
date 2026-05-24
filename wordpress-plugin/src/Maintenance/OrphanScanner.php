<?php

declare(strict_types=1);

namespace OrcaDam\Maintenance;

use OrcaDam\Api\OrcaClient;
use OrcaDam\Attachments\ShellFactory;

/**
 * Walks every WP attachment shell and probes ORCA for the corresponding asset.
 * Shells whose asset returns 404 (or `s3_missing_at` is set) are marked as
 * broken via the `_orca_broken_at` meta. Recovered shells have the meta cleared.
 *
 * The scan is throttled (sleep between probes) and bounded by a wall-clock
 * budget. If the budget is exceeded, the scan reschedules itself to continue
 * from where it left off via the `_orca_scan_cursor` option.
 */
final class OrphanScanner
{
    public const BROKEN_META = '_orca_broken_at';
    private const CURSOR_OPTION = 'orca_dam_scan_cursor';
    private const LAST_RUN_OPTION = 'orca_dam_scan_last_run';
    private const BATCH_SIZE = 50;
    private const SLEEP_MICROS = 100_000; // 100ms between probes
    private const BUDGET_SECONDS = 540;   // bail at 9 minutes

    public function __construct(private readonly OrcaClient $client) {}

    public function run(): void
    {
        $startedAt = time();
        $cursor = (int) get_option(self::CURSOR_OPTION, 0);
        $processed = 0;

        while (true) {
            $batch = $this->fetchBatch($cursor);
            if ($batch === []) {
                delete_option(self::CURSOR_OPTION);
                update_option(self::LAST_RUN_OPTION, [
                    'finished_at' => time(),
                    'processed'   => $processed,
                ], false);
                return;
            }

            foreach ($batch as $row) {
                $this->probe((int) $row['attachment_id'], (int) $row['asset_id']);
                $cursor = (int) $row['attachment_id'];
                $processed++;
                usleep(self::SLEEP_MICROS);

                if ((time() - $startedAt) >= self::BUDGET_SECONDS) {
                    update_option(self::CURSOR_OPTION, $cursor, false);
                    if (function_exists('as_enqueue_async_action')) {
                        as_enqueue_async_action(CronScheduler::HOOK, [], 'orca-dam');
                    } else {
                        wp_schedule_single_event(time() + 60, CronScheduler::HOOK);
                    }
                    return;
                }
            }

            update_option(self::CURSOR_OPTION, $cursor, false);
        }
    }

    /**
     * Count of shells currently flagged broken.
     */
    public function brokenCount(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
            self::BROKEN_META,
        ));
    }

    /**
     * @return list<array{attachment_id: int, asset_id: int, post_title: string, broken_at: int}>
     */
    public function brokenItems(int $limit = 50): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.post_id AS attachment_id, pm.meta_value AS broken_at,
                    pm2.meta_value AS asset_id, p.post_title AS post_title
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = pm.post_id AND pm2.meta_key = %s
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s
             ORDER BY pm.meta_value DESC
             LIMIT %d",
            ShellFactory::META_ASSET_ID,
            self::BROKEN_META,
            $limit,
        ), ARRAY_A) ?: [];

        return array_map(static fn (array $r) => [
            'attachment_id' => (int) $r['attachment_id'],
            'asset_id'      => (int) $r['asset_id'],
            'post_title'    => (string) $r['post_title'],
            'broken_at'     => (int) $r['broken_at'],
        ], $rows);
    }

    /**
     * @return list<array{attachment_id: int, asset_id: int}>
     */
    private function fetchBatch(int $afterAttachmentId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id AS attachment_id, meta_value AS asset_id
             FROM {$wpdb->postmeta}
             WHERE meta_key = %s AND post_id > %d
             ORDER BY post_id ASC
             LIMIT %d",
            ShellFactory::META_ASSET_ID,
            $afterAttachmentId,
            self::BATCH_SIZE,
        ), ARRAY_A) ?: [];

        return array_map(static fn (array $r) => [
            'attachment_id' => (int) $r['attachment_id'],
            'asset_id'      => (int) $r['asset_id'],
        ], $rows);
    }

    private function probe(int $attachmentId, int $assetId): void
    {
        if ($assetId <= 0) {
            return;
        }
        $response = $this->client->getAsset($assetId);

        $isBroken = false;
        if ($response->status === 404) {
            $isBroken = true;
        } elseif ($response->ok()) {
            $missingAt = $response->body['s3_missing_at'] ?? null;
            if (! empty($missingAt)) {
                $isBroken = true;
            }
        } else {
            // Transient errors (5xx, timeouts) — leave existing state alone.
            return;
        }

        if ($isBroken) {
            update_post_meta($attachmentId, self::BROKEN_META, (string) time());
        } else {
            delete_post_meta($attachmentId, self::BROKEN_META);
        }
    }
}
