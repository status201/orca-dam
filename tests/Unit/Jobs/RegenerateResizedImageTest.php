<?php

use App\Jobs\RegenerateResizedImage;
use App\Models\Asset;
use App\Services\S3Service;

test('job deletes old and generates new resized images', function () {
    $asset = Asset::factory()->image()->create([
        's3_key' => 'assets/test.jpg',
        'resize_s_s3_key' => 'thumbnails/S/old-s.jpg',
        'resize_m_s3_key' => 'thumbnails/M/old-m.jpg',
        'resize_l_s3_key' => 'thumbnails/L/old-l.jpg',
    ]);

    $s3 = Mockery::mock(S3Service::class);
    $s3->shouldReceive('deleteResizedImages')->once()->with(Mockery::on(fn ($a) => $a->id === $asset->id));
    $s3->shouldReceive('generateResizedImages')
        ->once()
        ->with('assets/test.jpg')
        ->andReturn([
            's' => 'thumbnails/S/new-s.jpg',
            'm' => 'thumbnails/M/new-m.jpg',
            'l' => 'thumbnails/L/new-l.jpg',
        ]);

    (new RegenerateResizedImage($asset->id))->handle($s3);

    $fresh = $asset->fresh();
    expect($fresh->resize_s_s3_key)->toBe('thumbnails/S/new-s.jpg');
    expect($fresh->resize_m_s3_key)->toBe('thumbnails/M/new-m.jpg');
    expect($fresh->resize_l_s3_key)->toBe('thumbnails/L/new-l.jpg');
});

test('job skips non-image assets', function () {
    $asset = Asset::factory()->create([
        'mime_type' => 'application/pdf',
        'filename' => 'doc.pdf',
    ]);

    $s3 = Mockery::mock(S3Service::class);
    $s3->shouldNotReceive('deleteResizedImages');
    $s3->shouldNotReceive('generateResizedImages');

    (new RegenerateResizedImage($asset->id))->handle($s3);
});

test('job is a silent no-op when asset not in DB', function () {
    $s3 = Mockery::mock(S3Service::class);
    $s3->shouldNotReceive('deleteResizedImages');

    (new RegenerateResizedImage(99999))->handle($s3);

    expect(true)->toBeTrue();
});
