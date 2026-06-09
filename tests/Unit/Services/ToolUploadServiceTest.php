<?php

use App\Models\Asset;
use App\Models\User;
use App\Services\AssetProcessingService;
use App\Services\S3Service;
use App\Services\ToolUploadService;

test('store uploads content and creates an asset with merged attributes', function () {
    $user = User::factory()->create();

    $s3 = Mockery::mock(S3Service::class);
    $s3->shouldReceive('uploadFile')
        ->once()
        ->andReturn(['s3_key' => 'assets/x.svg', 'size' => 123, 'etag' => 'etag-1']);

    $processing = Mockery::mock(AssetProcessingService::class);
    $processing->shouldReceive('processImageAsset')->once();
    $processing->shouldNotReceive('applyUploadMetadata');

    $asset = (new ToolUploadService($s3, $processing))->store(
        content: '<svg/>',
        filename: 'x.svg',
        mimeType: 'image/svg+xml',
        folder: 'assets',
        attributes: ['user_id' => $user->id, 'caption' => 'hi'],
    );

    expect($asset)->toBeInstanceOf(Asset::class);
    expect($asset->s3_key)->toBe('assets/x.svg');
    expect($asset->mime_type)->toBe('image/svg+xml');
    expect($asset->size)->toBe(123);
    expect($asset->etag)->toBe('etag-1');
    expect($asset->caption)->toBe('hi');
    expect($asset->user_id)->toBe($user->id);
});

test('store skips processing and metadata when process is false', function () {
    $user = User::factory()->create();

    $s3 = Mockery::mock(S3Service::class);
    $s3->shouldReceive('uploadFile')
        ->once()
        ->andReturn(['s3_key' => 'assets/t.tex', 'size' => 1, 'etag' => null]);

    $processing = Mockery::mock(AssetProcessingService::class);
    $processing->shouldNotReceive('processImageAsset');
    $processing->shouldNotReceive('applyUploadMetadata');

    $asset = (new ToolUploadService($s3, $processing))->store(
        content: 'x',
        filename: 't.tex',
        mimeType: 'application/x-tex',
        folder: 'assets',
        attributes: ['user_id' => $user->id],
        process: false,
    );

    expect($asset->mime_type)->toBe('application/x-tex');
    expect($asset->etag)->toBeNull();
});

test('store forwards the metadata payload to applyUploadMetadata', function () {
    $user = User::factory()->create();

    $s3 = Mockery::mock(S3Service::class);
    $s3->shouldReceive('uploadFile')
        ->once()
        ->andReturn(['s3_key' => 'assets/g.gif', 'size' => 5, 'etag' => 'e']);

    $processing = Mockery::mock(AssetProcessingService::class);
    $processing->shouldReceive('processImageAsset')->once();
    $processing->shouldReceive('applyUploadMetadata')
        ->once()
        ->with(Mockery::type(Asset::class), ['a'], 'cc_by', '© x', 'src', [7]);

    (new ToolUploadService($s3, $processing))->store(
        content: 'GIF',
        filename: 'g.gif',
        mimeType: 'image/gif',
        folder: 'assets',
        attributes: ['user_id' => $user->id],
        metadata: [
            'tags' => ['a'],
            'license_type' => 'cc_by',
            'copyright' => '© x',
            'copyright_source' => 'src',
            'reference_tag_ids' => [7],
        ],
    );
});

test('store always removes the temp file, even on success', function () {
    $user = User::factory()->create();

    $capturedPath = null;
    $s3 = Mockery::mock(S3Service::class);
    $s3->shouldReceive('uploadFile')
        ->once()
        ->andReturnUsing(function ($file) use (&$capturedPath) {
            $capturedPath = $file->getRealPath();

            return ['s3_key' => 'assets/x.svg', 'size' => 1, 'etag' => null];
        });

    $processing = Mockery::mock(AssetProcessingService::class);
    $processing->shouldReceive('processImageAsset')->once();

    (new ToolUploadService($s3, $processing))->store(
        content: '<svg/>',
        filename: 'x.svg',
        mimeType: 'image/svg+xml',
        folder: 'assets',
        attributes: ['user_id' => $user->id],
    );

    expect($capturedPath)->not->toBeNull();
    expect(file_exists($capturedPath))->toBeFalse();
});
