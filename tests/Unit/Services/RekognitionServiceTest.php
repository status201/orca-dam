<?php

use App\Models\Asset;
use App\Services\RekognitionService;

test('isEnabled reflects config flag', function () {
    config(['services.aws.rekognition_enabled' => false]);
    expect((new RekognitionService)->isEnabled())->toBeFalse();

    config(['services.aws.rekognition_enabled' => true]);
    config(['filesystems.disks.s3.bucket' => 'test']);
    config(['filesystems.disks.s3.region' => 'eu-west-1']);
    config(['filesystems.disks.s3.key' => 'key']);
    config(['filesystems.disks.s3.secret' => 'secret']);
    expect((new RekognitionService)->isEnabled())->toBeTrue();
});

test('detectLabels returns empty array when disabled', function () {
    config(['services.aws.rekognition_enabled' => false]);

    expect((new RekognitionService)->detectLabels('assets/foo.jpg'))->toBe([]);
});

test('detectText returns empty array when disabled', function () {
    config(['services.aws.rekognition_enabled' => false]);

    expect((new RekognitionService)->detectText('assets/foo.jpg'))->toBe([]);
});

test('autoTagAsset returns empty and attaches no tags for non-image assets', function () {
    config(['services.aws.rekognition_enabled' => false]);

    $asset = Asset::factory()->create([
        'mime_type' => 'application/pdf',
        'filename' => 'doc.pdf',
    ]);

    $result = (new RekognitionService)->autoTagAsset($asset);

    expect($result)->toBe([]);
    expect($asset->tags()->count())->toBe(0);
});
