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

test('tag assets_count attribute works with withCount', function () {
    $tag = Tag::factory()->create();
    $assets = Asset::factory()->count(5)->create();
    $tag->assets()->attach($assets);

    $tagWithCount = Tag::withCount('assets')->find($tag->id);

    expect($tagWithCount->assets_count)->toBe(5);
});
