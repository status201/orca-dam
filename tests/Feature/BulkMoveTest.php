<?php

use App\Models\Asset;
use App\Models\Setting;
use App\Models\User;
use App\Services\S3Service;

beforeEach(function () {
    Setting::firstOrCreate(
        ['key' => 'maintenance_mode'],
        ['value' => '0', 'type' => 'boolean', 'group' => 'general']
    );
    Setting::set('s3_root_folder', 'assets');
});

test('admin can move assets when maintenance mode is enabled', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Setting::set('maintenance_mode', '1');

    $asset = Asset::factory()->create([
        's3_key' => 'assets/old-folder/abc123.jpg',
        'thumbnail_s3_key' => 'thumbnails/old-folder/abc123_thumb.jpg',
        'resize_s_s3_key' => 'thumbnails/S/old-folder/abc123.jpg',
        'resize_m_s3_key' => 'thumbnails/M/old-folder/abc123.jpg',
        'resize_l_s3_key' => 'thumbnails/L/old-folder/abc123.jpg',
    ]);

    $this->mock(S3Service::class, function ($mock) {
        $mock->shouldReceive('moveObject')->andReturn(true);
    });

    $response = $this->actingAs($admin)->postJson(route('assets.bulk.move'), [
        'asset_ids' => [$asset->id],
        'destination_folder' => 'assets/new-folder',
    ]);

    $response->assertOk();
    $response->assertJsonPath('moved', 1);
    $response->assertJsonPath('failed', 0);

    $asset->refresh();
    expect($asset->s3_key)->toBe('assets/new-folder/abc123.jpg');
    expect($asset->thumbnail_s3_key)->toBe('thumbnails/new-folder/abc123_thumb.jpg');
    expect($asset->resize_s_s3_key)->toBe('thumbnails/S/new-folder/abc123.jpg');
    expect($asset->resize_m_s3_key)->toBe('thumbnails/M/new-folder/abc123.jpg');
    expect($asset->resize_l_s3_key)->toBe('thumbnails/L/new-folder/abc123.jpg');
});

test('non-admin gets 403 when trying to move assets', function () {
    $editor = User::factory()->create(['role' => 'editor']);
    Setting::set('maintenance_mode', '1');

    $asset = Asset::factory()->create();

    $response = $this->actingAs($editor)->postJson(route('assets.bulk.move'), [
        'asset_ids' => [$asset->id],
        'destination_folder' => 'assets/new-folder',
    ]);

    $response->assertForbidden();
});

test('move is denied when maintenance mode is disabled', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Setting::set('maintenance_mode', '0');

    $asset = Asset::factory()->create();

    $response = $this->actingAs($admin)->postJson(route('assets.bulk.move'), [
        'asset_ids' => [$asset->id],
        'destination_folder' => 'assets/new-folder',
    ]);

    $response->assertForbidden();
});

test('assets already in target folder are skipped', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Setting::set('maintenance_mode', '1');

    $asset = Asset::factory()->create([
        's3_key' => 'assets/target-folder/file.jpg',
    ]);

    $this->mock(S3Service::class, function ($mock) {
        $mock->shouldReceive('moveObject')->never();
    });

    $response = $this->actingAs($admin)->postJson(route('assets.bulk.move'), [
        'asset_ids' => [$asset->id],
        'destination_folder' => 'assets/target-folder',
    ]);

    $response->assertOk();
    $response->assertJsonPath('moved', 0);
    $response->assertJsonPath('failed', 0);
});

test('partial S3 failures report correct counts', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Setting::set('maintenance_mode', '1');

    $assetOk = Asset::factory()->create(['s3_key' => 'assets/old/file1.jpg']);
    $assetFail = Asset::factory()->create(['s3_key' => 'assets/old/file2.jpg']);

    $callCount = 0;
    $this->mock(S3Service::class, function ($mock) use (&$callCount) {
        $mock->shouldReceive('moveObject')->andReturnUsing(function ($src) use (&$callCount) {
            $callCount++;

            // First call succeeds, second fails
            return $callCount === 1;
        });
    });

    $response = $this->actingAs($admin)->postJson(route('assets.bulk.move'), [
        'asset_ids' => [$assetOk->id, $assetFail->id],
        'destination_folder' => 'assets/new',
    ]);

    $response->assertOk();
    $response->assertJsonPath('moved', 1);
    $response->assertJsonPath('failed', 1);
});

test('all key columns are updated after move', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Setting::set('maintenance_mode', '1');

    $asset = Asset::factory()->create([
        's3_key' => 'assets/photos/abc.png',
        'thumbnail_s3_key' => 'thumbnails/photos/abc_thumb.jpg',
        'resize_s_s3_key' => 'thumbnails/S/photos/abc.png',
        'resize_m_s3_key' => 'thumbnails/M/photos/abc.png',
        'resize_l_s3_key' => 'thumbnails/L/photos/abc.png',
    ]);

    $this->mock(S3Service::class, function ($mock) {
        $mock->shouldReceive('moveObject')->andReturn(true);
    });

    $response = $this->actingAs($admin)->postJson(route('assets.bulk.move'), [
        'asset_ids' => [$asset->id],
        'destination_folder' => 'assets/archive',
    ]);

    $response->assertOk();

    $asset->refresh();
    expect($asset->s3_key)->toBe('assets/archive/abc.png');
    expect($asset->thumbnail_s3_key)->toBe('thumbnails/archive/abc_thumb.jpg');
    expect($asset->resize_s_s3_key)->toBe('thumbnails/S/archive/abc.png');
    expect($asset->resize_m_s3_key)->toBe('thumbnails/M/archive/abc.png');
    expect($asset->resize_l_s3_key)->toBe('thumbnails/L/archive/abc.png');
});

test('validation errors for missing fields', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Setting::set('maintenance_mode', '1');

    // Missing destination_folder
    $response = $this->actingAs($admin)->postJson(route('assets.bulk.move'), [
        'asset_ids' => [1],
    ]);
    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('destination_folder');

    // Missing asset_ids
    $response = $this->actingAs($admin)->postJson(route('assets.bulk.move'), [
        'destination_folder' => 'assets/new',
    ]);
    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('asset_ids');
});

test('api users cannot move assets', function () {
    $apiUser = User::factory()->create(['role' => 'api']);
    Setting::set('maintenance_mode', '1');

    $asset = Asset::factory()->create();

    $response = $this->actingAs($apiUser)->postJson(route('assets.bulk.move'), [
        'asset_ids' => [$asset->id],
        'destination_folder' => 'assets/new-folder',
    ]);

    $response->assertForbidden();
});

test('moves response includes old and new keys', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Setting::set('maintenance_mode', '1');

    $asset = Asset::factory()->create([
        's3_key' => 'assets/source/file.jpg',
    ]);

    $this->mock(S3Service::class, function ($mock) {
        $mock->shouldReceive('moveObject')->andReturn(true);
    });

    $response = $this->actingAs($admin)->postJson(route('assets.bulk.move'), [
        'asset_ids' => [$asset->id],
        'destination_folder' => 'assets/dest',
    ]);

    $response->assertOk();
    $response->assertJsonPath('moves.0.old', 'assets/source/file.jpg');
    $response->assertJsonPath('moves.0.new', 'assets/dest/file.jpg');
});

test('assets at root folder are moved with thumbnails and resizes', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Setting::set('maintenance_mode', '1');

    // Asset in root folder (no subfolder) — the exact case that was failing
    $asset = Asset::factory()->create([
        's3_key' => 'assets/abc123.jpg',
        'thumbnail_s3_key' => 'thumbnails/abc123_thumb.jpg',
        'resize_s_s3_key' => 'thumbnails/S/abc123.jpg',
        'resize_m_s3_key' => 'thumbnails/M/abc123.jpg',
        'resize_l_s3_key' => 'thumbnails/L/abc123.jpg',
    ]);

    $this->mock(S3Service::class, function ($mock) {
        $mock->shouldReceive('moveObject')->andReturn(true);
    });

    $response = $this->actingAs($admin)->postJson(route('assets.bulk.move'), [
        'asset_ids' => [$asset->id],
        'destination_folder' => 'assets/new-folder',
    ]);

    $response->assertOk();
    $response->assertJsonPath('moved', 1);

    $asset->refresh();
    expect($asset->s3_key)->toBe('assets/new-folder/abc123.jpg');
    expect($asset->thumbnail_s3_key)->toBe('thumbnails/new-folder/abc123_thumb.jpg');
    expect($asset->resize_s_s3_key)->toBe('thumbnails/S/new-folder/abc123.jpg');
    expect($asset->resize_m_s3_key)->toBe('thumbnails/M/new-folder/abc123.jpg');
    expect($asset->resize_l_s3_key)->toBe('thumbnails/L/new-folder/abc123.jpg');
});

test('path traversal in destination folder is rejected', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Setting::set('maintenance_mode', '1');

    $asset = Asset::factory()->create();

    // Dot-dot traversal
    $response = $this->actingAs($admin)->postJson(route('assets.bulk.move'), [
        'asset_ids' => [$asset->id],
        'destination_folder' => '../../etc',
    ]);
    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('destination_folder');

    // Folder outside configured S3 folders
    $response = $this->actingAs($admin)->postJson(route('assets.bulk.move'), [
        'asset_ids' => [$asset->id],
        'destination_folder' => 'unauthorized-prefix/folder',
    ]);
    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('destination_folder');
});
