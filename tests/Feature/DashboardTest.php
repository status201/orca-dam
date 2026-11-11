<?php

use App\Models\Asset;
use App\Models\Tag;
use App\Models\User;

// specs/features/dashboard.md REQ-1 — tag totals are user + ai; reference tags are kept apart
// (tags.md REQ-reference-tags-kept-apart).
test('dashboard tag totals leave reference tags out', function () {
    $editor = User::factory()->create(['role' => 'editor']);
    $asset = Asset::factory()->create(['user_id' => $editor->id]);

    foreach (['user', 'ai', 'reference'] as $type) {
        $tag = Tag::factory()->{$type}()->create();
        $asset->tags()->attach($tag->id, ['attached_by' => $type]);
    }

    $this->actingAs($editor)->get(route('dashboard'))
        ->assertOk()
        ->assertViewHas('stats', fn (array $stats) => $stats['total_tags'] === 2
            && $stats['user_tags'] === 1
            && $stats['ai_tags'] === 1
            && $stats['my_tags'] === 2);
});
