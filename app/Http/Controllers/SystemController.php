<?php

namespace App\Http\Controllers;

use App\Jobs\RegenerateResizedImage;
use App\Models\Asset;
use App\Models\Setting;
use App\Services\QueueService;
use App\Services\SystemService;
use App\Services\TestRunnerService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use League\CommonMark\GithubFlavoredMarkdownConverter;

class SystemController extends Controller
{
    use AuthorizesRequests;

    protected SystemService $systemService;

    protected TestRunnerService $testRunnerService;

    protected QueueService $queueService;

    public function __construct(SystemService $systemService, TestRunnerService $testRunnerService, QueueService $queueService)
    {
        $this->systemService = $systemService;
        $this->testRunnerService = $testRunnerService;
        $this->queueService = $queueService;
    }

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
            'suggestedCommands' => $this->systemService->getSuggestedCommands(),
            'settings' => $this->systemService->getSettings(),
            'availableLanguages' => $this->systemService->getAvailableLanguages(),
            'availableUiLanguages' => $this->systemService->getAvailableUiLanguages(),
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
        $lines = min(max($lines, 10), 200); // Clamp between 10-200

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
        $result = $this->systemService->executeCommand($command);

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

        $result = $this->systemService->executeCommand('queue:retry '.$request->input('job_id'));

        return response()->json($result);
    }

    /**
     * Queue flush (clear all failed jobs)
     */
    public function flushQueue()
    {
        $this->authorize('access', SystemController::class);

        $result = $this->systemService->executeCommand('queue:flush');

        return response()->json($result);
    }

    /**
     * Process pending queue jobs (bounded batch)
     */
    public function processQueue()
    {
        $this->authorize('access', SystemController::class);

        $result = $this->systemService->executeCommand('queue:work --max-jobs=50 --tries=3 --stop-when-empty');

        return response()->json($result);
    }

    /**
     * Queue restart (signal workers to restart)
     */
    public function restartQueue()
    {
        $this->authorize('access', SystemController::class);

        $result = $this->systemService->executeCommand('queue:restart');

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

        // Validate specific settings
        $validationRules = [
            'items_per_page' => function ($v) {
                return is_numeric($v) && $v >= 10 && $v <= 100;
            },
            'rekognition_max_labels' => function ($v) {
                return is_numeric($v) && $v >= 1 && $v <= 20;
            },
            'rekognition_language' => function ($v) {
                $languages = array_keys($this->systemService->getAvailableLanguages());

                return in_array($v, $languages);
            },
            'rekognition_min_confidence' => function ($v) {
                return is_numeric($v) && $v >= 65 && $v <= 99;
            },
            'timezone' => function ($v) {
                return in_array($v, timezone_identifiers_list());
            },
            'locale' => function ($v) {
                $locales = array_keys($this->systemService->getAvailableUiLanguages());

                return in_array($v, $locales);
            },
            's3_root_folder' => function ($v) {
                // Allow empty string (bucket root) or alphanumeric with hyphens, underscores, slashes
                return $v === '' || preg_match('/^[a-zA-Z0-9_\-\/]+$/', $v);
            },
            'custom_domain' => function ($v) {
                // Allow empty (disabled) or a valid URL starting with http(s)://
                return $v === '' || preg_match('/^https?:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,}(\/.*)?$/', $v);
            },
            'resize_s_width' => fn ($v) => $v === '' || (is_numeric($v) && $v >= 50 && $v <= 5000),
            'resize_s_height' => fn ($v) => $v === '' || (is_numeric($v) && $v >= 50 && $v <= 5000),
            'resize_m_width' => fn ($v) => $v === '' || (is_numeric($v) && $v >= 50 && $v <= 5000),
            'resize_m_height' => fn ($v) => $v === '' || (is_numeric($v) && $v >= 50 && $v <= 5000),
            'resize_l_width' => fn ($v) => $v === '' || (is_numeric($v) && $v >= 50 && $v <= 5000),
            'resize_l_height' => fn ($v) => $v === '' || (is_numeric($v) && $v >= 50 && $v <= 5000),
        ];

        if (isset($validationRules[$key]) && ! $validationRules[$key]($value)) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid value for setting: '.$key,
            ], 422);
        }

        $success = $this->systemService->updateSetting($key, $value);

        // Clear cached folder list when root folder changes
        if ($success && $key === 's3_root_folder') {
            Setting::where('key', 's3_folders')->delete();
            cache()->forget('setting:s3_folders');
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
     * Run automated tests (AJAX)
     */
    public function runTests(Request $request)
    {
        $this->authorize('access', SystemController::class);

        $request->validate([
            'suite' => 'nullable|string|in:all,unit,feature',
            'filter' => 'nullable|string|max:255',
        ]);

        $result = $this->testRunnerService->run(
            $request->input('suite', 'all'),
            $request->input('filter')
        );

        if (isset($result['error'])) {
            return response()->json(['success' => false, 'error' => $result['error']], 500);
        }

        return response()->json($result);
    }

    /**
     * Get documentation file content (AJAX)
     */
    public function documentation(Request $request)
    {
        $this->authorize('access', SystemController::class);

        $allowedFiles = [
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
                'error' => 'Invalid documentation file',
            ], 400);
        }

        $filePath = base_path($file);

        if (! file_exists($filePath)) {
            return response()->json([
                'success' => false,
                'error' => 'Documentation file not found',
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
                'error' => 'Failed to parse documentation: '.$e->getMessage(),
            ], 500);
        }
    }
}
