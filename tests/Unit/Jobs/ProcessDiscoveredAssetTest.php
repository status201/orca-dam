<?php

use App\Jobs\ProcessDiscoveredAsset;
use App\Models\Asset;
use App\Services\AssetProcessingService;
use App\Services\RekognitionService;
use App\Services\S3Service;

test('job extracts dimensions and calls processImageAsset', function () {
    $asset = Asset::factory()->image()->create(['width' => null, 'height' => null]);

    $s3 = Mockery::mock(S3Service::class);
    $s3->shouldReceive('extractImageDimensions')
        ->once()
        ->andReturn(['width' => 640, 'height' => 480]);

    $rekognition = Mockery::mock(RekognitionService::class);

    $processing = Mockery::mock(AssetProcessingService::class);
    $processing->shouldReceive('processImageAsset')
        ->once()
        ->with(Mockery::on(fn ($a) => $a->id === $asset->id), Mockery::type('bool'));

    config(['services.aws.rekognition_enabled' => false]);

    (new ProcessDiscoveredAsset($asset->id))->handle($s3, $rekognition, $processing);

    $fresh = $asset->fresh();
    expect($fresh->width)->toBe(640);
    expect($fresh->height)->toBe(480);
});

test('job runs AI tagging synchronously when Rekognition is enabled', function () {
    $asset = Asset::factory()->image()->create(['width' => 100, 'height' => 100]);

    $s3 = Mockery::mock(S3Service::class);

    $rekognition = Mockery::mock(RekognitionService::class);
    $rekognition->shouldReceive('autoTagAsset')
        ->once()
        ->andReturn(['cat', 'feline']);

    $processing = Mockery::mock(AssetProcessingService::class);
    $processing->shouldReceive('processImageAsset')->once();

    config(['services.aws.rekognition_enabled' => true]);

    (new ProcessDiscoveredAsset($asset->id))->handle($s3, $rekognition, $processing);
});

test('job is a silent no-op when asset not in DB', function () {
    $s3 = Mockery::mock(S3Service::class);
    $rekognition = Mockery::mock(RekognitionService::class);
    $processing = Mockery::mock(AssetProcessingService::class);

    $s3->shouldNotReceive('extractImageDimensions');
    $processing->shouldNotReceive('processImageAsset');

    (new ProcessDiscoveredAsset(99999))->handle($s3, $rekognition, $processing);

    expect(true)->toBeTrue();
});

test('job re-throws exception on failure for retry', function () {
    $asset = Asset::factory()->image()->create();

    $s3 = Mockery::mock(S3Service::class);
    $s3->shouldReceive('extractImageDimensions')->andReturn(null);

    $rekognition = Mockery::mock(RekognitionService::class);

    $processing = Mockery::mock(AssetProcessingService::class);
    $processing->shouldReceive('processImageAsset')->andThrow(new \RuntimeException('boom'));

    expect(fn () => (new ProcessDiscoveredAsset($asset->id))->handle($s3, $rekognition, $processing))
        ->toThrow(\RuntimeException::class, 'boom');
});
