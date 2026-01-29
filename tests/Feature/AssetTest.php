<?php

use App\Models\Asset;
use App\Models\Setting;
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

test('authenticated users can access replace form', function () {
    $user = User::factory()->create();
    $asset = Asset::factory()->image()->create();

    $response = $this->actingAs($user)->get(route('assets.replace', $asset));

    $response->assertStatus(200);
    $response->assertSee('Replace Asset File');
    $response->assertSee('About Asset Replacement');
});

test('replace rejects file with different extension', function () {
    $user = User::factory()->create();
    $asset = Asset::factory()->create(['filename' => 'test.jpg']);

    // Create a fake PDF file (wrong extension)
    $file = \Illuminate\Http\UploadedFile::fake()->create('replacement.pdf', 100);

    $response = $this->actingAs($user)
        ->postJson(route('assets.replace.store', $asset), [
            'file' => $file,
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['file']);
});

test('replace accepts file with same extension', function () {
    $user = User::factory()->create();
    $asset = Asset::factory()->image()->create([
        'filename' => 'original.jpg',
        'alt_text' => 'Original alt text',
        'caption' => 'Original caption',
        'size' => 1000,
    ]);

    // Mock the S3Service
    $s3Service = Mockery::mock(\App\Services\S3Service::class);
    $s3Service->shouldReceive('replaceFile')->once()->andReturn([
        'filename' => 'replacement.jpg',
        'mime_type' => 'image/jpeg',
        'size' => 2000,
        'etag' => 'new-etag-123',
        'width' => 1920,
        'height' => 1080,
    ]);
    $s3Service->shouldReceive('deleteFile')->andReturn(true);
    $s3Service->shouldReceive('generateThumbnail')->andReturn('thumbnails/new_thumb.jpg');
    $this->app->instance(\App\Services\S3Service::class, $s3Service);

    $file = \Illuminate\Http\UploadedFile::fake()->image('replacement.jpg', 1920, 1080);

    $response = $this->actingAs($user)
        ->postJson(route('assets.replace.store', $asset), [
            'file' => $file,
        ]);

    $response->assertStatus(200);
    $response->assertJson(['message' => 'Asset replaced successfully']);

    $asset->refresh();
    // File attributes should be updated
    expect($asset->filename)->toBe('replacement.jpg');
    expect($asset->size)->toBe(2000);
    expect($asset->width)->toBe(1920);
    expect($asset->height)->toBe(1080);
    // Metadata should be preserved
    expect($asset->alt_text)->toBe('Original alt text');
    expect($asset->caption)->toBe('Original caption');
});

test('edit page shows replace button', function () {
    $user = User::factory()->create();
    $asset = Asset::factory()->create();

    $response = $this->actingAs($user)->get(route('assets.edit', $asset));

    $response->assertStatus(200);
    $response->assertSee('Replace File');
    $response->assertSee(route('assets.replace', $asset));
});

test('guests cannot access replace form', function () {
    $asset = Asset::factory()->create();

    $response = $this->get(route('assets.replace', $asset));

    $response->assertRedirect(route('login'));
});

test('guests cannot perform replace action', function () {
    $asset = Asset::factory()->create(['filename' => 'test.jpg']);
    $file = \Illuminate\Http\UploadedFile::fake()->image('replacement.jpg');

    $response = $this->postJson(route('assets.replace.store', $asset), [
        'file' => $file,
    ]);

    $response->assertStatus(401);
});

test('replace accepts file with different case extension', function () {
    $user = User::factory()->create();
    $asset = Asset::factory()->create(['filename' => 'original.jpg']); // lowercase

    $s3Service = Mockery::mock(\App\Services\S3Service::class);
    $s3Service->shouldReceive('replaceFile')->once()->andReturn([
        'filename' => 'replacement.JPG', // uppercase
        'mime_type' => 'image/jpeg',
        'size' => 2000,
        'etag' => 'new-etag',
        'width' => 800,
        'height' => 600,
    ]);
    $s3Service->shouldReceive('deleteFile')->andReturn(true);
    $s3Service->shouldReceive('generateThumbnail')->andReturn(null);
    $this->app->instance(\App\Services\S3Service::class, $s3Service);

    // Upload file with uppercase extension
    $file = \Illuminate\Http\UploadedFile::fake()->create('replacement.JPG', 100, 'image/jpeg');

    $response = $this->actingAs($user)
        ->postJson(route('assets.replace.store', $asset), [
            'file' => $file,
        ]);

    $response->assertStatus(200);
});

test('replace preserves tags after replacement', function () {
    $user = User::factory()->create();
    $asset = Asset::factory()->image()->create(['filename' => 'original.jpg']);

    // Add tags to the asset
    $userTag = \App\Models\Tag::factory()->create(['name' => 'user-tag', 'type' => 'user']);
    $aiTag = \App\Models\Tag::factory()->create(['name' => 'ai-tag', 'type' => 'ai']);
    $asset->tags()->attach([$userTag->id, $aiTag->id]);

    $s3Service = Mockery::mock(\App\Services\S3Service::class);
    $s3Service->shouldReceive('replaceFile')->once()->andReturn([
        'filename' => 'replacement.jpg',
        'mime_type' => 'image/jpeg',
        'size' => 2000,
        'etag' => 'new-etag',
        'width' => 800,
        'height' => 600,
    ]);
    $s3Service->shouldReceive('deleteFile')->andReturn(true);
    $s3Service->shouldReceive('generateThumbnail')->andReturn(null);
    $this->app->instance(\App\Services\S3Service::class, $s3Service);

    $file = \Illuminate\Http\UploadedFile::fake()->image('replacement.jpg');

    $response = $this->actingAs($user)
        ->postJson(route('assets.replace.store', $asset), [
            'file' => $file,
        ]);

    $response->assertStatus(200);

    $asset->refresh();
    expect($asset->tags)->toHaveCount(2);
    expect($asset->userTags)->toHaveCount(1);
    expect($asset->aiTags)->toHaveCount(1);
});

test('assets index uses user home folder preference as default', function () {
    Setting::set('s3_root_folder', 'assets', 'string', 'aws');
    Setting::set('s3_folders', ['assets', 'assets/marketing', 'assets/docs'], 'json', 'aws');

    $user = User::factory()->create([
        'preferences' => ['home_folder' => 'assets/marketing'],
    ]);

    // Create assets in different folders
    Asset::factory()->create(['s3_key' => 'assets/marketing/file1.jpg', 'filename' => 'file1.jpg']);
    Asset::factory()->create(['s3_key' => 'assets/docs/file2.jpg', 'filename' => 'file2.jpg']);

    $response = $this->actingAs($user)->get(route('assets.index'));

    $response->assertStatus(200);
    $response->assertSee('file1.jpg');
    $response->assertDontSee('file2.jpg');
});

test('assets index uses user items per page preference', function () {
    Setting::set('items_per_page', 24, 'integer', 'display');
    Setting::set('s3_root_folder', '', 'string', 'aws');

    $user = User::factory()->create([
        'preferences' => ['items_per_page' => 12],
    ]);

    // Create 15 assets
    Asset::factory(15)->create();

    $response = $this->actingAs($user)->get(route('assets.index'));

    $response->assertStatus(200);
    // With 12 per page, we should have pagination
    expect($response['assets']->perPage())->toBe(12);
});

test('assets index url param overrides user folder preference', function () {
    Setting::set('s3_root_folder', 'assets', 'string', 'aws');
    Setting::set('s3_folders', ['assets', 'assets/marketing', 'assets/docs'], 'json', 'aws');

    $user = User::factory()->create([
        'preferences' => ['home_folder' => 'assets/marketing'],
    ]);

    Asset::factory()->create(['s3_key' => 'assets/marketing/file1.jpg', 'filename' => 'file1.jpg']);
    Asset::factory()->create(['s3_key' => 'assets/docs/file2.jpg', 'filename' => 'file2.jpg']);

    // Override with URL param
    $response = $this->actingAs($user)->get(route('assets.index', ['folder' => 'assets/docs']));

    $response->assertStatus(200);
    $response->assertDontSee('file1.jpg');
    $response->assertSee('file2.jpg');
});

test('assets index url param overrides user items per page preference', function () {
    Setting::set('items_per_page', 24, 'integer', 'display');
    Setting::set('s3_root_folder', '', 'string', 'aws');

    $user = User::factory()->create([
        'preferences' => ['items_per_page' => 12],
    ]);

    Asset::factory(50)->create();

    // Override with URL param
    $response = $this->actingAs($user)->get(route('assets.index', ['per_page' => 48]));

    $response->assertStatus(200);
    expect($response['assets']->perPage())->toBe(48);
});

test('assets index falls back to global setting when no user preference', function () {
    Setting::set('items_per_page', 36, 'integer', 'display');
    Setting::set('s3_root_folder', '', 'string', 'aws');

    $user = User::factory()->create(); // No preferences

    Asset::factory(50)->create();

    $response = $this->actingAs($user)->get(route('assets.index'));

    $response->assertStatus(200);
    expect($response['assets']->perPage())->toBe(36);
});
