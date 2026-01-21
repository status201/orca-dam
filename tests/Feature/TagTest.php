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

test('tags index shows all tags', function () {
    $user = User::factory()->create();
    Tag::factory()->create(['name' => 'nature']);
    Tag::factory()->create(['name' => 'landscape']);

    $response = $this->actingAs($user)->get(route('tags.index'));

    $response->assertStatus(200);
    $response->assertSee('nature');
    $response->assertSee('landscape');
});

test('tags index can filter by type user', function () {
    $user = User::factory()->create();
    Tag::factory()->user()->create(['name' => 'user-tag']);
    Tag::factory()->ai()->create(['name' => 'ai-tag']);

    $response = $this->actingAs($user)->get(route('tags.index', ['type' => 'user']));

    $response->assertStatus(200);
    $response->assertSee('user-tag');
    $response->assertDontSee('ai-tag');
});

test('tags index can filter by type ai', function () {
    $user = User::factory()->create();
    Tag::factory()->user()->create(['name' => 'user-tag']);
    Tag::factory()->ai()->create(['name' => 'ai-tag']);

    $response = $this->actingAs($user)->get(route('tags.index', ['type' => 'ai']));

    $response->assertStatus(200);
    $response->assertDontSee('user-tag');
    $response->assertSee('ai-tag');
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
