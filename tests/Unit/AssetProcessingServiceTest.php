<?php

use App\Jobs\GenerateAiTags;
use App\Models\Asset;
use App\Services\AssetProcessingService;
use App\Services\RekognitionService;
use App\Services\S3Service;
use Illuminate\Support\Facades\Bus;

test('processImageAsset skips non-image assets', function () {
    $s3Service = Mockery::mock(S3Service::class);
    $rekognitionService = Mockery::mock(RekognitionService::class);

    $s3Service->shouldNotReceive('generateThumbnail');
    $s3Service->shouldNotReceive('generateResizedImages');

    $service = new AssetProcessingService($s3Service, $rekognitionService);

    $asset = Asset::factory()->create(['mime_type' => 'application/pdf', 's3_key' => 'assets/doc.pdf']);
    $service->processImageAsset($asset);
});

test('processImageAsset generates thumbnail and updates asset', function () {
    $s3Service = Mockery::mock(S3Service::class);
    $rekognitionService = Mockery::mock(RekognitionService::class);
    $rekognitionService->shouldReceive('isEnabled')->andReturn(false);

    $asset = Asset::factory()->create([
        'mime_type' => 'image/jpeg',
        's3_key' => 'assets/photo.jpg',
    ]);

    $s3Service->shouldReceive('generateThumbnail')
        ->with('assets/photo.jpg')
        ->once()
        ->andReturn('thumbnails/photo_thumb.jpg');

    $s3Service->shouldReceive('generateResizedImages')
        ->with('assets/photo.jpg')
        ->once()
        ->andReturn([]);

    $service = new AssetProcessingService($s3Service, $rekognitionService);
    $service->processImageAsset($asset);

    $asset->refresh();
    expect($asset->thumbnail_s3_key)->toBe('thumbnails/photo_thumb.jpg');
});

test('processImageAsset generates resized images and updates asset', function () {
    $s3Service = Mockery::mock(S3Service::class);
    $rekognitionService = Mockery::mock(RekognitionService::class);
    $rekognitionService->shouldReceive('isEnabled')->andReturn(false);

    $asset = Asset::factory()->create([
        'mime_type' => 'image/png',
        's3_key' => 'assets/image.png',
    ]);

    $s3Service->shouldReceive('generateThumbnail')
        ->with('assets/image.png')
        ->once()
        ->andReturn(null);

    $s3Service->shouldReceive('generateResizedImages')
        ->with('assets/image.png')
        ->once()
        ->andReturn([
            's' => 'thumbnails/S/image.png',
            'm' => 'thumbnails/M/image.png',
            'l' => 'thumbnails/L/image.png',
        ]);

    $service = new AssetProcessingService($s3Service, $rekognitionService);
    $service->processImageAsset($asset);

    $asset->refresh();
    expect($asset->resize_s_s3_key)->toBe('thumbnails/S/image.png');
    expect($asset->resize_m_s3_key)->toBe('thumbnails/M/image.png');
    expect($asset->resize_l_s3_key)->toBe('thumbnails/L/image.png');
});

test('processImageAsset dispatches AI tagging when rekognition is enabled', function () {
    Bus::fake([GenerateAiTags::class]);

    $s3Service = Mockery::mock(S3Service::class);
    $rekognitionService = Mockery::mock(RekognitionService::class);
    $rekognitionService->shouldReceive('isEnabled')->andReturn(true);

    $asset = Asset::factory()->create([
        'mime_type' => 'image/jpeg',
        's3_key' => 'assets/photo.jpg',
    ]);

    $s3Service->shouldReceive('generateThumbnail')->andReturn(null);
    $s3Service->shouldReceive('generateResizedImages')->andReturn([]);

    $service = new AssetProcessingService($s3Service, $rekognitionService);
    $service->processImageAsset($asset);

    Bus::assertDispatched(GenerateAiTags::class, function ($job) use ($asset) {
        return $job->asset->id === $asset->id;
    });
});

test('processImageAsset skips AI dispatch when dispatchAiTagging is false', function () {
    Bus::fake([GenerateAiTags::class]);

    $s3Service = Mockery::mock(S3Service::class);
    $rekognitionService = Mockery::mock(RekognitionService::class);
    $rekognitionService->shouldNotReceive('isEnabled');

    $asset = Asset::factory()->create([
        'mime_type' => 'image/jpeg',
        's3_key' => 'assets/photo.jpg',
    ]);

    $s3Service->shouldReceive('generateThumbnail')->andReturn(null);
    $s3Service->shouldReceive('generateResizedImages')->andReturn([]);

    $service = new AssetProcessingService($s3Service, $rekognitionService);
    $service->processImageAsset($asset, dispatchAiTagging: false);

    Bus::assertNotDispatched(GenerateAiTags::class);
});

test('processImageAsset continues with resize when thumbnail fails', function () {
    $s3Service = Mockery::mock(S3Service::class);
    $rekognitionService = Mockery::mock(RekognitionService::class);
    $rekognitionService->shouldReceive('isEnabled')->andReturn(false);

    $asset = Asset::factory()->create([
        'mime_type' => 'image/jpeg',
        's3_key' => 'assets/photo.jpg',
    ]);

    $s3Service->shouldReceive('generateThumbnail')
        ->andThrow(new \RuntimeException('S3 error'));

    $s3Service->shouldReceive('generateResizedImages')
        ->with('assets/photo.jpg')
        ->once()
        ->andReturn([
            's' => 'thumbnails/S/photo.jpg',
        ]);

    $service = new AssetProcessingService($s3Service, $rekognitionService);
    $service->processImageAsset($asset);

    $asset->refresh();
    expect($asset->resize_s_s3_key)->toBe('thumbnails/S/photo.jpg');
});
