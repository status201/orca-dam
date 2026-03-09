<?php

use App\Models\Asset;
use App\Models\User;
use App\Services\S3Service;

test('admin can bulk restore trashed assets', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $asset1 = Asset::factory()->create(['deleted_at' => now()]);
    $asset2 = Asset::factory()->create(['deleted_at' => now()]);

    $response = $this->actingAs($admin)->postJson(route('assets.trash.bulk-restore'), [
        'asset_ids' => [$asset1->id, $asset2->id],
    ]);

    $response->assertOk();
    $response->assertJsonPath('restored', 2);
    $response->assertJsonPath('failed', 0);
    expect($response->json('restored_filenames'))->toHaveCount(2);

    expect(Asset::find($asset1->id))->not->toBeNull();
    expect(Asset::find($asset2->id))->not->toBeNull();
    expect(Asset::find($asset1->id)->deleted_at)->toBeNull();
    expect(Asset::find($asset2->id)->deleted_at)->toBeNull();
});

test('non-admin gets 403 on bulk restore', function () {
    $editor = User::factory()->create(['role' => 'editor']);

    $asset = Asset::factory()->create(['deleted_at' => now()]);

    $response = $this->actingAs($editor)->postJson(route('assets.trash.bulk-restore'), [
        'asset_ids' => [$asset->id],
    ]);

    $response->assertForbidden();
    expect(Asset::onlyTrashed()->find($asset->id))->not->toBeNull();
});

test('bulk restore validates asset_ids', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    // Missing asset_ids
    $response = $this->actingAs($admin)->postJson(route('assets.trash.bulk-restore'), []);
    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('asset_ids');

    // Empty array
    $response = $this->actingAs($admin)->postJson(route('assets.trash.bulk-restore'), [
        'asset_ids' => [],
    ]);
    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('asset_ids');
});

test('bulk restore only restores trashed assets', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    // Non-trashed asset should not be found by onlyTrashed query
    $activeAsset = Asset::factory()->create(['deleted_at' => null]);
    $trashedAsset = Asset::factory()->create(['deleted_at' => now()]);

    $response = $this->actingAs($admin)->postJson(route('assets.trash.bulk-restore'), [
        'asset_ids' => [$activeAsset->id, $trashedAsset->id],
    ]);

    $response->assertOk();
    // Only the trashed asset should be restored (found by query)
    $response->assertJsonPath('restored', 1);
});

test('admin can bulk force delete trashed assets', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $asset1 = Asset::factory()->create([
        's3_key' => 'assets/folder/file1.jpg',
        'thumbnail_s3_key' => 'thumbnails/folder/file1_thumb.jpg',
        'resize_s_s3_key' => 'thumbnails/S/folder/file1.jpg',
        'resize_m_s3_key' => 'thumbnails/M/folder/file1.jpg',
        'resize_l_s3_key' => 'thumbnails/L/folder/file1.jpg',
        'deleted_at' => now(),
    ]);
    $asset2 = Asset::factory()->create([
        's3_key' => 'assets/folder/file2.jpg',
        'thumbnail_s3_key' => 'thumbnails/folder/file2_thumb.jpg',
        'deleted_at' => now(),
    ]);

    $this->mock(S3Service::class, function ($mock) {
        $mock->shouldReceive('deleteAssetFiles')->twice();
    });

    $response = $this->actingAs($admin)->deleteJson(route('assets.trash.bulk-force-delete'), [
        'asset_ids' => [$asset1->id, $asset2->id],
    ]);

    $response->assertOk();
    $response->assertJsonPath('deleted', 2);
    $response->assertJsonPath('failed', 0);
    $response->assertJsonPath('deleted_keys.0', 'assets/folder/file1.jpg');
    $response->assertJsonPath('deleted_keys.1', 'assets/folder/file2.jpg');

    expect(Asset::withTrashed()->find($asset1->id))->toBeNull();
    expect(Asset::withTrashed()->find($asset2->id))->toBeNull();
});

test('non-admin gets 403 on bulk force delete trashed', function () {
    $editor = User::factory()->create(['role' => 'editor']);

    $asset = Asset::factory()->create(['deleted_at' => now()]);

    $response = $this->actingAs($editor)->deleteJson(route('assets.trash.bulk-force-delete'), [
        'asset_ids' => [$asset->id],
    ]);

    $response->assertForbidden();
    expect(Asset::onlyTrashed()->find($asset->id))->not->toBeNull();
});

test('bulk force delete trashed does NOT require maintenance_mode', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $asset = Asset::factory()->create([
        's3_key' => 'assets/folder/file.jpg',
        'deleted_at' => now(),
    ]);

    $this->mock(S3Service::class, function ($mock) {
        $mock->shouldReceive('deleteAssetFiles')->once();
    });

    // No maintenance_mode setting created — should still work
    $response = $this->actingAs($admin)->deleteJson(route('assets.trash.bulk-force-delete'), [
        'asset_ids' => [$asset->id],
    ]);

    $response->assertOk();
    $response->assertJsonPath('deleted', 1);
});

test('bulk force delete trashed cleans up S3 objects', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $asset = Asset::factory()->create([
        's3_key' => 'assets/folder/file.jpg',
        'thumbnail_s3_key' => 'thumbnails/folder/file_thumb.jpg',
        'resize_s_s3_key' => 'thumbnails/S/folder/file.jpg',
        'resize_m_s3_key' => 'thumbnails/M/folder/file.jpg',
        'resize_l_s3_key' => 'thumbnails/L/folder/file.jpg',
        'deleted_at' => now(),
    ]);

    $this->mock(S3Service::class, function ($mock) {
        $mock->shouldReceive('deleteAssetFiles')->once();
    });

    $response = $this->actingAs($admin)->deleteJson(route('assets.trash.bulk-force-delete'), [
        'asset_ids' => [$asset->id],
    ]);

    $response->assertOk();
    $response->assertJsonPath('deleted', 1);
    expect(Asset::withTrashed()->find($asset->id))->toBeNull();
});

test('bulk force delete trashed only affects trashed assets', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $activeAsset = Asset::factory()->create([
        's3_key' => 'assets/folder/active.jpg',
        'deleted_at' => null,
    ]);
    $trashedAsset = Asset::factory()->create([
        's3_key' => 'assets/folder/trashed.jpg',
        'deleted_at' => now(),
    ]);

    $this->mock(S3Service::class, function ($mock) {
        $mock->shouldReceive('deleteAssetFiles');
    });

    $response = $this->actingAs($admin)->deleteJson(route('assets.trash.bulk-force-delete'), [
        'asset_ids' => [$activeAsset->id, $trashedAsset->id],
    ]);

    $response->assertOk();
    // Only the trashed asset should be deleted
    $response->assertJsonPath('deleted', 1);
    expect(Asset::find($activeAsset->id))->not->toBeNull();
    expect(Asset::withTrashed()->find($trashedAsset->id))->toBeNull();
});
