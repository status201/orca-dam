<?php

use App\Models\Asset;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;

test('guests cannot access assets index', function () {
    $response = $this->get(route('assets.index'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can access assets index', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('assets.index'));

    $response->assertStatus(200);
});

test('assets index shows assets', function () {
    $user = User::factory()->create();
    $asset = Asset::factory()->create(['filename' => 'test-file.jpg']);

    $response = $this->actingAs($user)->get(route('assets.index'));

    $response->assertStatus(200);
    $response->assertSee('test-file.jpg');
});

test('assets index can filter by search', function () {
    $user = User::factory()->create();
    Asset::factory()->create(['filename' => 'findme.jpg']);
    Asset::factory()->create(['filename' => 'other.pdf']);

    $response = $this->actingAs($user)->get(route('assets.index', ['search' => 'findme']));

    $response->assertStatus(200);
    $response->assertSee('findme.jpg');
    $response->assertDontSee('other.pdf');
});

test('assets index can filter by type', function () {
    $user = User::factory()->create();
    Asset::factory()->image()->create(['filename' => 'image.jpg']);
    Asset::factory()->pdf()->create(['filename' => 'document.pdf']);

    $response = $this->actingAs($user)->get(route('assets.index', ['type' => 'image']));

    $response->assertStatus(200);
    $response->assertSee('image.jpg');
    $response->assertDontSee('document.pdf');
});

test('assets index respects per_page parameter', function () {
    $user = User::factory()->create();
    Asset::factory()->count(30)->create();

    $response = $this->actingAs($user)->get(route('assets.index', ['per_page' => 12]));

    $response->assertStatus(200);
});

test('authenticated users can view asset detail', function () {
    $user = User::factory()->create();
    $asset = Asset::factory()->create();

    $response = $this->actingAs($user)->get(route('assets.show', $asset));

    $response->assertStatus(200);
    $response->assertSee($asset->filename);
});

test('authenticated users can access edit form', function () {
    $user = User::factory()->create();
    $asset = Asset::factory()->create();

    $response = $this->actingAs($user)->get(route('assets.edit', $asset));

    $response->assertStatus(200);
});

test('authenticated users can update asset metadata', function () {
    $user = User::factory()->create();
    $asset = Asset::factory()->create();

    $response = $this->actingAs($user)->patch(route('assets.update', $asset), [
        'alt_text' => 'New alt text',
        'caption' => 'New caption',
        'license_type' => 'cc_by',
        'license_expiry_date' => '2025-12-31',
        'copyright' => 'Test Copyright',
        'copyright_source' => 'https://example.com',
    ]);

    $response->assertRedirect(route('assets.show', $asset));

    $asset->refresh();
    expect($asset->alt_text)->toBe('New alt text');
    expect($asset->caption)->toBe('New caption');
    expect($asset->license_type)->toBe('cc_by');
    expect($asset->license_expiry_date->format('Y-m-d'))->toBe('2025-12-31');
    expect($asset->copyright)->toBe('Test Copyright');
    expect($asset->copyright_source)->toBe('https://example.com');
});

test('authenticated users can update asset tags', function () {
    $user = User::factory()->create();
    $asset = Asset::factory()->create();

    $response = $this->actingAs($user)->patch(route('assets.update', $asset), [
        'tags' => ['tag1', 'tag2', 'tag3'],
    ]);

    $response->assertRedirect();

    $asset->refresh();
    expect($asset->userTags)->toHaveCount(3);
});

test('authenticated users can soft delete asset', function () {
    $user = User::factory()->create();
    $asset = Asset::factory()->create();
    $assetId = $asset->id;

    $response = $this->actingAs($user)->delete(route('assets.destroy', $asset));

    $response->assertRedirect(route('assets.index'));
    expect(Asset::find($assetId))->toBeNull();
    expect(Asset::withTrashed()->find($assetId))->not->toBeNull();
});

test('only admins can access trash', function () {
    $editor = User::factory()->create(['role' => 'editor']);
    $admin = User::factory()->create(['role' => 'admin']);

    $editorResponse = $this->actingAs($editor)->get(route('assets.trash'));
    $adminResponse = $this->actingAs($admin)->get(route('assets.trash'));

    $editorResponse->assertForbidden();
    $adminResponse->assertStatus(200);
});

test('only admins can restore assets', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $asset = Asset::factory()->create();
    $asset->delete();

    $response = $this->actingAs($admin)->post(route('assets.restore', $asset->id));

    $response->assertRedirect();
    expect(Asset::find($asset->id))->not->toBeNull();
});

test('only admins can force delete assets', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $asset = Asset::factory()->create();
    $asset->delete();
    $assetId = $asset->id;

    $response = $this->actingAs($admin)->delete(route('assets.force-delete', $assetId));

    $response->assertRedirect();
    expect(Asset::withTrashed()->find($assetId))->toBeNull();
});

test('asset detail shows license expiry date', function () {
    $user = User::factory()->create();
    $asset = Asset::factory()->create([
        'license_type' => 'cc_by',
        'license_expiry_date' => '2025-06-15',
    ]);

    $response = $this->actingAs($user)->get(route('assets.show', $asset));

    $response->assertStatus(200);
    $response->assertSee('License Expiry Date');
    $response->assertSee('Jun 15, 2025');
});

test('asset detail shows copyright source as link when url', function () {
    $user = User::factory()->create();
    $asset = Asset::factory()->create([
        'copyright_source' => 'https://example.com/license',
    ]);

    $response = $this->actingAs($user)->get(route('assets.show', $asset));

    $response->assertStatus(200);
    $response->assertSee('https://example.com/license');
});
