<?php

use App\Models\Asset;
use App\Models\User;
use Firebase\JWT\JWT;

beforeEach(function () {
    config(['jwt.enabled' => true]);
    config(['jwt.algorithm' => 'HS256']);
    config(['jwt.max_ttl' => 3600]);
    config(['jwt.leeway' => 60]);
});

test('api endpoint accepts valid jwt authentication', function () {
    $secret = str_repeat('a', 64);
    $user = User::factory()->create(['jwt_secret' => $secret]);

    $token = JWT::encode([
        'sub' => $user->id,
        'iat' => time(),
        'exp' => time() + 3600,
    ], $secret, 'HS256');

    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/assets');

    $response->assertOk();
});

test('api endpoint rejects expired jwt', function () {
    $secret = str_repeat('a', 64);
    $user = User::factory()->create(['jwt_secret' => $secret]);

    $token = JWT::encode([
        'sub' => $user->id,
        'iat' => time() - 7200,
        'exp' => time() - 3600, // Expired
    ], $secret, 'HS256');

    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/assets');

    $response->assertUnauthorized();
});

test('api endpoint rejects jwt with invalid signature', function () {
    $userSecret = str_repeat('a', 64);
    $wrongSecret = str_repeat('b', 64);

    $user = User::factory()->create(['jwt_secret' => $userSecret]);

    $token = JWT::encode([
        'sub' => $user->id,
        'iat' => time(),
        'exp' => time() + 3600,
    ], $wrongSecret, 'HS256');

    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/assets');

    $response->assertUnauthorized();
});

test('sanctum tokens still work when jwt is enabled', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test-token')->plainTextToken;

    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/assets');

    $response->assertOk();
});

test('jwt auth returns correct user in authenticated requests', function () {
    $secret = str_repeat('a', 64);
    $user = User::factory()->create(['jwt_secret' => $secret, 'role' => 'editor']);

    $token = JWT::encode([
        'sub' => $user->id,
        'iat' => time(),
        'exp' => time() + 3600,
    ], $secret, 'HS256');

    // Create an asset belonging to this user
    $asset = Asset::factory()->create(['user_id' => $user->id]);

    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/assets/'.$asset->id);

    $response->assertOk();
    $response->assertJsonPath('id', $asset->id);
});

test('jwt authentication respects user roles', function () {
    $secret = str_repeat('a', 64);
    $apiUser = User::factory()->create([
        'jwt_secret' => $secret,
        'role' => 'api',
    ]);

    $token = JWT::encode([
        'sub' => $apiUser->id,
        'iat' => time(),
        'exp' => time() + 3600,
    ], $secret, 'HS256');

    // API users cannot delete assets
    $asset = Asset::factory()->create();

    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->deleteJson('/api/assets/'.$asset->id);

    $response->assertForbidden();
});

test('jwt auth disabled returns 401 for jwt tokens', function () {
    config(['jwt.enabled' => false]);

    $secret = str_repeat('a', 64);
    $user = User::factory()->create(['jwt_secret' => $secret]);

    $token = JWT::encode([
        'sub' => $user->id,
        'iat' => time(),
        'exp' => time() + 3600,
    ], $secret, 'HS256');

    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/assets');

    $response->assertUnauthorized();
});

test('chunked upload endpoints accept jwt authentication', function () {
    $secret = str_repeat('a', 64);
    $user = User::factory()->create(['jwt_secret' => $secret]);

    $token = JWT::encode([
        'sub' => $user->id,
        'iat' => time(),
        'exp' => time() + 3600,
    ], $secret, 'HS256');

    // Just test that the endpoint is accessible with JWT auth
    // The actual upload would require more setup
    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/chunked-upload/init', [
            'filename' => 'test.jpg',
            'size' => 1024,
            'mime_type' => 'image/jpeg',
        ]);

    // Should not be 401 (might be 422 for validation errors, which is fine)
    expect($response->status())->not->toBe(401);
});
