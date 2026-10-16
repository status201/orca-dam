<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Services\CloudflareService;
use App\Services\S3Service;
use App\Support\UploadPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Finds stored objects served with headers their extension doesn't call for — what uploads stored
 * before upload-policy REQ-content-type-from-extension, when the uploader chose the type. Dry run
 * by default; --fix rewrites the headers in place (maintenance-commands REQ-audit-content-types).
 */
class AuditContentTypesCommand extends Command
{
    protected $signature = 'assets:audit-content-types
                            {--fix : Rewrite the headers of every mismatched object in place (default is a dry-run report)}';

    protected $description = 'Find stored objects served with a Content-Type or Content-Disposition their extension does not call for';

    /** Rows listed before the report summarises the rest as a count. */
    private const LIST_LIMIT = 100;

    public function handle(S3Service $s3Service, CloudflareService $cloudflare): int
    {
        $fix = (bool) $this->option('fix');

        $this->info('Auditing the served headers of every stored asset, trashed ones included...');
        if (! $fix) {
            $this->warn('DRY RUN — nothing is changed. Use --fix to rewrite the mismatched headers.');
        }
        $this->newLine();

        $checked = 0;
        $missing = 0;
        $mismatches = [];

        // Trashed assets too: soft delete keeps the object, and it stays publicly readable.
        Asset::withTrashed()
            ->select(['id', 's3_key', 'etag', 'deleted_at'])
            ->chunkById(500, function ($assets) use ($s3Service, &$checked, &$missing, &$mismatches) {
                $headers = $s3Service->getObjectHeaders($assets->pluck('s3_key')->all());

                foreach ($assets as $asset) {
                    $checked++;
                    $stored = $headers[$asset->s3_key] ?? null;

                    if ($stored === null) {
                        $missing++;

                        continue;
                    }

                    $mismatch = UploadPolicy::servedHeaderMismatch(
                        $asset->s3_key,
                        $stored['content_type'],
                        $stored['content_disposition']
                    );
                    if ($mismatch !== null) {
                        $mismatches[] = ['asset' => $asset] + $mismatch;
                    }
                }
            });

        // Dangerous first: those are the ones to look at.
        usort($mismatches, fn ($a, $b) => $b['dangerous'] <=> $a['dangerous']);
        $dangerous = count(array_filter($mismatches, fn ($m) => $m['dangerous']));

        if ($mismatches === []) {
            $this->info('Every stored object is served with the headers its extension calls for.');
        } else {
            $this->listMismatches($mismatches);
        }

        $fixed = 0;
        $failed = 0;
        if ($fix && $mismatches !== []) {
            [$fixed, $failed] = $this->repair($mismatches, $s3Service, $cloudflare);
        }

        $this->newLine();
        $rows = [
            ['Checked', $checked],
            ['Mismatched', count($mismatches)],
            ['Dangerous (active type served inline)', $dangerous],
            ['Missing or unreadable (see assets:verify-integrity)', $missing],
        ];
        if ($fix) {
            $rows[] = ['Fixed', $fixed];
            $rows[] = ['Failed', $failed];
        }
        $this->table(['Result', 'Count'], $rows);

        if (! $fix && $mismatches !== []) {
            $this->warn('Run with --fix to rewrite these headers in place (same key, same bytes).');
        }

        return count($mismatches) - $fixed === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param  list<array{asset: Asset, type: string, attachment: bool, expected_type: string, expected_attachment: bool, dangerous: bool}>  $mismatches
     */
    private function listMismatches(array $mismatches): void
    {
        $this->table(
            ['Asset', 'Key', 'Stored type', 'Served', 'Expected', 'Risk'],
            array_map(fn ($m) => [
                '#'.$m['asset']->id.($m['asset']->deleted_at ? ' (trashed)' : ''),
                $m['asset']->s3_key,
                $m['type'] !== '' ? $m['type'] : '(none)',
                $m['attachment'] ? 'download' : 'inline',
                $m['expected_type'].($m['expected_attachment'] ? ', download' : ', inline'),
                $m['dangerous'] ? 'DANGEROUS' : 'mismatch',
            ], array_slice($mismatches, 0, self::LIST_LIMIT))
        );

        if (count($mismatches) > self::LIST_LIMIT) {
            $this->line('... and '.(count($mismatches) - self::LIST_LIMIT).' more.');
        }
    }

    /**
     * Rewrite each mismatched object's headers, keep the asset's etag in step with the copy, and
     * purge the original's URL from Cloudflare (a no-op unless purging is configured).
     *
     * @param  list<array{asset: Asset, dangerous: bool}>  $mismatches
     * @return array{0: int, 1: int} fixed, failed
     */
    private function repair(array $mismatches, S3Service $s3Service, CloudflareService $cloudflare): array
    {
        $fixed = 0;
        $failed = 0;
        $purge = [];

        foreach ($mismatches as $mismatch) {
            $asset = $mismatch['asset'];
            $etag = $s3Service->repairUploadHeaders($asset->s3_key);

            if ($etag === null) {
                $failed++;
                $this->error("✗ Could not rewrite #{$asset->id} ({$asset->s3_key})");

                continue;
            }

            $fixed++;
            if ($etag !== $asset->etag) {
                // A query update: a header repair is not an edit, so no model events or audit trail.
                Asset::withTrashed()->whereKey($asset->id)->update(['etag' => $etag]);
            }
            $purge[] = $s3Service->getUrl($asset->s3_key);
        }

        // Cloudflare accepts 30 URLs per purge call.
        foreach (array_chunk($purge, 30) as $batch) {
            $cloudflare->purgeUrls($batch);
        }

        Log::info('assets:audit-content-types rewrote stored headers', ['fixed' => $fixed, 'failed' => $failed]);

        return [$fixed, $failed];
    }
}
