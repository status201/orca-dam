<?php

use App\Models\Tag;

test('reference-tag:create creates a single reference tag', function () {
    $this->artisan('reference-tag:create', ['names' => ['linkedin']])
        ->assertExitCode(0);

    $tag = Tag::where('name', 'linkedin')->first();
    expect($tag)->not->toBeNull();
    expect($tag->type)->toBe('reference');
});

test('reference-tag:create accepts multiple names in one call', function () {
    $this->artisan('reference-tag:create', ['names' => ['linkedin', 'facebook', 'newsletter-q1']])
        ->assertExitCode(0);

    expect(Tag::where('type', 'reference')->whereIn('name', ['linkedin', 'facebook', 'newsletter-q1'])->count())
        ->toBe(3);
});

test('reference-tag:create normalizes names (trim + lowercase)', function () {
    $this->artisan('reference-tag:create', ['names' => ['  LinkedIn  ']])
        ->assertExitCode(0);

    expect(Tag::where('name', 'linkedin')->where('type', 'reference')->exists())->toBeTrue();
    expect(Tag::where('name', 'LinkedIn')->exists())->toBeFalse();
});

test('reference-tag:create is idempotent for existing reference tag', function () {
    Tag::create(['name' => 'linkedin', 'type' => 'reference']);

    $this->artisan('reference-tag:create', ['names' => ['linkedin']])
        ->assertExitCode(0);

    expect(Tag::where('name', 'linkedin')->count())->toBe(1);
});

test('reference-tag:create skips collision with existing user tag and does not change its type', function () {
    Tag::create(['name' => 'collide', 'type' => 'user']);

    $this->artisan('reference-tag:create', ['names' => ['collide']])
        ->assertExitCode(1);

    $tag = Tag::where('name', 'collide')->first();
    expect($tag->type)->toBe('user');
    expect(Tag::where('name', 'collide')->count())->toBe(1);
});

test('reference-tag:create succeeds when at least one name is created and another collides', function () {
    Tag::create(['name' => 'collide', 'type' => 'user']);

    $this->artisan('reference-tag:create', ['names' => ['collide', 'fresh']])
        ->assertExitCode(0);

    expect(Tag::where('name', 'fresh')->where('type', 'reference')->exists())->toBeTrue();
    expect(Tag::where('name', 'collide')->first()->type)->toBe('user');
});
