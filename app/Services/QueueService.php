<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class QueueService
{
    public function getQueueStats(): array
    {
        return [
            'pending' => DB::table('jobs')->count(),
            'failed' => DB::table('failed_jobs')->count(),
            'batches' => DB::table('job_batches')->count(),
        ];
    }

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

    private function truncateException(string $exception, int $length): string
    {
        if (strlen($exception) <= $length) {
            return $exception;
        }

        return substr($exception, 0, $length).'...';
    }
}
