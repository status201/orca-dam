<?php

use App\Models\Asset;
use App\Models\Setting;
use App\Services\CloudflareService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

test('isEnabled returns false when disabled in config', function () {
    config()->set('cloudflare.enabled', false);
    config()->set('cloudflare.api_token', 'token');
    config()->set('cloudflare.zone_id', 'zone123');
    Setting::set('custom_domain', 'https://cdn.example.com');
    Setting::set('cloudflare_cache_purge', '1', 'boolean', 'aws');

    $service = new CloudflareService;

    expect($service->isEnabled())->toBeFalse();
});

test('isEnabled returns false when api_token is empty', function () {
    config()->set('cloudflare.enabled', true);
    config()->set('cloudflare.api_token', '');
    config()->set('cloudflare.zone_id', 'zone123');
    Setting::set('custom_domain', 'https://cdn.example.com');
    Setting::set('cloudflare_cache_purge', '1', 'boolean', 'aws');

    $service = new CloudflareService;

    expect($service->isEnabled())->toBeFalse();
});

test('isEnabled returns false when zone_id is empty', function () {
    config()->set('cloudflare.enabled', true);
    config()->set('cloudflare.api_token', 'token');
    config()->set('cloudflare.zone_id', '');
    Setting::set('custom_domain', 'https://cdn.example.com');
    Setting::set('cloudflare_cache_purge', '1', 'boolean', 'aws');

    $service = new CloudflareService;

    expect($service->isEnabled())->toBeFalse();
});

test('isEnabled returns false when custom_domain is empty', function () {
    config()->set('cloudflare.enabled', true);
    config()->set('cloudflare.api_token', 'test-token');
    config()->set('cloudflare.zone_id', 'zone123');
    Setting::set('custom_domain', '');
    Setting::set('cloudflare_cache_purge', '1', 'boolean', 'aws');

    $service = new CloudflareService;

    expect($service->isEnabled())->toBeFalse();
});

test('isEnabled returns false when cloudflare_cache_purge setting is off', function () {
    config()->set('cloudflare.enabled', true);
    config()->set('cloudflare.api_token', 'test-token');
    config()->set('cloudflare.zone_id', 'zone123');
    Setting::set('custom_domain', 'https://cdn.example.com');
    Setting::set('cloudflare_cache_purge', '0', 'boolean', 'aws');

    $service = new CloudflareService;

    expect($service->isEnabled())->toBeFalse();
});

test('isEnabled returns true when fully configured', function () {
    config()->set('cloudflare.enabled', true);
    config()->set('cloudflare.api_token', 'test-token');
    config()->set('cloudflare.zone_id', 'zone123');
    Setting::set('custom_domain', 'https://cdn.example.com');
    Setting::set('cloudflare_cache_purge', '1', 'boolean', 'aws');

    $service = new CloudflareService;

    expect($service->isEnabled())->toBeTrue();
});

test('purgeUrls returns false when disabled', function () {
    config()->set('cloudflare.enabled', false);

    Http::fake();
    $service = new CloudflareService;

    expect($service->purgeUrls(['https://example.com/file.jpg']))->toBeFalse();
    Http::assertNothingSent();
});

test('purgeUrls returns true for empty url list', function () {
    config()->set('cloudflare.enabled', true);
    config()->set('cloudflare.api_token', 'test-token');
    config()->set('cloudflare.zone_id', 'zone123');
    Setting::set('custom_domain', 'https://cdn.example.com');
    Setting::set('cloudflare_cache_purge', '1', 'boolean', 'aws');

    Http::fake();
    $service = new CloudflareService;

    expect($service->purgeUrls([]))->toBeTrue();
    Http::assertNothingSent();
});

test('purgeUrls sends correct request to Cloudflare API', function () {
    config()->set('cloudflare.enabled', true);
    config()->set('cloudflare.api_token', 'test-token');
    config()->set('cloudflare.zone_id', 'zone123');
    Setting::set('custom_domain', 'https://cdn.example.com');
    Setting::set('cloudflare_cache_purge', '1', 'boolean', 'aws');

    Http::fake([
        'api.cloudflare.com/*' => Http::response(['success' => true, 'errors' => [], 'messages' => []], 200),
    ]);

    $service = new CloudflareService;
    $urls = [
        'https://cdn.example.com/assets/test.jpg',
        'https://cdn.example.com/thumbnails/test_thumb.jpg',
    ];

    $result = $service->purgeUrls($urls);

    expect($result)->toBeTrue();

    Http::assertSent(function ($request) use ($urls) {
        return $request->url() === 'https://api.cloudflare.com/client/v4/zones/zone123/purge_cache'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['files'] === $urls;
    });
});

test('purgeUrls returns false and logs error on API failure', function () {
    config()->set('cloudflare.enabled', true);
    config()->set('cloudflare.api_token', 'test-token');
    config()->set('cloudflare.zone_id', 'zone123');
    Setting::set('custom_domain', 'https://cdn.example.com');
    Setting::set('cloudflare_cache_purge', '1', 'boolean', 'aws');

    Http::fake([
        'api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => ['Unauthorized']], 403),
    ]);

    Log::shouldReceive('error')->once()->withArgs(function ($message) {
        return str_contains($message, 'Cloudflare cache purge failed');
    });

    $service = new CloudflareService;
    $result = $service->purgeUrls(['https://cdn.example.com/assets/test.jpg']);

    expect($result)->toBeFalse();
});

test('purgeUrls returns false and logs error on exception', function () {
    config()->set('cloudflare.enabled', true);
    config()->set('cloudflare.api_token', 'test-token');
    config()->set('cloudflare.zone_id', 'zone123');
    Setting::set('custom_domain', 'https://cdn.example.com');
    Setting::set('cloudflare_cache_purge', '1', 'boolean', 'aws');

    Http::fake(function () {
        throw new Exception('Connection timeout');
    });

    Log::shouldReceive('error')->once()->withArgs(function ($message) {
        return str_contains($message, 'Connection timeout');
    });

    $service = new CloudflareService;
    $result = $service->purgeUrls(['https://cdn.example.com/assets/test.jpg']);

    expect($result)->toBeFalse();
});

test('purgeUrls filters out empty and null values', function () {
    config()->set('cloudflare.enabled', true);
    config()->set('cloudflare.api_token', 'test-token');
    config()->set('cloudflare.zone_id', 'zone123');
    Setting::set('custom_domain', 'https://cdn.example.com');
    Setting::set('cloudflare_cache_purge', '1', 'boolean', 'aws');

    Http::fake([
        'api.cloudflare.com/*' => Http::response(['success' => true, 'errors' => [], 'messages' => []], 200),
    ]);

    $service = new CloudflareService;
    $result = $service->purgeUrls([
        'https://cdn.example.com/assets/test.jpg',
        null,
        '',
        'https://cdn.example.com/thumbnails/test_thumb.jpg',
    ]);

    expect($result)->toBeTrue();

    Http::assertSent(function ($request) {
        return count($request['files']) === 2
            && $request['files'][0] === 'https://cdn.example.com/assets/test.jpg'
            && $request['files'][1] === 'https://cdn.example.com/thumbnails/test_thumb.jpg';
    });
});

test('collectAssetUrls returns empty array when disabled', function () {
    config()->set('cloudflare.enabled', false);

    $asset = Asset::factory()->create(['s3_key' => 'assets/test.jpg']);
    $service = new CloudflareService;

    expect($service->collectAssetUrls($asset))->toBe([]);
});

test('collectAssetUrls returns only original url when no variants exist', function () {
    config()->set('cloudflare.enabled', true);
    config()->set('cloudflare.api_token', 'test-token');
    config()->set('cloudflare.zone_id', 'zone123');
    Setting::set('custom_domain', 'https://cdn.example.com');
    Setting::set('cloudflare_cache_purge', '1', 'boolean', 'aws');

    $asset = Asset::factory()->create([
        's3_key' => 'assets/test.jpg',
        'thumbnail_s3_key' => null,
        'resize_s_s3_key' => null,
        'resize_m_s3_key' => null,
        'resize_l_s3_key' => null,
    ]);

    $service = new CloudflareService;
    $urls = $service->collectAssetUrls($asset);

    expect($urls)->toHaveCount(1);
    expect($urls[0])->toEndWith('assets/test.jpg');
});

test('collectAssetUrls returns all urls when all variants exist', function () {
    config()->set('cloudflare.enabled', true);
    config()->set('cloudflare.api_token', 'test-token');
    config()->set('cloudflare.zone_id', 'zone123');
    Setting::set('custom_domain', 'https://cdn.example.com');
    Setting::set('cloudflare_cache_purge', '1', 'boolean', 'aws');

    $asset = Asset::factory()->create([
        's3_key' => 'assets/test.jpg',
        'thumbnail_s3_key' => 'thumbnails/test_thumb.jpg',
        'resize_s_s3_key' => 'thumbnails/S/test.jpg',
        'resize_m_s3_key' => 'thumbnails/M/test.jpg',
        'resize_l_s3_key' => 'thumbnails/L/test.jpg',
    ]);

    $service = new CloudflareService;
    $urls = $service->collectAssetUrls($asset);

    expect($urls)->toHaveCount(5);
    expect($urls[0])->toEndWith('assets/test.jpg');
    expect($urls[1])->toEndWith('thumbnails/test_thumb.jpg');
    expect($urls[2])->toEndWith('thumbnails/S/test.jpg');
    expect($urls[3])->toEndWith('thumbnails/M/test.jpg');
    expect($urls[4])->toEndWith('thumbnails/L/test.jpg');
});
