<?php

use App\Models\Setting;
use App\Models\User;
use Firebase\JWT\JWT;

beforeEach(function () {
    config(['jwt.enabled' => true]);
    config(['jwt.algorithm' => 'HS256']);
    config(['jwt.max_ttl' => 3600]);
    config(['jwt.leeway' => 60]);
});

test('no credentials returns 401', function () {
    $this->getJson('/api/assets')->assertUnauthorized();
});

test('malformed bearer token returns 401', function () {
    $this->withHeader('Authorization', 'Bearer not-a-real-token')
        ->getJson('/api/assets')
        ->assertUnauthorized();
});

test('jwt rejected when disabled via env even if setting override is on', function () {
    config(['jwt.enabled' => false]);
    Setting::set('jwt_enabled_override', true);

    $secret = str_repeat('a', 64);
    $user = User::factory()->create(['jwt_secret' => $secret]);
    $token = JWT::encode(
        ['sub' => $user->id, 'iat' => time(), 'exp' => time() + 3600],
        $secret,
        'HS256'
    );

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/assets')
        ->assertUnauthorized();
});

test('jwt rejected when disabled via setting even if env is on', function () {
    config(['jwt.enabled' => true]);
    Setting::set('jwt_enabled_override', false);

    $secret = str_repeat('a', 64);
    $user = User::factory()->create(['jwt_secret' => $secret]);
    $token = JWT::encode(
        ['sub' => $user->id, 'iat' => time(), 'exp' => time() + 3600],
        $secret,
        'HS256'
    );

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/assets')
        ->assertUnauthorized();
});

test('sanctum token works even when jwt is disabled', function () {
    config(['jwt.enabled' => false]);

    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/assets')
        ->assertOk();
});
