<?php

use App\Models\Asset;
use App\Models\User;
use App\Services\S3Service;

test('authenticated user can bulk download assets as zip', function () {
    $user = User::factory()->create(['role' => 'editor']);

    $asset1 = Asset::factory()->create([
        'filename' => 'photo1.jpg',
        's3_key' => 'assets/photo1.jpg',
        'size' => 1024,
    ]);
    $asset2 = Asset::factory()->create([
        'filename' => 'photo2.jpg',
        's3_key' => 'assets/photo2.jpg',
        'size' => 2048,
    ]);

    $this->mock(S3Service::class, function ($mock) {
        $mock->shouldReceive('getObjectContent')
            ->with('assets/photo1.jpg')
            ->andReturn('fake-image-content-1');
        $mock->shouldReceive('getObjectContent')
            ->with('assets/photo2.jpg')
            ->andReturn('fake-image-content-2');
    });

    $response = $this->actingAs($user)->postJson(route('assets.bulk.download'), [
        'asset_ids' => [$asset1->id, $asset2->id],
    ]);

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/zip');
    expect($response->headers->get('Content-Disposition'))->toContain('orca-dam-assets-');
    expect($response->headers->get('Content-Disposition'))->toContain('.zip');
});

test('zip contains correct files with correct filenames', function () {
    $user = User::factory()->create(['role' => 'editor']);

    $asset1 = Asset::factory()->create([
        'filename' => 'doc.pdf',
        's3_key' => 'assets/doc.pdf',
        'size' => 100,
    ]);
    $asset2 = Asset::factory()->create([
        'filename' => 'image.png',
        's3_key' => 'assets/image.png',
        'size' => 200,
    ]);

    $this->mock(S3Service::class, function ($mock) {
        $mock->shouldReceive('getObjectContent')
            ->with('assets/doc.pdf')
            ->andReturn('pdf-content');
        $mock->shouldReceive('getObjectContent')
            ->with('assets/image.png')
            ->andReturn('png-content');
    });

    $response = $this->actingAs($user)->postJson(route('assets.bulk.download'), [
        'asset_ids' => [$asset1->id, $asset2->id],
    ]);

    $response->assertOk();

    // Write response to temp file and verify ZIP contents
    $tempFile = tempnam(sys_get_temp_dir(), 'test_zip_');
    file_put_contents($tempFile, $response->getContent());

    $zip = new ZipArchive;
    expect($zip->open($tempFile))->toBe(true);
    expect($zip->numFiles)->toBe(2);
    expect($zip->getFromName('doc.pdf'))->toBe('pdf-content');
    expect($zip->getFromName('image.png'))->toBe('png-content');
    $zip->close();

    @unlink($tempFile);
});

test('duplicate filenames are disambiguated in zip', function () {
    $user = User::factory()->create(['role' => 'editor']);

    $asset1 = Asset::factory()->create([
        'filename' => 'photo.jpg',
        's3_key' => 'assets/folder1/photo.jpg',
        'size' => 100,
    ]);
    $asset2 = Asset::factory()->create([
        'filename' => 'photo.jpg',
        's3_key' => 'assets/folder2/photo.jpg',
        'size' => 100,
    ]);

    $this->mock(S3Service::class, function ($mock) {
        $mock->shouldReceive('getObjectContent')
            ->with('assets/folder1/photo.jpg')
            ->andReturn('content-1');
        $mock->shouldReceive('getObjectContent')
            ->with('assets/folder2/photo.jpg')
            ->andReturn('content-2');
    });

    $response = $this->actingAs($user)->postJson(route('assets.bulk.download'), [
        'asset_ids' => [$asset1->id, $asset2->id],
    ]);

    $response->assertOk();

    $tempFile = tempnam(sys_get_temp_dir(), 'test_zip_');
    file_put_contents($tempFile, $response->getContent());

    $zip = new ZipArchive;
    $zip->open($tempFile);
    expect($zip->numFiles)->toBe(2);
    expect($zip->getFromName('photo.jpg'))->toBe('content-1');
    expect($zip->getFromName('photo_1.jpg'))->toBe('content-2');
    $zip->close();

    @unlink($tempFile);
});

test('bulk download rejects more than 100 assets', function () {
    $user = User::factory()->create(['role' => 'editor']);

    $assetIds = [];
    for ($i = 0; $i < 101; $i++) {
        $assetIds[] = Asset::factory()->create(['size' => 100])->id;
    }

    $response = $this->actingAs($user)->postJson(route('assets.bulk.download'), [
        'asset_ids' => $assetIds,
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('asset_ids');
});

test('bulk download rejects total size exceeding 500MB', function () {
    $user = User::factory()->create(['role' => 'editor']);

    $asset1 = Asset::factory()->create(['size' => 300 * 1024 * 1024]);
    $asset2 = Asset::factory()->create(['size' => 250 * 1024 * 1024]);

    $response = $this->actingAs($user)->postJson(route('assets.bulk.download'), [
        'asset_ids' => [$asset1->id, $asset2->id],
    ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('500 MB');
});

test('unauthenticated user cannot bulk download', function () {
    $asset = Asset::factory()->create(['size' => 100]);

    $response = $this->postJson(route('assets.bulk.download'), [
        'asset_ids' => [$asset->id],
    ]);

    $response->assertUnauthorized();
});

test('bulk download skips failed S3 fetches gracefully', function () {
    $user = User::factory()->create(['role' => 'editor']);

    $asset1 = Asset::factory()->create([
        'filename' => 'good.jpg',
        's3_key' => 'assets/good.jpg',
        'size' => 100,
    ]);
    $asset2 = Asset::factory()->create([
        'filename' => 'missing.jpg',
        's3_key' => 'assets/missing.jpg',
        'size' => 100,
    ]);

    $this->mock(S3Service::class, function ($mock) {
        $mock->shouldReceive('getObjectContent')
            ->with('assets/good.jpg')
            ->andReturn('good-content');
        $mock->shouldReceive('getObjectContent')
            ->with('assets/missing.jpg')
            ->andReturn(null);
    });

    $response = $this->actingAs($user)->postJson(route('assets.bulk.download'), [
        'asset_ids' => [$asset1->id, $asset2->id],
    ]);

    $response->assertOk();

    $tempFile = tempnam(sys_get_temp_dir(), 'test_zip_');
    file_put_contents($tempFile, $response->getContent());

    $zip = new ZipArchive;
    $zip->open($tempFile);
    expect($zip->numFiles)->toBe(1);
    expect($zip->getFromName('good.jpg'))->toBe('good-content');
    $zip->close();

    @unlink($tempFile);
});

test('bulk download returns 422 when all files fail', function () {
    $user = User::factory()->create(['role' => 'editor']);

    $asset = Asset::factory()->create([
        'filename' => 'missing.jpg',
        's3_key' => 'assets/missing.jpg',
        'size' => 100,
    ]);

    $this->mock(S3Service::class, function ($mock) {
        $mock->shouldReceive('getObjectContent')
            ->andReturn(null);
    });

    $response = $this->actingAs($user)->postJson(route('assets.bulk.download'), [
        'asset_ids' => [$asset->id],
    ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('No files');
});

test('api user can bulk download assets', function () {
    $apiUser = User::factory()->create(['role' => 'api']);

    $asset = Asset::factory()->create([
        'filename' => 'file.jpg',
        's3_key' => 'assets/file.jpg',
        'size' => 100,
    ]);

    $this->mock(S3Service::class, function ($mock) {
        $mock->shouldReceive('getObjectContent')
            ->with('assets/file.jpg')
            ->andReturn('content');
    });

    $response = $this->actingAs($apiUser)->postJson(route('assets.bulk.download'), [
        'asset_ids' => [$asset->id],
    ]);

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/zip');
});
