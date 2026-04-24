<?php

use App\Jobs\GenerateAiTags;
use App\Models\Asset;
use App\Services\RekognitionService;

test('job calls autoTagAsset for image assets', function () {
    $asset = Asset::factory()->image()->create(['mime_type' => 'image/jpeg']);

    $rekognition = Mockery::mock(RekognitionService::class);
    $rekognition->shouldReceive('autoTagAsset')->once()->with(Mockery::on(fn ($a) => $a->id === $asset->id));

    (new GenerateAiTags($asset))->handle($rekognition);
});

test('job skips non-image assets', function () {
    $asset = Asset::factory()->create([
        'mime_type' => 'application/pdf',
        'filename' => 'doc.pdf',
    ]);

    $rekognition = Mockery::mock(RekognitionService::class);
    $rekognition->shouldNotReceive('autoTagAsset');

    (new GenerateAiTags($asset))->handle($rekognition);
});

test('job skips GIF images (unsupported by Rekognition)', function () {
    $asset = Asset::factory()->image()->create(['mime_type' => 'image/gif']);

    $rekognition = Mockery::mock(RekognitionService::class);
    $rekognition->shouldNotReceive('autoTagAsset');

    (new GenerateAiTags($asset))->handle($rekognition);
});

test('job swallows rekognition exceptions (does not throw)', function () {
    $asset = Asset::factory()->image()->create(['mime_type' => 'image/jpeg']);

    $rekognition = Mockery::mock(RekognitionService::class);
    $rekognition->shouldReceive('autoTagAsset')->andThrow(new \RuntimeException('AWS down'));

    (new GenerateAiTags($asset))->handle($rekognition);

    expect(true)->toBeTrue();
});
