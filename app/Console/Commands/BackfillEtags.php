<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Services\S3Service;
use Illuminate\Console\Command;

class BackfillEtags extends Command
{
    protected $signature = 'assets:backfill-etags';

    protected $description = 'Fetch and store etags from S3 for assets that have a null etag';

    public function handle(S3Service $s3Service): int
    {
        $assets = Asset::whereNull('etag')
            ->orWhere('etag', '')
            ->get();

        if ($assets->isEmpty()) {
            $this->info('All assets already have etags.');

            return Command::SUCCESS;
        }

        $this->info("Found {$assets->count()} asset(s) without etags. Fetching from S3...");

        $bar = $this->output->createProgressBar($assets->count());
        $bar->start();

        $updated = 0;
        $failed = 0;

        foreach ($assets as $asset) {
            $metadata = $s3Service->getObjectMetadata($asset->s3_key);

            if ($metadata && ! empty($metadata['etag'])) {
                $asset->update(['etag' => $metadata['etag']]);
                $updated++;
            } else {
                $this->newLine();
                $this->warn("  Could not fetch etag for #{$asset->id} ({$asset->s3_key})");
                $failed++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Updated: {$updated}, Failed: {$failed}");

        return Command::SUCCESS;
    }
}
