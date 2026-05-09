<?php

use App\Exceptions\DuplicateAssetException;
use App\Models\Asset;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\ChunkedUploadService;
use App\Services\S3Service;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

function expectedDuplicateKeys(): array
{
    return [
        'filename',
        'existing_asset_id',
        'existing_filename',
        'existing_folder',
        'mime_type',
        'size',
        'thumbnail_url',
        'public_url',
        'show_url',
        'is_trashed',
        'can_restore',
        'uploaded_at',
    ];
}

test('direct upload duplicate response carries enriched panel payload', function () {
    $user = User::factory()->create();
    $existing = Asset::factory()->create([
        'etag' => 'enriched-etag',
        'filename' => 'kept-name.jpg',
        's3_key' => 'assets/marketing/kept-name.jpg',
        'mime_type' => 'image/jpeg',
        'size' => 12345,
    ]);

    $s3Service = Mockery::mock(S3Service::class);
    $s3Service->shouldReceive('uploadFile')->once()->andReturn([
        's3_key' => 'assets/new-file.jpg',
        'filename' => 'attempted-upload.jpg',
        'mime_type' => 'image/jpeg',
        'size' => 5000,
        'etag' => 'enriched-etag',
        'width' => 800,
        'height' => 600,
    ]);
    $s3Service->shouldReceive('deleteFile')->once()->andReturn(true);
    $this->app->instance(S3Service::class, $s3Service);

    $file = UploadedFile::fake()->image('attempted-upload.jpg', 800, 600);

    $response = $this->actingAs($user)
        ->postJson(route('assets.store'), ['files' => [$file]]);

    $response->assertStatus(409);
    $payload = $response->json('duplicates.0');

    foreach (expectedDuplicateKeys() as $key) {
        expect($payload)->toHaveKey($key);
    }

    expect($payload['existing_asset_id'])->toBe($existing->id);
    expect($payload['existing_filename'])->toBe('kept-name.jpg');
    expect($payload['filename'])->toBe('attempted-upload.jpg');
    expect($payload['existing_folder'])->toBe('assets/marketing');
    expect($payload['is_trashed'])->toBeFalse();
    expect($payload['can_restore'])->toBeFalse();
    expect($payload['show_url'])->toBe(route('assets.show', $existing));
});

test('chunked upload duplicate response uses identical payload shape as direct', function () {
    $user = User::factory()->create();
    $existing = Asset::factory()->create([
        'filename' => 'big.jpg',
        's3_key' => 'assets/big.jpg',
        'mime_type' => 'image/jpeg',
    ]);

    $session = UploadSession::create([
        'upload_id' => 'u1',
        'session_token' => Str::uuid()->toString(),
        'filename' => 'big.jpg',
        'mime_type' => 'image/jpeg',
        'file_size' => 20 * 1024 * 1024,
        's3_key' => 'assets/big.jpg',
        'chunk_size' => 10 * 1024 * 1024,
        'total_chunks' => 2,
        'uploaded_chunks' => 2,
        'part_etags' => [],
        'status' => 'uploading',
        'user_id' => $user->id,
        'last_activity_at' => now(),
    ]);

    $chunkedMock = Mockery::mock(ChunkedUploadService::class);
    $chunkedMock->shouldReceive('completeUpload')->once()
        ->andThrow(new DuplicateAssetException($existing));
    $this->app->instance(ChunkedUploadService::class, $chunkedMock);

    $response = $this->actingAs($user)->postJson(route('chunked-upload.complete'), [
        'session_token' => $session->session_token,
    ]);

    $response->assertStatus(409);
    $payload = $response->json('duplicates.0');

    foreach (expectedDuplicateKeys() as $key) {
        expect($payload)->toHaveKey($key);
    }
    expect($payload['existing_asset_id'])->toBe($existing->id);
});

test('trashed duplicate sets is_trashed and nullifies show_url', function () {
    $user = User::factory()->admin()->create();
    $existing = Asset::factory()->create(['etag' => 'trashed-etag']);
    $existing->delete();

    $s3Service = Mockery::mock(S3Service::class);
    $s3Service->shouldReceive('uploadFile')->once()->andReturn([
        's3_key' => 'assets/new.jpg',
        'filename' => 'new.jpg',
        'mime_type' => 'image/jpeg',
        'size' => 5000,
        'etag' => 'trashed-etag',
        'width' => 800,
        'height' => 600,
    ]);
    $s3Service->shouldReceive('deleteFile')->once()->andReturn(true);
    $this->app->instance(S3Service::class, $s3Service);

    $file = UploadedFile::fake()->image('new.jpg', 800, 600);

    $response = $this->actingAs($user)
        ->postJson(route('assets.store'), ['files' => [$file]]);

    $response->assertStatus(409);
    $payload = $response->json('duplicates.0');

    expect($payload['is_trashed'])->toBeTrue();
    expect($payload['show_url'])->toBeNull();
    // Admin can restore — policy permits admin + editor.
    expect($payload['can_restore'])->toBeTrue();
});

test('api role cannot restore a trashed duplicate from the panel payload', function () {
    $apiUser = User::factory()->apiUser()->create();
    $existing = Asset::factory()->create(['etag' => 'api-trashed-etag']);
    $existing->delete();

    $s3Service = Mockery::mock(S3Service::class);
    $s3Service->shouldReceive('uploadFile')->once()->andReturn([
        's3_key' => 'assets/new.jpg',
        'filename' => 'new.jpg',
        'mime_type' => 'image/jpeg',
        'size' => 5000,
        'etag' => 'api-trashed-etag',
        'width' => 800,
        'height' => 600,
    ]);
    $s3Service->shouldReceive('deleteFile')->once()->andReturn(true);
    $this->app->instance(S3Service::class, $s3Service);

    $file = UploadedFile::fake()->image('new.jpg', 800, 600);

    $response = $this->actingAs($apiUser)
        ->postJson(route('assets.store'), ['files' => [$file]]);

    $response->assertStatus(409);
    $payload = $response->json('duplicates.0');

    expect($payload['is_trashed'])->toBeTrue();
    expect($payload['can_restore'])->toBeFalse();
});

test('assets index ids[] filter returns only the listed assets and bypasses folder scoping', function () {
    $user = User::factory()->create();
    $a = Asset::factory()->create(['s3_key' => 'assets/folder-a/a.jpg']);
    $b = Asset::factory()->create(['s3_key' => 'assets/folder-b/b.jpg']);
    $c = Asset::factory()->create(['s3_key' => 'assets/folder-c/c.jpg']);

    // Pass folder=assets/folder-a explicitly — ids[] should still bypass it.
    $response = $this->actingAs($user)->get(route('assets.index', [
        'ids' => [$a->id, $c->id],
        'folder' => 'assets/folder-a',
    ]));

    $response->assertOk();
    $assets = $response->viewData('assets');
    $ids = collect($assets->items())->pluck('id')->all();

    expect($ids)->toContain($a->id, $c->id);
    expect($ids)->not->toContain($b->id);
});

test('assets index ids[] filter caps at 200 ids', function () {
    $user = User::factory()->create();

    // Build a 250-id list — request must succeed (i.e., the cap silently drops the tail).
    $ids = range(1, 250);

    $response = $this->actingAs($user)->get(route('assets.index', ['ids' => $ids]));
    $response->assertOk();
});

test('assets index ids[] filter ignores invalid values', function () {
    $user = User::factory()->create();
    $asset = Asset::factory()->create();

    // Mixed garbage + a real id.
    $response = $this->actingAs($user)->get(route('assets.index', [
        'ids' => ['abc', '0', '-3', $asset->id],
    ]));

    $response->assertOk();
    $ids = collect($response->viewData('assets')->items())->pluck('id')->all();
    expect($ids)->toEqual([$asset->id]);
});
