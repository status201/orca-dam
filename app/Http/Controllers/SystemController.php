<?php

namespace App\Http\Controllers;

use App\Models\Setting;
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
            's3_root_folder' => function ($v) {
                // Allow empty string (bucket root) or alphanumeric with hyphens, underscores, slashes
                return $v === '' || preg_match('/^[a-zA-Z0-9_\-\/]+$/', $v);
            },
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

        // Clear config cache before running tests to avoid cached config issues
        \Artisan::call('config:clear');

        // Find PHP CLI binary (PHP_BINARY might point to php-fpm which can't run CLI commands)
        $phpBinary = $this->findPhpCliBinary();
        $command = escapeshellarg($phpBinary).' artisan test --colors=never';

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

        // Set up environment for testing - inherit current env but override key values
        // These must match phpunit.xml to ensure tests run correctly
        $env = array_merge($_ENV, $_SERVER, [
            'APP_ENV' => 'testing',
            'APP_MAINTENANCE_DRIVER' => 'file',
            'BCRYPT_ROUNDS' => '4',
            'BROADCAST_CONNECTION' => 'null',
            'CACHE_STORE' => 'array',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'MAIL_MAILER' => 'array',
            'PULSE_ENABLED' => 'false',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'TELESCOPE_ENABLED' => 'false',
            'NIGHTWATCH_ENABLED' => 'false',
            'PATH' => $this->getExtendedPath(),
        ]);

        // Filter out non-string values that can cause issues
        $env = array_filter($env, fn ($value) => is_string($value));

        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $projectPath,
            $env
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
     * Find the PHP CLI binary path
     * PHP_BINARY might point to php-fpm which can't run CLI commands
     *
     * Configure via .env: PHP_CLI_PATH=/usr/bin/php8.2
     * On Plesk: PHP_CLI_PATH=/opt/plesk/php/8.2/bin/php
     */
    private function findPhpCliBinary(): string
    {
        // First check for explicit configuration
        $configuredPath = config('app.php_cli_path') ?: env('PHP_CLI_PATH');
        if ($configuredPath) {
            return $configuredPath;
        }

        // Check if PHP_BINARY is already CLI (not fpm/cgi)
        $phpBinary = PHP_BINARY;
        if (! str_contains($phpBinary, 'fpm') && ! str_contains($phpBinary, 'cgi')) {
            return $phpBinary;
        }

        // For FPM environments, return 'php' and rely on PATH
        return 'php';
    }

    /**
     * Get extended PATH for finding PHP CLI in restricted environments
     */
    private function getExtendedPath(): string
    {
        $currentPath = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';

        // Add common PHP CLI locations to PATH (including Plesk paths)
        $extraPaths = [
            '/usr/local/bin',
            '/usr/bin',
            '/bin',
            '/opt/plesk/php/8.4/bin',
            '/opt/plesk/php/8.3/bin',
            '/opt/plesk/php/8.2/bin',
            '/opt/plesk/php/8.1/bin',
        ];

        return implode(':', array_unique(array_merge($extraPaths, explode(':', $currentPath))));
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

            // Match individual passed test lines like "✓ asset belongs to a user    0.01s"
            if (preg_match('/^\s*[✓✔]\s*(.+?)\s+[\d\.]+s\s*$/u', $line, $matches)) {
                $stats['tests'][] = [
                    'name' => trim($matches[1]),
                    'suite' => $currentSuite,
                    'status' => 'passed',
                ];
            }
            // Match individual failed test lines with X mark like "✗ test name    0.01s"
            elseif (preg_match('/^\s*[✗✘×]\s*(.+?)\s+[\d\.]+s\s*$/u', $line, $matches)) {
                $stats['tests'][] = [
                    'name' => trim($matches[1]),
                    'suite' => $currentSuite,
                    'status' => 'failed',
                ];
            }
            // Match Pest failed test format: "FAILED  Tests\Feature\AssetTest > test name"
            elseif (preg_match('/^\s*FAILED\s+(.+?)\s*>\s*(.+?)\s*$/i', $line, $matches)) {
                $stats['tests'][] = [
                    'name' => trim($matches[2]),
                    'suite' => trim($matches[1]),
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
