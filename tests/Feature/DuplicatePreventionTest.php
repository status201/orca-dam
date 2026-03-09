<?php

use App\Exceptions\DuplicateAssetException;
use App\Models\Asset;
use App\Models\Tag;
use App\Models\User;
use App\Services\S3Service;

test('web upload blocks duplicate files by etag', function () {
    $user = User::factory()->create();
    $existing = Asset::factory()->create(['etag' => 'abc123']);

    $s3Service = Mockery::mock(S3Service::class);
    $s3Service->shouldReceive('uploadFile')->once()->andReturn([
        's3_key' => 'assets/new-file.jpg',
        'filename' => 'photo.jpg',
        'mime_type' => 'image/jpeg',
        'size' => 5000,
        'etag' => 'abc123',
        'width' => 800,
        'height' => 600,
    ]);
    $s3Service->shouldReceive('deleteFile')->once()->with('assets/new-file.jpg')->andReturn(true);
    $this->app->instance(S3Service::class, $s3Service);

    $file = \Illuminate\Http\UploadedFile::fake()->image('photo.jpg', 800, 600);

    $response = $this->actingAs($user)
        ->postJson(route('assets.store'), ['files' => [$file]]);

    $response->assertStatus(409);
    $response->assertJsonFragment(['existing_asset_id' => $existing->id]);
});

test('web upload allows files with unique etag', function () {
    $user = User::factory()->create();
    Asset::factory()->create(['etag' => 'different-etag']);

    $s3Service = Mockery::mock(S3Service::class);
    $s3Service->shouldReceive('uploadFile')->once()->andReturn([
        's3_key' => 'assets/new-file.jpg',
        'filename' => 'photo.jpg',
        'mime_type' => 'image/jpeg',
        'size' => 5000,
        'etag' => 'unique-etag-456',
        'width' => 800,
        'height' => 600,
    ]);
    $s3Service->shouldReceive('generateThumbnail')->andReturn(null);
    $s3Service->shouldReceive('generateResizedImages')->andReturn([]);
    $this->app->instance(S3Service::class, $s3Service);

    $file = \Illuminate\Http\UploadedFile::fake()->image('photo.jpg', 800, 600);

    $response = $this->actingAs($user)
        ->postJson(route('assets.store'), ['files' => [$file]]);

    $response->assertStatus(200);
    expect(Asset::where('etag', 'unique-etag-456')->exists())->toBeTrue();
});

test('web upload detects duplicate even when existing is trashed', function () {
    $user = User::factory()->create();
    $existing = Asset::factory()->create(['etag' => 'abc123']);
    $existing->delete(); // Soft delete

    $s3Service = Mockery::mock(S3Service::class);
    $s3Service->shouldReceive('uploadFile')->once()->andReturn([
        's3_key' => 'assets/new-file.jpg',
        'filename' => 'photo.jpg',
        'mime_type' => 'image/jpeg',
        'size' => 5000,
        'etag' => 'abc123',
        'width' => 800,
        'height' => 600,
    ]);
    $s3Service->shouldReceive('deleteFile')->once()->with('assets/new-file.jpg')->andReturn(true);
    $this->app->instance(S3Service::class, $s3Service);

    $file = \Illuminate\Http\UploadedFile::fake()->image('photo.jpg', 800, 600);

    $response = $this->actingAs($user)
        ->postJson(route('assets.store'), ['files' => [$file]]);

    $response->assertStatus(409);
});

test('api upload blocks duplicate files by etag', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;
    $existing = Asset::factory()->create(['etag' => 'abc123']);

    $s3Service = Mockery::mock(S3Service::class);
    $s3Service->shouldReceive('uploadFile')->once()->andReturn([
        's3_key' => 'assets/new-file.jpg',
        'filename' => 'photo.jpg',
        'mime_type' => 'image/jpeg',
        'size' => 5000,
        'etag' => 'abc123',
        'width' => 800,
        'height' => 600,
    ]);
    $s3Service->shouldReceive('deleteFile')->once()->with('assets/new-file.jpg')->andReturn(true);
    $this->app->instance(S3Service::class, $s3Service);

    $file = \Illuminate\Http\UploadedFile::fake()->image('photo.jpg', 800, 600);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/assets', ['files' => [$file]]);

    $response->assertStatus(409);
    $response->assertJsonFragment(['existing_asset_id' => $existing->id]);
});

test('api upload saves etag on asset', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $s3Service = Mockery::mock(S3Service::class);
    $s3Service->shouldReceive('uploadFile')->once()->andReturn([
        's3_key' => 'assets/new-file.jpg',
        'filename' => 'photo.jpg',
        'mime_type' => 'image/jpeg',
        'size' => 5000,
        'etag' => 'brand-new-etag',
        'width' => 800,
        'height' => 600,
    ]);
    $s3Service->shouldReceive('generateThumbnail')->andReturn(null);
    $s3Service->shouldReceive('generateResizedImages')->andReturn([]);
    $this->app->instance(S3Service::class, $s3Service);

    $file = \Illuminate\Http\UploadedFile::fake()->image('photo.jpg', 800, 600);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/assets', ['files' => [$file]]);

    $response->assertStatus(201);
    $asset = Asset::where('etag', 'brand-new-etag')->first();
    expect($asset)->not->toBeNull();
    expect($asset->etag)->toBe('brand-new-etag');
});

test('deduplicate command finds and reports duplicates in dry run', function () {
    $user = User::factory()->create();

    // Create 3 assets with same etag
    Asset::factory()->create(['etag' => 'dupe-etag', 'user_id' => $user->id, 'created_at' => now()->subDays(3)]);
    Asset::factory()->create(['etag' => 'dupe-etag', 'user_id' => $user->id, 'created_at' => now()->subDays(2)]);
    Asset::factory()->create(['etag' => 'dupe-etag', 'user_id' => $user->id, 'created_at' => now()->subDays(1)]);

    // Unique asset should not be affected
    Asset::factory()->create(['etag' => 'unique-etag', 'user_id' => $user->id]);

    $this->artisan('assets:deduplicate')
        ->expectsOutputToContain('Found 1 group(s) of duplicates')
        ->expectsOutputToContain('Total duplicates: 2')
        ->expectsOutputToContain('Would soft-delete: 2')
        ->assertExitCode(0);

    // Nothing actually deleted in dry run
    expect(Asset::count())->toBe(4);
});

test('deduplicate command soft-deletes duplicates with --force', function () {
    $user = User::factory()->create();

    $keeper = Asset::factory()->create(['etag' => 'dupe-etag', 'user_id' => $user->id, 'created_at' => now()->subDays(3)]);
    $dupe1 = Asset::factory()->create(['etag' => 'dupe-etag', 'user_id' => $user->id, 'created_at' => now()->subDays(2)]);
    $dupe2 = Asset::factory()->create(['etag' => 'dupe-etag', 'user_id' => $user->id, 'created_at' => now()->subDays(1)]);

    $this->artisan('assets:deduplicate --force')
        ->expectsOutputToContain('Soft-deleted: 2')
        ->assertExitCode(0);

    expect(Asset::count())->toBe(1);
    expect(Asset::first()->id)->toBe($keeper->id);
    expect(Asset::onlyTrashed()->count())->toBe(2);
});

test('deduplicate command skips assets with reference tags', function () {
    $user = User::factory()->create();
    $refTag = Tag::factory()->reference()->create();

    $keeper = Asset::factory()->create(['etag' => 'dupe-etag', 'user_id' => $user->id, 'created_at' => now()->subDays(3)]);
    $dupeWithRef = Asset::factory()->create(['etag' => 'dupe-etag', 'user_id' => $user->id, 'created_at' => now()->subDays(2)]);
    $dupeWithRef->tags()->attach($refTag->id);
    $normalDupe = Asset::factory()->create(['etag' => 'dupe-etag', 'user_id' => $user->id, 'created_at' => now()->subDays(1)]);

    $this->artisan('assets:deduplicate --force')
        ->expectsOutputToContain('Skipped (reference tags): 1')
        ->expectsOutputToContain('Soft-deleted: 1')
        ->assertExitCode(0);

    expect(Asset::count())->toBe(2); // keeper + ref-tagged dupe
    expect(Asset::onlyTrashed()->count())->toBe(1);
    expect(Asset::onlyTrashed()->first()->id)->toBe($normalDupe->id);
});

test('deduplicate command ignores assets with null etag', function () {
    $user = User::factory()->create();

    Asset::factory()->create(['etag' => null, 'user_id' => $user->id]);
    Asset::factory()->create(['etag' => null, 'user_id' => $user->id]);

    $this->artisan('assets:deduplicate')
        ->expectsOutputToContain('No duplicates found')
        ->assertExitCode(0);
});

test('DuplicateAssetException contains existing asset', function () {
    $asset = Asset::factory()->create();
    $exception = new DuplicateAssetException($asset);

    expect($exception->existingAsset->id)->toBe($asset->id);
    expect($exception->getMessage())->toContain((string) $asset->id);
});
