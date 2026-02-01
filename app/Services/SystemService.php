<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class SystemService
{
    /**
     * Whitelist of allowed artisan commands for security
     */
    private const ALLOWED_COMMANDS = [
        'cache:clear',
        'config:clear',
        'route:clear',
        'view:clear',
        'optimize:clear',
        'optimize',
        'config:cache',
        'route:cache',
        'view:cache',
        'storage:link',
        'uploads:cleanup',
        'queue:retry',
        'queue:retry all',
        'queue:flush',
        'queue:restart',
        'migrate:status',
        'migrate:rollback',
        'migrate',
        'migrate --force',
    ];

    /**
     * Get queue statistics
     */
    public function getQueueStats(): array
    {
        return [
            'pending' => DB::table('jobs')->count(),
            'failed' => DB::table('failed_jobs')->count(),
            'batches' => DB::table('job_batches')->count(),
        ];
    }

    /**
     * Get failed jobs with details
     */
    public function getFailedJobs(int $limit = 20): array
    {
        return DB::table('failed_jobs')
            ->orderBy('failed_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($job) {
                return [
                    'id' => $job->id,
                    'uuid' => $job->uuid,
                    'connection' => $job->connection,
                    'queue' => $job->queue,
                    'exception' => $this->truncateException($job->exception, 500),
                    'failed_at' => $job->failed_at,
                ];
            })
            ->toArray();
    }

    /**
     * Get pending jobs information
     */
    public function getPendingJobs(int $limit = 20): array
    {
        return DB::table('jobs')
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get()
            ->map(function ($job) {
                $payload = json_decode($job->payload, true);

                return [
                    'id' => $job->id,
                    'queue' => $job->queue,
                    'attempts' => $job->attempts,
                    'reserved_at' => $job->reserved_at,
                    'available_at' => $job->available_at,
                    'created_at' => date('Y-m-d H:i:s', $job->created_at),
                    'job_name' => $payload['displayName'] ?? 'Unknown',
                ];
            })
            ->toArray();
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
     * Execute whitelisted artisan command
     */
    public function executeCommand(string $command, array $parameters = []): array
    {
        // Parse command to get base command name
        $parts = explode(' ', trim($command));
        $baseCommand = $parts[0];
        $fullCommand = trim($command);

        // Validate command is whitelisted (check both full command and base command)
        if (! in_array($fullCommand, self::ALLOWED_COMMANDS) && ! in_array($baseCommand, self::ALLOWED_COMMANDS)) {
            Log::warning("Attempted to execute non-whitelisted command: {$command}", [
                'user_id' => auth()->id(),
            ]);

            return [
                'success' => false,
                'output' => '',
                'error' => "Command '{$baseCommand}' is not allowed for security reasons.",
            ];
        }

        try {
            // Log the command execution
            Log::info("System admin executing command: {$command}", [
                'parameters' => $parameters,
                'user_id' => auth()->id(),
            ]);

            // Handle special command cases that need specific argument mapping
            $commandArgs = $this->parseCommandArguments($baseCommand, $parts);

            // Execute via Artisan facade (safer than shell)
            $exitCode = Artisan::call($baseCommand, array_merge($commandArgs, $parameters));
            $output = Artisan::output();

            return [
                'success' => $exitCode === 0,
                'output' => $output,
                'error' => $exitCode !== 0 ? "Command exited with code {$exitCode}" : null,
                'exit_code' => $exitCode,
            ];

        } catch (\Exception $e) {
            Log::error("Command execution failed: {$command}", [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
            ]);

            return [
                'success' => false,
                'output' => '',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Parse command arguments based on the specific command type
     */
    private function parseCommandArguments(string $baseCommand, array $parts): array
    {
        $commandArgs = [];

        if (count($parts) <= 1) {
            return $commandArgs;
        }

        // Handle specific commands that need special argument mapping
        switch ($baseCommand) {
            case 'queue:retry':
                // queue:retry accepts 'all' or job IDs as the 'id' argument
                // Signature: queue:retry {id?*}
                $ids = [];
                for ($i = 1; $i < count($parts); $i++) {
                    $arg = $parts[$i];
                    if (strpos($arg, '--') === 0) {
                        // Handle options like --queue=default
                        if (strpos($arg, '=') !== false) {
                            [$option, $value] = explode('=', $arg, 2);
                            $commandArgs[$option] = $value;
                        } else {
                            $commandArgs[$arg] = true;
                        }
                    } else {
                        // It's 'all' or a job ID - collect as id argument
                        $ids[] = $arg;
                    }
                }
                if (! empty($ids)) {
                    $commandArgs['id'] = $ids;
                }
                break;

            default:
                // Generic parsing for other commands
                for ($i = 1; $i < count($parts); $i++) {
                    $arg = $parts[$i];

                    if (strpos($arg, '--') === 0) {
                        // Handle --option=value syntax
                        if (strpos($arg, '=') !== false) {
                            [$option, $value] = explode('=', $arg, 2);
                            $commandArgs[$option] = $value;
                        } else {
                            $commandArgs[$arg] = true;
                        }
                    } elseif (strpos($arg, '-') === 0 && strlen($arg) === 2) {
                        $commandArgs[$arg] = true;
                    }
                    // Skip positional arguments for generic commands as they require
                    // command-specific argument names
                }
                break;
        }

        return $commandArgs;
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
     * Test S3 connection
     */
    public function testS3Connection(): array
    {
        try {
            $s3Service = app(\App\Services\S3Service::class);

            // List objects with limit of 1 to test connection
            $objects = $s3Service->listObjects('', 1);

            return [
                'success' => true,
                'message' => 'S3 connection successful',
                'bucket' => config('filesystems.disks.s3.bucket'),
                'region' => config('filesystems.disks.s3.region'),
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'S3 connection failed',
                'error' => $e->getMessage(),
            ];
        }
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
                'message' => 'Supervisor is not available on Windows. Use a process manager like NSSM or run queue workers manually.',
                'workers' => [],
            ];
        }

        // Check if supervisorctl is available
        $which = exec('which supervisorctl 2>/dev/null');
        if (empty($which)) {
            return [
                'available' => false,
                'message' => 'Supervisor is not installed. Install it to manage queue workers persistently.',
                'workers' => [],
            ];
        }

        try {
            // Run supervisorctl status for orca-queue-worker processes
            exec('supervisorctl status orca-queue-worker:* 2>&1', $output, $returnCode);

            // If no processes are configured, output will be empty or contain error
            if (empty($output) || $returnCode !== 0) {
                return [
                    'available' => true,
                    'message' => 'No orca-queue-worker processes configured in supervisor. See DEPLOYMENT.md for setup instructions.',
                    'workers' => [],
                ];
            }

            // Parse supervisor output
            $workers = [];
            foreach ($output as $line) {
                // Example line: "orca-queue-worker:orca-queue-worker_00   RUNNING   pid 12345, uptime 0:05:23"
                if (preg_match('/^([\w:-]+)\s+(STOPPED|STARTING|RUNNING|BACKOFF|STOPPING|EXITED|FATAL|UNKNOWN)\s+(.*)$/', $line, $matches)) {
                    $name = $matches[1];
                    $status = $matches[2];
                    $details = $matches[3];

                    // Extract PID and uptime if available
                    $pid = null;
                    $uptime = null;
                    if (preg_match('/pid (\d+)/', $details, $pidMatch)) {
                        $pid = (int) $pidMatch[1];
                    }
                    if (preg_match('/uptime ([\d:]+)/', $details, $uptimeMatch)) {
                        $uptime = $uptimeMatch[1];
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

            return [
                'available' => true,
                'message' => count($workers) > 0 ? 'Supervisor is managing queue workers' : 'No workers found',
                'workers' => $workers,
                'total' => count($workers),
                'running' => count(array_filter($workers, fn ($w) => $w['is_running'])),
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
     * Get suggested commands with descriptions
     */
    public function getSuggestedCommands(): array
    {
        return [
            [
                'command' => 'cache:clear',
                'description' => 'Clear application cache',
                'category' => 'Cache',
            ],
            [
                'command' => 'config:clear',
                'description' => 'Remove configuration cache',
                'category' => 'Cache',
            ],
            [
                'command' => 'route:clear',
                'description' => 'Remove route cache',
                'category' => 'Cache',
            ],
            [
                'command' => 'view:clear',
                'description' => 'Clear compiled views',
                'category' => 'Cache',
            ],
            [
                'command' => 'optimize:clear',
                'description' => 'Clear all cached bootstrap files',
                'category' => 'Optimization',
            ],
            [
                'command' => 'optimize',
                'description' => 'Cache framework bootstrap files',
                'category' => 'Optimization',
            ],
            [
                'command' => 'config:cache',
                'description' => 'Cache configuration files',
                'category' => 'Cache',
            ],
            [
                'command' => 'route:cache',
                'description' => 'Cache route registrations',
                'category' => 'Cache',
            ],
            [
                'command' => 'view:cache',
                'description' => 'Cache compiled views',
                'category' => 'Cache',
            ],
            [
                'command' => 'storage:link',
                'description' => 'Create symbolic storage link',
                'category' => 'Storage',
            ],
            [
                'command' => 'uploads:cleanup',
                'description' => 'Clean stale upload sessions',
                'category' => 'Maintenance',
            ],
            [
                'command' => 'queue:retry all',
                'description' => 'Retry all failed queue jobs',
                'category' => 'Queue',
            ],
            [
                'command' => 'queue:flush',
                'description' => 'Delete all failed jobs',
                'category' => 'Queue',
            ],
            [
                'command' => 'queue:restart',
                'description' => 'Restart queue workers',
                'category' => 'Queue',
            ],
            [
                'command' => 'migrate:status',
                'description' => 'Check migration status',
                'category' => 'Database',
            ],
        ];
    }

    /**
     * Get all settings for the settings page
     */
    public function getSettings(): array
    {
        return \App\Models\Setting::query()
            ->orderBy('group')
            ->orderBy('key')
            ->get()
            ->toArray();
    }

    /**
     * Get available languages for Rekognition
     */
    public function getAvailableLanguages(): array
    {
        return [
            'en' => 'English',
            'nl' => 'Dutch (Nederlands)',
            'fr' => 'French (Français)',
            'de' => 'German (Deutsch)',
            'es' => 'Spanish (Español)',
            'it' => 'Italian (Italiano)',
            'pt' => 'Portuguese (Português)',
            'pl' => 'Polish (Polski)',
            'ja' => 'Japanese (日本語)',
            'ko' => 'Korean (한국어)',
            'zh' => 'Chinese (中文)',
            'ar' => 'Arabic (العربية)',
            'ru' => 'Russian (Русский)',
        ];
    }

    /**
     * Update a setting value
     */
    public function updateSetting(string $key, mixed $value): bool
    {
        return \App\Models\Setting::set($key, $value);
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
                $result[] = rtrim($line);
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

    /**
     * Helper: Truncate exception text
     */
    private function truncateException(string $exception, int $length): string
    {
        if (strlen($exception) <= $length) {
            return $exception;
        }

        return substr($exception, 0, $length).'...';
    }
}
