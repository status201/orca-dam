<?php

namespace App\Http\Controllers;

use App\Jobs\RegenerateResizedImage;
use App\Jobs\RunTestSuiteJob;
use App\Models\Asset;
use App\Models\Setting;
use App\Services\ArtisanCommandRunner;
use App\Services\FolderRegistry;
use App\Services\QueueService;
use App\Services\SystemService;
use App\Services\TestRunnerService;
use App\Support\SettingRules;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use League\CommonMark\GithubFlavoredMarkdownConverter;

class SystemController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected SystemService $systemService,
        protected ArtisanCommandRunner $commands,
        protected TestRunnerService $testRunnerService,
        protected QueueService $queueService,
        protected FolderRegistry $folders,
    ) {}

    /**
     * Show system administration dashboard
     */
    public function index()
    {
        $this->authorize('access', SystemController::class);

        $data = [
            'queueStats' => $this->queueService->getQueueStats(),
            'systemInfo' => $this->systemService->getSystemInfo(),
            'diskUsage' => $this->systemService->getDiskUsage(),
            'databaseStats' => $this->systemService->getDatabaseStats(),
            'suggestedCommands' => $this->commands->suggestedCommands(),
            'settings' => Setting::query()->orderBy('group')->orderBy('key')->get()->toArray(),
            'availableLanguages' => config('orca.rekognition_languages'),
            'availableUiLanguages' => config('orca.ui_locales'),
            'availableTimezones' => timezone_identifiers_list(),
            'missingAssetsCount' => Asset::missing()->count(),
        ];

        return view('system.index', $data);
    }

    /**
     * Get queue status (AJAX)
     */
    public function queueStatus()
    {
        $this->authorize('access', SystemController::class);

        return response()->json([
            'stats' => $this->queueService->getQueueStats(),
            'pending_jobs' => $this->queueService->getPendingJobs(10),
            'failed_jobs' => $this->queueService->getFailedJobs(10),
        ]);
    }

    /**
     * Get log tail (AJAX)
     */
    public function logs(Request $request)
    {
        $this->authorize('access', SystemController::class);

        $lines = $request->input('lines', 50);
        $lines = min(max($lines, 10), 500); // Clamp between 10-500

        $logData = $this->systemService->getLogTail($lines);

        return response()->json($logData);
    }

    /**
     * Execute artisan command (AJAX)
     */
    public function executeCommand(Request $request)
    {
        $this->authorize('access', SystemController::class);

        $request->validate([
            'command' => 'required|string|max:255',
        ]);

        $command = trim($request->input('command'));
        $result = $this->commands->executeCommand($command);

        return response()->json($result);
    }

    /**
     * Test S3 connection
     */
    public function testS3()
    {
        $this->authorize('access', SystemController::class);

        $result = $this->systemService->testS3Connection();

        return response()->json($result);
    }

    /**
     * Get cache statistics
     */
    public function cacheStats()
    {
        $this->authorize('access', SystemController::class);

        $stats = $this->systemService->getCacheStats();

        return response()->json($stats);
    }

    /**
     * Retry failed job
     */
    public function retryJob(Request $request)
    {
        $this->authorize('access', SystemController::class);

        $request->validate([
            'job_id' => 'required|string',
        ]);

        $result = $this->commands->executeCommand('queue:retry '.$request->input('job_id'));

        return response()->json($result);
    }

    /**
     * Queue flush (clear all failed jobs)
     */
    public function flushQueue()
    {
        $this->authorize('access', SystemController::class);

        $result = $this->commands->executeCommand('queue:flush');

        return response()->json($result);
    }

    /**
     * Process pending queue jobs (bounded batch)
     */
    public function processQueue()
    {
        $this->authorize('access', SystemController::class);

        $result = $this->commands->executeCommand('queue:work --max-jobs=50 --tries=3 --stop-when-empty');

        return response()->json($result);
    }

    /**
     * Queue restart (signal workers to restart)
     */
    public function restartQueue()
    {
        $this->authorize('access', SystemController::class);

        $result = $this->commands->executeCommand('queue:restart');

        return response()->json($result);
    }

    /**
     * Get supervisor status (AJAX)
     */
    public function supervisorStatus()
    {
        $this->authorize('access', SystemController::class);

        $status = $this->systemService->getSupervisorStatus();

        return response()->json($status);
    }

    /**
     * Update a setting (AJAX)
     */
    public function updateSetting(Request $request)
    {
        $this->authorize('access', SystemController::class);

        $request->validate([
            'key' => 'required|string|max:255',
            'value' => 'present',
        ]);

        $key = $request->input('key');
        $value = $request->input('value') ?? '';

        if (! SettingRules::passes($key, $value)) {
            return response()->json([
                'success' => false,
                'error' => __('Invalid value for setting: :key', ['key' => $key]),
            ], 422);
        }

        $success = Setting::set($key, $value);

        // Every stored folder path is relative to the old root — rebuild the list
        if ($success && $key === 's3_root_folder') {
            $this->folders->forget();
        }

        return response()->json([
            'success' => $success,
            'message' => $success ? 'Setting updated successfully' : 'Failed to update setting',
        ]);
    }

    /**
     * Regenerate resized images for all image assets (AJAX)
     */
    public function regenerateResizedImages()
    {
        $this->authorize('access', SystemController::class);

        $assetIds = Asset::where('mime_type', 'like', 'image/%')->pluck('id');

        foreach ($assetIds as $assetId) {
            RegenerateResizedImage::dispatch($assetId);
        }

        return response()->json([
            'success' => true,
            'count' => $assetIds->count(),
            'message' => $assetIds->count().' resize job(s) dispatched',
        ]);
    }

    /**
     * Get S3 integrity status (AJAX)
     */
    public function integrityStatus()
    {
        $this->authorize('access', SystemController::class);

        return response()->json([
            'missing' => Asset::missing()->count(),
            'total' => Asset::count(),
        ]);
    }

    /**
     * Verify S3 integrity for all assets (AJAX)
     */
    public function verifyIntegrity()
    {
        $this->authorize('access', SystemController::class);

        \Artisan::call('assets:verify-integrity');

        $count = Asset::count();

        return response()->json([
            'success' => true,
            'count' => $count,
            'message' => $count.' integrity check(s) queued',
        ]);
    }

    /**
     * Dispatch a queued test run and return its run id immediately.
     */
    public function runTests(Request $request)
    {
        $this->authorize('access', SystemController::class);

        $request->validate([
            'suite' => 'nullable|string|in:all,unit,feature',
            'filter' => 'nullable|string|max:255',
        ]);

        $suite = $request->input('suite', 'all');
        $filter = $request->input('filter') ?: null;

        $runId = (string) Str::uuid();
        $this->testRunnerService->seedQueued($runId, $suite, $filter);

        RunTestSuiteJob::dispatch($runId, $suite, $filter);

        return response()->json([
            'success' => true,
            'run_id' => $runId,
            'status' => 'queued',
        ]);
    }

    /**
     * Return the live status payload for a test run.
     */
    public function runTestsStatus(string $runId)
    {
        $this->authorize('access', SystemController::class);

        $state = $this->testRunnerService->status($runId);

        if (! $state) {
            return response()->json(['success' => false, 'error' => __('Run not found')], 404);
        }

        return response()->json(['success' => true, 'state' => $state]);
    }

    /**
     * Abort a running test process.
     */
    public function runTestsAbort(string $runId)
    {
        $this->authorize('access', SystemController::class);

        $aborted = $this->testRunnerService->abort($runId);

        if (! $aborted) {
            return response()->json(['success' => false, 'error' => __('Run not found')], 404);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Get documentation file content (AJAX)
     */
    public function documentation(Request $request)
    {
        $this->authorize('access', SystemController::class);

        $allowedFiles = [
            'CHANGELOG.md',
            'CLAUDE.md',
            'DEPLOYMENT.md',
            'GEBRUIKERSHANDLEIDING.md',
            'QUICK_REFERENCE.md',
            'README.md',
            'RTE_INTEGRATION.md',
            'SETUP_GUIDE.md',
            'USER_MANUAL.md',
        ];

        $file = $request->input('file', 'USER_MANUAL.md');

        if (! in_array($file, $allowedFiles)) {
            return response()->json([
                'success' => false,
                'error' => __('Invalid documentation file'),
            ], 400);
        }

        $filePath = base_path($file);

        if (! file_exists($filePath)) {
            return response()->json([
                'success' => false,
                'error' => __('Documentation file not found'),
            ], 404);
        }

        try {
            $content = file_get_contents($filePath);

            $converter = new GithubFlavoredMarkdownConverter([
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ]);

            $html = $converter->convert($content)->getContent();

            return response()->json([
                'success' => true,
                'content' => $html,
                'file' => $file,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => __('Failed to parse documentation: :error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }
}
