<?php

use App\Exceptions\DuplicateAssetException;
use App\Models\Asset;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\ChunkedUploadService;
use App\Services\S3Service;
use Illuminate\Support\Str;

function makeUploadSession(User $user, array $overrides = []): UploadSession
{
    return UploadSession::create(array_merge([
        'upload_id' => 'fake-upload-id',
        'session_token' => Str::uuid()->toString(),
        'filename' => 'big-photo.jpg',
        'mime_type' => 'image/jpeg',
        'file_size' => 20 * 1024 * 1024,
        's3_key' => 'assets/big-photo.jpg',
        'chunk_size' => 10 * 1024 * 1024,
        'total_chunks' => 2,
        'uploaded_chunks' => 2,
        'part_etags' => [],
        'status' => 'uploading',
        'user_id' => $user->id,
        'last_activity_at' => now(),
    ], $overrides));
}

test('chunked upload complete applies batch metadata to created asset', function () {
    $user = User::factory()->create();
    $session = makeUploadSession($user);

    $asset = Asset::factory()->create([
        's3_key' => 'assets/big-photo.jpg',
        'mime_type' => 'image/jpeg',
        'user_id' => $user->id,
    ]);

    $chunkedMock = Mockery::mock(ChunkedUploadService::class);
    $chunkedMock->shouldReceive('completeUpload')->once()->andReturn($asset);
    $this->app->instance(ChunkedUploadService::class, $chunkedMock);

    $s3Mock = Mockery::mock(S3Service::class);
    $s3Mock->shouldReceive('generateThumbnail')->andReturn(null);
    $s3Mock->shouldReceive('generateResizedImages')->andReturn([]);
    $this->app->instance(S3Service::class, $s3Mock);

    $response = $this->actingAs($user)->postJson(route('chunked-upload.complete'), [
        'session_token' => $session->session_token,
        'metadata_tags' => ['Architecture', 'urban'],
        'metadata_license_type' => 'cc_by_nc',
        'metadata_copyright' => '© 2026 Studio',
        'metadata_copyright_source' => 'https://example.com/src',
    ]);

    $response->assertOk();

    $asset->refresh()->load('tags');
    expect($asset->license_type)->toBe('cc_by_nc');
    expect($asset->copyright)->toBe('© 2026 Studio');
    expect($asset->copyright_source)->toBe('https://example.com/src');

    $tagNames = $asset->tags->pluck('name')->all();
    expect($tagNames)->toContain('architecture');
    expect($tagNames)->toContain('urban');

    foreach ($asset->tags as $tag) {
        expect($tag->type)->toBe('user');
        expect($tag->pivot->attached_by)->toBe('user');
    }
});

test('chunked upload complete works without metadata fields', function () {
    $user = User::factory()->create();
    $session = makeUploadSession($user);

    $asset = Asset::factory()->create([
        's3_key' => 'assets/big-photo.jpg',
        'mime_type' => 'image/jpeg',
        'user_id' => $user->id,
        'license_type' => null,
        'copyright' => null,
    ]);

    $chunkedMock = Mockery::mock(ChunkedUploadService::class);
    $chunkedMock->shouldReceive('completeUpload')->once()->andReturn($asset);
    $this->app->instance(ChunkedUploadService::class, $chunkedMock);

    $s3Mock = Mockery::mock(S3Service::class);
    $s3Mock->shouldReceive('generateThumbnail')->andReturn(null);
    $s3Mock->shouldReceive('generateResizedImages')->andReturn([]);
    $this->app->instance(S3Service::class, $s3Mock);

    $response = $this->actingAs($user)->postJson(route('chunked-upload.complete'), [
        'session_token' => $session->session_token,
    ]);

    $response->assertOk();

    $asset->refresh();
    expect($asset->license_type)->toBeNull();
    expect($asset->copyright)->toBeNull();
    expect($asset->tags()->count())->toBe(0);
});

test('chunked upload complete rejects invalid license type', function () {
    $user = User::factory()->create();
    $session = makeUploadSession($user);

    $response = $this->actingAs($user)->postJson(route('chunked-upload.complete'), [
        'session_token' => $session->session_token,
        'metadata_license_type' => 'bogus',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('metadata_license_type');
});

test('chunked upload complete rejects metadata_copyright over 500 chars', function () {
    $user = User::factory()->create();
    $session = makeUploadSession($user);

    $response = $this->actingAs($user)->postJson(route('chunked-upload.complete'), [
        'session_token' => $session->session_token,
        'metadata_copyright' => str_repeat('a', 501),
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('metadata_copyright');
});

test('chunked upload complete returns 409 on duplicate', function () {
    $user = User::factory()->create();
    $existing = Asset::factory()->create();
    $session = makeUploadSession($user);

    $chunkedMock = Mockery::mock(ChunkedUploadService::class);
    $chunkedMock->shouldReceive('completeUpload')->once()
        ->andThrow(new DuplicateAssetException($existing));
    $this->app->instance(ChunkedUploadService::class, $chunkedMock);

    $response = $this->actingAs($user)->postJson(route('chunked-upload.complete'), [
        'session_token' => $session->session_token,
    ]);

    $response->assertStatus(409);
    $response->assertJsonFragment(['existing_asset_id' => $existing->id]);
});
