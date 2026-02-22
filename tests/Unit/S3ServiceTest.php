<?php

use App\Models\Setting;
use App\Services\S3Service;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

test('getConfiguredFolders returns root folder array when no s3_folders configured', function () {
    Setting::set('s3_root_folder', 'assets', 'string', 'aws');
    Cache::flush();

    $folders = S3Service::getConfiguredFolders();

    expect($folders)->toBe(['assets']);
});

test('getConfiguredFolders returns configured folders from s3_folders setting', function () {
    Setting::set('s3_root_folder', 'assets', 'string', 'aws');
    Setting::set('s3_folders', ['assets', 'assets/marketing', 'assets/photos'], 'json', 'aws');
    Cache::flush();

    $folders = S3Service::getConfiguredFolders();

    expect($folders)->toBe(['assets', 'assets/marketing', 'assets/photos']);
});

test('getConfiguredFolders prepends empty string when root folder is empty and not in list', function () {
    Setting::set('s3_root_folder', '', 'string', 'aws');
    Setting::set('s3_folders', ['marketing', 'photos'], 'json', 'aws');
    Cache::flush();

    $folders = S3Service::getConfiguredFolders();

    expect($folders[0])->toBe('');
    expect($folders)->toContain('marketing');
    expect($folders)->toContain('photos');
});

test('getConfiguredFolders returns root folder array when s3_folders is empty', function () {
    Setting::set('s3_root_folder', 'uploads', 'string', 'aws');
    Setting::set('s3_folders', [], 'json', 'aws');
    Cache::flush();

    $folders = S3Service::getConfiguredFolders();

    expect($folders)->toBe(['uploads']);
});
