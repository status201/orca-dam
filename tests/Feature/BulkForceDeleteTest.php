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
});

test('admin can bulk force delete assets when maintenance mode is enabled', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Setting::set('maintenance_mode', '1');

    $asset1 = Asset::factory()->create([
        's3_key' => 'assets/folder/file1.jpg',
        'thumbnail_s3_key' => 'thumbnails/folder/file1_thumb.jpg',
        'resize_s_s3_key' => 'thumbnails/S/folder/file1.jpg',
        'resize_m_s3_key' => 'thumbnails/M/folder/file1.jpg',
        'resize_l_s3_key' => 'thumbnails/L/folder/file1.jpg',
    ]);
    $asset2 = Asset::factory()->create([
        's3_key' => 'assets/folder/file2.jpg',
        'thumbnail_s3_key' => 'thumbnails/folder/file2_thumb.jpg',
    ]);

    $this->mock(S3Service::class, function ($mock) {
        $mock->shouldReceive('deleteAssetFiles')->twice();
    });

    $response = $this->actingAs($admin)->deleteJson(route('assets.bulk.force-delete'), [
        'asset_ids' => [$asset1->id, $asset2->id],
    ]);

    $response->assertOk();
    $response->assertJsonPath('deleted', 2);
    $response->assertJsonPath('failed', 0);
    $response->assertJsonPath('deleted_keys.0', 'assets/folder/file1.jpg');
    $response->assertJsonPath('deleted_keys.1', 'assets/folder/file2.jpg');

    expect(Asset::find($asset1->id))->toBeNull();
    expect(Asset::find($asset2->id))->toBeNull();
    expect(Asset::withTrashed()->find($asset1->id))->toBeNull();
    expect(Asset::withTrashed()->find($asset2->id))->toBeNull();
});

test('non-admin gets 403 when trying to bulk force delete', function () {
    $editor = User::factory()->create(['role' => 'editor']);
    Setting::set('maintenance_mode', '1');

    $asset = Asset::factory()->create();

    $response = $this->actingAs($editor)->deleteJson(route('assets.bulk.force-delete'), [
        'asset_ids' => [$asset->id],
    ]);

    $response->assertForbidden();
    expect(Asset::find($asset->id))->not->toBeNull();
});

test('bulk force delete is denied when maintenance mode is disabled', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Setting::set('maintenance_mode', '0');

    $asset = Asset::factory()->create();

    $response = $this->actingAs($admin)->deleteJson(route('assets.bulk.force-delete'), [
        'asset_ids' => [$asset->id],
    ]);

    $response->assertForbidden();
    expect(Asset::find($asset->id))->not->toBeNull();
});

test('api users cannot bulk force delete', function () {
    $apiUser = User::factory()->create(['role' => 'api']);
    Setting::set('maintenance_mode', '1');

    $asset = Asset::factory()->create();

    $response = $this->actingAs($apiUser)->deleteJson(route('assets.bulk.force-delete'), [
        'asset_ids' => [$asset->id],
    ]);

    $response->assertForbidden();
});

test('bulk force delete validates asset_ids', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Setting::set('maintenance_mode', '1');

    // Missing asset_ids
    $response = $this->actingAs($admin)->deleteJson(route('assets.bulk.force-delete'), []);
    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('asset_ids');

    // Empty array
    $response = $this->actingAs($admin)->deleteJson(route('assets.bulk.force-delete'), [
        'asset_ids' => [],
    ]);
    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('asset_ids');

    // Non-existent asset
    $response = $this->actingAs($admin)->deleteJson(route('assets.bulk.force-delete'), [
        'asset_ids' => [99999],
    ]);
    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('asset_ids.0');
});

test('bulk force delete skips thumbnail deletion when no thumbnail exists', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Setting::set('maintenance_mode', '1');

    $asset = Asset::factory()->create([
        's3_key' => 'assets/folder/file.jpg',
        'thumbnail_s3_key' => null,
    ]);

    $this->mock(S3Service::class, function ($mock) {
        $mock->shouldReceive('deleteAssetFiles')->once();
    });

    $response = $this->actingAs($admin)->deleteJson(route('assets.bulk.force-delete'), [
        'asset_ids' => [$asset->id],
    ]);

    $response->assertOk();
    $response->assertJsonPath('deleted', 1);
});

test('bulk force delete reports failures when S3 deletion throws', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Setting::set('maintenance_mode', '1');

    $assetOk = Asset::factory()->create(['s3_key' => 'assets/folder/ok.jpg']);
    $assetFail = Asset::factory()->create(['s3_key' => 'assets/folder/fail.jpg']);

    $this->mock(S3Service::class, function ($mock) {
        $mock->shouldReceive('deleteAssetFiles')->andReturnUsing(function ($asset) {
            if (str_contains($asset->s3_key, 'fail')) {
                throw new \Exception('S3 error');
            }
        });
    });

    $response = $this->actingAs($admin)->deleteJson(route('assets.bulk.force-delete'), [
        'asset_ids' => [$assetOk->id, $assetFail->id],
    ]);

    $response->assertOk();
    $response->assertJsonPath('deleted', 1);
    $response->assertJsonPath('failed', 1);
});

test('bulk force delete works on non-trashed assets', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Setting::set('maintenance_mode', '1');

    // Create a normal (non-trashed) asset
    $asset = Asset::factory()->create([
        's3_key' => 'assets/folder/active.jpg',
        'deleted_at' => null,
    ]);

    $this->mock(S3Service::class, function ($mock) {
        $mock->shouldReceive('deleteAssetFiles');
    });

    $response = $this->actingAs($admin)->deleteJson(route('assets.bulk.force-delete'), [
        'asset_ids' => [$asset->id],
    ]);

    $response->assertOk();
    $response->assertJsonPath('deleted', 1);
    expect(Asset::withTrashed()->find($asset->id))->toBeNull();
});

test('bulk force delete returns deleted s3 keys in response', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Setting::set('maintenance_mode', '1');

    $asset1 = Asset::factory()->create(['s3_key' => 'assets/a/one.jpg']);
    $asset2 = Asset::factory()->create(['s3_key' => 'assets/b/two.png']);

    $this->mock(S3Service::class, function ($mock) {
        $mock->shouldReceive('deleteAssetFiles');
    });

    $response = $this->actingAs($admin)->deleteJson(route('assets.bulk.force-delete'), [
        'asset_ids' => [$asset1->id, $asset2->id],
    ]);

    $response->assertOk();
    $data = $response->json();
    expect($data['deleted_keys'])->toContain('assets/a/one.jpg');
    expect($data['deleted_keys'])->toContain('assets/b/two.png');
});
