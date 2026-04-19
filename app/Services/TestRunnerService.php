<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class TestRunnerService
{
    public const CACHE_PREFIX = 'test_run:';

    public const CACHE_TTL_SECONDS = 3600;

    private const ESTIMATE_KEY_PREFIX = 'test_run_estimate:';

    private const ESTIMATE_TTL_SECONDS = 2592000; // 30 days

    private const TICK_INTERVAL_MS = 250;

    private const OUTPUT_TAIL_BYTES = 16384;

    // Max bytes of full output we persist on completion. 2 MiB comfortably
    // covers the full 629-test suite (~60-120 KiB) with headroom, without
    // letting a runaway subprocess balloon the cache entry.
    private const OUTPUT_FULL_MAX_BYTES = 2_097_152;

    /**
     * Run the test suite in streaming mode, writing live progress to the cache.
     *
     * Safe to invoke from a queued job: the entire subprocess lifecycle is owned
     * here, and the caller is expected to look up results via `status()`.
     */
    public function runStreaming(string $runId, string $suite = 'all', ?string $filter = null): void
    {
        // Clear the bootstrap config cache so the subprocess doesn't pick up a
        // stale compiled config. Do NOT touch the application cache here —
        // that would wipe the run-status entry the controller just seeded.
        \Artisan::call('config:clear');

        $phpCli = $this->findPhpCliBinary();
        $env = $this->buildEnv();

        // Pest/PHPUnit on Windows fully buffers subprocess stdout until exit,
        // so we can't stream per-test counters. Instead we drive the progress
        // bar off wall-clock elapsed vs. the last successful run's duration.
        $estimate = $this->loadEstimate($suite, $filter);

        $this->writeCache($runId, [
            'status' => 'running',
            'completed' => 0,
            'passed' => 0,
            'failed' => 0,
            'skipped' => 0,
            'current_suite' => null,
            'estimate' => $estimate,
            'started_at' => microtime(true),
            'duration' => 0,
            'pid' => null,
            'output_tail' => '',
            'stats' => null,
            'exit_code' => null,
            'error' => null,
        ]);

        $command = $this->buildTestCommand($phpCli, $suite, $filter);
        $this->runProcess($runId, $command, $env, $suite, $filter);
    }

    /**
     * Seed the cache for a freshly dispatched run so polling endpoints return
     * a queued payload before the job starts executing.
     */
    public function seedQueued(string $runId, string $suite, ?string $filter): void
    {
        Cache::put(self::CACHE_PREFIX.$runId, [
            'status' => 'queued',
            'completed' => 0,
            'passed' => 0,
            'failed' => 0,
            'skipped' => 0,
            'current_suite' => null,
            'estimate' => $this->loadEstimate($suite, $filter),
            'started_at' => microtime(true),
            'duration' => 0,
            'pid' => null,
            'output_tail' => '',
            'stats' => null,
            'exit_code' => null,
            'error' => null,
            'suite' => $suite,
            'filter' => $filter,
        ], self::CACHE_TTL_SECONDS);
    }

    private function estimateKey(string $suite, ?string $filter): string
    {
        return self::ESTIMATE_KEY_PREFIX.$suite.':'.md5((string) $filter);
    }

    private function loadEstimate(string $suite, ?string $filter): ?float
    {
        $value = Cache::get($this->estimateKey($suite, $filter));

        return is_numeric($value) ? (float) $value : null;
    }

    private function saveEstimate(string $suite, ?string $filter, float $duration): void
    {
        if ($duration <= 0) {
            return;
        }
        Cache::put($this->estimateKey($suite, $filter), $duration, self::ESTIMATE_TTL_SECONDS);
    }

    public function status(string $runId): ?array
    {
        return Cache::get(self::CACHE_PREFIX.$runId);
    }

    public function markFailed(string $runId, string $message): void
    {
        $current = Cache::get(self::CACHE_PREFIX.$runId, []);
        $current['status'] = 'failed';
        $current['error'] = $message;
        $current['duration'] = isset($current['started_at'])
            ? round(microtime(true) - $current['started_at'], 2)
            : 0;
        Cache::put(self::CACHE_PREFIX.$runId, $current, self::CACHE_TTL_SECONDS);
    }

    public function abort(string $runId): bool
    {
        $state = Cache::get(self::CACHE_PREFIX.$runId);
        if (! $state) {
            return false;
        }

        $pid = $state['pid'] ?? null;
        if ($pid) {
            $this->killProcess((int) $pid);
        }

        $state['status'] = 'aborted';
        $state['duration'] = isset($state['started_at'])
            ? round(microtime(true) - $state['started_at'], 2)
            : 0;
        Cache::put(self::CACHE_PREFIX.$runId, $state, self::CACHE_TTL_SECONDS);

        return true;
    }

    private function runProcess(string $runId, string $command, array $env, string $suite, ?string $filter): void
    {
        // On Windows, stream_set_blocking() doesn't actually make pipes from
        // proc_open non-blocking (it returns true but fread() still blocks
        // until the subprocess exits). Pest/PHPUnit only flush at exit, so a
        // tick loop reading from pipes would run exactly once. We dodge the
        // whole class of issues by routing stdout/stderr through a temp file:
        // the file grows in the background and our loop only checks process
        // liveness, so ticks fire every TICK_INTERVAL_MS regardless of OS.
        $tmpFile = tempnam(sys_get_temp_dir(), 'orca_testrun_');
        if ($tmpFile === false) {
            $this->markFailed($runId, 'Failed to allocate temp log file');

            return;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $tmpFile, 'w'],
            2 => ['file', $tmpFile, 'a'],
        ];

        $process = proc_open($command, $descriptors, $pipes, base_path(), $env);

        if (! is_resource($process)) {
            @unlink($tmpFile);
            $this->markFailed($runId, 'Failed to start test process');

            return;
        }

        fclose($pipes[0]);

        $status = proc_get_status($process);
        $state = Cache::get(self::CACHE_PREFIX.$runId, []);
        $state['pid'] = $status['pid'] ?? null;
        Cache::put(self::CACHE_PREFIX.$runId, $state, self::CACHE_TTL_SECONDS);

        while (true) {
            $this->tickElapsed($runId, $tmpFile);

            $procStatus = proc_get_status($process);
            if (! $procStatus['running']) {
                break;
            }

            // Honour aborts within ~1 tick by checking the cache flag.
            $state = Cache::get(self::CACHE_PREFIX.$runId);
            if ($state && ($state['status'] ?? null) === 'aborted') {
                $this->killProcess((int) ($procStatus['pid'] ?? 0));
                break;
            }

            usleep(self::TICK_INTERVAL_MS * 1000);
        }

        $exitCode = proc_close($process);
        $outputAll = $this->stripAnsi(@file_get_contents($tmpFile) ?: '');
        @unlink($tmpFile);

        $stats = $this->parseTestOutput($outputAll);
        $stats['exit_code'] = $exitCode;
        $stats['success'] = $exitCode === 0;

        $final = Cache::get(self::CACHE_PREFIX.$runId, []);
        $fullOutput = $this->capFullOutput($outputAll);

        // Respect a user-initiated abort: don't overwrite its status.
        if (($final['status'] ?? null) === 'aborted') {
            $final['exit_code'] = $exitCode;
            $final['duration'] = round(microtime(true) - ($final['started_at'] ?? microtime(true)), 2);
            $final['output_tail'] = $fullOutput;
            Cache::put(self::CACHE_PREFIX.$runId, $final, self::CACHE_TTL_SECONDS);

            return;
        }

        $duration = round(microtime(true) - ($final['started_at'] ?? microtime(true)), 2);
        $stats['duration'] = $duration;

        $final['status'] = $exitCode === 0 ? 'completed' : 'failed';
        $final['exit_code'] = $exitCode;
        $final['duration'] = $duration;
        $final['stats'] = $stats;
        $final['output_tail'] = $fullOutput;
        $final['passed'] = $stats['passed'];
        $final['failed'] = $stats['failed'];
        $final['skipped'] = $stats['skipped'];
        $final['completed'] = $stats['passed'] + $stats['failed'] + $stats['skipped'];
        Cache::put(self::CACHE_PREFIX.$runId, $final, self::CACHE_TTL_SECONDS);

        if ($exitCode === 0) {
            $this->saveEstimate($suite, $filter, (float) $duration);
        }
    }

    private function tickElapsed(string $runId, string $tmpFile): void
    {
        $state = Cache::get(self::CACHE_PREFIX.$runId);
        if (! $state || ($state['status'] ?? null) === 'aborted') {
            return;
        }
        $state['duration'] = round(microtime(true) - ($state['started_at'] ?? microtime(true)), 2);

        clearstatcache(true, $tmpFile);
        $size = @filesize($tmpFile);
        if ($size !== false && $size > 0) {
            // Read a slightly bigger window than we keep so ANSI-stripping
            // doesn't leave us with less than OUTPUT_TAIL_BYTES of plain text.
            $window = self::OUTPUT_TAIL_BYTES * 2;
            $offset = max(0, $size - $window);
            $fp = @fopen($tmpFile, 'r');
            if ($fp !== false) {
                if ($offset > 0) {
                    fseek($fp, $offset);
                }
                $chunk = fread($fp, $window);
                fclose($fp);
                if ($chunk !== false && $chunk !== '') {
                    $state['output_tail'] = $this->tail($this->stripAnsi($chunk));
                }
            }
        }

        Cache::put(self::CACHE_PREFIX.$runId, $state, self::CACHE_TTL_SECONDS);
    }

    private function writeCache(string $runId, array $state): void
    {
        Cache::put(self::CACHE_PREFIX.$runId, $state, self::CACHE_TTL_SECONDS);
    }

    private function tail(string $output): string
    {
        if (strlen($output) <= self::OUTPUT_TAIL_BYTES) {
            return $output;
        }

        return substr($output, -self::OUTPUT_TAIL_BYTES);
    }

    private function capFullOutput(string $output): string
    {
        if (strlen($output) <= self::OUTPUT_FULL_MAX_BYTES) {
            return $output;
        }

        return '[…output truncated; first '.self::OUTPUT_FULL_MAX_BYTES.' bytes of '
            .strlen($output).' follow…]'."\n"
            .substr($output, 0, self::OUTPUT_FULL_MAX_BYTES);
    }

    /**
     * Strip ANSI/VT color + cursor escape sequences so the captured output
     * renders cleanly in the browser and the regex-based parser can match
     * ✓/✗ markers that would otherwise sit between escape bytes.
     */
    private function stripAnsi(string $output): string
    {
        // CSI sequences (ESC [ ... final-byte) + a few stray control bytes.
        return preg_replace(
            [
                '/\x1B\[[0-?]*[ -\/]*[@-~]/',
                '/\x1B\][^\x07\x1B]*(\x07|\x1B\\\\)/',
                '/[\x00-\x08\x0B\x0C\x0E-\x1F]/',
            ],
            '',
            $output
        ) ?? $output;
    }

    private function buildTestCommand(string $phpCli, string $suite, ?string $filter): string
    {
        // Note: Laravel's `test` command already forwards a --colors flag, so
        // we don't pass our own to avoid "Option --colors cannot be used more
        // than once" warnings in the captured output.
        $command = escapeshellarg($phpCli).' artisan test';

        if ($suite !== 'all') {
            $command .= ' --testsuite='.ucfirst($suite);
        }

        if ($filter) {
            $command .= ' --filter='.escapeshellarg($filter);
        }

        return $command;
    }

    private function buildEnv(): array
    {
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

        return array_filter($env, fn ($value) => is_string($value));
    }

    private function killProcess(int $pid): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            @exec('taskkill /F /T /PID '.(int) $pid);

            return;
        }

        if (function_exists('posix_kill')) {
            @posix_kill($pid, 15); // SIGTERM

            return;
        }

        @exec('kill -TERM '.(int) $pid);
    }

    /**
     * Find the PHP CLI binary path.
     * PHP_BINARY might point to php-fpm which can't run CLI commands.
     * Configure via .env: PHP_CLI_PATH=/usr/bin/php8.2
     * On Plesk: PHP_CLI_PATH=/opt/plesk/php/8.2/bin/php
     */
    private function findPhpCliBinary(): string
    {
        $configuredPath = config('app.php_cli_path') ?: env('PHP_CLI_PATH');
        if ($configuredPath) {
            return $configuredPath;
        }

        $phpBinary = PHP_BINARY;
        if (! str_contains($phpBinary, 'fpm') && ! str_contains($phpBinary, 'cgi')) {
            return $phpBinary;
        }

        return 'php';
    }

    /**
     * Get extended PATH for finding PHP CLI in restricted environments (e.g. Plesk).
     */
    private function getExtendedPath(): string
    {
        $currentPath = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';

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
     * Parse test output for final statistics (used at the end of a run).
     */
    public function parseTestOutput(string $output): array
    {
        $stats = [
            'total' => 0,
            'passed' => 0,
            'failed' => 0,
            'skipped' => 0,
            'assertions' => 0,
            'tests' => [],
        ];

        if (preg_match('/(\d+)\s*passed/i', $output, $matches)) {
            $stats['passed'] = (int) $matches[1];
        }

        if (preg_match('/(\d+)\s*failed/i', $output, $matches)) {
            $stats['failed'] = (int) $matches[1];
        }

        if (preg_match('/(\d+)\s*skipped/i', $output, $matches)) {
            $stats['skipped'] = (int) $matches[1];
        }

        $stats['total'] = $stats['passed'] + $stats['failed'] + $stats['skipped'];

        if (preg_match('/\((\d+)\s*assertions?\)/i', $output, $matches)) {
            $stats['assertions'] = (int) $matches[1];
        }

        $lines = explode("\n", $output);
        $currentSuite = '';

        foreach ($lines as $line) {
            if (preg_match('/^\s*(PASS|FAIL)\s+(.+)$/i', $line, $matches)) {
                $currentSuite = trim($matches[2]);
            }

            if (preg_match('/^\s*[✓✔]\s*(.+?)\s+[\d\.]+s\s*$/u', $line, $matches)) {
                $stats['tests'][] = ['name' => trim($matches[1]), 'suite' => $currentSuite, 'status' => 'passed'];
            } elseif (preg_match('/^\s*[✗✘×⨯]\s*(.+?)\s+[\d\.]+s\s*$/u', $line, $matches)) {
                $stats['tests'][] = ['name' => trim($matches[1]), 'suite' => $currentSuite, 'status' => 'failed'];
            } elseif (preg_match('/^\s*FAILED\s+(.+?)\s*>\s*(.+?)\s*$/i', $line, $matches)) {
                $stats['tests'][] = ['name' => trim($matches[2]), 'suite' => trim($matches[1]), 'status' => 'failed'];
            }
        }

        return $stats;
    }
}
