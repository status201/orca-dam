<?php

use App\Models\Asset;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

test('api assets index requires authentication', function () {
    $response = $this->getJson('/api/assets');

    $response->assertUnauthorized();
});

test('api assets index returns paginated assets', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    Asset::factory()->count(5)->create();

    $response = $this->getJson('/api/assets');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            '*' => ['id', 'filename', 'mime_type', 'size'],
        ],
    ]);
});

test('api assets index can filter by search', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    Asset::factory()->create(['filename' => 'findme.jpg']);
    Asset::factory()->create(['filename' => 'other.pdf']);

    $response = $this->getJson('/api/assets?search=findme');

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonFragment(['filename' => 'findme.jpg']);
});

test('api assets index can filter by type', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    Asset::factory()->image()->create();
    Asset::factory()->pdf()->create();

    $response = $this->getJson('/api/assets?type=image');

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
});

test('api can get single asset', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $asset = Asset::factory()->create(['filename' => 'test.jpg']);

    $response = $this->getJson("/api/assets/{$asset->id}");

    $response->assertOk();
    $response->assertJsonFragment(['filename' => 'test.jpg']);
});

test('api can update asset', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $asset = Asset::factory()->create(['user_id' => $user->id]);

    $response = $this->patchJson("/api/assets/{$asset->id}", [
        'alt_text' => 'Updated alt text',
        'caption' => 'Updated caption',
    ]);

    $response->assertOk();

    $asset->refresh();
    expect($asset->alt_text)->toBe('Updated alt text');
    expect($asset->caption)->toBe('Updated caption');
});

test('api can delete asset', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $asset = Asset::factory()->create(['user_id' => $user->id]);
    $assetId = $asset->id;

    $response = $this->deleteJson("/api/assets/{$assetId}");

    $response->assertOk();
    $this->assertSoftDeleted('assets', ['id' => $assetId]);
});

test('api role user cannot delete asset (even their own)', function () {
    $apiUser = User::factory()->create(['role' => 'api']);
    Sanctum::actingAs($apiUser);

    $asset = Asset::factory()->create(['user_id' => $apiUser->id]);

    $response = $this->deleteJson("/api/assets/{$asset->id}");

    $response->assertForbidden();
    $this->assertDatabaseHas('assets', ['id' => $asset->id, 'deleted_at' => null]);
});

test('api tags index requires authentication', function () {
    $response = $this->getJson('/api/tags');

    $response->assertUnauthorized();
});

test('api tags index returns all tags', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    Tag::factory()->count(3)->create();

    $response = $this->getJson('/api/tags');

    $response->assertOk();
    $response->assertJsonCount(3, 'data');
    $response->assertJsonPath('total', 3);
});

test('api tags show returns single tag by id', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $tag = Tag::factory()->user()->create(['name' => 'landscape']);
    Asset::factory()->count(2)->create()->each(fn ($a) => $a->tags()->attach($tag));

    $response = $this->getJson("/api/tags/{$tag->id}");

    $response->assertOk();
    $response->assertJsonPath('id', $tag->id);
    $response->assertJsonPath('name', 'landscape');
    $response->assertJsonPath('assets_count', 2);
});

test('api tags show returns multiple tags by comma-separated ids', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $tag1 = Tag::factory()->user()->create(['name' => 'alpha']);
    $tag2 = Tag::factory()->ai()->create(['name' => 'beta']);
    $tag3 = Tag::factory()->user()->create(['name' => 'gamma']);

    $response = $this->getJson("/api/tags/{$tag1->id},{$tag2->id},{$tag3->id}");

    $response->assertOk();
    $response->assertJsonCount(3);
    $names = collect($response->json())->pluck('name')->all();
    expect($names)->toContain('alpha');
    expect($names)->toContain('beta');
    expect($names)->toContain('gamma');
});

test('api tags show returns 404 for nonexistent single id', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/tags/99999');

    $response->assertNotFound();
});

test('api tags show returns only found tags for multiple ids', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $tag = Tag::factory()->create(['name' => 'exists']);

    $response = $this->getJson("/api/tags/{$tag->id},99999");

    $response->assertOk();
    $response->assertJsonCount(1);
    $response->assertJsonPath('0.name', 'exists');
});

test('api tags show requires authentication', function () {
    $tag = Tag::factory()->create();

    $response = $this->getJson("/api/tags/{$tag->id}");

    $response->assertUnauthorized();
});

test('api tags index supports sorting', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $tagA = Tag::factory()->create(['name' => 'alpha']);
    $tagZ = Tag::factory()->create(['name' => 'zulu']);

    $response = $this->getJson('/api/tags?sort=name_desc');

    $response->assertOk();
    $data = $response->json('data');
    expect($data[0]['name'])->toBe('zulu');
    expect($data[1]['name'])->toBe('alpha');
});

test('api tags index supports search', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    Tag::factory()->create(['name' => 'sunset']);
    Tag::factory()->create(['name' => 'sunrise']);
    Tag::factory()->create(['name' => 'mountain']);

    $response = $this->getJson('/api/tags?search=sun');

    $response->assertOk();
    $response->assertJsonCount(2, 'data');
});

test('api tags index supports per_page', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    Tag::factory()->count(15)->create();

    $response = $this->getJson('/api/tags?per_page=10');

    $response->assertOk();
    $response->assertJsonCount(10, 'data');
    $response->assertJsonPath('per_page', 10);
    $response->assertJsonPath('total', 15);
});

test('api tags index can filter by type', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    Tag::factory()->user()->count(2)->create();
    Tag::factory()->ai()->count(3)->create();

    $response = $this->getJson('/api/tags?type=user');

    $response->assertOk();
    $response->assertJsonCount(2, 'data');
    $response->assertJsonPath('total', 2);
});

test('api asset meta endpoint is public', function () {
    $asset = Asset::factory()->create([
        'alt_text' => 'Test alt text',
        'caption' => 'Test caption',
    ]);

    // The meta endpoint should work without authentication
    $response = $this->getJson('/api/assets/meta?url='.urlencode($asset->url));

    $response->assertOk();
    $response->assertJsonFragment(['alt_text' => 'Test alt text']);
});

test('api asset meta returns error for unknown url', function () {
    $response = $this->getJson('/api/assets/meta?url='.urlencode('https://example.com/nonexistent.jpg'));

    $response->assertStatus(400);
});

test('api asset meta works with custom domain url', function () {
    $asset = Asset::factory()->create([
        's3_key' => 'assets/test-image.jpg',
        'alt_text' => 'Custom domain test',
    ]);

    Setting::set('custom_domain', 'https://cdn.example.com', 'string', 'aws');
    cache()->forget('setting:custom_domain');

    $response = $this->getJson('/api/assets/meta?url='.urlencode('https://cdn.example.com/assets/test-image.jpg'));

    $response->assertOk();
    $response->assertJsonFragment(['alt_text' => 'Custom domain test']);

    // Clean up
    Setting::where('key', 'custom_domain')->delete();
    cache()->forget('setting:custom_domain');
});

test('api asset meta still works with s3 url when custom domain is set', function () {
    $asset = Asset::factory()->create([
        's3_key' => 'assets/test-image.jpg',
        'alt_text' => 'S3 fallback test',
    ]);

    Setting::set('custom_domain', 'https://cdn.example.com', 'string', 'aws');
    cache()->forget('setting:custom_domain');

    $s3Url = config('filesystems.disks.s3.url');
    $response = $this->getJson('/api/assets/meta?url='.urlencode($s3Url.'/assets/test-image.jpg'));

    $response->assertOk();
    $response->assertJsonFragment(['alt_text' => 'S3 fallback test']);

    // Clean up
    Setting::where('key', 'custom_domain')->delete();
    cache()->forget('setting:custom_domain');
});

test('api assets index can sort by date ascending', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $older = Asset::factory()->create(['updated_at' => now()->subDays(2)]);
    $newer = Asset::factory()->create(['updated_at' => now()]);

    $response = $this->getJson('/api/assets?sort=date_asc');

    $response->assertOk();
    $data = $response->json('data');
    expect($data[0]['id'])->toBe($older->id);
    expect($data[1]['id'])->toBe($newer->id);
});

test('api assets index can sort by date descending', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $older = Asset::factory()->create(['updated_at' => now()->subDays(2)]);
    $newer = Asset::factory()->create(['updated_at' => now()]);

    $response = $this->getJson('/api/assets?sort=date_desc');

    $response->assertOk();
    $data = $response->json('data');
    expect($data[0]['id'])->toBe($newer->id);
    expect($data[1]['id'])->toBe($older->id);
});

test('api assets index can sort by upload date ascending', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $older = Asset::factory()->create(['created_at' => now()->subDays(2)]);
    $newer = Asset::factory()->create(['created_at' => now()]);

    $response = $this->getJson('/api/assets?sort=upload_asc');

    $response->assertOk();
    $data = $response->json('data');
    expect($data[0]['id'])->toBe($older->id);
    expect($data[1]['id'])->toBe($newer->id);
});

test('api assets index can sort by upload date descending', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $older = Asset::factory()->create(['created_at' => now()->subDays(2)]);
    $newer = Asset::factory()->create(['created_at' => now()]);

    $response = $this->getJson('/api/assets?sort=upload_desc');

    $response->assertOk();
    $data = $response->json('data');
    expect($data[0]['id'])->toBe($newer->id);
    expect($data[1]['id'])->toBe($older->id);
});

test('api assets index can sort by size', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $small = Asset::factory()->create(['size' => 1000]);
    $large = Asset::factory()->create(['size' => 10000]);

    $response = $this->getJson('/api/assets?sort=size_asc');

    $response->assertOk();
    $data = $response->json('data');
    expect($data[0]['id'])->toBe($small->id);
    expect($data[1]['id'])->toBe($large->id);

    $response = $this->getJson('/api/assets?sort=size_desc');

    $response->assertOk();
    $data = $response->json('data');
    expect($data[0]['id'])->toBe($large->id);
    expect($data[1]['id'])->toBe($small->id);
});

test('api assets index can sort by name', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $alpha = Asset::factory()->create(['filename' => 'alpha.jpg']);
    $zeta = Asset::factory()->create(['filename' => 'zeta.jpg']);

    $response = $this->getJson('/api/assets?sort=name_asc');

    $response->assertOk();
    $data = $response->json('data');
    expect($data[0]['filename'])->toBe('alpha.jpg');
    expect($data[1]['filename'])->toBe('zeta.jpg');

    $response = $this->getJson('/api/assets?sort=name_desc');

    $response->assertOk();
    $data = $response->json('data');
    expect($data[0]['filename'])->toBe('zeta.jpg');
    expect($data[1]['filename'])->toBe('alpha.jpg');
});

test('api assets search can sort results', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $older = Asset::factory()->create(['filename' => 'test-old.jpg', 'created_at' => now()->subDays(2)]);
    $newer = Asset::factory()->create(['filename' => 'test-new.jpg', 'created_at' => now()]);

    $response = $this->getJson('/api/assets/search?q=test&sort=upload_asc');

    $response->assertOk();
    $data = $response->json('data');
    expect($data[0]['id'])->toBe($older->id);
    expect($data[1]['id'])->toBe($newer->id);
});

test('api assets index hides sensitive user data', function () {
    $user = User::factory()->create([
        'email' => 'secret@example.com',
        'preferences' => ['items_per_page' => 48],
    ]);
    Sanctum::actingAs($user);

    Asset::factory()->create(['user_id' => $user->id]);

    $response = $this->getJson('/api/assets');

    $response->assertOk();
    $userData = $response->json('data.0.user');
    expect($userData)->toHaveKeys(['id', 'name', 'role']);
    expect($userData)->not->toHaveKey('email');
    expect($userData)->not->toHaveKey('email_verified_at');
    expect($userData)->not->toHaveKey('jwt_secret_generated_at');
    expect($userData)->not->toHaveKey('preferences');
    expect($userData)->not->toHaveKey('password');
    expect($userData)->not->toHaveKey('jwt_secret');
});

test('api asset show hides sensitive user data', function () {
    $user = User::factory()->create(['email' => 'secret@example.com']);
    Sanctum::actingAs($user);

    $asset = Asset::factory()->create(['user_id' => $user->id]);

    $response = $this->getJson("/api/assets/{$asset->id}");

    $response->assertOk();
    $userData = $response->json('user');
    expect($userData)->toHaveKeys(['id', 'name', 'role']);
    expect($userData)->not->toHaveKey('email');
    expect($userData)->not->toHaveKey('preferences');
});

test('api assets index can filter by folder', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    Asset::factory()->create(['s3_key' => 'assets/marketing/photo.jpg']);
    Asset::factory()->create(['s3_key' => 'assets/design/logo.png']);
    Asset::factory()->create(['s3_key' => 'assets/marketing/banner.jpg']);

    $response = $this->getJson('/api/assets?folder=assets/marketing');

    $response->assertOk();
    $response->assertJsonCount(2, 'data');
    $filenames = collect($response->json('data'))->pluck('s3_key')->all();
    expect($filenames)->each->toContain('assets/marketing/');
});

test('api assets search can filter by folder', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    Asset::factory()->create(['s3_key' => 'assets/marketing/photo.jpg', 'filename' => 'photo.jpg']);
    Asset::factory()->create(['s3_key' => 'assets/design/photo.png', 'filename' => 'photo.png']);

    $response = $this->getJson('/api/assets/search?q=photo&folder=assets/marketing');

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    expect($response->json('data.0.s3_key'))->toContain('assets/marketing/');
});

test('api folders endpoint returns folder list', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    Asset::factory()->create(['s3_key' => 'assets/marketing/photo.jpg']);
    Asset::factory()->create(['s3_key' => 'assets/design/logo.png']);

    // Clear any cached folders so they rebuild from assets
    Setting::where('key', 's3_folders')->delete();
    Cache::forget('settings');

    $response = $this->getJson('/api/folders');

    $response->assertOk();
    $response->assertJsonStructure(['folders']);
    $folders = $response->json('folders');
    expect($folders)->toContain('assets/marketing');
    expect($folders)->toContain('assets/design');
});

test('api folders endpoint requires authentication', function () {
    $response = $this->getJson('/api/folders');

    $response->assertUnauthorized();
});

test('health endpoint returns ok', function () {
    $response = $this->getJson('/api/health');

    $response->assertOk();
    $response->assertExactJson([
        'status' => 'ok',
        'database' => 'ok',
    ]);
});

test('health endpoint is public and requires no authentication', function () {
    // No Sanctum::actingAs — should still succeed
    $response = $this->getJson('/api/health');

    $response->assertOk();
    $response->assertJsonPath('status', 'ok');
});

test('api assets index defaults to newest first when no sort specified', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $older = Asset::factory()->create(['updated_at' => now()->subDays(2)]);
    $newer = Asset::factory()->create(['updated_at' => now()]);

    $response = $this->getJson('/api/assets');

    $response->assertOk();
    $data = $response->json('data');
    expect($data[0]['id'])->toBe($newer->id);
    expect($data[1]['id'])->toBe($older->id);
});

// Reference Tags API tests

test('api can add reference tags to asset by asset_id', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $asset = Asset::factory()->create();

    $response = $this->postJson('/api/reference-tags', [
        'asset_id' => $asset->id,
        'tags' => ['2F.4.6.2', 'REF-001'],
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['message' => 'Reference tags added to 1 asset(s)']);

    $asset->refresh();
    expect($asset->tags)->toHaveCount(2);
    expect($asset->tags->where('type', 'reference')->count())->toBe(2);
});

test('api can add reference tags to asset by s3_key', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $asset = Asset::factory()->create(['s3_key' => 'assets/test-ref.jpg']);

    $response = $this->postJson('/api/reference-tags', [
        's3_key' => 'assets/test-ref.jpg',
        'tags' => ['EXT-123'],
    ]);

    $response->assertOk();

    $asset->refresh();
    expect($asset->tags)->toHaveCount(1);
    expect($asset->tags->first()->type)->toBe('reference');
    expect($asset->tags->first()->name)->toBe('ext-123');
});

test('api reference tags endpoint creates tags with type reference', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $asset = Asset::factory()->create();

    $this->postJson('/api/reference-tags', [
        'asset_id' => $asset->id,
        'tags' => ['new-ref-tag'],
    ]);

    $tag = Tag::where('name', 'new-ref-tag')->first();
    expect($tag)->not->toBeNull();
    expect($tag->type)->toBe('reference');
});

test('api can remove reference tag from asset', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $asset = Asset::factory()->create();
    $tag = Tag::factory()->reference()->create(['name' => 'ref-to-remove']);
    $asset->tags()->attach($tag);

    $response = $this->deleteJson("/api/reference-tags/{$tag->id}?asset_id={$asset->id}");

    $response->assertOk();
    $response->assertJsonFragment(['message' => 'Reference tag removed from 1 asset(s)']);

    $asset->refresh();
    expect($asset->tags)->toHaveCount(0);
});

test('api cannot remove non-reference tag via reference-tags endpoint', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $asset = Asset::factory()->create();
    $tag = Tag::factory()->user()->create(['name' => 'user-tag-not-ref']);
    $asset->tags()->attach($tag);

    $response = $this->deleteJson("/api/reference-tags/{$tag->id}?asset_id={$asset->id}");

    $response->assertStatus(422);
    $response->assertJsonFragment(['message' => 'Only reference tags can be removed via this endpoint']);
});

test('api reference tags endpoint requires authentication', function () {
    $response = $this->postJson('/api/reference-tags', [
        'asset_id' => 1,
        'tags' => ['test'],
    ]);

    $response->assertUnauthorized();
});

// Batch Reference Tags API tests

test('api can add reference tags to multiple assets via asset_ids', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $assets = Asset::factory()->count(3)->create();

    $response = $this->postJson('/api/reference-tags', [
        'asset_ids' => $assets->pluck('id')->toArray(),
        'tags' => ['batch-ref-1', 'batch-ref-2'],
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['message' => 'Reference tags added to 3 asset(s)']);
    expect($response->json('data'))->toHaveCount(3);

    foreach ($assets as $asset) {
        $asset->refresh();
        expect($asset->tags->where('type', 'reference')->count())->toBe(2);
    }
});

test('api can add reference tags to multiple assets via s3_keys', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $asset1 = Asset::factory()->create(['s3_key' => 'assets/batch-1.jpg']);
    $asset2 = Asset::factory()->create(['s3_key' => 'assets/batch-2.jpg']);

    $response = $this->postJson('/api/reference-tags', [
        's3_keys' => ['assets/batch-1.jpg', 'assets/batch-2.jpg'],
        'tags' => ['s3-batch-ref'],
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['message' => 'Reference tags added to 2 asset(s)']);

    foreach ([$asset1, $asset2] as $asset) {
        $asset->refresh();
        expect($asset->tags->where('name', 's3-batch-ref')->count())->toBe(1);
    }
});

test('api can add reference tags with mixed asset_ids and s3_keys', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $asset1 = Asset::factory()->create();
    $asset2 = Asset::factory()->create(['s3_key' => 'assets/mixed-batch.jpg']);

    $response = $this->postJson('/api/reference-tags', [
        'asset_ids' => [$asset1->id],
        's3_keys' => ['assets/mixed-batch.jpg'],
        'tags' => ['mixed-ref'],
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['message' => 'Reference tags added to 2 asset(s)']);

    foreach ([$asset1, $asset2] as $asset) {
        $asset->refresh();
        expect($asset->tags->where('name', 'mixed-ref')->count())->toBe(1);
    }
});

test('api batch reference tags reports not found s3_keys', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $asset = Asset::factory()->create(['s3_key' => 'assets/exists.jpg']);

    $response = $this->postJson('/api/reference-tags', [
        's3_keys' => ['assets/exists.jpg', 'assets/missing.jpg'],
        'tags' => ['partial-ref'],
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['message' => 'Reference tags added to 1 asset(s)']);
    expect($response->json('not_found_s3_keys'))->toBe(['assets/missing.jpg']);

    $asset->refresh();
    expect($asset->tags->where('name', 'partial-ref')->count())->toBe(1);
});

test('api batch reference tags returns 422 when no identifiers provided', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/reference-tags', [
        'tags' => ['orphan-ref'],
    ]);

    $response->assertStatus(422);
});

test('api can remove reference tag from multiple assets via asset_ids', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $assets = Asset::factory()->count(3)->create();
    $tag = Tag::factory()->reference()->create(['name' => 'batch-remove']);
    foreach ($assets as $asset) {
        $asset->tags()->attach($tag);
    }

    $response = $this->deleteJson("/api/reference-tags/{$tag->id}", [
        'asset_ids' => $assets->pluck('id')->toArray(),
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['message' => 'Reference tag removed from 3 asset(s)']);

    foreach ($assets as $asset) {
        $asset->refresh();
        expect($asset->tags)->toHaveCount(0);
    }
});

test('api can remove reference tag from multiple assets via s3_keys', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $asset1 = Asset::factory()->create(['s3_key' => 'assets/rm-batch-1.jpg']);
    $asset2 = Asset::factory()->create(['s3_key' => 'assets/rm-batch-2.jpg']);
    $tag = Tag::factory()->reference()->create(['name' => 'rm-s3-batch']);
    $asset1->tags()->attach($tag);
    $asset2->tags()->attach($tag);

    $response = $this->deleteJson("/api/reference-tags/{$tag->id}", [
        's3_keys' => ['assets/rm-batch-1.jpg', 'assets/rm-batch-2.jpg'],
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['message' => 'Reference tag removed from 2 asset(s)']);

    foreach ([$asset1, $asset2] as $asset) {
        $asset->refresh();
        expect($asset->tags)->toHaveCount(0);
    }
});

// Remove Reference Tags by Name API tests

test('api can remove reference tag by tag_name and asset_id', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $asset = Asset::factory()->create();
    $tag = Tag::factory()->reference()->create(['name' => 'ext-remove-1']);
    $asset->tags()->attach($tag->id);

    $response = $this->deleteJson('/api/reference-tags', [
        'tag_name' => 'ext-remove-1',
        'asset_id' => $asset->id,
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['message' => 'Reference tag(s) removed from 1 asset(s)']);
    $asset->refresh();
    expect($asset->tags)->toHaveCount(0);
});

test('api can remove reference tags by tag_names array and asset_ids', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $assets = Asset::factory()->count(2)->create();
    $tag1 = Tag::factory()->reference()->create(['name' => 'ext-batch-1']);
    $tag2 = Tag::factory()->reference()->create(['name' => 'ext-batch-2']);
    foreach ($assets as $asset) {
        $asset->tags()->attach([$tag1->id, $tag2->id]);
    }

    $response = $this->deleteJson('/api/reference-tags', [
        'tag_names' => ['ext-batch-1', 'ext-batch-2'],
        'asset_ids' => $assets->pluck('id')->toArray(),
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['message' => 'Reference tag(s) removed from 2 asset(s)']);
    foreach ($assets as $asset) {
        $asset->refresh();
        expect($asset->tags)->toHaveCount(0);
    }
});

test('api can remove reference tag by tag_name and s3_key', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $asset = Asset::factory()->create(['s3_key' => 'assets/test/byname.jpg']);
    $tag = Tag::factory()->reference()->create(['name' => 'ext-s3key-1']);
    $asset->tags()->attach($tag->id);

    $response = $this->deleteJson('/api/reference-tags', [
        'tag_name' => 'ext-s3key-1',
        's3_key' => 'assets/test/byname.jpg',
    ]);

    $response->assertOk();
    $asset->refresh();
    expect($asset->tags)->toHaveCount(0);
});

test('api remove by name with some names not found returns not_found_tags and still removes found tags', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $asset = Asset::factory()->create();
    $tag = Tag::factory()->reference()->create(['name' => 'ext-exists-1']);
    $asset->tags()->attach($tag->id);

    $response = $this->deleteJson('/api/reference-tags', [
        'tag_names' => ['ext-exists-1', 'ext-does-not-exist'],
        'asset_id' => $asset->id,
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['not_found_tags' => ['ext-does-not-exist']]);
    $asset->refresh();
    expect($asset->tags)->toHaveCount(0);
});

test('api remove by name treats user-type tag name as not found', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $asset = Asset::factory()->create();
    $userTag = Tag::factory()->user()->create(['name' => 'user-only-tag']);
    $asset->tags()->attach($userTag->id);

    $response = $this->deleteJson('/api/reference-tags', [
        'tag_name' => 'user-only-tag',
        'asset_id' => $asset->id,
    ]);

    $response->assertNotFound();
    $response->assertJsonFragment(['not_found_tags' => ['user-only-tag']]);
    // User tag should still be attached
    $asset->refresh();
    expect($asset->tags)->toHaveCount(1);
});

test('api remove by name returns 404 when all names missing', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $asset = Asset::factory()->create();

    $response = $this->deleteJson('/api/reference-tags', [
        'tag_names' => ['ghost-tag-1', 'ghost-tag-2'],
        'asset_id' => $asset->id,
    ]);

    $response->assertNotFound();
    $response->assertJsonFragment(['not_found_tags' => ['ghost-tag-1', 'ghost-tag-2']]);
});

test('api remove by name returns 422 when no tag_name or tag_names provided', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $asset = Asset::factory()->create();

    $response = $this->deleteJson('/api/reference-tags', [
        'asset_id' => $asset->id,
    ]);

    $response->assertUnprocessable();
    $response->assertJsonPath('errors.tags', fn ($v) => ! empty($v));
});

test('api remove by name returns 422 when no asset identifiers provided', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    Tag::factory()->reference()->create(['name' => 'ext-noasset']);

    $response = $this->deleteJson('/api/reference-tags', [
        'tag_name' => 'ext-noasset',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonPath('errors.identifiers', fn ($v) => ! empty($v));
});

test('api remove by name requires authentication', function () {
    $response = $this->deleteJson('/api/reference-tags', [
        'tag_name' => 'ext-unauth',
        'asset_id' => 1,
    ]);

    $response->assertUnauthorized();
});

test('api remove by name with missing s3_keys and missing tags returns both not_found keys', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $asset = Asset::factory()->create(['s3_key' => 'assets/test/present.jpg']);
    $tag = Tag::factory()->reference()->create(['name' => 'ext-present-1']);
    $asset->tags()->attach($tag->id);

    $response = $this->deleteJson('/api/reference-tags', [
        'tag_names' => ['ext-present-1', 'ext-missing-tag'],
        's3_keys' => ['assets/test/present.jpg', 'assets/test/missing.jpg'],
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['not_found_tags' => ['ext-missing-tag']]);
    $response->assertJsonFragment(['not_found_s3_keys' => ['assets/test/missing.jpg']]);
});

test('api update preserves reference tags when updating user tags', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $asset = Asset::factory()->create(['user_id' => $user->id]);
    $refTag = Tag::factory()->reference()->create(['name' => 'ref-preserve']);
    $aiTag = Tag::factory()->ai()->create(['name' => 'ai-preserve']);
    $asset->tags()->attach([$refTag->id, $aiTag->id]);

    $response = $this->patchJson("/api/assets/{$asset->id}", [
        'tags' => ['new-user-tag'],
    ]);

    $response->assertOk();

    $asset->refresh();
    $tagNames = $asset->tags->pluck('name')->toArray();
    expect($tagNames)->toContain('ref-preserve');
    expect($tagNames)->toContain('ai-preserve');
    expect($tagNames)->toContain('new-user-tag');
});

test('api assets index response includes url per item', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    Asset::factory()->count(2)->create();

    $response = $this->getJson('/api/assets');

    $response->assertOk();
    $items = $response->json('data');
    expect($items)->not->toBeEmpty();

    foreach ($items as $item) {
        expect($item)->toHaveKey('url');
        expect($item['url'])->toBeString()->not->toBeEmpty();
    }
});

test('api assets show response includes url', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $asset = Asset::factory()->create(['s3_key' => 'assets/test.jpg']);

    $response = $this->getJson("/api/assets/{$asset->id}");

    $response->assertOk();
    $response->assertJsonPath('url', fn ($url) => is_string($url) && str_contains($url, 'assets/test.jpg'));
});
