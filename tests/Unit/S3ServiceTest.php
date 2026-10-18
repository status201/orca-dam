<?php

use App\Models\Setting;
use App\Services\S3Service;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

test('getRootFolder trims whitespace and slashes', function () {
    Setting::set('s3_root_folder', '  /assets/  ', 'string', 'aws');
    Cache::flush();

    expect(S3Service::getRootFolder())->toBe('assets');
});

test('getRootFolder returns empty string for bucket root', function () {
    Setting::set('s3_root_folder', '', 'string', 'aws');
    Cache::flush();

    expect(S3Service::getRootFolder())->toBe('');
});

test('getRootPrefix adds trailing slash or returns empty string', function () {
    Setting::set('s3_root_folder', 'assets', 'string', 'aws');
    Cache::flush();
    expect(S3Service::getRootPrefix())->toBe('assets/');

    Setting::set('s3_root_folder', '', 'string', 'aws');
    Cache::flush();
    expect(S3Service::getRootPrefix())->toBe('');
});

test('getPublicBaseUrl returns custom_domain when set', function () {
    Setting::set('custom_domain', 'https://cdn.example.com', 'string', 'aws');
    Cache::flush();

    expect(S3Service::getPublicBaseUrl())->toBe('https://cdn.example.com');
});

test('getPublicBaseUrl strips trailing slash from custom_domain', function () {
    Setting::set('custom_domain', 'https://cdn.example.com/', 'string', 'aws');
    Cache::flush();

    expect(S3Service::getPublicBaseUrl())->toBe('https://cdn.example.com');
});

test('getPublicBaseUrl falls back to S3 bucket URL when custom_domain empty', function () {
    Setting::set('custom_domain', '', 'string', 'aws');
    Cache::flush();
    config(['filesystems.disks.s3.url' => 'https://bucket.s3.amazonaws.com']);

    expect(S3Service::getPublicBaseUrl())->toBe('https://bucket.s3.amazonaws.com');
});
