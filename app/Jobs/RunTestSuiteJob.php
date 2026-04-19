<?php

namespace App\Jobs;

use App\Services\TestRunnerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunTestSuiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 900;

    public function __construct(
        public string $runId,
        public string $suite = 'all',
        public ?string $filter = null,
    ) {}

    public function handle(TestRunnerService $service): void
    {
        try {
            $service->runStreaming($this->runId, $this->suite, $this->filter);
        } catch (Throwable $e) {
            Log::error("RunTestSuiteJob failed for run {$this->runId}: ".$e->getMessage());
            $service->markFailed($this->runId, $e->getMessage());
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        app(TestRunnerService::class)->markFailed($this->runId, $exception->getMessage());
    }
}
