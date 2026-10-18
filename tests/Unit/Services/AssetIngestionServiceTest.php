<?php

use App\Models\Asset;
use App\Models\User;
use App\Services\AssetIngestionService;
use App\Services\S3Service;

test('an overwrite deletes the old derived objects, resets their keys and clears the missing flag', function () {
    $uploader = User::factory()->create();
    $existing = Asset::factory()->create([
        's3_key' => 'assets/photo.jpg',
        'thumbnail_s3_key' => 'thumbnails/photo_thumb.jpg',
        's3_missing_at' => now(),
    ]);
    $existing->delete();

    $s3 = Mockery::mock(S3Service::class);
    $s3->shouldReceive('deleteAssetFiles')->once()->with(Mockery::on(fn ($a) => $a->is($existing)), true);

    $asset = (new AssetIngestionService($s3))->persist([
        's3_key' => 'assets/photo.jpg',
        'filename' => 'photo.jpg',
        'mime_type' => 'image/jpeg',
        'size' => 10,
        'etag' => 'new',
        'user_id' => $uploader->id,
    ], $existing);

    expect($asset->trashed())->toBeFalse()
        ->and($asset->thumbnail_s3_key)->toBeNull()
        ->and($asset->s3_missing_at)->toBeNull()
        ->and($asset->last_modified_by)->toBe($uploader->id);
});

test('a new asset touches no S3 objects', function () {
    $s3 = Mockery::mock(S3Service::class);
    $s3->shouldNotReceive('deleteAssetFiles');

    $asset = (new AssetIngestionService($s3))->persist([
        's3_key' => 'assets/new.jpg',
        'filename' => 'new.jpg',
        'mime_type' => 'image/jpeg',
        'size' => 10,
        'user_id' => User::factory()->create()->id,
    ]);

    expect($asset->exists)->toBeTrue();
});
