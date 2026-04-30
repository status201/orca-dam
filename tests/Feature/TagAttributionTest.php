<?php

use App\Models\Asset;
use App\Models\Tag;
use App\Models\User;
use App\Services\AssetProcessingService;

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

test('asset update preserves reference tag pivot when reference_tag_ids submitted', function () {
    $asset = Asset::factory()->image()->create(['user_id' => $this->editor->id]);
    $refTag = Tag::create(['name' => 'linkedin', 'type' => 'reference']);
    $asset->syncTagsWithAttribution([$refTag->id], 'reference');

    $this->actingAs($this->editor)
        ->patch(route('assets.update', $asset), [
            'filename' => $asset->filename,
            'tags' => ['something'],
            'reference_tag_ids' => [$refTag->id],
        ])
        ->assertRedirect();

    $asset->refresh()->load('tags');
    $pivot = $asset->tags->firstWhere('id', $refTag->id);
    expect($pivot)->not->toBeNull();
    expect($pivot->pivot->attached_by)->toBe('reference');
});

test('asset update detaches reference tag when omitted from reference_tag_ids', function () {
    $asset = Asset::factory()->image()->create(['user_id' => $this->editor->id]);
    $refTag = Tag::create(['name' => 'linkedin', 'type' => 'reference']);
    $asset->syncTagsWithAttribution([$refTag->id], 'reference');

    $this->actingAs($this->editor)
        ->patch(route('assets.update', $asset), [
            'filename' => $asset->filename,
            'tags' => ['something'],
            'reference_tag_ids' => [],
        ])
        ->assertRedirect();

    $asset->refresh()->load('tags');
    expect($asset->tags->firstWhere('id', $refTag->id))->toBeNull();
});

test('asset update preserves reference tag when reference_tag_ids field is absent', function () {
    $asset = Asset::factory()->image()->create(['user_id' => $this->editor->id]);
    $refTag = Tag::create(['name' => 'linkedin', 'type' => 'reference']);
    $asset->syncTagsWithAttribution([$refTag->id], 'reference');

    // API client that doesn't know about reference_tag_ids should not wipe the tag
    $this->actingAs($this->editor)
        ->patch(route('assets.update', $asset), [
            'filename' => $asset->filename,
            'tags' => ['something'],
        ])
        ->assertRedirect();

    $asset->refresh()->load('tags');
    $pivot = $asset->tags->firstWhere('id', $refTag->id);
    expect($pivot)->not->toBeNull();
    expect($pivot->pivot->attached_by)->toBe('reference');
});

test('addTags accepts reference_tag_ids and sets attached_by to reference', function () {
    $asset = Asset::factory()->image()->create(['user_id' => $this->editor->id]);
    $refTag = Tag::create(['name' => 'linkedin', 'type' => 'reference']);

    $this->actingAs($this->editor)
        ->postJson(route('assets.tags.add', $asset), [
            'reference_tag_ids' => [$refTag->id],
        ])
        ->assertOk();

    $pivot = $asset->fresh()->tags->firstWhere('id', $refTag->id);
    expect($pivot)->not->toBeNull();
    expect($pivot->pivot->attached_by)->toBe('reference');
});

test('addTags rejects non-reference tag IDs in reference_tag_ids', function () {
    $asset = Asset::factory()->image()->create(['user_id' => $this->editor->id]);
    $userTag = Tag::factory()->user()->create(['name' => 'foo']);

    $this->actingAs($this->editor)
        ->postJson(route('assets.tags.add', $asset), [
            'reference_tag_ids' => [$userTag->id],
        ])
        ->assertStatus(422);
});

test('bulkAddTags accepts reference_tag_ids across multiple assets', function () {
    $assets = Asset::factory()->image()->count(2)->create(['user_id' => $this->editor->id]);
    $refTag = Tag::create(['name' => 'newsletter-q1', 'type' => 'reference']);

    $this->actingAs($this->editor)
        ->postJson(route('assets.bulk.tags.add'), [
            'asset_ids' => $assets->pluck('id')->toArray(),
            'reference_tag_ids' => [$refTag->id],
        ])
        ->assertOk();

    foreach ($assets as $asset) {
        $pivot = $asset->fresh()->tags->firstWhere('id', $refTag->id);
        expect($pivot)->not->toBeNull();
        expect($pivot->pivot->attached_by)->toBe('reference');
    }
});

test('applyUploadMetadata attaches reference tag with attached_by=reference', function () {
    $asset = Asset::factory()->image()->create(['user_id' => $this->editor->id]);
    $refTag = Tag::create(['name' => 'linkedin', 'type' => 'reference']);

    app(AssetProcessingService::class)->applyUploadMetadata(
        $asset,
        ['summer'],
        null,
        null,
        null,
        [$refTag->id],
    );

    $asset->refresh()->load('tags');
    $userTag = $asset->tags->firstWhere('name', 'summer');
    $reference = $asset->tags->firstWhere('id', $refTag->id);

    expect($userTag)->not->toBeNull();
    expect($userTag->pivot->attached_by)->toBe('user');
    expect($reference)->not->toBeNull();
    expect($reference->pivot->attached_by)->toBe('reference');
});

test('TagController search includes reference tags when types=user,reference', function () {
    Tag::factory()->user()->create(['name' => 'sunshine']);
    Tag::create(['name' => 'sunshine-publication', 'type' => 'reference']);
    Tag::create(['name' => 'sunshine-ai', 'type' => 'ai']);

    $response = $this->actingAs($this->editor)
        ->getJson('/tags/search?q=sun&types=user,reference');

    $response->assertOk();
    $names = collect($response->json())->pluck('name');
    expect($names)->toContain('sunshine');
    expect($names)->toContain('sunshine-publication');
    expect($names)->not->toContain('sunshine-ai');
});
