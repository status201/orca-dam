<?php

use App\Models\Asset;
use App\Models\Setting;
use App\Services\FolderRegistry;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

test('forPicker returns root folder array when no s3_folders configured', function () {
    Setting::set('s3_root_folder', 'assets', 'string', 'aws');
    Cache::flush();

    $folders = app(FolderRegistry::class)->forPicker();

    expect($folders)->toBe(['assets']);
});

test('forPicker returns configured folders from s3_folders setting', function () {
    Setting::set('s3_root_folder', 'assets', 'string', 'aws');
    Setting::set('s3_folders', ['assets', 'assets/marketing', 'assets/photos'], 'json', 'aws');
    Cache::flush();

    $folders = app(FolderRegistry::class)->forPicker();

    expect($folders)->toBe(['assets', 'assets/marketing', 'assets/photos']);
});

test('forPicker prepends empty string when root folder is empty and not in list', function () {
    Setting::set('s3_root_folder', '', 'string', 'aws');
    Setting::set('s3_folders', ['marketing', 'photos'], 'json', 'aws');
    Cache::flush();

    $folders = app(FolderRegistry::class)->forPicker();

    expect($folders[0])->toBe('');
    expect($folders)->toContain('marketing');
    expect($folders)->toContain('photos');
});

test('forPicker returns root folder array when s3_folders is empty', function () {
    Setting::set('s3_root_folder', 'uploads', 'string', 'aws');
    Setting::set('s3_folders', [], 'json', 'aws');
    Cache::flush();

    $folders = app(FolderRegistry::class)->forPicker();

    expect($folders)->toBe(['uploads']);
});

test('forPicker drops a stale empty entry and always offers the configured root', function () {
    Setting::set('s3_root_folder', 'assets', 'string', 'aws');
    Setting::set('s3_folders', ['', 'assets/marketing'], 'json', 'aws');

    expect(app(FolderRegistry::class)->forPicker())->toBe(['assets', 'assets/marketing']);
});

test('cachedOrDerived derives the list from asset folders once and persists it', function () {
    Setting::set('s3_root_folder', 'assets', 'string', 'aws');
    Asset::factory()->create(['s3_key' => 'assets/photos/a.jpg']);
    Asset::factory()->create(['s3_key' => 'assets/photos/b.jpg']);

    expect(app(FolderRegistry::class)->cachedOrDerived())->toBe(['assets', 'assets/photos'])
        ->and(Setting::get('s3_folders'))->toBe(['assets', 'assets/photos']);
});

test('add keeps the list sorted and free of duplicates; forget drops it', function () {
    $registry = app(FolderRegistry::class);
    $registry->replace(['assets', 'assets/z']);

    $registry->add('assets/a');
    $registry->add('assets/a');
    expect(Setting::get('s3_folders'))->toBe(['assets', 'assets/a', 'assets/z']);

    $registry->forget();
    expect(Setting::get('s3_folders', 'gone'))->toBe('gone');
});
