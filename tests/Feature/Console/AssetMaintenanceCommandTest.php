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

test('assets:audit-content-types reports mismatched objects, trashed ones included, changes nothing, and exits 0 unless --strict', function () {
    $html = Asset::factory()->create(['s3_key' => 'assets/photo.jpg', 'mime_type' => 'image/jpeg']);
    $trashed = Asset::factory()->create(['s3_key' => 'assets/notes.txt', 'mime_type' => 'text/plain']);
    $trashed->delete();
    Asset::factory()->create(['s3_key' => 'assets/logo.png', 'mime_type' => 'image/png']);

    $s3 = Mockery::mock(S3Service::class);
    $s3->shouldReceive('getObjectHeaders')->twice()->andReturn([
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
        // Findings are the report, not a failure: System → Commands and Forge show non-zero as "Failed".
        ->assertExitCode(0);

    $this->artisan('assets:audit-content-types', ['--strict' => true])->assertExitCode(1);

    expect($html->fresh()->etag)->toBe($html->etag);
});

test('assets:audit-content-types --fix exits 1 when it could not rewrite an object', function () {
    Asset::factory()->create(['s3_key' => 'assets/photo.jpg', 'mime_type' => 'image/jpeg']);

    $s3 = Mockery::mock(S3Service::class);
    $s3->shouldReceive('getObjectHeaders')->once()->andReturn([
        'assets/photo.jpg' => ['content_type' => 'text/html', 'content_disposition' => null],
    ]);
    $s3->shouldReceive('repairUploadHeaders')->once()->andReturnNull();
    $this->app->instance(S3Service::class, $s3);

    $this->artisan('assets:audit-content-types', ['--fix' => true])
        ->expectsOutputToContain('Could not rewrite')
        ->assertExitCode(1);
});

test('assets:audit-content-types exits 0 when every object matches, counting missing ones', function () {
    Asset::factory()->create(['s3_key' => 'assets/logo.png', 'mime_type' => 'image/png']);
    Asset::factory()->create(['s3_key' => 'assets/report.pdf', 'mime_type' => 'application/pdf']);
    Asset::factory()->create(['s3_key' => 'assets/gone.zip', 'mime_type' => 'application/zip']);

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
            ['Database type out of step', 0],
            ['Missing or unreadable (see assets:verify-integrity)', 1],
        ])
        ->assertExitCode(0);
});

test('assets:audit-content-types --fix rewrites the headers, records the new etag and purges the URL', function () {
    $asset = Asset::factory()->create(['s3_key' => 'assets/photo.jpg', 'mime_type' => 'image/jpeg', 'etag' => 'composite-2']);

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

test('assets:audit-content-types --fix also gives a repaired asset its extension type', function () {
    $asset = Asset::factory()->create(['s3_key' => 'assets/diagram.tex', 'mime_type' => 'text/x-tex', 'etag' => 'e1']);

    $s3 = Mockery::mock(S3Service::class);
    $s3->shouldReceive('getObjectHeaders')->once()->andReturn([
        'assets/diagram.tex' => ['content_type' => 'text/x-tex', 'content_disposition' => null],
    ]);
    $s3->shouldReceive('repairUploadHeaders')->once()->with('assets/diagram.tex')->andReturn('e1');
    $s3->shouldReceive('getUrl')->andReturn('https://cdn.example.test/assets/diagram.tex');
    $this->app->instance(S3Service::class, $s3);

    $cloudflare = Mockery::mock(CloudflareService::class);
    $cloudflare->shouldReceive('purgeUrls')->once()->andReturnTrue();
    $this->app->instance(CloudflareService::class, $cloudflare);

    $this->artisan('assets:audit-content-types', ['--fix' => true])->assertExitCode(0);

    expect($asset->fresh())
        ->mime_type->toBe('application/x-tex')
        ->etag->toBe('e1');
});

test('assets:audit-content-types counts a database type out of step, and --fix syncs it without touching S3', function () {
    $asset = Asset::factory()->create(['s3_key' => 'assets/diagram.tex', 'mime_type' => 'text/x-tex']);

    $s3 = Mockery::mock(S3Service::class);
    $s3->shouldReceive('getObjectHeaders')->times(3)->andReturn([
        'assets/diagram.tex' => ['content_type' => 'application/x-tex', 'content_disposition' => 'attachment'],
    ]);
    $s3->shouldNotReceive('repairUploadHeaders');
    $this->app->instance(S3Service::class, $s3);

    $cloudflare = Mockery::mock(CloudflareService::class);
    $cloudflare->shouldNotReceive('purgeUrls');
    $this->app->instance(CloudflareService::class, $cloudflare);

    $this->artisan('assets:audit-content-types')
        ->expectsOutputToContain('Every stored object is served with the headers its extension calls for')
        ->expectsTable(['Result', 'Count'], [
            ['Checked', 1],
            ['Mismatched', 0],
            ['Dangerous (active type served inline)', 0],
            ['Database type out of step', 1],
            ['Missing or unreadable (see assets:verify-integrity)', 0],
        ])
        ->assertExitCode(0);
    $this->artisan('assets:audit-content-types', ['--strict' => true])->assertExitCode(1);
    expect($asset->fresh()->mime_type)->toBe('text/x-tex');

    $this->artisan('assets:audit-content-types', ['--fix' => true])->assertExitCode(0);
    expect($asset->fresh()->mime_type)->toBe('application/x-tex');
});

test('assets:audit-content-types --fix leaves the mime_type of an off-list extension alone', function () {
    $asset = Asset::factory()->create(['s3_key' => 'assets/scan.bmp', 'mime_type' => 'image/bmp']);

    $s3 = Mockery::mock(S3Service::class);
    $s3->shouldReceive('getObjectHeaders')->once()->andReturn([
        'assets/scan.bmp' => ['content_type' => 'image/bmp', 'content_disposition' => null],
    ]);
    $s3->shouldReceive('repairUploadHeaders')->once()->andReturn($asset->etag);
    $s3->shouldReceive('getUrl')->andReturn('https://cdn.example.test/assets/scan.bmp');
    $this->app->instance(S3Service::class, $s3);

    $cloudflare = Mockery::mock(CloudflareService::class);
    $cloudflare->shouldReceive('purgeUrls')->andReturnTrue();
    $this->app->instance(CloudflareService::class, $cloudflare);

    $this->artisan('assets:audit-content-types', ['--fix' => true])->assertExitCode(0);

    expect($asset->fresh()->mime_type)->toBe('image/bmp');
});
