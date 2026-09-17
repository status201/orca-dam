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

test('api-role users cannot replace while editors see detailed replace errors', function () {
    $asset = Asset::factory()->image()->create(['filename' => 'original.jpg']);

    $s3Service = Mockery::mock(S3Service::class);
    $s3Service->shouldReceive('replaceFile')->andThrow(new Exception('s3://secret-bucket detail'));
    $this->app->instance(S3Service::class, $s3Service);

    // api-role is blocked from replace entirely (AssetPolicy::replace), so it can
    // never reach — let alone see the internal detail of — a replace error.
    $apiUser = User::factory()->create(['role' => 'api']);
    $apiResponse = $this->actingAs($apiUser)->postJson(route('assets.replace.store', $asset), [
        'file' => UploadedFile::fake()->image('original.jpg'),
    ]);
    $apiResponse->assertForbidden();

    // editors can replace and, when it fails, see the underlying detail (clientError).
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

test('heavy and public routes declare their own named throttle limiter', function () {
    $routes = app('router')->getRoutes();

    $middleware = fn (string $name) => $routes->getByName($name)?->gatherMiddleware() ?? [];

    // A bare `throttle:N,1` would share one per-user counter across all of these (upload-policy REQ-7).
    expect($middleware('assets.bulk.download'))->toContain('throttle:bulk-download');
    expect($middleware('assets.ai-tag'))->toContain('throttle:ai-tag');
    expect($middleware('tools.tikz-server.render'))->toContain('throttle:tikz-render');

    foreach (['init', 'chunk', 'complete', 'abort'] as $step) {
        expect($middleware("chunked-upload.{$step}"))->toContain('throttle:chunked-upload');
    }
});

test('tikz renders and ai tagging do not spend the bulk-download budget', function () {
    $editor = User::factory()->create(['role' => 'editor']);
    $asset = Asset::factory()->pdf()->create();

    // Invalid payloads still count: the throttle runs before validation. Twenty hits is the
    // whole bulk-download budget, so on a shared counter the next bulk download would be a 429.
    for ($i = 0; $i < 20; $i++) {
        $this->actingAs($editor)->postJson(route('tools.tikz-server.render'), [])->assertStatus(422);
    }
    $this->actingAs($editor)->postJson(route('assets.bulk.download'), [])->assertStatus(422);

    for ($i = 0; $i < 20; $i++) {
        expect($this->actingAs($editor)->post(route('assets.ai-tag', $asset))->status())->not->toBe(429);
    }
    $this->actingAs($editor)->postJson(route('assets.bulk.download'), [])->assertStatus(422);
});

test('tikz render answers 429 with Retry-After once the configured limit is spent', function () {
    config(['tikz.render_rate_limit' => 2]);
    $editor = User::factory()->create(['role' => 'editor']);

    $this->actingAs($editor)->postJson(route('tools.tikz-server.render'), [])->assertStatus(422);
    $this->actingAs($editor)->postJson(route('tools.tikz-server.render'), [])->assertStatus(422);

    $response = $this->actingAs($editor)->postJson(route('tools.tikz-server.render'), []);

    $response->assertStatus(429);
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});

test('tikz render limit defaults to 60 per minute', function () {
    expect(config('tikz.render_rate_limit'))->toBe(60);
});
