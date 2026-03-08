<?php

use App\Services\QueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// ─── getQueueStats() ─────────────────────────────────────────────────────────

test('getQueueStats returns all zeros when tables are empty', function () {
    $service = new QueueService;
    $stats = $service->getQueueStats();

    expect($stats['pending'])->toBe(0);
    expect($stats['failed'])->toBe(0);
    expect($stats['batches'])->toBe(0);
});

test('getQueueStats returns correct counts when rows are inserted', function () {
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'TestJob']),
        'attempts' => 0,
        'available_at' => time(),
        'created_at' => time(),
    ]);

    DB::table('failed_jobs')->insert([
        'uuid' => 'test-uuid-1',
        'connection' => 'sync',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'Error',
        'failed_at' => now(),
    ]);
    DB::table('failed_jobs')->insert([
        'uuid' => 'test-uuid-2',
        'connection' => 'sync',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'Error2',
        'failed_at' => now(),
    ]);

    DB::table('job_batches')->insert([
        'id' => 'batch-1',
        'name' => 'TestBatch',
        'total_jobs' => 1,
        'pending_jobs' => 1,
        'failed_jobs' => 0,
        'failed_job_ids' => '[]',
        'created_at' => time(),
    ]);

    $service = new QueueService;
    $stats = $service->getQueueStats();

    expect($stats['pending'])->toBe(1);
    expect($stats['failed'])->toBe(2);
    expect($stats['batches'])->toBe(1);
});

// ─── getFailedJobs() ─────────────────────────────────────────────────────────

test('getFailedJobs returns empty array when table is empty', function () {
    $service = new QueueService;

    expect($service->getFailedJobs())->toBe([]);
});

test('getFailedJobs maps correct fields', function () {
    DB::table('failed_jobs')->insert([
        'uuid' => 'uuid-abc',
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'RuntimeException',
        'failed_at' => now(),
    ]);

    $service = new QueueService;
    $jobs = $service->getFailedJobs();

    expect($jobs)->toHaveCount(1);
    expect($jobs[0])->toHaveKeys(['id', 'uuid', 'connection', 'queue', 'exception', 'failed_at']);
    expect($jobs[0]['uuid'])->toBe('uuid-abc');
    expect($jobs[0]['connection'])->toBe('database');
    expect($jobs[0]['queue'])->toBe('default');
    expect($jobs[0]['exception'])->toBe('RuntimeException');
});

test('getFailedJobs leaves exceptions under 500 chars unchanged', function () {
    $short = str_repeat('x', 500);
    DB::table('failed_jobs')->insert([
        'uuid' => 'uuid-short',
        'connection' => 'sync',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => $short,
        'failed_at' => now(),
    ]);

    $service = new QueueService;
    $jobs = $service->getFailedJobs();

    expect($jobs[0]['exception'])->toBe($short);
});

test('getFailedJobs truncates exceptions over 500 chars to 500 chars plus ellipsis', function () {
    $long = str_repeat('a', 600);
    DB::table('failed_jobs')->insert([
        'uuid' => 'uuid-long',
        'connection' => 'sync',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => $long,
        'failed_at' => now(),
    ]);

    $service = new QueueService;
    $jobs = $service->getFailedJobs();

    expect($jobs[0]['exception'])->toBe(str_repeat('a', 500).'...');
});

test('getFailedJobs respects limit parameter', function () {
    for ($i = 1; $i <= 5; $i++) {
        DB::table('failed_jobs')->insert([
            'uuid' => "uuid-{$i}",
            'connection' => 'sync',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => "Error {$i}",
            'failed_at' => now(),
        ]);
    }

    $service = new QueueService;

    expect($service->getFailedJobs(3))->toHaveCount(3);
});

test('getFailedJobs is ordered by failed_at descending', function () {
    DB::table('failed_jobs')->insert([
        'uuid' => 'uuid-old',
        'connection' => 'sync',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'Old error',
        'failed_at' => now()->subHour(),
    ]);
    DB::table('failed_jobs')->insert([
        'uuid' => 'uuid-new',
        'connection' => 'sync',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'New error',
        'failed_at' => now(),
    ]);

    $service = new QueueService;
    $jobs = $service->getFailedJobs();

    expect($jobs[0]['uuid'])->toBe('uuid-new');
    expect($jobs[1]['uuid'])->toBe('uuid-old');
});

// ─── getPendingJobs() ────────────────────────────────────────────────────────

test('getPendingJobs returns empty array when table is empty', function () {
    $service = new QueueService;

    expect($service->getPendingJobs())->toBe([]);
});

test('getPendingJobs extracts job_name from payload displayName', function () {
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\MyJob']),
        'attempts' => 0,
        'available_at' => time(),
        'created_at' => time(),
    ]);

    $service = new QueueService;
    $jobs = $service->getPendingJobs();

    expect($jobs[0]['job_name'])->toBe('App\\Jobs\\MyJob');
});

test('getPendingJobs falls back to Unknown when displayName is absent', function () {
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode(['commandName' => 'SomethingElse']),
        'attempts' => 0,
        'available_at' => time(),
        'created_at' => time(),
    ]);

    $service = new QueueService;
    $jobs = $service->getPendingJobs();

    expect($jobs[0]['job_name'])->toBe('Unknown');
});

test('getPendingJobs formats created_at as Y-m-d H:i:s from unix timestamp', function () {
    $timestamp = mktime(12, 30, 0, 6, 15, 2025);
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'TestJob']),
        'attempts' => 0,
        'available_at' => $timestamp,
        'created_at' => $timestamp,
    ]);

    $service = new QueueService;
    $jobs = $service->getPendingJobs();

    expect($jobs[0]['created_at'])->toBe(date('Y-m-d H:i:s', $timestamp));
});

test('getPendingJobs respects limit parameter', function () {
    for ($i = 1; $i <= 5; $i++) {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => "Job{$i}"]),
            'attempts' => 0,
            'available_at' => time(),
            'created_at' => time(),
        ]);
    }

    $service = new QueueService;

    expect($service->getPendingJobs(2))->toHaveCount(2);
});
