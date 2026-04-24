<?php

use App\Jobs\ProcessDiscoveredAsset;
use App\Models\Asset;
use App\Models\User;
use App\Services\S3Service;
use Illuminate\Support\Facades\Bus;

test('discover index forbidden for editor', function () {
    $this->actingAs(User::factory()->create(['role' => 'editor']))
        ->get('/discover')
        ->assertForbidden();
});

test('discover index forbidden for api user', function () {
    $user = User::factory()->create(['role' => 'api']);
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->get('/discover')
        ->assertStatus(302); // api user via session is not logged in → redirected
});

test('discover index renders for admin', function () {
    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->get('/discover')
        ->assertOk();
});

test('discover scan forbidden for editor', function () {
    $this->actingAs(User::factory()->create(['role' => 'editor']))
        ->postJson('/discover/scan')
        ->assertForbidden();
});

test('discover scan returns unmapped S3 objects enriched with soft-delete status', function () {
    // Pre-seed a soft-deleted asset with matching s3_key
    $deleted = Asset::factory()->image()->create(['s3_key' => 'assets/old.jpg']);
    $deleted->delete();

    $mock = Mockery::mock(S3Service::class);
    $mock->shouldReceive('findUnmappedObjects')->andReturn([
        ['key' => 'assets/old.jpg', 'size' => 100, 'last_modified' => '2026-01-01'],
        ['key' => 'assets/new.jpg', 'size' => 200, 'last_modified' => '2026-01-02'],
    ]);
    $mock->shouldReceive('getObjectMetadata')->andReturn(['mime_type' => 'image/jpeg', 'size' => 100]);
    $mock->shouldReceive('getUrl')->andReturnUsing(fn ($k) => 'https://s3.example.com/'.$k);
    $this->app->instance(S3Service::class, $mock);

    $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->postJson('/discover/scan')
        ->assertOk();

    expect($response->json('count'))->toBe(2);
    $objects = $response->json('objects');
    $deletedEntry = collect($objects)->firstWhere('key', 'assets/old.jpg');
    $freshEntry = collect($objects)->firstWhere('key', 'assets/new.jpg');
    expect($deletedEntry['is_deleted'])->toBeTrue();
    expect($freshEntry['is_deleted'])->toBeFalse();
});

test('discover import creates asset and dispatches ProcessDiscoveredAsset', function () {
    Bus::fake([ProcessDiscoveredAsset::class]);

    $mock = Mockery::mock(S3Service::class);
    $mock->shouldReceive('getObjectMetadata')->andReturn([
        'etag' => 'new-etag',
        'mime_type' => 'image/jpeg',
        'size' => 12345,
    ]);
    $this->app->instance(S3Service::class, $mock);

    $admin = User::factory()->create(['role' => 'admin']);
    $this->actingAs($admin)
        ->postJson('/discover/import', ['keys' => ['assets/photo.jpg']])
        ->assertOk()
        ->assertJson(['imported' => 1, 'skipped' => 0]);

    expect(Asset::where('s3_key', 'assets/photo.jpg')->exists())->toBeTrue();
    Bus::assertDispatched(ProcessDiscoveredAsset::class);
});

test('discover import skips soft-deleted assets to prevent re-import', function () {
    Bus::fake([ProcessDiscoveredAsset::class]);

    $existing = Asset::factory()->image()->create(['s3_key' => 'assets/photo.jpg']);
    $existing->delete();

    $mock = Mockery::mock(S3Service::class);
    $mock->shouldNotReceive('getObjectMetadata');
    $this->app->instance(S3Service::class, $mock);

    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->postJson('/discover/import', ['keys' => ['assets/photo.jpg']])
        ->assertOk()
        ->assertJson(['imported' => 0, 'skipped' => 1]);

    Bus::assertNotDispatched(ProcessDiscoveredAsset::class);
});

test('discover import skips duplicate etags', function () {
    Bus::fake([ProcessDiscoveredAsset::class]);

    Asset::factory()->image()->create(['s3_key' => 'assets/existing.jpg', 'etag' => 'dup-etag']);

    $mock = Mockery::mock(S3Service::class);
    $mock->shouldReceive('getObjectMetadata')->andReturn([
        'etag' => 'dup-etag',
        'mime_type' => 'image/jpeg',
        'size' => 100,
    ]);
    $this->app->instance(S3Service::class, $mock);

    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->postJson('/discover/import', ['keys' => ['assets/new.jpg']])
        ->assertOk()
        ->assertJson(['imported' => 0, 'skipped' => 1]);

    Bus::assertNotDispatched(ProcessDiscoveredAsset::class);
});

test('discover import requires admin', function () {
    $this->actingAs(User::factory()->create(['role' => 'editor']))
        ->postJson('/discover/import', ['keys' => ['assets/photo.jpg']])
        ->assertForbidden();
});
