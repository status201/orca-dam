<?php

use App\Models\Asset;
use App\Models\Tag;
use App\Models\User;

test('guests cannot access export page', function () {
    $response = $this->get(route('export.index'));

    $response->assertRedirect(route('login'));
});

test('editors cannot access export page', function () {
    $editor = User::factory()->create(['role' => 'editor']);

    $response = $this->actingAs($editor)->get(route('export.index'));

    $response->assertForbidden();
});

test('admins can access export page', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->get(route('export.index'));

    $response->assertStatus(200);
});

test('admins can export assets as csv', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Asset::factory()->count(3)->create();

    $response = $this->actingAs($admin)->post(route('export.download'));

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    $response->assertHeader('Content-Disposition');
});

test('export includes all required columns', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $asset = Asset::factory()->create([
        'filename' => 'test-file.jpg',
        'license_type' => 'cc_by',
        'license_expiry_date' => '2025-12-31',
        'copyright' => 'Test Copyright',
        'copyright_source' => 'https://example.com',
    ]);

    $response = $this->actingAs($admin)->post(route('export.download'));

    $content = $response->streamedContent();

    expect($content)->toContain('id');
    expect($content)->toContain('filename');
    expect($content)->toContain('license_type');
    expect($content)->toContain('license_expiry_date');
    expect($content)->toContain('copyright');
    expect($content)->toContain('copyright_source');
    expect($content)->toContain('user_tags');
    expect($content)->toContain('ai_tags');
});

test('export includes asset data', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $asset = Asset::factory()->create([
        'filename' => 'unique-test-file.jpg',
    ]);

    $response = $this->actingAs($admin)->post(route('export.download'));

    $content = $response->streamedContent();

    expect($content)->toContain('unique-test-file.jpg');
});

test('export can filter by file type', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Asset::factory()->image()->create(['filename' => 'image.jpg']);
    Asset::factory()->pdf()->create(['filename' => 'document.pdf']);

    $response = $this->actingAs($admin)->post(route('export.download'), [
        'file_type' => 'image',
    ]);

    $content = $response->streamedContent();

    expect($content)->toContain('image.jpg');
    expect($content)->not->toContain('document.pdf');
});

test('export can filter by tags', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $tag = Tag::factory()->create(['name' => 'special']);

    $assetWithTag = Asset::factory()->create(['filename' => 'with-tag.jpg']);
    $assetWithTag->tags()->attach($tag);

    Asset::factory()->create(['filename' => 'without-tag.jpg']);

    $response = $this->actingAs($admin)->post(route('export.download'), [
        'tags' => [$tag->id],
    ]);

    $content = $response->streamedContent();

    expect($content)->toContain('with-tag.jpg');
    expect($content)->not->toContain('without-tag.jpg');
});

test('export separates user tags and ai tags', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $userTag = Tag::factory()->user()->create(['name' => 'user-tag']);
    $aiTag = Tag::factory()->ai()->create(['name' => 'ai-tag']);

    $asset = Asset::factory()->create();
    $asset->tags()->attach([$userTag->id, $aiTag->id]);

    $response = $this->actingAs($admin)->post(route('export.download'));

    $content = $response->streamedContent();

    // The CSV should have separate columns for user_tags and ai_tags
    expect($content)->toContain('user_tags');
    expect($content)->toContain('ai_tags');
});
