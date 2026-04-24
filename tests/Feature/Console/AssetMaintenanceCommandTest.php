<?php

use App\Models\Asset;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\ChunkedUploadService;
use App\Services\S3Service;

test('assets:backfill-etags is a no-op when all assets have etags', function () {
    Asset::factory()->image()->create(['etag' => 'existing-etag']);

    $this->artisan('assets:backfill-etags')
        ->expectsOutputToContain('All assets already have etags')
        ->assertExitCode(0);
});

test('assets:backfill-etags fetches etags from S3 for assets missing one', function () {
    $asset = Asset::factory()->image()->create(['etag' => null]);

    $mock = Mockery::mock(S3Service::class);
    $mock->shouldReceive('getObjectMetadata')
        ->once()
        ->with($asset->s3_key)
        ->andReturn(['etag' => 'new-etag-123']);
    $this->app->instance(S3Service::class, $mock);

    $this->artisan('assets:backfill-etags')->assertExitCode(0);

    expect($asset->fresh()->etag)->toBe('new-etag-123');
});

test('assets:backfill-etags warns when S3 metadata is unavailable', function () {
    $asset = Asset::factory()->image()->create(['etag' => null]);

    $mock = Mockery::mock(S3Service::class);
    $mock->shouldReceive('getObjectMetadata')->andReturn(null);
    $this->app->instance(S3Service::class, $mock);

    $this->artisan('assets:backfill-etags')
        ->expectsOutputToContain('Failed: 1')
        ->assertExitCode(0);

    expect($asset->fresh()->etag)->toBeNull();
});

test('uploads:cleanup aborts stale sessions via ChunkedUploadService', function () {
    $user = User::factory()->create();
    $stale = UploadSession::create([
        'upload_id' => 'upl-1',
        'session_token' => 'tok-stale',
        'filename' => 'big.mp4',
        'mime_type' => 'video/mp4',
        'file_size' => 1000,
        's3_key' => 'assets/big.mp4',
        'chunk_size' => 100,
        'total_chunks' => 10,
        'uploaded_chunks' => 3,
        'part_etags' => [],
        'status' => 'uploading',
        'user_id' => $user->id,
        'last_activity_at' => now()->subDays(3),
    ]);
    // Non-stale session should be left alone
    UploadSession::create([
        'upload_id' => 'upl-2',
        'session_token' => 'tok-fresh',
        'filename' => 'small.mp4',
        'mime_type' => 'video/mp4',
        'file_size' => 1000,
        's3_key' => 'assets/small.mp4',
        'chunk_size' => 100,
        'total_chunks' => 10,
        'uploaded_chunks' => 1,
        'part_etags' => [],
        'status' => 'uploading',
        'user_id' => $user->id,
        'last_activity_at' => now(),
    ]);

    $mock = Mockery::mock(ChunkedUploadService::class);
    $mock->shouldReceive('abortUpload')
        ->once()
        ->with(Mockery::on(fn ($s) => $s->session_token === 'tok-stale'));
    $this->app->instance(ChunkedUploadService::class, $mock);

    $this->artisan('uploads:cleanup', ['--hours' => 24])
        ->expectsOutputToContain('Found 1 stale upload session')
        ->assertExitCode(0);
});

test('uploads:cleanup is a no-op when no stale sessions', function () {
    $this->artisan('uploads:cleanup')
        ->expectsOutputToContain('No stale upload sessions found')
        ->assertExitCode(0);
});
