<?php

namespace App\Http\Controllers;

use App\Services\SystemService;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

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

        $result = $this->systemService->executeCommand('queue:retry ' . $request->input('job_id'));

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
}
