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

test('asset scope ofType accepts plural/friendly type names', function () {
    Asset::factory()->create(['mime_type' => 'image/jpeg']);
    Asset::factory()->create(['mime_type' => 'image/png']);
    Asset::factory()->create(['mime_type' => 'video/mp4']);
    Asset::factory()->create(['mime_type' => 'application/pdf']);

    expect(Asset::ofType('images')->get())->toHaveCount(2);
    expect(Asset::ofType('videos')->get())->toHaveCount(1);
    expect(Asset::ofType('documents')->get())->toHaveCount(1);
    expect(Asset::ofType('Images')->get())->toHaveCount(2);
});

test('asset url uses custom domain when configured', function () {
    $asset = Asset::factory()->create(['s3_key' => 'assets/test-image.jpg']);

    // Without custom domain, should use S3 URL
    $s3Url = config('filesystems.disks.s3.url');
    expect($asset->url)->toBe($s3Url.'/assets/test-image.jpg');

    // With custom domain, should use it
    \App\Models\Setting::set('custom_domain', 'https://cdn.example.com', 'string', 'aws');
    cache()->forget('setting:custom_domain');

    // Re-fetch to get fresh attribute
    $asset->refresh();
    expect($asset->url)->toBe('https://cdn.example.com/assets/test-image.jpg');

    // Clean up
    \App\Models\Setting::where('key', 'custom_domain')->delete();
    cache()->forget('setting:custom_domain');
});

test('asset thumbnail_url uses custom domain when configured', function () {
    $asset = Asset::factory()->create([
        's3_key' => 'assets/test-image.jpg',
        'thumbnail_s3_key' => 'thumbnails/test-image_thumb.jpg',
        'mime_type' => 'image/jpeg',
    ]);

    \App\Models\Setting::set('custom_domain', 'https://cdn.example.com', 'string', 'aws');
    cache()->forget('setting:custom_domain');

    $asset->refresh();
    expect($asset->thumbnail_url)->toBe('https://cdn.example.com/thumbnails/test-image_thumb.jpg');

    // Clean up
    \App\Models\Setting::where('key', 'custom_domain')->delete();
    cache()->forget('setting:custom_domain');
});

test('asset url falls back to s3 url when custom domain is empty', function () {
    \App\Models\Setting::set('custom_domain', '', 'string', 'aws');
    cache()->forget('setting:custom_domain');

    $asset = Asset::factory()->create(['s3_key' => 'assets/test.jpg']);

    $s3Url = config('filesystems.disks.s3.url');
    expect($asset->url)->toBe($s3Url.'/assets/test.jpg');

    // Clean up
    \App\Models\Setting::where('key', 'custom_domain')->delete();
    cache()->forget('setting:custom_domain');
});

test('asset resize url accessors return correct urls when keys are set', function () {
    $asset = Asset::factory()->create([
        'resize_s_s3_key' => 'thumbnails/S/test.jpg',
        'resize_m_s3_key' => 'thumbnails/M/test.jpg',
        'resize_l_s3_key' => 'thumbnails/L/test.jpg',
    ]);

    $baseUrl = \App\Services\S3Service::getPublicBaseUrl();
    expect($asset->resize_s_url)->toBe($baseUrl.'/thumbnails/S/test.jpg');
    expect($asset->resize_m_url)->toBe($baseUrl.'/thumbnails/M/test.jpg');
    expect($asset->resize_l_url)->toBe($baseUrl.'/thumbnails/L/test.jpg');
});

test('asset resize url accessors return null when keys are null', function () {
    $asset = Asset::factory()->create([
        'resize_s_s3_key' => null,
        'resize_m_s3_key' => null,
        'resize_l_s3_key' => null,
    ]);

    expect($asset->resize_s_url)->toBeNull();
    expect($asset->resize_m_url)->toBeNull();
    expect($asset->resize_l_url)->toBeNull();
});

test('asset search with exclude modifier removes matching assets', function () {
    Asset::factory()->create(['filename' => 'beach-sunset.jpg']);
    Asset::factory()->create(['filename' => 'beach-morning.jpg']);
    Asset::factory()->create(['filename' => 'mountain-view.jpg']);

    $results = Asset::search('beach -sunset')->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->filename)->toBe('beach-morning.jpg');
});

test('asset search with require modifier requires matching assets', function () {
    Asset::factory()->create(['filename' => 'beach-sunset.jpg', 'alt_text' => 'summer day']);
    Asset::factory()->create(['filename' => 'beach-morning.jpg', 'alt_text' => 'winter day']);
    Asset::factory()->create(['filename' => 'mountain-view.jpg', 'alt_text' => 'summer hike']);

    $results = Asset::search('+beach +summer')->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->filename)->toBe('beach-sunset.jpg');
});

test('asset search with mixed modifiers works correctly', function () {
    Asset::factory()->create(['filename' => 'beach-sunset.jpg', 'alt_text' => 'summer']);
    Asset::factory()->create(['filename' => 'beach-rain.jpg', 'alt_text' => 'summer']);
    Asset::factory()->create(['filename' => 'mountain-view.jpg', 'alt_text' => 'summer']);

    $results = Asset::search('+summer -rain')->get();

    expect($results)->toHaveCount(2);
    expect($results->pluck('filename')->toArray())->not->toContain('beach-rain.jpg');
});

test('asset search with bare plus or minus is ignored', function () {
    Asset::factory()->create(['filename' => 'test-image.jpg']);
    Asset::factory()->create(['filename' => 'other-file.pdf']);

    // Bare + and - should be ignored, "test" is a regular term
    $results = Asset::search('+ - test')->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->filename)->toBe('test-image.jpg');
});

test('asset search without modifiers works as before', function () {
    Asset::factory()->create(['filename' => 'test-image.jpg']);
    Asset::factory()->create(['filename' => 'document.pdf']);
    Asset::factory()->create(['filename' => 'another-test.png']);

    $results = Asset::search('test')->get();

    expect($results)->toHaveCount(2);
});

test('asset search exclude works on tags', function () {
    $tag = Tag::factory()->create(['name' => 'sunset']);

    $asset1 = Asset::factory()->create(['filename' => 'beach-photo.jpg']);
    $asset1->tags()->attach($tag);
    $asset2 = Asset::factory()->create(['filename' => 'beach-other.jpg']);

    $results = Asset::search('beach -sunset')->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->filename)->toBe('beach-other.jpg');
});

test('asset search require works on tags', function () {
    $tag = Tag::factory()->create(['name' => 'nature']);

    $asset1 = Asset::factory()->create(['filename' => 'beach-photo.jpg']);
    $asset1->tags()->attach($tag);
    $asset2 = Asset::factory()->create(['filename' => 'beach-other.jpg']);

    $results = Asset::search('+beach +nature')->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->filename)->toBe('beach-photo.jpg');
});

test('asset search exclude handles null alt_text and caption', function () {
    Asset::factory()->create(['filename' => 'photo.jpg', 'alt_text' => null, 'caption' => null]);
    Asset::factory()->create(['filename' => 'sunset.jpg', 'alt_text' => 'sunset view', 'caption' => null]);

    // Excluding "sunset" should keep the null alt_text asset
    $results = Asset::search('-sunset')->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->filename)->toBe('photo.jpg');
});

test('asset getIconColorClass returns correct color for different file types', function () {
    $pdfAsset = Asset::factory()->create(['mime_type' => 'application/pdf']);
    $wordAsset = Asset::factory()->create(['mime_type' => 'application/msword']);
    $videoAsset = Asset::factory()->create(['mime_type' => 'video/mp4']);
    $unknownAsset = Asset::factory()->create(['mime_type' => 'application/octet-stream', 'filename' => 'data.bin']);
    $epsAsset = Asset::factory()->create(['mime_type' => 'application/postscript']);

    expect($pdfAsset->getIconColorClass())->toBe('text-red-500');
    expect($wordAsset->getIconColorClass())->toBe('text-blue-600');
    expect($videoAsset->getIconColorClass())->toBe('text-pink-600');
    expect($unknownAsset->getIconColorClass())->toBe('text-gray-400');
    expect($epsAsset->getIconColorClass())->toBe('text-emerald-600');
});

test('asset licenseTypes returns all license types with labels', function () {
    $types = Asset::licenseTypes();

    expect($types)->toBeArray();
    expect($types)->toHaveCount(11);
    expect($types)->toHaveKeys([
        'public_domain', 'cc0', 'cc_by', 'cc_by_sa', 'cc_by_nd',
        'cc_by_nc', 'cc_by_nc_sa', 'cc_by_nc_nd', 'fair_use',
        'all_rights_reserved', 'other',
    ]);
});

test('asset getLicenseLabel returns translated label for license type', function () {
    $asset = Asset::factory()->create(['license_type' => 'cc_by']);
    expect($asset->getLicenseLabel())->toBe('CC BY (Attribution)');

    $asset2 = Asset::factory()->create(['license_type' => 'public_domain']);
    expect($asset2->getLicenseLabel())->toBe('Public Domain');

    $asset3 = Asset::factory()->create(['license_type' => null]);
    expect($asset3->getLicenseLabel())->toBe('');

    $asset4 = Asset::factory()->create(['license_type' => 'unknown_type']);
    expect($asset4->getLicenseLabel())->toBe('unknown_type');
});

// ─── folder accessor ─────────────────────────────────────────────────────────

test('folder accessor returns parent path for nested s3_key', function () {
    $asset = Asset::factory()->create(['s3_key' => 'assets/photos/img.jpg']);

    expect($asset->folder)->toBe('assets/photos');
});

test('folder accessor returns single segment path for top-level s3_key', function () {
    $asset = Asset::factory()->create(['s3_key' => 'assets/img.jpg']);

    expect($asset->folder)->toBe('assets');
});

test('folder accessor falls back to S3Service root folder for root-only key', function () {
    $asset = Asset::factory()->create(['s3_key' => 'img.jpg']);
    $expected = \App\Services\S3Service::getRootFolder();

    expect($asset->folder)->toBe($expected);
});

// ─── APPEND_FIELDS constant ───────────────────────────────────────────────────

test('APPEND_FIELDS constant contains exactly 7 expected field names', function () {
    expect(Asset::APPEND_FIELDS)->toBe([
        'url',
        'thumbnail_url',
        'resize_s_url',
        'resize_m_url',
        'resize_l_url',
        'formatted_size',
        'folder',
    ]);
});

// ─── thumbnail_url branches ───────────────────────────────────────────────────

test('thumbnail_url returns url for GIF without thumbnail_s3_key', function () {
    $asset = Asset::factory()->create([
        's3_key' => 'assets/anim.gif',
        'thumbnail_s3_key' => null,
        'mime_type' => 'image/gif',
    ]);

    expect($asset->thumbnail_url)->toBe($asset->url);
});

test('thumbnail_url returns null for non-GIF without thumbnail_s3_key', function () {
    $asset = Asset::factory()->create([
        's3_key' => 'assets/photo.jpg',
        'thumbnail_s3_key' => null,
        'mime_type' => 'image/jpeg',
    ]);

    expect($asset->thumbnail_url)->toBeNull();
});

// ─── SVG handling ─────────────────────────────────────────────────────────────

test('isSvg returns true for image/svg+xml mime type', function () {
    $asset = Asset::factory()->create(['mime_type' => 'image/svg+xml', 's3_key' => 'assets/icon.svg']);

    expect($asset->isSvg())->toBeTrue();
});

test('isSvg returns true for svg extension with non-standard mime type', function () {
    $asset = Asset::factory()->create(['mime_type' => 'text/plain', 's3_key' => 'assets/icon.svg']);

    expect($asset->isSvg())->toBeTrue();
});

test('isSvg returns false for regular image', function () {
    $asset = Asset::factory()->create(['mime_type' => 'image/png', 's3_key' => 'assets/photo.png']);

    expect($asset->isSvg())->toBeFalse();
});

test('isImage returns false for SVG mime type', function () {
    $asset = Asset::factory()->create(['mime_type' => 'image/svg+xml', 's3_key' => 'assets/icon.svg']);

    expect($asset->isImage())->toBeFalse();
});

test('isImage returns false for SVG extension with non-standard mime type', function () {
    $asset = Asset::factory()->create(['mime_type' => 'text/plain', 's3_key' => 'assets/icon.svg']);

    expect($asset->isImage())->toBeFalse();
});

test('thumbnail_url returns url for SVG without thumbnail_s3_key', function () {
    $asset = Asset::factory()->create([
        's3_key' => 'assets/icon.svg',
        'thumbnail_s3_key' => null,
        'mime_type' => 'image/svg+xml',
    ]);

    expect($asset->thumbnail_url)->toBe($asset->url);
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
