<?php

namespace App\Console\Commands;

use App\Models\UploadSession;
use App\Services\ChunkedUploadService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupStaleUploads extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'uploads:cleanup {--hours=24 : Hours after which uploads are considered stale}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleanup stale chunked upload sessions and abort incomplete S3 multipart uploads';

    protected ChunkedUploadService $chunkedUploadService;

    public function __construct(ChunkedUploadService $chunkedUploadService)
    {
        parent::__construct();
        $this->chunkedUploadService = $chunkedUploadService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $threshold = now()->subHours($hours);

        $this->info("Cleaning up upload sessions older than {$hours} hours...");

        // Find stale sessions (pending or uploading, with no activity)
        $staleSessions = UploadSession::whereIn('status', ['pending', 'uploading'])
            ->where('last_activity_at', '<', $threshold)
            ->get();

        if ($staleSessions->isEmpty()) {
            $this->info('No stale upload sessions found.');
            return Command::SUCCESS;
        }

        $this->info("Found {$staleSessions->count()} stale upload session(s).");

        $aborted = 0;
        $failed = 0;

        foreach ($staleSessions as $session) {
            try {
                $this->chunkedUploadService->abortUpload($session);
                $aborted++;
                $this->line("✓ Aborted session: {$session->session_token} ({$session->filename})");
            } catch (\Exception $e) {
                $failed++;
                $this->error("✗ Failed to abort session {$session->session_token}: {$e->getMessage()}");
                Log::error("Cleanup failed for session {$session->session_token}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $this->info("Cleanup complete:");
        $this->table(
            ['Status', 'Count'],
            [
                ['Aborted', $aborted],
                ['Failed', $failed],
                ['Total', $staleSessions->count()],
            ]
        );

        return Command::SUCCESS;
    }
}
