<?php

use App\Models\Asset;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\ChunkedUploadService;
use App\Services\CloudflareService;
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

// ─── assets:audit-content-types (maintenance-commands REQ-audit-content-types) ────

test('assets:audit-content-types reports mismatched objects, trashed ones included, and changes nothing', function () {
    $html = Asset::factory()->create(['s3_key' => 'assets/photo.jpg']);
    $trashed = Asset::factory()->create(['s3_key' => 'assets/notes.txt']);
    $trashed->delete();
    Asset::factory()->create(['s3_key' => 'assets/logo.png']);

    $s3 = Mockery::mock(S3Service::class);
    $s3->shouldReceive('getObjectHeaders')->once()->andReturn([
        'assets/photo.jpg' => ['content_type' => 'text/html', 'content_disposition' => null],
        'assets/notes.txt' => ['content_type' => 'text/plain; charset=utf-8', 'content_disposition' => null],
        'assets/logo.png' => ['content_type' => 'image/png', 'content_disposition' => null],
    ]);
    $s3->shouldNotReceive('repairUploadHeaders');
    $this->app->instance(S3Service::class, $s3);

    $this->artisan('assets:audit-content-types')
        ->expectsOutputToContain('DRY RUN')
        ->expectsOutputToContain('DANGEROUS')
        ->expectsOutputToContain("#{$trashed->id} (trashed)")
        ->doesntExpectOutputToContain('assets/logo.png')
        ->expectsOutputToContain('Run with --fix')
        ->assertExitCode(1);

    expect($html->fresh()->etag)->toBe($html->etag);
});

test('assets:audit-content-types exits 0 when every object matches, counting missing ones', function () {
    Asset::factory()->create(['s3_key' => 'assets/logo.png']);
    Asset::factory()->create(['s3_key' => 'assets/report.pdf']);
    Asset::factory()->create(['s3_key' => 'assets/gone.zip']);

    $s3 = Mockery::mock(S3Service::class);
    $s3->shouldReceive('getObjectHeaders')->once()->andReturn([
        'assets/logo.png' => ['content_type' => 'image/png', 'content_disposition' => null],
        'assets/report.pdf' => ['content_type' => 'application/pdf', 'content_disposition' => null],
        'assets/gone.zip' => null,
    ]);
    $this->app->instance(S3Service::class, $s3);

    $this->artisan('assets:audit-content-types')
        ->expectsOutputToContain('Every stored object is served with the headers its extension calls for')
        ->expectsTable(['Result', 'Count'], [
            ['Checked', 3],
            ['Mismatched', 0],
            ['Dangerous (active type served inline)', 0],
            ['Missing or unreadable (see assets:verify-integrity)', 1],
        ])
        ->assertExitCode(0);
});

test('assets:audit-content-types --fix rewrites the headers, records the new etag and purges the URL', function () {
    $asset = Asset::factory()->create(['s3_key' => 'assets/photo.jpg', 'etag' => 'composite-2']);

    $s3 = Mockery::mock(S3Service::class);
    $s3->shouldReceive('getObjectHeaders')->once()->andReturn([
        'assets/photo.jpg' => ['content_type' => 'text/html', 'content_disposition' => null],
    ]);
    $s3->shouldReceive('repairUploadHeaders')->once()->with('assets/photo.jpg')->andReturn('plainmd5');
    $s3->shouldReceive('getUrl')->with('assets/photo.jpg')->andReturn('https://cdn.example.test/assets/photo.jpg');
    $this->app->instance(S3Service::class, $s3);

    $cloudflare = Mockery::mock(CloudflareService::class);
    $cloudflare->shouldReceive('purgeUrls')->once()->with(['https://cdn.example.test/assets/photo.jpg'])->andReturnTrue();
    $this->app->instance(CloudflareService::class, $cloudflare);

    $this->artisan('assets:audit-content-types', ['--fix' => true])
        ->doesntExpectOutputToContain('DRY RUN')
        ->assertExitCode(0);

    expect($asset->fresh()->etag)->toBe('plainmd5');
});
