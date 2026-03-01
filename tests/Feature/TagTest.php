<?php

use App\Models\Asset;
use App\Models\Tag;
use App\Models\User;

test('guests cannot access tags index', function () {
    $response = $this->get(route('tags.index'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can access tags index', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('tags.index'));

    $response->assertStatus(200);
});

test('tags index JSON returns paginated tags', function () {
    $user = User::factory()->create();
    Tag::factory()->count(5)->create();

    $response = $this->actingAs($user)->getJson(route('tags.index'));

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [['id', 'name', 'type', 'assets_count']],
        'current_page',
        'last_page',
        'per_page',
        'total',
    ]);
    $response->assertJsonPath('total', 5);
});

test('tags index JSON respects per_page parameter', function () {
    $user = User::factory()->create();
    Tag::factory()->count(30)->create();

    $response = $this->actingAs($user)->getJson(route('tags.index', ['per_page' => 10]));

    $response->assertOk();
    $response->assertJsonCount(10, 'data');
    $response->assertJsonPath('per_page', 10);
    $response->assertJsonPath('total', 30);
    $response->assertJsonPath('last_page', 3);
});

test('tags index JSON supports search parameter', function () {
    $user = User::factory()->create();
    Tag::factory()->create(['name' => 'nature']);
    Tag::factory()->create(['name' => 'natural']);
    Tag::factory()->create(['name' => 'landscape']);

    $response = $this->actingAs($user)->getJson(route('tags.index', ['search' => 'natur']));

    $response->assertOk();
    $response->assertJsonCount(2, 'data');
});

test('tags index JSON can filter by type user', function () {
    $user = User::factory()->create();
    Tag::factory()->user()->create(['name' => 'user-tag']);
    Tag::factory()->ai()->create(['name' => 'ai-tag']);

    $response = $this->actingAs($user)->getJson(route('tags.index', ['type' => 'user']));

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.name', 'user-tag');
});

test('tags index JSON can filter by type ai', function () {
    $user = User::factory()->create();
    Tag::factory()->user()->create(['name' => 'user-tag']);
    Tag::factory()->ai()->create(['name' => 'ai-tag']);

    $response = $this->actingAs($user)->getJson(route('tags.index', ['type' => 'ai']));

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.name', 'ai-tag');
});

test('tags index JSON can filter by type reference', function () {
    $user = User::factory()->create();
    Tag::factory()->user()->create(['name' => 'user-tag']);
    Tag::factory()->reference()->create(['name' => 'ref-tag']);
    Tag::factory()->ai()->create(['name' => 'ai-tag']);

    $response = $this->actingAs($user)->getJson(route('tags.index', ['type' => 'reference']));

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.name', 'ref-tag');
});

test('tags index JSON sorts by name ascending by default', function () {
    $user = User::factory()->create();
    Tag::factory()->create(['name' => 'zebra']);
    Tag::factory()->create(['name' => 'apple']);
    Tag::factory()->create(['name' => 'mango']);

    $response = $this->actingAs($user)->getJson(route('tags.index'));

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name')->toArray();
    expect($names)->toBe(['apple', 'mango', 'zebra']);
});

test('tags index JSON can sort by name descending', function () {
    $user = User::factory()->create();
    Tag::factory()->create(['name' => 'zebra']);
    Tag::factory()->create(['name' => 'apple']);
    Tag::factory()->create(['name' => 'mango']);

    $response = $this->actingAs($user)->getJson(route('tags.index', ['sort' => 'name_desc']));

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name')->toArray();
    expect($names)->toBe(['zebra', 'mango', 'apple']);
});

test('tags index JSON can sort by most used', function () {
    $user = User::factory()->create();
    $tagA = Tag::factory()->create(['name' => 'rarely-used']);
    $tagB = Tag::factory()->create(['name' => 'often-used']);

    $assets = Asset::factory()->count(3)->create();
    $tagB->assets()->attach($assets->pluck('id'));
    $tagA->assets()->attach($assets->first()->id);

    $response = $this->actingAs($user)->getJson(route('tags.index', ['sort' => 'most_used']));

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name')->toArray();
    expect($names)->toBe(['often-used', 'rarely-used']);
});

test('tags index JSON can sort by least used', function () {
    $user = User::factory()->create();
    $tagA = Tag::factory()->create(['name' => 'rarely-used']);
    $tagB = Tag::factory()->create(['name' => 'often-used']);

    $assets = Asset::factory()->count(3)->create();
    $tagB->assets()->attach($assets->pluck('id'));
    $tagA->assets()->attach($assets->first()->id);

    $response = $this->actingAs($user)->getJson(route('tags.index', ['sort' => 'least_used']));

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name')->toArray();
    expect($names)->toBe(['rarely-used', 'often-used']);
});

test('tags index JSON can sort by newest', function () {
    $user = User::factory()->create();
    Tag::factory()->create(['name' => 'old-tag', 'created_at' => now()->subDays(5)]);
    Tag::factory()->create(['name' => 'new-tag', 'created_at' => now()]);

    $response = $this->actingAs($user)->getJson(route('tags.index', ['sort' => 'newest']));

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name')->toArray();
    expect($names)->toBe(['new-tag', 'old-tag']);
});

test('tags index JSON can sort by oldest', function () {
    $user = User::factory()->create();
    Tag::factory()->create(['name' => 'old-tag', 'created_at' => now()->subDays(5)]);
    Tag::factory()->create(['name' => 'new-tag', 'created_at' => now()]);

    $response = $this->actingAs($user)->getJson(route('tags.index', ['sort' => 'oldest']));

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name')->toArray();
    expect($names)->toBe(['old-tag', 'new-tag']);
});

test('tags index JSON sort works combined with type filter', function () {
    $user = User::factory()->create();
    Tag::factory()->user()->create(['name' => 'zebra-user']);
    Tag::factory()->user()->create(['name' => 'apple-user']);
    Tag::factory()->ai()->create(['name' => 'ai-tag']);

    $response = $this->actingAs($user)->getJson(route('tags.index', ['type' => 'user', 'sort' => 'name_desc']));

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name')->toArray();
    expect($names)->toBe(['zebra-user', 'apple-user']);
});

test('tags index web view returns type counts', function () {
    $user = User::factory()->create();
    Tag::factory()->user()->count(3)->create();
    Tag::factory()->ai()->count(2)->create();
    Tag::factory()->reference()->count(1)->create();

    $response = $this->actingAs($user)->get(route('tags.index'));

    $response->assertOk();
    $response->assertViewHas('typeCounts', [
        'all' => 6,
        'user' => 3,
        'ai' => 2,
        'reference' => 1,
    ]);
});

test('tags by-ids returns correct tags', function () {
    $user = User::factory()->create();
    $tag1 = Tag::factory()->create(['name' => 'alpha']);
    $tag2 = Tag::factory()->create(['name' => 'beta']);
    Tag::factory()->create(['name' => 'gamma']);

    $response = $this->actingAs($user)
        ->postJson(route('tags.byIds'), ['ids' => [$tag1->id, $tag2->id]]);

    $response->assertOk();
    $response->assertJsonCount(2);
    $names = collect($response->json())->pluck('name')->sort()->values()->toArray();
    expect($names)->toBe(['alpha', 'beta']);
});

test('tags by-ids validates ids are required', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson(route('tags.byIds'), []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['ids']);
});

test('authenticated users can update user tags', function () {
    $user = User::factory()->create();
    $tag = Tag::factory()->user()->create(['name' => 'old-name']);

    $response = $this->actingAs($user)
        ->patchJson(route('tags.update', $tag), [
            'name' => 'new-name',
        ]);

    $response->assertOk();

    $tag->refresh();
    expect($tag->name)->toBe('new-name');
});

test('cannot update ai tags', function () {
    $user = User::factory()->create();
    $tag = Tag::factory()->ai()->create(['name' => 'ai-tag']);

    $response = $this->actingAs($user)
        ->patchJson(route('tags.update', $tag), [
            'name' => 'new-name',
        ]);

    $response->assertStatus(403);
});

test('authenticated users can delete tags', function () {
    $user = User::factory()->create();
    $tag = Tag::factory()->create();
    $tagId = $tag->id;

    $response = $this->actingAs($user)
        ->deleteJson(route('tags.destroy', $tag));

    $response->assertOk();
    expect(Tag::find($tagId))->toBeNull();
});

test('deleting tag removes it from all assets', function () {
    $user = User::factory()->create();
    $tag = Tag::factory()->create();
    $asset = Asset::factory()->create();
    $asset->tags()->attach($tag);

    expect($asset->tags)->toHaveCount(1);

    $this->actingAs($user)->deleteJson(route('tags.destroy', $tag));

    $asset->refresh();
    expect($asset->tags)->toHaveCount(0);
});

test('tag search returns matching tags', function () {
    $user = User::factory()->create();
    Tag::factory()->create(['name' => 'nature']);
    Tag::factory()->create(['name' => 'natural']);
    Tag::factory()->create(['name' => 'landscape']);

    $response = $this->actingAs($user)
        ->getJson(route('tags.search', ['q' => 'natur']));

    $response->assertOk();
    $response->assertJsonCount(2);
});

test('tag search filters by type', function () {
    $user = User::factory()->create();
    Tag::factory()->user()->create(['name' => 'user-nature']);
    Tag::factory()->ai()->create(['name' => 'ai-nature']);

    $response = $this->actingAs($user)
        ->getJson(route('tags.search', ['q' => 'nature', 'type' => 'user']));

    $response->assertOk();
    $response->assertJsonCount(1);
});

test('can add tags to asset', function () {
    $user = User::factory()->create();
    $asset = Asset::factory()->create();

    $response = $this->actingAs($user)
        ->postJson(route('assets.tags.add', $asset), [
            'tags' => ['new-tag-1', 'new-tag-2'],
        ]);

    $response->assertOk();

    $asset->refresh();
    expect($asset->tags)->toHaveCount(2);
});

test('can remove tag from asset', function () {
    $user = User::factory()->create();
    $asset = Asset::factory()->create();
    $tag = Tag::factory()->create();
    $asset->tags()->attach($tag);

    $response = $this->actingAs($user)
        ->deleteJson(route('assets.tags.remove', [$asset, $tag]));

    $response->assertOk();

    $asset->refresh();
    expect($asset->tags)->toHaveCount(0);
});

// Bulk tag management tests

test('can bulk add tags to multiple assets', function () {
    $user = User::factory()->create();
    $assets = Asset::factory()->count(3)->create();

    $response = $this->actingAs($user)
        ->postJson(route('assets.bulk.tags.add'), [
            'asset_ids' => $assets->pluck('id')->toArray(),
            'tags' => ['bulk-tag-1', 'bulk-tag-2'],
        ]);

    $response->assertOk();
    $response->assertJsonFragment(['message' => '3 asset(s) updated']);

    foreach ($assets as $asset) {
        $asset->refresh();
        expect($asset->tags)->toHaveCount(2);
        expect($asset->tags->pluck('name')->toArray())->toContain('bulk-tag-1', 'bulk-tag-2');
    }
});

test('bulk add tags does not duplicate existing tags', function () {
    $user = User::factory()->create();
    $asset = Asset::factory()->create();
    $existingTag = Tag::factory()->create(['name' => 'existing']);
    $asset->tags()->attach($existingTag);

    $response = $this->actingAs($user)
        ->postJson(route('assets.bulk.tags.add'), [
            'asset_ids' => [$asset->id],
            'tags' => ['existing', 'new-tag'],
        ]);

    $response->assertOk();
    $asset->refresh();
    expect($asset->tags)->toHaveCount(2);
});

test('can bulk remove tags from multiple assets', function () {
    $user = User::factory()->create();
    $assets = Asset::factory()->count(3)->create();
    $tag = Tag::factory()->create(['name' => 'remove-me']);

    foreach ($assets as $asset) {
        $asset->tags()->attach($tag);
    }

    $response = $this->actingAs($user)
        ->postJson(route('assets.bulk.tags.remove'), [
            'asset_ids' => $assets->pluck('id')->toArray(),
            'tag_ids' => [$tag->id],
        ]);

    $response->assertOk();

    foreach ($assets as $asset) {
        $asset->refresh();
        expect($asset->tags)->toHaveCount(0);
    }
});

test('bulk get tags returns correct counts', function () {
    $user = User::factory()->create();
    $assets = Asset::factory()->count(3)->create();
    $tagA = Tag::factory()->create(['name' => 'common']);
    $tagB = Tag::factory()->create(['name' => 'rare']);

    // Attach tagA to all 3 assets, tagB to only 1
    foreach ($assets as $asset) {
        $asset->tags()->attach($tagA);
    }
    $assets->first()->tags()->attach($tagB);

    $response = $this->actingAs($user)
        ->postJson(route('assets.bulk.tags.list'), [
            'asset_ids' => $assets->pluck('id')->toArray(),
        ]);

    $response->assertOk();
    $response->assertJsonFragment(['total_assets' => 3]);

    $tags = $response->json('tags');
    $commonTag = collect($tags)->firstWhere('name', 'common');
    $rareTag = collect($tags)->firstWhere('name', 'rare');

    expect($commonTag['count'])->toBe(3);
    expect($rareTag['count'])->toBe(1);
});

test('bulk add tags validates required fields', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson(route('assets.bulk.tags.add'), []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['asset_ids', 'tags']);
});

test('bulk remove tags validates required fields', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson(route('assets.bulk.tags.remove'), []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['asset_ids', 'tag_ids']);
});

test('bulk tag operations require authentication', function () {
    $response = $this->postJson(route('assets.bulk.tags.add'), [
        'asset_ids' => [1],
        'tags' => ['test'],
    ]);

    $response->assertUnauthorized();
});

test('bulk get tags requires authentication', function () {
    $response = $this->postJson(route('assets.bulk.tags.list'), [
        'asset_ids' => [1],
    ]);

    $response->assertUnauthorized();
});

// Reference tag tests

test('reference tags can be renamed', function () {
    $user = User::factory()->create();
    $tag = Tag::factory()->reference()->create(['name' => 'old-ref']);

    $response = $this->actingAs($user)
        ->patchJson(route('tags.update', $tag), [
            'name' => 'new-ref',
        ]);

    $response->assertOk();

    $tag->refresh();
    expect($tag->name)->toBe('new-ref');
});

test('ai tags still cannot be renamed after reference tag update', function () {
    $user = User::factory()->create();
    $tag = Tag::factory()->ai()->create(['name' => 'ai-tag']);

    $response = $this->actingAs($user)
        ->patchJson(route('tags.update', $tag), [
            'name' => 'new-name',
        ]);

    $response->assertStatus(403);
});
