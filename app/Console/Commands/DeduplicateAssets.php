<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Services\S3Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DeduplicateAssets extends Command
{
    protected $signature = 'assets:deduplicate {--force : Actually soft-delete duplicates (default is dry-run)}';

    protected $description = 'Find and soft-delete duplicate assets based on etag (keeps oldest, skips assets with reference tags)';

    public function handle(S3Service $s3Service): int
    {
        $this->info('Scanning for duplicate assets by etag...');

        // Group assets by etag, excluding nulls
        $duplicateGroups = Asset::whereNotNull('etag')
            ->where('etag', '!=', '')
            ->selectRaw('etag, COUNT(*) as count')
            ->groupBy('etag')
            ->having('count', '>', 1)
            ->pluck('count', 'etag');

        if ($duplicateGroups->isEmpty()) {
            $this->info('No duplicates found.');

            return Command::SUCCESS;
        }

        $this->info("Found {$duplicateGroups->count()} group(s) of duplicates.");

        $totalDuplicates = 0;
        $toDelete = 0;
        $skippedRefTags = 0;
        $deleted = 0;
        $isDryRun = ! $this->option('force');

        if ($isDryRun) {
            $this->warn('DRY RUN — no changes will be made. Use --force to soft-delete duplicates.');
            $this->newLine();
        }

        foreach ($duplicateGroups as $etag => $count) {
            $assets = Asset::where('etag', $etag)
                ->with(['tags' => fn ($q) => $q->where('type', 'reference')])
                ->orderBy('created_at', 'asc')
                ->get();

            $keeper = $assets->first();
            $duplicates = $assets->slice(1);
            $totalDuplicates += $duplicates->count();

            $this->line("Etag: {$etag}");
            $this->line("  Keep: #{$keeper->id} — {$keeper->filename} ({$keeper->s3_key})");

            foreach ($duplicates as $dupe) {
                $hasRefTags = $dupe->tags->where('type', 'reference')->isNotEmpty();

                if ($hasRefTags) {
                    $refTagNames = $dupe->tags->where('type', 'reference')->pluck('name')->implode(', ');
                    $this->warn("  Skip: #{$dupe->id} — {$dupe->filename} (has reference tags: {$refTagNames})");
                    $skippedRefTags++;

                    continue;
                }

                $toDelete++;

                if ($isDryRun) {
                    $this->line("  Would delete: #{$dupe->id} — {$dupe->filename} ({$dupe->s3_key})");
                } else {
                    $dupe->delete(); // Soft delete
                    $deleted++;
                    $this->line("  Soft-deleted: #{$dupe->id} — {$dupe->filename} ({$dupe->s3_key})");
                    Log::info("Deduplicate: soft-deleted asset #{$dupe->id} (duplicate of #{$keeper->id}, etag: {$etag})");
                }
            }

            $this->newLine();
        }

        $this->info('Summary:');
        $this->info("  Duplicate groups: {$duplicateGroups->count()}");
        $this->info("  Total duplicates: {$totalDuplicates}");
        $this->info("  Skipped (reference tags): {$skippedRefTags}");

        if ($isDryRun) {
            $this->info("  Would soft-delete: {$toDelete}");
            $this->newLine();
            $this->warn('Run with --force to apply changes.');
        } else {
            $this->info("  Soft-deleted: {$deleted}");
        }

        return Command::SUCCESS;
    }
}
