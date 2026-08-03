<?php

use App\Http\Controllers\ApiDocsController;
use App\Models\Asset;
use App\Models\Setting;
use App\Models\User;
use App\Services\S3Service;
use Illuminate\Http\UploadedFile;
use Tests\Security\Support\SourceScanner;

/**
 * REQ-7 of specs/features/security-invariants.md — the settings that open and close API surface
 * are tested on both sides of the branch.
 *
 * `api_meta_endpoint_enabled` and `api_upload_enabled` live in the database and are flipped from
 * /api-docs at runtime. That makes the exposure of an unauthenticated endpoint *operator state*,
 * not code: nothing in a diff shows that the public metadata endpoint is on, and until now
 * nothing in the suite showed that turning it off actually closes it.
 * specs/features/rest-api.md recorded both branches as untested; this closes that.
 *
 * The endpoint behaviour itself is owned by specs/features/rest-api.md REQ-5. What is asserted
 * here is the security property of the toggle: on means reachable without credentials, off means
 * closed to everyone.
 */

/**
 * The runtime settings that ApiDocsController is allowed to flip, and where each one is covered.
 *
 * Enumerated from the controller's own validation rule rather than hand-listed, so a new toggle
 * that opens surface fails this file until someone covers both of its states.
 *
 * @return array<string, string>
 */
function runtimeExposureToggleCoverage(): array
{
    return [
        'api_meta_endpoint_enabled' => 'Covered in this file — both states, unauthenticated.',
        'api_upload_enabled' => 'Covered in this file — both states, with a valid token.',
        'jwt_enabled_override' => 'Covered by tests/Feature/Middleware/AuthenticateMultipleTest.php, which drives the env/setting double gate (specs/features/jwt-auth.md REQ-1).',
    ];
}

/**
 * Pin the public asset base URL.
 *
 * `$asset->url` is derived from the configured S3/CDN base, which comes from `AWS_URL`. CI copies
 * `.env.example`, where it is **empty**, so relying on the ambient value made these tests pass
 * only on a machine with real AWS credentials in `.env` — they failed in CI with a 422, because
 * the resulting URL did not satisfy the endpoint's `url` validation rule. Pinning it here is what
 * `tests/Feature/ApiTest.php` already does for the same endpoint, for the same reason.
 */
beforeEach(function () {
    config(['filesystems.disks.s3.url' => 'https://orca-test.s3.amazonaws.com']);
});

/** The asset URL the public metadata endpoint resolves against. */
function metaUrlFor(Asset $asset): string
{
    return '/api/assets/meta?url='.urlencode($asset->url);
}

// ─── REQ-7: the toggle set is enumerated, not listed ──────────────────────────

test('every runtime setting that opens API surface is covered by a test', function () {
    $source = SourceScanner::sourceOf((new ReflectionClass(ApiDocsController::class))->getFileName());

    $matched = preg_match('/\'key\' => \'required\|string\|in:([^\']+)\'/', $source, $matches);

    expect($matched)->toBe(1,
        'Could not find the `key` validation rule in ApiDocsController::updateSettings. This test '
        .'reads that rule to discover which runtime toggles exist, so it must not be assumed '
        .'absent — confirm the toggles moved somewhere else, then update this test deliberately.'
    );

    $declared = explode(',', $matches[1]);
    sort($declared);

    $covered = array_keys(runtimeExposureToggleCoverage());
    sort($covered);

    expect($declared)->toBe($covered,
        'ApiDocsController can flip ['.implode(', ', $declared).'] but this suite covers ['
        .implode(', ', $covered).']. A runtime setting that opens an endpoint needs a test on '
        .'both sides of its branch, or nobody knows what turning it off actually does.'
    );
});

// ─── REQ-7: api_meta_endpoint_enabled ─────────────────────────────────────────

test('the public metadata endpoint serves an unauthenticated caller when enabled', function () {
    Setting::set('api_meta_endpoint_enabled', '1', 'boolean', 'api');

    $asset = Asset::factory()->create([
        'alt_text' => 'Enabled alt text',
        'caption' => 'Enabled caption',
    ]);

    $response = $this->getJson(metaUrlFor($asset));

    $response->assertOk();
    $response->assertJsonFragment(['alt_text' => 'Enabled alt text']);
    $this->assertGuest();
});

test('disabling the metadata endpoint closes it to unauthenticated callers', function () {
    Setting::set('api_meta_endpoint_enabled', '0', 'boolean', 'api');

    $asset = Asset::factory()->create([
        'alt_text' => 'Should not be visible',
        'caption' => 'Should not be visible either',
    ]);

    $response = $this->getJson(metaUrlFor($asset));

    $response->assertForbidden();

    // The kill switch has to actually withhold the data, not merely change the status line.
    expect($response->getContent())
        ->not->toContain('Should not be visible')
        ->not->toContain($asset->s3_key);
});

/**
 * The check sits at the top of AssetApiController::getMeta, before any auth consideration, so
 * the toggle is a kill switch rather than an authentication downgrade. Worth pinning: a future
 * refactor that moved the check behind an auth branch would leave the endpoint open to every
 * token holder while the setting read "off".
 */
test('disabling the metadata endpoint closes it for authenticated callers too', function (string $role) {
    Setting::set('api_meta_endpoint_enabled', '0', 'boolean', 'api');

    $asset = Asset::factory()->create(['alt_text' => 'Still not visible']);
    $user = User::factory()->create(['role' => $role]);

    $response = $this->actingAs($user)->getJson(metaUrlFor($asset));

    $response->assertForbidden();
    expect($response->getContent())->not->toContain('Still not visible');
})->with(['admin', 'editor', 'api']);

// ─── REQ-7: api_upload_enabled ────────────────────────────────────────────────

test('the API upload endpoint accepts an authenticated upload when enabled', function () {
    Setting::set('api_upload_enabled', '1', 'boolean', 'api');

    $s3 = Mockery::mock(S3Service::class);
    $s3->shouldReceive('uploadFile')->once()->andReturn([
        's3_key' => 'assets/toggle-on.jpg',
        'filename' => 'toggle-on.jpg',
        'mime_type' => 'image/jpeg',
        'size' => 1024,
        'etag' => 'etag-toggle-on',
        'width' => 10,
        'height' => 10,
    ]);
    $s3->shouldReceive('generateThumbnail')->andReturn(null);
    $s3->shouldReceive('generateResizedImages')->andReturn([]);
    $this->app->instance(S3Service::class, $s3);

    $response = $this->actingAs(User::factory()->apiUser()->create())->postJson('/api/assets', [
        'files' => [UploadedFile::fake()->image('toggle-on.jpg')],
    ]);

    expect($response->getStatusCode())->not->toBe(403);
    $this->assertDatabaseHas('assets', ['s3_key' => 'assets/toggle-on.jpg']);
});

test('disabling API uploads refuses an authenticated upload and stores nothing', function () {
    Setting::set('api_upload_enabled', '0', 'boolean', 'api');

    // No S3 mock on purpose: if the gate leaks, the real service is reached and the test fails
    // loudly rather than quietly recording a success against a stub.
    $response = $this->actingAs(User::factory()->apiUser()->create())->postJson('/api/assets', [
        'files' => [UploadedFile::fake()->image('toggle-off.jpg')],
    ]);

    $response->assertForbidden();
    $this->assertDatabaseMissing('assets', ['filename' => 'toggle-off.jpg']);
});

// ─── REQ-7: only an admin can flip these ──────────────────────────────────────

/**
 * The toggles are only a control if the set of people who can move them is the set of people who
 * are supposed to. `POST /api-docs/settings` sits behind `can:access,SystemController`.
 */
test('a non-admin cannot flip a runtime exposure toggle', function (string $role) {
    Setting::set('api_meta_endpoint_enabled', '0', 'boolean', 'api');

    $this->actingAs(User::factory()->create(['role' => $role]))
        ->postJson(route('api.settings.update'), [
            'key' => 'api_meta_endpoint_enabled',
            'value' => true,
        ])
        ->assertForbidden();

    expect(Setting::get('api_meta_endpoint_enabled'))->toBeFalse(
        "A {$role} re-opened the public metadata endpoint."
    );
})->with(['editor', 'api']);

test('an admin can flip a runtime exposure toggle and the endpoint follows', function () {
    Setting::set('api_meta_endpoint_enabled', '0', 'boolean', 'api');

    $asset = Asset::factory()->create(['alt_text' => 'Visible after re-enabling']);
    $admin = User::factory()->admin()->create();

    $this->getJson(metaUrlFor($asset))->assertForbidden();

    $this->actingAs($admin)->postJson(route('api.settings.update'), [
        'key' => 'api_meta_endpoint_enabled',
        'value' => true,
    ])->assertOk();

    $this->getJson(metaUrlFor($asset))
        ->assertOk()
        ->assertJsonFragment(['alt_text' => 'Visible after re-enabling']);
});

test('the settings endpoint refuses a key outside the declared toggle set', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->postJson(route('api.settings.update'), [
        'key' => 'maintenance_mode',
        'value' => true,
    ])->assertStatus(422);
});
