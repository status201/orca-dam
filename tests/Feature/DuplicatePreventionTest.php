<?php

use App\Exceptions\DuplicateAssetException;
use App\Models\Asset;
use App\Models\Tag;
use App\Models\User;
use App\Services\S3Service;
use Illuminate\Http\UploadedFile;

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

    $file = UploadedFile::fake()->image('photo.jpg', 800, 600);

    $response = $this->actingAs($user)
        ->postJson(route('assets.store'), ['files' => [$file]]);

    $response->assertStatus(409);
    $response->assertJsonFragment(['existing_asset_id' => $existing->id]);
});

/**
 * The keep_original_filename matrix.
 *
 * This flag had no test at all — `keep_original_filename` appeared nowhere under tests/ — which is
 * how the dedup gate came to be written against the flag instead of against the key collision it
 * was meant to stand for. With the box ticked and a *different* filename, the etag check was
 * skipped although no overwrite was happening, and identical bytes uploaded twice.
 *
 * All four combinations are pinned, because the two that must NOT report a duplicate are what stop
 * the fix being "always dedup", which would break the intentional-overwrite case.
 */
test('keeping the original filename still blocks identical bytes under a different name', function () {
    // The reported bug. Ticked box, different filename, same etag: no overwrite is happening, so
    // this is a plain duplicate and must be refused.
    $user = User::factory()->create();
    $existing = Asset::factory()->create(['etag' => 'same-bytes', 's3_key' => 'assets/first-name.jpg']);

    $s3Service = Mockery::mock(S3Service::class);
    // ->with() constrains the third argument, so the test fails if the flag never reaches S3Service.
    // Without it the mock answers identically either way and could not tell the two cases apart.
    $s3Service->shouldReceive('uploadFile')->once()
        ->with(Mockery::any(), Mockery::any(), true)
        ->andReturn([
            's3_key' => 'assets/second-name.jpg',
            'filename' => 'second-name.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 5000,
            'etag' => 'same-bytes',
            'width' => 800,
            'height' => 600,
        ]);
    $s3Service->shouldReceive('deleteFile')->once()->with('assets/second-name.jpg')->andReturn(true);
    $this->app->instance(S3Service::class, $s3Service);

    $response = $this->actingAs($user)->postJson(route('assets.store'), [
        'files' => [UploadedFile::fake()->image('second-name.jpg', 800, 600)],
        'keep_original_filename' => true,
    ]);

    $response->assertStatus(409);
    $response->assertJsonFragment(['existing_asset_id' => $existing->id]);
    // The S3 object was cleaned up and no second row exists.
    expect(Asset::withTrashed()->where('etag', 'same-bytes')->count())->toBe(1);
});

test('keeping the original filename overwrites in place when the key collides', function () {
    // REQ-2's legitimate case, previously unpinned in either direction. The existing row already
    // carries this etag, so a gate that ignored the collision would report it as a duplicate of
    // itself and refuse a deliberate re-upload.
    $user = User::factory()->create();
    $existing = Asset::factory()->create([
        'etag' => 'old-bytes',
        's3_key' => 'assets/report.pdf',
        'filename' => 'report.pdf',
    ]);

    $s3Service = Mockery::mock(S3Service::class);
    $s3Service->shouldReceive('uploadFile')->once()->andReturn([
        's3_key' => 'assets/report.pdf',
        'filename' => 'report.pdf',
        'mime_type' => 'image/jpeg',
        'size' => 6000,
        'etag' => 'new-bytes',
        'width' => 800,
        'height' => 600,
    ]);
    $s3Service->shouldReceive('deleteAssetFiles')->once();
    $s3Service->shouldReceive('generateThumbnail')->andReturn(null);
    $s3Service->shouldReceive('generateResizedImages')->andReturn([]);
    $this->app->instance(S3Service::class, $s3Service);

    $response = $this->actingAs($user)->postJson(route('assets.store'), [
        'files' => [UploadedFile::fake()->image('report.pdf', 800, 600)],
        'keep_original_filename' => true,
    ]);

    $response->assertStatus(200);
    // Updated in place, not duplicated and not refused.
    expect(Asset::count())->toBe(1)
        ->and($existing->fresh()->etag)->toBe('new-bytes')
        ->and($existing->fresh()->size)->toBe(6000);
});

test('re-uploading the same file at the same key is an overwrite, not a duplicate of itself', function () {
    // The trap in gating on the collision: the row found by s3_key IS the row that would be found
    // by etag. Uploading identical bytes to the same key must still overwrite.
    $user = User::factory()->create();
    $existing = Asset::factory()->create(['etag' => 'unchanged', 's3_key' => 'assets/logo.png']);

    $s3Service = Mockery::mock(S3Service::class);
    $s3Service->shouldReceive('uploadFile')->once()->andReturn([
        's3_key' => 'assets/logo.png',
        'filename' => 'logo.png',
        'mime_type' => 'image/png',
        'size' => 5000,
        'etag' => 'unchanged',
        'width' => 800,
        'height' => 600,
    ]);
    $s3Service->shouldReceive('deleteAssetFiles')->once();
    $s3Service->shouldReceive('generateThumbnail')->andReturn(null);
    $s3Service->shouldReceive('generateResizedImages')->andReturn([]);
    $this->app->instance(S3Service::class, $s3Service);

    $response = $this->actingAs($user)->postJson(route('assets.store'), [
        'files' => [UploadedFile::fake()->image('logo.png', 800, 600)],
        'keep_original_filename' => true,
    ]);

    $response->assertStatus(200);
    expect(Asset::count())->toBe(1)
        ->and(Asset::first()->id)->toBe($existing->id);
});

test('keeping the original filename still creates a new asset for different bytes', function () {
    $user = User::factory()->create();
    Asset::factory()->create(['etag' => 'other-bytes', 's3_key' => 'assets/first.jpg']);

    $s3Service = Mockery::mock(S3Service::class);
    $s3Service->shouldReceive('uploadFile')->once()->andReturn([
        's3_key' => 'assets/second.jpg',
        'filename' => 'second.jpg',
        'mime_type' => 'image/jpeg',
        'size' => 5000,
        'etag' => 'fresh-bytes',
        'width' => 800,
        'height' => 600,
    ]);
    $s3Service->shouldReceive('generateThumbnail')->andReturn(null);
    $s3Service->shouldReceive('generateResizedImages')->andReturn([]);
    $this->app->instance(S3Service::class, $s3Service);

    $response = $this->actingAs($user)->postJson(route('assets.store'), [
        'files' => [UploadedFile::fake()->image('second.jpg', 800, 600)],
        'keep_original_filename' => true,
    ]);

    $response->assertStatus(200);
    expect(Asset::count())->toBe(2);
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

    $file = UploadedFile::fake()->image('photo.jpg', 800, 600);

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

    $file = UploadedFile::fake()->image('photo.jpg', 800, 600);

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

    $file = UploadedFile::fake()->image('photo.jpg', 800, 600);

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

    $file = UploadedFile::fake()->image('photo.jpg', 800, 600);

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
