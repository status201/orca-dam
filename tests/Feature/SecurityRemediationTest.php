<?php

use App\Models\Asset;
use App\Models\Setting;
use App\Models\User;
use App\Services\S3Service;
use Illuminate\Http\UploadedFile;

// ─── F1: upload type allowlist ────────────────────────────────────────────────

test('store rejects a disallowed file extension', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('assets.store'), [
        'files' => [UploadedFile::fake()->create('malware.exe', 10)],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('files.0');
});

test('store rejects a php upload disguised by extension', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('assets.store'), [
        'files' => [UploadedFile::fake()->create('shell.php', 10)],
    ]);

    $response->assertStatus(422);
});

test('store accepts an allowlisted svg upload', function () {
    $user = User::factory()->create();

    $s3Service = Mockery::mock(S3Service::class);
    $s3Service->shouldReceive('uploadFile')->once()->andReturn([
        's3_key' => 'assets/vector.svg',
        'filename' => 'vector.svg',
        'mime_type' => 'image/svg+xml',
        'size' => 1000,
        'etag' => 'etag-svg',
        'width' => null,
        'height' => null,
    ]);
    $s3Service->shouldReceive('generateThumbnail')->andReturn(null);
    $s3Service->shouldReceive('generateResizedImages')->andReturn([]);
    $this->app->instance(S3Service::class, $s3Service);

    $response = $this->actingAs($user)->postJson(route('assets.store'), [
        'files' => [UploadedFile::fake()->create('vector.svg', 5)],
    ]);

    $response->assertStatus(200);
});

test('chunked upload init rejects a disallowed extension', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('chunked-upload.init'), [
        'filename' => 'archive.exe',
        'mime_type' => 'application/octet-stream',
        'file_size' => 20 * 1024 * 1024,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('filename');
});

// ─── F5/F9: security headers + safe download ──────────────────────────────────

test('web responses carry baseline security headers', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('assets.index'));

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN');
    expect($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
});

test('asset download forces attachment and nosniff', function () {
    $user = User::factory()->create();
    $asset = Asset::factory()->image()->create(['filename' => 'photo.jpg']);

    $s3Service = Mockery::mock(S3Service::class);
    $s3Service->shouldReceive('getObjectContent')->once()->andReturn('binary-data');
    $this->app->instance(S3Service::class, $s3Service);

    $response = $this->actingAs($user)->get(route('assets.download', $asset));

    $response->assertOk();
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($response->headers->get('Content-Disposition'))->toContain('attachment');
});

// ─── F3: role-aware error detail ──────────────────────────────────────────────

test('api-role users get a generic replace error while editors see detail', function () {
    $asset = Asset::factory()->image()->create(['filename' => 'original.jpg']);

    $s3Service = Mockery::mock(S3Service::class);
    $s3Service->shouldReceive('replaceFile')->andThrow(new Exception('s3://secret-bucket detail'));
    $this->app->instance(S3Service::class, $s3Service);

    $apiUser = User::factory()->create(['role' => 'api']);
    $apiResponse = $this->actingAs($apiUser)->postJson(route('assets.replace.store', $asset), [
        'file' => UploadedFile::fake()->image('original.jpg'),
    ]);
    $apiResponse->assertStatus(500);
    expect($apiResponse->json('message'))->toBe('Failed to replace asset.');
    expect($apiResponse->json('message'))->not->toContain('secret-bucket');

    $editor = User::factory()->create(['role' => 'editor']);
    $editorResponse = $this->actingAs($editor)->postJson(route('assets.replace.store', $asset), [
        'file' => UploadedFile::fake()->image('original.jpg'),
    ]);
    $editorResponse->assertStatus(500);
    expect($editorResponse->json('message'))->toContain('secret-bucket');
});

// ─── F8: CSP frame-ancestors validation ───────────────────────────────────────

test('embed CSP ignores malformed domains and keeps valid ones', function () {
    $this->actingAs(User::factory()->create(['role' => 'admin']));

    Setting::set('embed_allowed_domains', [
        'https://good.example.com',
        "evil.com'; script-src *",
        'bad domain with spaces',
    ]);

    $response = $this->get(route('assets.index'));
    $csp = $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain('https://good.example.com');
    expect($csp)->not->toContain('script-src');
    expect($csp)->not->toContain('bad domain');
});

// ─── F4/F6: rate limiting present on heavy/public routes ──────────────────────

test('heavy and public routes declare throttle middleware', function () {
    $routes = app('router')->getRoutes();

    $hasThrottle = fn (string $name) => collect($routes->getByName($name)?->gatherMiddleware() ?? [])
        ->contains(fn ($m) => str_starts_with($m, 'throttle'));

    expect($hasThrottle('assets.bulk.download'))->toBeTrue();
    expect($hasThrottle('assets.ai-tag'))->toBeTrue();
    expect($hasThrottle('tools.tikz-server.render'))->toBeTrue();
});
