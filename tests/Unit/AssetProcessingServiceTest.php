<?php

use App\Jobs\GenerateAiTags;
use App\Models\Asset;
use App\Models\Tag;
use App\Services\AssetProcessingService;
use App\Services\RekognitionService;
use App\Services\S3Service;
use App\Support\UploadMetadata;
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
        ->andThrow(new RuntimeException('S3 error'));

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

// ---------------------------------------------------------------------------
// applyUploadMetadata
// ---------------------------------------------------------------------------

function makeProcessingService(): AssetProcessingService
{
    $s3 = Mockery::mock(S3Service::class);
    $rek = Mockery::mock(RekognitionService::class);

    return new AssetProcessingService($s3, $rek);
}

test('applyUploadMetadata writes license, copyright, and copyright source', function () {
    $asset = Asset::factory()->create([
        'license_type' => null,
        'copyright' => null,
        'copyright_source' => null,
    ]);

    makeProcessingService()->applyUploadMetadata($asset, new UploadMetadata(
        licenseType: 'cc_by',
        copyright: '© 2026 ACME',
        copyrightSource: 'https://example.com/license',
    ));

    $asset->refresh();
    expect($asset->license_type)->toBe('cc_by');
    expect($asset->copyright)->toBe('© 2026 ACME');
    expect($asset->copyright_source)->toBe('https://example.com/license');
});

test('applyUploadMetadata writes date_obtained', function () {
    $asset = Asset::factory()->create(['date_obtained' => null]);

    makeProcessingService()->applyUploadMetadata($asset, new UploadMetadata(dateObtained: '2026-05-01'));

    $asset->refresh();
    expect($asset->date_obtained)->not->toBeNull();
    expect($asset->date_obtained->format('Y-m-d'))->toBe('2026-05-01');
});

test('applyUploadMetadata skips null and empty-string values without overwriting existing data', function () {
    $asset = Asset::factory()->create([
        'license_type' => 'cc_by_sa',
        'copyright' => 'existing copyright',
        'copyright_source' => 'existing source',
        'date_obtained' => '2026-01-01',
    ]);

    // '' is the value a cleared <input type="date"> submits — it must not reach the 'date' cast
    // and must not blank a stored date.
    makeProcessingService()->applyUploadMetadata($asset, new UploadMetadata(copyright: '', dateObtained: ''));

    $asset->refresh();
    expect($asset->license_type)->toBe('cc_by_sa');
    expect($asset->copyright)->toBe('existing copyright');
    expect($asset->copyright_source)->toBe('existing source');
    expect($asset->date_obtained->format('Y-m-d'))->toBe('2026-01-01');
});

test('applyUploadMetadata attaches user tags with user attribution', function () {
    $asset = Asset::factory()->create();

    makeProcessingService()->applyUploadMetadata($asset, new UploadMetadata(tags: ['Landscape', 'nature']));

    $asset->refresh()->load('tags');
    $tagNames = $asset->tags->pluck('name')->all();
    expect($tagNames)->toContain('landscape');
    expect($tagNames)->toContain('nature');

    foreach ($asset->tags as $tag) {
        expect($tag->type)->toBe('user');
        expect($tag->pivot->attached_by)->toBe('user');
    }
});

test('applyUploadMetadata is a no-op when all inputs are null or empty', function () {
    $asset = Asset::factory()->create([
        'license_type' => 'public_domain',
        'copyright' => 'original',
        'copyright_source' => 'original-src',
        'date_obtained' => '2026-01-01',
    ]);

    makeProcessingService()->applyUploadMetadata($asset, new UploadMetadata);
    makeProcessingService()->applyUploadMetadata($asset, new UploadMetadata(
        tags: [],
        referenceTagIds: [],
        licenseType: '',
        copyright: '',
        copyrightSource: '',
        dateObtained: '',
    ));

    $asset->refresh();
    expect($asset->license_type)->toBe('public_domain');
    expect($asset->copyright)->toBe('original');
    expect($asset->copyright_source)->toBe('original-src');
    expect($asset->date_obtained->format('Y-m-d'))->toBe('2026-01-01');
    expect($asset->tags()->count())->toBe(0);
});

test('applyUploadMetadata with tags only does not modify license or copyright fields', function () {
    $asset = Asset::factory()->create([
        'license_type' => 'cc_by_nc',
        'copyright' => 'preserved',
        'copyright_source' => 'preserved-src',
    ]);

    makeProcessingService()->applyUploadMetadata($asset, new UploadMetadata(tags: ['photo']));

    $asset->refresh()->load('tags');
    expect($asset->license_type)->toBe('cc_by_nc');
    expect($asset->copyright)->toBe('preserved');
    expect($asset->copyright_source)->toBe('preserved-src');
    expect($asset->tags->pluck('name')->all())->toContain('photo');
});

test('applyUploadMetadata reuses existing user tags without duplicating them', function () {
    $existing = Tag::factory()->create(['name' => 'sunset', 'type' => 'user']);
    $asset = Asset::factory()->create();

    makeProcessingService()->applyUploadMetadata($asset, new UploadMetadata(tags: ['Sunset']));

    $asset->refresh()->load('tags');
    expect($asset->tags)->toHaveCount(1);
    expect($asset->tags->first()->id)->toBe($existing->id);
    expect(Tag::where('name', 'sunset')->count())->toBe(1);
});
