<?php

declare(strict_types=1);

namespace OrcaDam\Tests\Unit;

use Brain\Monkey\Functions;
use OrcaDam\Api\Transport\TransportResponse;
use OrcaDam\Attachments\ShellFactory;
use OrcaDam\Maintenance\CronScheduler;
use OrcaDam\Maintenance\OrphanScanner;
use OrcaDam\Tests\Support\FakeOrca;
use OrcaDam\Tests\Support\WpTestCase;
use PHPUnit\Framework\Assert;

/**
 * `time()` and `usleep()` are faked through Patchwork (see patchwork.json), so the
 * 100ms throttle costs nothing and the 9-minute budget can be crossed on demand.
 */
final class OrphanScannerTest extends WpTestCase
{
    private int $now = 1_800_000_000;

    /** Seconds the fake clock advances per throttle sleep. */
    private int $secondsPerProbe = 0;

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<int, string> attachment id → broken_at */
    private array $broken = [];

    /** @var list<array{0: int, 1: string, 2: list<mixed>}> */
    private array $scheduled = [];

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('time')->alias(fn () => $this->now);
        Functions\when('usleep')->alias(function (): void {
            $this->now += $this->secondsPerProbe;
        });
        Functions\when('get_option')->alias(fn (string $key, $default = false) => $this->options[$key] ?? $default);
        Functions\when('update_option')->alias(function (string $key, $value): bool {
            $this->options[$key] = $value;
            return true;
        });
        Functions\when('delete_option')->alias(function (string $key): bool {
            unset($this->options[$key]);
            return true;
        });
        Functions\when('update_post_meta')->alias(function (int $id, string $key, $value): bool {
            $this->assertSame(OrphanScanner::BROKEN_META, $key);
            $this->broken[$id] = $value;
            return true;
        });
        Functions\when('delete_post_meta')->alias(function (int $id, string $key): bool {
            $this->assertSame(OrphanScanner::BROKEN_META, $key);
            unset($this->broken[$id]);
            return true;
        });
        Functions\when('wp_schedule_single_event')->alias(function (int $at, string $hook, array $args = []): bool {
            $this->scheduled[] = [$at, $hook, $args];
            return true;
        });
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    /**
     * @param array<int, int> $shells attachment id → ORCA asset id
     */
    private function shells(array $shells): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb($shells);
    }

    /**
     * @param array<int, TransportResponse> $byAssetId
     * @return array{0: \OrcaDam\Api\OrcaClient, 1: FakeOrca}
     */
    private function orca(array $byAssetId): array
    {
        return FakeOrca::client(function (string $method, string $url) use ($byAssetId): TransportResponse {
            $assetId = (int) substr($url, strrpos($url, '/') + 1);
            return $byAssetId[$assetId] ?? new TransportResponse(200, ['id' => $assetId]);
        });
    }

    public function test_marks_missing_assets_broken_and_clears_recovered_ones(): void
    {
        $this->shells([10 => 100, 11 => 101, 12 => 102, 13 => 103]);
        $this->broken = [12 => '1700000000', 13 => '1700000000'];
        [$client] = $this->orca([
            100 => new TransportResponse(404, ['message' => 'Not found']),
            101 => new TransportResponse(200, ['id' => 101, 's3_missing_at' => '2026-10-01T00:00:00Z']),
            102 => new TransportResponse(200, ['id' => 102, 's3_missing_at' => null]),
            103 => new TransportResponse(503, []),
        ]);

        (new OrphanScanner($client))->run();

        ksort($this->broken);
        $this->assertSame([
            10 => (string) $this->now,     // 404: asset deleted in ORCA
            11 => (string) $this->now,     // S3 object missing
            13 => '1700000000',            // 5xx is transient: earlier flag kept
        ], $this->broken);                 // 12 recovered, so its flag is cleared
    }

    public function test_a_transient_error_does_not_flag_a_healthy_shell(): void
    {
        $this->shells([10 => 100]);
        [$client] = $this->orca([100 => new TransportResponse(0, ['message' => 'timed out'])]);

        (new OrphanScanner($client))->run();

        $this->assertSame([], $this->broken);
    }

    public function test_skips_shells_without_a_valid_asset_id(): void
    {
        $this->shells([10 => 0]);
        [$client, $orca] = $this->orca([]);

        (new OrphanScanner($client))->run();

        $this->assertSame([], $orca->requests);
    }

    public function test_a_finished_scan_records_its_run_and_drops_the_cursor(): void
    {
        $this->shells([10 => 100, 11 => 101, 12 => 0]);
        $this->options['orca_dam_scan_cursor'] = 0;
        [$client] = $this->orca([]);

        (new OrphanScanner($client))->run();

        $this->assertArrayNotHasKey('orca_dam_scan_cursor', $this->options);
        $this->assertSame(['finished_at' => $this->now, 'processed' => 3], $this->options['orca_dam_scan_last_run']);
        $this->assertSame([], $this->scheduled);
    }

    public function test_walks_every_batch_of_a_large_library(): void
    {
        $shells = [];
        for ($id = 1; $id <= 120; $id++) {
            $shells[$id] = 1000 + $id;
        }
        $this->shells($shells);
        [$client, $orca] = $this->orca([]);

        (new OrphanScanner($client))->run();

        $this->assertCount(120, $orca->requests);
        $this->assertSame(120, $this->options['orca_dam_scan_last_run']['processed']);
    }

    public function test_stops_at_the_time_budget_and_reschedules_itself(): void
    {
        $this->shells([10 => 100, 11 => 101, 12 => 102]);
        $this->secondsPerProbe = 300; // the second probe crosses the 540s budget
        [$client, $orca] = $this->orca([]);

        (new OrphanScanner($client))->run();

        $this->assertCount(2, $orca->requests);
        $this->assertSame(11, $this->options['orca_dam_scan_cursor']);
        $this->assertArrayNotHasKey('orca_dam_scan_last_run', $this->options);
        $this->assertSame([[$this->now + 60, CronScheduler::HOOK, []]], $this->scheduled);
    }

    public function test_a_rescheduled_scan_resumes_after_the_cursor(): void
    {
        $this->shells([10 => 100, 11 => 101, 12 => 102]);
        $this->options['orca_dam_scan_cursor'] = 11;
        [$client, $orca] = $this->orca([]);

        (new OrphanScanner($client))->run();

        $this->assertSame(['https://dam.test/api/assets/102'], array_column($orca->requests, 'url'));
        $this->assertSame(1, $this->options['orca_dam_scan_last_run']['processed']);
    }

    public function test_broken_items_are_cast_to_their_documented_types(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb([], [
            ['attachment_id' => '12', 'asset_id' => '102', 'post_title' => 'logo.png', 'broken_at' => '1800000000'],
        ]);
        [$client] = $this->orca([]);

        $this->assertSame(
            [['attachment_id' => 12, 'asset_id' => 102, 'post_title' => 'logo.png', 'broken_at' => 1800000000]],
            (new OrphanScanner($client))->brokenItems(),
        );
    }
}

/**
 * Just enough `$wpdb` for OrphanScanner: answers the shell-batch query from a list
 * of shells (as strings, like the real one) and any other query with `$otherRows`.
 */
final class FakeWpdb
{
    public string $postmeta = 'wp_postmeta';
    public string $posts = 'wp_posts';

    /**
     * @param array<int, int> $shells attachment id → asset id
     * @param list<array<string, string>> $otherRows
     */
    public function __construct(private readonly array $shells, private readonly array $otherRows = []) {}

    /** @return array{0: string, 1: list<mixed>} */
    public function prepare(string $query, mixed ...$args): array
    {
        return [$query, $args];
    }

    /**
     * @param array{0: string, 1: list<mixed>} $prepared
     * @return list<array<string, string>>
     */
    public function get_results(array $prepared, string $output): array
    {
        [$query, $args] = $prepared;
        if (! str_contains($query, 'post_id > %d')) {
            return $this->otherRows;
        }

        [$metaKey, $after, $limit] = $args;
        Assert::assertSame(ShellFactory::META_ASSET_ID, $metaKey);

        $rows = [];
        foreach ($this->shells as $attachmentId => $assetId) {
            if ($attachmentId > $after) {
                $rows[] = ['attachment_id' => (string) $attachmentId, 'asset_id' => (string) $assetId];
            }
        }
        return array_slice($rows, 0, $limit);
    }
}
