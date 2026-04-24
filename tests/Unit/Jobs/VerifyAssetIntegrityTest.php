<?php

use App\Jobs\VerifyAssetIntegrity;
use App\Models\Asset;
use App\Services\S3Service;
use Illuminate\Support\Facades\Cache;

test('job sets s3_missing_at when object is missing', function () {
    $asset = Asset::factory()->image()->create(['s3_missing_at' => null]);

    $s3 = Mockery::mock(S3Service::class);
    $s3->shouldReceive('getObjectMetadata')->once()->with($asset->s3_key)->andReturn(null);

    (new VerifyAssetIntegrity($asset->id))->handle($s3);

    expect($asset->fresh()->s3_missing_at)->not->toBeNull();
});

test('job does not update s3_missing_at when already set', function () {
    $originalTimestamp = now()->subDays(5);
    $asset = Asset::factory()->image()->create(['s3_missing_at' => $originalTimestamp]);

    $s3 = Mockery::mock(S3Service::class);
    $s3->shouldReceive('getObjectMetadata')->andReturn(null);

    (new VerifyAssetIntegrity($asset->id))->handle($s3);

    expect($asset->fresh()->s3_missing_at->timestamp)->toBe($originalTimestamp->timestamp);
});

test('job clears s3_missing_at when object is recovered', function () {
    $asset = Asset::factory()->image()->create(['s3_missing_at' => now()->subDay()]);

    $s3 = Mockery::mock(S3Service::class);
    $s3->shouldReceive('getObjectMetadata')->andReturn(['etag' => 'found']);

    (new VerifyAssetIntegrity($asset->id))->handle($s3);

    expect($asset->fresh()->s3_missing_at)->toBeNull();
});

test('job flushes missing_count cache when state changes', function () {
    $asset = Asset::factory()->image()->create(['s3_missing_at' => null]);
    Cache::put('assets:missing_count', 42);

    $s3 = Mockery::mock(S3Service::class);
    $s3->shouldReceive('getObjectMetadata')->andReturn(null);

    (new VerifyAssetIntegrity($asset->id))->handle($s3);

    expect(Cache::has('assets:missing_count'))->toBeFalse();
});

test('job is a silent no-op when asset is missing from DB', function () {
    $s3 = Mockery::mock(S3Service::class);
    $s3->shouldNotReceive('getObjectMetadata');

    (new VerifyAssetIntegrity(99999))->handle($s3);

    expect(true)->toBeTrue();
});
