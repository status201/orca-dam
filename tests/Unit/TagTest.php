<?php

use App\Models\Asset;
use App\Models\Tag;

test('tag can have many assets', function () {
    $tag = Tag::factory()->create();
    $assets = Asset::factory()->count(3)->create();

    $tag->assets()->attach($assets);

    expect($tag->assets)->toHaveCount(3);
});

test('tag type defaults to user', function () {
    $tag = Tag::factory()->create();

    expect($tag->type)->toBe('user');
});

test('tag can be ai type', function () {
    $tag = Tag::factory()->ai()->create();

    expect($tag->type)->toBe('ai');
});

test('tag name is unique', function () {
    Tag::factory()->create(['name' => 'unique-tag']);

    expect(fn () => Tag::factory()->create(['name' => 'unique-tag']))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

test('resolveUserTagIds creates missing tags and returns their ids', function () {
    $ids = Tag::resolveUserTagIds(['landscape', 'nature']);

    expect($ids)->toHaveCount(2);
    expect(Tag::find($ids[0])->name)->toBe('landscape');
    expect(Tag::find($ids[0])->type)->toBe('user');
    expect(Tag::find($ids[1])->name)->toBe('nature');
});

test('resolveUserTagIds returns existing tag ids without duplicates', function () {
    $existing = Tag::factory()->create(['name' => 'portrait', 'type' => 'user']);

    $ids = Tag::resolveUserTagIds(['portrait', 'new-tag']);

    expect($ids)->toHaveCount(2);
    expect($ids[0])->toBe($existing->id);
    expect(Tag::where('name', 'portrait')->count())->toBe(1);
});

test('resolveUserTagIds lowercases and trims names', function () {
    $ids = Tag::resolveUserTagIds(['  Sunset ', 'BEACH']);

    expect(Tag::find($ids[0])->name)->toBe('sunset');
    expect(Tag::find($ids[1])->name)->toBe('beach');
});

test('resolveUserTagIds handles empty array', function () {
    $ids = Tag::resolveUserTagIds([]);

    expect($ids)->toBe([]);
});

test('tag assets_count attribute works with withCount', function () {
    $tag = Tag::factory()->create();
    $assets = Asset::factory()->count(5)->create();
    $tag->assets()->attach($assets);

    $tagWithCount = Tag::withCount('assets')->find($tag->id);

    expect($tagWithCount->assets_count)->toBe(5);
});

test('tag can be reference type', function () {
    $tag = Tag::factory()->reference()->create();

    expect($tag->type)->toBe('reference');
});

test('resolveReferenceTagIds creates tags with reference type', function () {
    $ids = Tag::resolveReferenceTagIds(['2F.4.6.2', 'REF-001']);

    expect($ids)->toHaveCount(2);
    expect(Tag::find($ids[0])->name)->toBe('2f.4.6.2');
    expect(Tag::find($ids[0])->type)->toBe('reference');
    expect(Tag::find($ids[1])->name)->toBe('ref-001');
    expect(Tag::find($ids[1])->type)->toBe('reference');
});

test('scopeReferenceTags filters correctly', function () {
    Tag::factory()->user()->create(['name' => 'user-tag']);
    Tag::factory()->ai()->create(['name' => 'ai-tag']);
    Tag::factory()->reference()->create(['name' => 'ref-tag']);

    $referenceTags = Tag::referenceTags()->get();

    expect($referenceTags)->toHaveCount(1);
    expect($referenceTags->first()->name)->toBe('ref-tag');
});

test('asset referenceTags relationship returns only reference tags', function () {
    $asset = Asset::factory()->create();
    $userTag = Tag::factory()->user()->create();
    $aiTag = Tag::factory()->ai()->create();
    $refTag = Tag::factory()->reference()->create();

    $asset->tags()->attach([$userTag->id, $aiTag->id, $refTag->id]);

    expect($asset->referenceTags)->toHaveCount(1);
    expect($asset->referenceTags->first()->type)->toBe('reference');
});
