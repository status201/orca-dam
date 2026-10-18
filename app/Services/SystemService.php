<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class SystemService
{
    /**
     * S3Service, built only when an S3 check runs. Its constructor throws when the bucket or
     * region is missing, and the rest of the System page must keep working for an admin to fix
     * exactly that (REQ-diagnostics-without-s3).
     */
    private function s3(): S3Service
    {
        return app(S3Service::class);
    }

    /**
     * Read last N lines from laravel.log
     */
    public function getLogTail(int $lines = 50): array
    {
        $logPath = storage_path('logs/laravel.log');

        if (! File::exists($logPath)) {
            return [
                'exists' => false,
                'lines' => [],
                'size' => 0,
            ];
        }

        $fileSize = File::size($logPath);
        $content = $this->tailFile($logPath, $lines);

        return [
            'exists' => true,
            'lines' => $content,
            'size' => $fileSize,
            'path' => $logPath,
        ];
    }

    /**
     * Get system information
     */
    public function getSystemInfo(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'environment' => app()->environment(),
            'debug_mode' => config('app.debug'),
            'timezone' => config('app.timezone'),
            'queue_driver' => config('queue.default'),
            'cache_driver' => config('cache.default'),
            'session_driver' => config('session.driver'),
            'storage_disk' => config('filesystems.default'),
            'rekognition_enabled' => config('services.aws.rekognition_enabled'),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'gd_enabled' => extension_loaded('gd'),
            'imagick_enabled' => extension_loaded('imagick'),
            'jwt_enabled' => config('jwt.enabled'),
            'cloudflare_enabled' => config('cloudflare.enabled') && config('cloudflare.api_token') !== '' && config('cloudflare.zone_id') !== '',
            's3_bucket_endpoint' => $this->getS3BucketEndpoint(),
            's3_versioning' => $this->getS3Versioning(),
        ];
    }

    /**
     * Get disk usage statistics
     */
    public function getDiskUsage(): array
    {
        $storagePath = storage_path();

        return [
            'storage_path' => $storagePath,
            'storage_size' => $this->getDirectorySize(storage_path('app')),
            'logs_size' => $this->getDirectorySize(storage_path('logs')),
            'cache_size' => $this->getDirectorySize(storage_path('framework/cache')),
            'total_size' => $this->getDirectorySize($storagePath),
        ];
    }

    /**
     * Get database statistics
     */
    public function getDatabaseStats(): array
    {
        $tables = [
            'users' => DB::table('users')->count(),
            'assets' => DB::table('assets')->count(),
            'tags' => DB::table('tags')->count(),
            'asset_tag' => DB::table('asset_tag')->count(),
            'upload_sessions' => DB::table('upload_sessions')->count(),
            'jobs' => DB::table('jobs')->count(),
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'job_batches' => DB::table('job_batches')->count(),
        ];

        return [
            'tables' => $tables,
            'total_records' => array_sum($tables),
            'connection' => config('database.default'),
        ];
    }

    /**
     * Test S3 connection. Asks a probe that reports its failure: listObjects() swallows it, so this
     * check used to pass for a wrong bucket or bad credentials (REQ-s3-test-reports-failure).
     */
    public function testS3Connection(): array
    {
        try {
            $error = $this->s3()->connectionError();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        if ($error !== null) {
            return [
                'success' => false,
                'message' => 'S3 connection failed',
                'error' => $error,
            ];
        }

        return [
            'success' => true,
            'message' => 'S3 connection successful',
            'bucket' => config('filesystems.disks.s3.bucket'),
            'region' => config('filesystems.disks.s3.region'),
        ];
    }

    /**
     * Get cache statistics
     */
    public function getCacheStats(): array
    {
        $driver = config('cache.default');

        return [
            'driver' => $driver,
            'store' => config("cache.stores.{$driver}.driver"),
        ];
    }

    /**
     * Get supervisor status for queue workers
     */
    public function getSupervisorStatus(): array
    {
        // Check if we're on Windows (supervisor not typically available)
        if (DIRECTORY_SEPARATOR === '\\') {
            return [
                'available' => false,
                'message' => __('Supervisor is not available on Windows. Use a process manager like NSSM or run queue workers manually.'),
                'workers' => [],
            ];
        }

        // Check if supervisorctl is available
        $which = exec('which supervisorctl 2>/dev/null');
        if (empty($which)) {
            return [
                'available' => false,
                'message' => __('Supervisor is not installed. Install it to manage queue workers persistently.'),
                'workers' => [],
            ];
        }

        try {
            // Get ALL supervisor processes and filter for queue workers
            // Try multiple methods to run supervisorctl (permissions vary by setup)
            $output = [];
            $returnCode = -1;

            // Method 1: Direct supervisorctl (works on most manual setups)
            exec('supervisorctl status 2>&1', $output, $returnCode);

            // Method 2: If that failed, try with full path
            if (empty($output) || $returnCode !== 0) {
                $output = [];
                exec('/usr/bin/supervisorctl status 2>&1', $output, $returnCode);
            }

            // Method 3: Try with sudo (required for Laravel Forge setups)
            if (empty($output) || $returnCode !== 0) {
                $output = [];
                exec('sudo /usr/bin/supervisorctl status 2>&1', $output, $returnCode);
            }

            // If supervisor returned no output
            if (empty($output)) {
                return [
                    'available' => true,
                    'message' => __('No queue worker processes found in supervisor. For manual setup see DEPLOYMENT.md, or use Laravel Forge for automatic configuration.'),
                    'workers' => [],
                ];
            }

            // Parse supervisor output and filter for queue workers
            $workers = [];
            $workerSource = null;

            foreach ($output as $line) {
                // Match supervisor status line format:
                // ORCA: "orca-queue-worker:orca-queue-worker_00   RUNNING   pid 12345, uptime 0:05:23"
                // Forge: "worker-670782:worker-670782_00          RUNNING   pid 269178, uptime 3:03:15"
                if (preg_match('/^([\w:-]+)\s+(STOPPED|STARTING|RUNNING|BACKOFF|STOPPING|EXITED|FATAL|UNKNOWN)\s+(.*)$/', $line, $matches)) {
                    $name = $matches[1];
                    $status = $matches[2];
                    $details = $matches[3];

                    // Check if this is a queue worker we care about
                    $isOrcaWorker = str_starts_with($name, 'orca-queue-worker:');
                    $isForgeWorker = preg_match('/^worker-\d+:/', $name);

                    if (! $isOrcaWorker && ! $isForgeWorker) {
                        continue; // Skip non-queue-worker processes
                    }

                    // Track the source (prefer ORCA if both exist)
                    if ($isOrcaWorker) {
                        $workerSource = 'orca';
                    } elseif ($workerSource === null) {
                        $workerSource = 'forge';
                    }

                    // Extract PID and uptime if available
                    $pid = null;
                    $uptime = null;
                    if (preg_match('/pid (\d+)/', $details, $pidMatch)) {
                        $pid = (int) $pidMatch[1];
                    }
                    if (preg_match('/uptime (?:(\d+) days?, )?(\d+:\d+:\d+)/', $details, $uptimeMatch)) {
                        $uptime = $this->formatUptime($uptimeMatch[2], (int) ($uptimeMatch[1] ?? 0));
                    }

                    $workers[] = [
                        'name' => $name,
                        'status' => $status,
                        'pid' => $pid,
                        'uptime' => $uptime,
                        'details' => $details,
                        'is_running' => $status === 'RUNNING',
                    ];
                }
            }

            // No queue workers found
            if (empty($workers)) {
                return [
                    'available' => true,
                    'message' => __('No queue worker processes found in supervisor. For manual setup see DEPLOYMENT.md, or use Laravel Forge for automatic configuration.'),
                    'workers' => [],
                ];
            }

            // Build appropriate message based on worker source
            $message = $workerSource === 'forge'
                ? __('Laravel Forge is managing queue workers')
                : __('Supervisor is managing queue workers');

            return [
                'available' => true,
                'message' => $message,
                'workers' => $workers,
                'total' => count($workers),
                'running' => count(array_filter($workers, fn ($w) => $w['is_running'])),
                'source' => $workerSource,
            ];

        } catch (\Exception $e) {
            Log::error('Failed to check supervisor status', ['error' => $e->getMessage()]);

            return [
                'available' => true,
                'message' => 'Error checking supervisor status: '.$e->getMessage(),
                'workers' => [],
            ];
        }
    }

    /**
     * Format supervisor uptime into human-readable format.
     *
     * Supervisor outputs uptime as either "H:MM:SS" or "N day(s), H:MM:SS".
     */
    protected function formatUptime(string $time, int $days = 0): string
    {
        $parts = explode(':', $time);
        if (count($parts) !== 3) {
            return $time;
        }

        $hours = (int) $parts[0];
        $minutes = (int) $parts[1];
        $seconds = (int) $parts[2];

        $segments = [];
        if ($days > 0) {
            $segments[] = $days.'d';
        }
        if ($hours > 0) {
            $segments[] = $hours.'h';
        }
        if ($minutes > 0) {
            $segments[] = $minutes.'m';
        }
        if (empty($segments)) {
            return $seconds.'s';
        }

        return implode(' ', $segments);
    }

    /**
     * Get S3 bucket endpoint via S3Service.
     */
    private function getS3BucketEndpoint(): ?string
    {
        try {
            return $this->s3()->getBucketEndpoint();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get S3 bucket versioning status.
     *
     * @return bool|null true = Enabled, false = Suspended/not set, null = error
     */
    private function getS3Versioning(): ?bool
    {
        try {
            $status = $this->s3()->getBucketVersioning();

            if ($status === null) {
                return null;
            }

            return $status === 'Enabled';
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Helper: Tail file efficiently
     */
    private function tailFile(string $filePath, int $lines): array
    {
        $file = new \SplFileObject($filePath, 'r');
        $file->seek(PHP_INT_MAX);
        $lastLine = $file->key();
        $startLine = max(0, $lastLine - $lines);

        $result = [];
        $file->seek($startLine);

        while (! $file->eof()) {
            $line = $file->current();
            if ($line !== false && trim($line) !== '') {
                $result[] = mb_convert_encoding(rtrim($line), 'UTF-8', 'UTF-8');
            }
            $file->next();
        }

        return array_slice($result, -$lines);
    }

    /**
     * Helper: Calculate directory size
     */
    private function getDirectorySize(string $path): int
    {
        if (! File::exists($path)) {
            return 0;
        }

        $size = 0;
        foreach (File::allFiles($path) as $file) {
            $size += $file->getSize();
        }

        return $size;
    }
}
