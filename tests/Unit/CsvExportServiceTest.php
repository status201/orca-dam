<?php

use App\Models\Asset;
use App\Models\Tag;
use App\Models\User;
use App\Services\CsvExportService;

// ─── generateHeaders() ───────────────────────────────────────────────────────

test('generateHeaders returns exactly 34 items', function () {
    $service = new CsvExportService;

    expect($service->generateHeaders())->toHaveCount(33);
});

test('generateHeaders contains all expected column names', function () {
    $service = new CsvExportService;
    $headers = $service->generateHeaders();

    foreach ([
        'id', 's3_key', 'url', 'thumbnail_url', 'resize_s_url',
        'user_tags', 'ai_tags', 'reference_tags', 'license_expiry_date',
        'filename', 'mime_type', 'size', 'etag', 'width', 'height',
        'thumbnail_s3_key', 'resize_s_s3_key', 'resize_m_s3_key', 'resize_l_s3_key',
        'alt_text', 'caption', 'license_type', 'copyright', 'copyright_source',
        'user_id', 'user_name', 'user_email', 'created_at', 'updated_at',
    ] as $expected) {
        expect($headers)->toContain($expected);
    }
});

test('generateHeaders has no duplicate columns', function () {
    $service = new CsvExportService;
    $headers = $service->generateHeaders();

    expect(count($headers))->toBe(count(array_unique($headers)));
});

// ─── formatRow() ─────────────────────────────────────────────────────────────

test('formatRow returns exactly 34 values', function () {
    $user = User::factory()->create();
    $asset = Asset::factory()->create(['user_id' => $user->id]);
    $asset->load('tags', 'user');

    $service = new CsvExportService;

    expect($service->formatRow($asset))->toHaveCount(33);
});

test('formatRow separates user tags, ai tags, and reference tags', function () {
    $user = User::factory()->create();
    $asset = Asset::factory()->create(['user_id' => $user->id]);
    $userTag = Tag::factory()->user()->create(['name' => 'nature']);
    $aiTag = Tag::factory()->ai()->create(['name' => 'sky']);
    $refTag = Tag::factory()->reference()->create(['name' => 'ref-1']);
    $asset->tags()->attach([$userTag->id, $aiTag->id, $refTag->id]);
    $asset->load('tags', 'user');

    $service = new CsvExportService;
    $headers = $service->generateHeaders();
    $row = $service->formatRow($asset);
    $map = array_combine($headers, $row);

    expect($map['user_tags'])->toBe('nature');
    expect($map['ai_tags'])->toBe('sky');
    expect($map['reference_tags'])->toBe('ref-1');
});

test('formatRow returns empty strings for all tag columns when asset has no tags', function () {
    $user = User::factory()->create();
    $asset = Asset::factory()->create(['user_id' => $user->id]);
    $asset->load('tags', 'user');

    $service = new CsvExportService;
    $headers = $service->generateHeaders();
    $row = $service->formatRow($asset);
    $map = array_combine($headers, $row);

    expect($map['user_tags'])->toBe('');
    expect($map['ai_tags'])->toBe('');
    expect($map['reference_tags'])->toBe('');
});

test('formatRow returns null for null license_expiry_date', function () {
    $user = User::factory()->create();
    $asset = Asset::factory()->create(['user_id' => $user->id, 'license_expiry_date' => null]);
    $asset->load('tags', 'user');

    $service = new CsvExportService;
    $headers = $service->generateHeaders();
    $row = $service->formatRow($asset);
    $map = array_combine($headers, $row);

    expect($map['license_expiry_date'])->toBeNull();
});

test('formatRow formats license_expiry_date as Y-m-d string', function () {
    $user = User::factory()->create();
    $asset = Asset::factory()->create(['user_id' => $user->id, 'license_expiry_date' => '2027-01-15']);
    $asset->load('tags', 'user');

    $service = new CsvExportService;
    $headers = $service->generateHeaders();
    $row = $service->formatRow($asset);
    $map = array_combine($headers, $row);

    expect($map['license_expiry_date'])->toBe('2027-01-15');
});

test('formatRow returns empty strings for user_name and user_email when user relation is null', function () {
    $user = User::factory()->create();
    $asset = Asset::factory()->create(['user_id' => $user->id]);
    $asset->load('tags');
    // Manually unset the user relation to simulate a missing user
    $asset->setRelation('user', null);

    $service = new CsvExportService;
    $headers = $service->generateHeaders();
    $row = $service->formatRow($asset);
    $map = array_combine($headers, $row);

    expect($map['user_name'])->toBe('');
    expect($map['user_email'])->toBe('');
});

test('formatRow url column is a non-empty string', function () {
    $user = User::factory()->create();
    $asset = Asset::factory()->create(['user_id' => $user->id, 's3_key' => 'assets/photo.jpg']);
    $asset->load('tags', 'user');

    $service = new CsvExportService;
    $headers = $service->generateHeaders();
    $row = $service->formatRow($asset);
    $map = array_combine($headers, $row);

    expect($map['url'])->toBeString()->not->toBeEmpty();
});
