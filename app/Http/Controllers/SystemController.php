<?php

namespace App\Http\Controllers;

use App\Services\SystemService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use League\CommonMark\GithubFlavoredMarkdownConverter;

class SystemController extends Controller
{
    use AuthorizesRequests;

    protected SystemService $systemService;

    public function __construct(SystemService $systemService)
    {
        $this->systemService = $systemService;
    }

    /**
     * Show system administration dashboard
     */
    public function index()
    {
        $this->authorize('access', SystemController::class);

        $data = [
            'queueStats' => $this->systemService->getQueueStats(),
            'systemInfo' => $this->systemService->getSystemInfo(),
            'diskUsage' => $this->systemService->getDiskUsage(),
            'databaseStats' => $this->systemService->getDatabaseStats(),
            'suggestedCommands' => $this->systemService->getSuggestedCommands(),
            'settings' => $this->systemService->getSettings(),
            'availableLanguages' => $this->systemService->getAvailableLanguages(),
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
            'stats' => $this->systemService->getQueueStats(),
            'pending_jobs' => $this->systemService->getPendingJobs(10),
            'failed_jobs' => $this->systemService->getFailedJobs(10),
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
            'value' => 'required',
        ]);

        $key = $request->input('key');
        $value = $request->input('value');

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
        ];

        if (isset($validationRules[$key]) && ! $validationRules[$key]($value)) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid value for setting: '.$key,
            ], 422);
        }

        $success = $this->systemService->updateSetting($key, $value);

        return response()->json([
            'success' => $success,
            'message' => $success ? 'Setting updated successfully' : 'Failed to update setting',
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

        $suite = $request->input('suite', 'all');
        $filter = $request->input('filter');

        // Build command
        $command = 'php artisan test --colors=never';

        if ($suite !== 'all') {
            $command .= ' --testsuite='.ucfirst($suite);
        }

        if ($filter) {
            $command .= ' --filter='.escapeshellarg($filter);
        }

        $startTime = microtime(true);

        // Execute tests
        $output = [];
        $exitCode = 0;

        // Change to project directory and run tests
        $projectPath = base_path();

        // Use proc_open for better control
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $projectPath,
            null
        );

        if (is_resource($process)) {
            fclose($pipes[0]);

            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);

            $output = $stdout.$stderr;
        } else {
            return response()->json([
                'success' => false,
                'error' => 'Failed to start test process',
            ], 500);
        }

        $duration = round(microtime(true) - $startTime, 2);

        // Parse output for statistics
        $stats = $this->parseTestOutput($output);
        $stats['duration'] = $duration;
        $stats['exit_code'] = $exitCode;
        $stats['success'] = $exitCode === 0;

        return response()->json([
            'success' => true,
            'output' => $output,
            'stats' => $stats,
        ]);
    }

    /**
     * Parse test output for statistics
     */
    private function parseTestOutput(string $output): array
    {
        $stats = [
            'total' => 0,
            'passed' => 0,
            'failed' => 0,
            'skipped' => 0,
            'assertions' => 0,
            'tests' => [],
        ];

        // Parse the summary line - handles multiple formats:
        // "Tests: 101 passed (244 assertions)"
        // "Tests: 31 failed, 70 passed (185 assertions)"
        // "Tests: 1 failed, 100 passed, 2 skipped (244 assertions)"

        // Match passed count - can appear as "X passed" anywhere in the Tests line
        if (preg_match('/(\d+)\s*passed/i', $output, $matches)) {
            $stats['passed'] = (int) $matches[1];
        }

        // Match failed count
        if (preg_match('/(\d+)\s*failed/i', $output, $matches)) {
            $stats['failed'] = (int) $matches[1];
        }

        // Match skipped count
        if (preg_match('/(\d+)\s*skipped/i', $output, $matches)) {
            $stats['skipped'] = (int) $matches[1];
        }

        // Calculate total
        $stats['total'] = $stats['passed'] + $stats['failed'] + $stats['skipped'];

        // Parse assertions
        if (preg_match('/\((\d+)\s*assertions?\)/i', $output, $matches)) {
            $stats['assertions'] = (int) $matches[1];
        }

        // Parse individual test results
        $lines = explode("\n", $output);
        $currentSuite = '';

        foreach ($lines as $line) {
            // Match test suite headers like "PASS Tests\Unit\AssetTest"
            if (preg_match('/^\s*(PASS|FAIL)\s+(.+)$/i', $line, $matches)) {
                $currentSuite = trim($matches[2]);
            }

            // Match individual test lines like "✓ asset belongs to a user"
            if (preg_match('/^\s*[✓✔]\s*(.+?)\s+[\d\.]+s\s*$/u', $line, $matches)) {
                $stats['tests'][] = [
                    'name' => trim($matches[1]),
                    'suite' => $currentSuite,
                    'status' => 'passed',
                ];
            } elseif (preg_match('/^\s*[✗✘×]\s*(.+?)\s+[\d\.]+s\s*$/u', $line, $matches)) {
                $stats['tests'][] = [
                    'name' => trim($matches[1]),
                    'suite' => $currentSuite,
                    'status' => 'failed',
                ];
            }
        }

        return $stats;
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
            'QUICK_REFERENCE.md',
            'README.md',
            'RTE_INTEGRATION.md',
            'SETUP_GUIDE.md',
        ];

        $file = $request->input('file', 'README.md');

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
