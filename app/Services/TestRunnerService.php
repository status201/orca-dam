<?php

namespace App\Services;

class TestRunnerService
{
    /**
     * Run the test suite and return output and statistics.
     *
     * @return array{success: bool, output: string, stats: array}|array{success: false, error: string}
     */
    public function run(string $suite = 'all', ?string $filter = null): array
    {
        \Artisan::call('config:clear');
        \Artisan::call('cache:clear');

        $command = escapeshellarg($this->findPhpCliBinary()).' artisan test --colors=never';

        if ($suite !== 'all') {
            $command .= ' --testsuite='.ucfirst($suite);
        }

        if ($filter) {
            $command .= ' --filter='.escapeshellarg($filter);
        }

        $startTime = microtime(true);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

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

        $env = array_filter($env, fn ($value) => is_string($value));

        $process = proc_open($command, $descriptors, $pipes, base_path(), $env);

        if (! is_resource($process)) {
            return ['success' => false, 'error' => 'Failed to start test process'];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $output = $stdout.$stderr;
        $duration = round(microtime(true) - $startTime, 2);

        $stats = $this->parseTestOutput($output);
        $stats['duration'] = $duration;
        $stats['exit_code'] = $exitCode;
        $stats['success'] = $exitCode === 0;

        return ['success' => true, 'output' => $output, 'stats' => $stats];
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
     * Parse test output for statistics.
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
            } elseif (preg_match('/^\s*[✗✘×]\s*(.+?)\s+[\d\.]+s\s*$/u', $line, $matches)) {
                $stats['tests'][] = ['name' => trim($matches[1]), 'suite' => $currentSuite, 'status' => 'failed'];
            } elseif (preg_match('/^\s*FAILED\s+(.+?)\s*>\s*(.+?)\s*$/i', $line, $matches)) {
                $stats['tests'][] = ['name' => trim($matches[2]), 'suite' => trim($matches[1]), 'status' => 'failed'];
            }
        }

        return $stats;
    }
}
