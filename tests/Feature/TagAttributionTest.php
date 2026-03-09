<?php

use App\Models\Asset;
use App\Models\Tag;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->editor = User::factory()->create(['role' => 'editor']);
});

test('addTags sets attached_by to user', function () {
    $asset = Asset::factory()->image()->create(['user_id' => $this->editor->id]);
    $tag = Tag::factory()->user()->create(['name' => 'landscape']);

    $this->actingAs($this->editor)
        ->postJson(route('assets.tags.add', $asset), ['tags' => ['landscape']])
        ->assertOk();

    $pivot = $asset->fresh()->tags()->where('tags.id', $tag->id)->first()->pivot;
    expect($pivot->attached_by)->toBe('user');
});

test('bulkAddTags sets attached_by to user', function () {
    $assets = Asset::factory()->image()->count(2)->create(['user_id' => $this->editor->id]);

    $this->actingAs($this->editor)
        ->postJson(route('assets.bulk.tags.add'), [
            'asset_ids' => $assets->pluck('id')->toArray(),
            'tags' => ['nature'],
        ])
        ->assertOk();

    foreach ($assets as $asset) {
        $pivot = $asset->fresh()->tags->first()->pivot;
        expect($pivot->attached_by)->toBe('user');
    }
});

test('AI tagging sets attached_by to ai', function () {
    $asset = Asset::factory()->image()->create(['user_id' => $this->editor->id]);
    $tag = Tag::factory()->create(['name' => 'tree', 'type' => 'ai']);

    $asset->syncTagsWithAttribution([$tag->id], 'ai');

    $pivot = $asset->fresh()->tags()->where('tags.id', $tag->id)->first()->pivot;
    expect($pivot->attached_by)->toBe('ai');
});

test('last attacher wins: re-attaching AI tag as user updates attached_by', function () {
    $asset = Asset::factory()->image()->create(['user_id' => $this->editor->id]);
    $tag = Tag::factory()->create(['name' => 'tree', 'type' => 'ai']);

    // First attach as AI
    $asset->syncTagsWithAttribution([$tag->id], 'ai');
    expect($asset->fresh()->tags->first()->pivot->attached_by)->toBe('ai');

    // Re-attach as user
    $asset->syncTagsWithAttribution([$tag->id], 'user');
    expect($asset->fresh()->tags->first()->pivot->attached_by)->toBe('user');
});

test('last attacher wins: re-attaching user tag as reference updates attached_by', function () {
    $asset = Asset::factory()->image()->create(['user_id' => $this->editor->id]);
    $tag = Tag::factory()->user()->create(['name' => 'logo']);

    $asset->syncTagsWithAttribution([$tag->id], 'user');
    expect($asset->fresh()->tags->first()->pivot->attached_by)->toBe('user');

    $asset->syncTagsWithAttribution([$tag->id], 'reference');
    expect($asset->fresh()->tags->first()->pivot->attached_by)->toBe('reference');
});

test('pivot attached_by is accessible via relationship', function () {
    $asset = Asset::factory()->image()->create(['user_id' => $this->editor->id]);
    $aiTag = Tag::factory()->create(['name' => 'mountain', 'type' => 'ai']);
    $userTag = Tag::factory()->user()->create(['name' => 'favorite']);

    $asset->syncTagsWithAttribution([$aiTag->id], 'ai');
    $asset->syncTagsWithAttribution([$userTag->id], 'user');

    $tags = $asset->fresh()->tags;
    $ai = $tags->firstWhere('id', $aiTag->id);
    $user = $tags->firstWhere('id', $userTag->id);

    expect($ai->pivot->attached_by)->toBe('ai');
    expect($user->pivot->attached_by)->toBe('user');
});

test('update preserves AI tag attached_by when syncing user tags', function () {
    $asset = Asset::factory()->image()->create(['user_id' => $this->editor->id]);
    $aiTag = Tag::factory()->create(['name' => 'sky', 'type' => 'ai']);

    // Attach AI tag with 'user' attached_by (cross-origin scenario)
    $asset->syncTagsWithAttribution([$aiTag->id], 'user');
    expect($asset->fresh()->tags->first()->pivot->attached_by)->toBe('user');

    // Update asset with new user tags — AI tag should keep its attached_by
    $this->actingAs($this->editor)
        ->patch(route('assets.update', $asset), [
            'filename' => $asset->filename,
            'tags' => ['newtag'],
        ])
        ->assertRedirect();

    $asset->refresh()->load('tags');
    $aiPivot = $asset->tags->firstWhere('id', $aiTag->id);
    expect($aiPivot)->not->toBeNull();
    expect($aiPivot->pivot->attached_by)->toBe('user');
});

test('addReferenceTags sets attached_by to reference', function () {
    $asset = Asset::factory()->image()->create(['user_id' => $this->editor->id]);

    $this->actingAs($this->editor)
        ->postJson('/api/reference-tags', [
            'asset_id' => $asset->id,
            'tags' => ['article-123'],
        ])
        ->assertOk();

    $pivot = $asset->fresh()->tags->first()->pivot;
    expect($pivot->attached_by)->toBe('reference');
});

test('import sets attached_by correctly for user and reference tags', function () {
    $asset = Asset::factory()->image()->create([
        'user_id' => $this->admin->id,
        's3_key' => 'assets/test-import.jpg',
    ]);

    $csvData = "s3_key,user_tags,reference_tags\nassets/test-import.jpg,\"summer,beach\",\"doc-456\"";

    $this->actingAs($this->admin)
        ->postJson(route('import.import'), [
            'csv_data' => $csvData,
            'match_field' => 's3_key',
        ])
        ->assertOk();

    $asset->refresh()->load('tags');

    $userTag = $asset->tags->firstWhere('name', 'summer');
    expect($userTag)->not->toBeNull();
    expect($userTag->pivot->attached_by)->toBe('user');

    $refTag = $asset->tags->firstWhere('name', 'doc-456');
    expect($refTag)->not->toBeNull();
    expect($refTag->pivot->attached_by)->toBe('reference');
});
