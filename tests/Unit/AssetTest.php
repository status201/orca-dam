<?php

use App\Models\Asset;
use App\Models\Tag;
use App\Models\User;

test('asset belongs to a user', function () {
    $user = User::factory()->create();
    $asset = Asset::factory()->create(['user_id' => $user->id]);

    expect($asset->user)->toBeInstanceOf(User::class);
    expect($asset->user->id)->toBe($user->id);
});

test('asset can have tags', function () {
    $asset = Asset::factory()->create();
    $tags = Tag::factory()->count(3)->create();

    $asset->tags()->attach($tags);

    expect($asset->tags)->toHaveCount(3);
});

test('asset can separate user tags from ai tags', function () {
    $asset = Asset::factory()->create();
    $userTags = Tag::factory()->count(2)->user()->create();
    $aiTags = Tag::factory()->count(3)->ai()->create();

    $asset->tags()->attach($userTags);
    $asset->tags()->attach($aiTags);

    expect($asset->userTags)->toHaveCount(2);
    expect($asset->aiTags)->toHaveCount(3);
});

test('asset isImage returns true for image mime types', function () {
    $jpegAsset = Asset::factory()->create(['mime_type' => 'image/jpeg']);
    $pngAsset = Asset::factory()->create(['mime_type' => 'image/png']);
    $gifAsset = Asset::factory()->create(['mime_type' => 'image/gif']);
    $pdfAsset = Asset::factory()->create(['mime_type' => 'application/pdf']);

    expect($jpegAsset->isImage())->toBeTrue();
    expect($pngAsset->isImage())->toBeTrue();
    expect($gifAsset->isImage())->toBeTrue();
    expect($pdfAsset->isImage())->toBeFalse();
});

test('asset formatted_size returns human readable size', function () {
    $smallAsset = Asset::factory()->create(['size' => 500]);
    $kbAsset = Asset::factory()->create(['size' => 1500]);
    $mbAsset = Asset::factory()->create(['size' => 1500000]);

    expect($smallAsset->formatted_size)->toContain('B');
    expect($kbAsset->formatted_size)->toContain('KB');
    expect($mbAsset->formatted_size)->toContain('MB');
});

test('asset uses soft deletes', function () {
    $asset = Asset::factory()->create();
    $assetId = $asset->id;

    $asset->delete();

    expect(Asset::find($assetId))->toBeNull();
    expect(Asset::withTrashed()->find($assetId))->not->toBeNull();
});

test('asset casts license_expiry_date to date', function () {
    $asset = Asset::factory()->create([
        'license_expiry_date' => '2025-12-31',
    ]);

    expect($asset->license_expiry_date)->toBeInstanceOf(\Carbon\Carbon::class);
    expect($asset->license_expiry_date->format('Y-m-d'))->toBe('2025-12-31');
});

test('asset getFileIcon returns correct icon for different file types', function () {
    $pdfAsset = Asset::factory()->create(['mime_type' => 'application/pdf']);
    $wordAsset = Asset::factory()->create(['mime_type' => 'application/msword']);
    $videoAsset = Asset::factory()->create(['mime_type' => 'video/mp4']);

    expect($pdfAsset->getFileIcon())->toBe('fa-file-pdf');
    expect($wordAsset->getFileIcon())->toBe('fa-file-word');
    expect($videoAsset->getFileIcon())->toBe('fa-file-video');
});

test('asset scope search filters by filename', function () {
    Asset::factory()->create(['filename' => 'test-image.jpg']);
    Asset::factory()->create(['filename' => 'document.pdf']);
    Asset::factory()->create(['filename' => 'another-test.png']);

    $results = Asset::search('test')->get();

    expect($results)->toHaveCount(2);
});

test('asset scope ofType filters by mime type prefix', function () {
    Asset::factory()->create(['mime_type' => 'image/jpeg']);
    Asset::factory()->create(['mime_type' => 'image/png']);
    Asset::factory()->create(['mime_type' => 'application/pdf']);

    $images = Asset::ofType('image')->get();
    $documents = Asset::ofType('application')->get();

    expect($images)->toHaveCount(2);
    expect($documents)->toHaveCount(1);
});

test('asset scope withTags filters by tag ids', function () {
    $tag1 = Tag::factory()->create();
    $tag2 = Tag::factory()->create();

    $asset1 = Asset::factory()->create();
    $asset2 = Asset::factory()->create();
    $asset3 = Asset::factory()->create();

    $asset1->tags()->attach($tag1);
    $asset2->tags()->attach([$tag1->id, $tag2->id]);

    $results = Asset::withTags([$tag1->id])->get();

    expect($results)->toHaveCount(2);
});
