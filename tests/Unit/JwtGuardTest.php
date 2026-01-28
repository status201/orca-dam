<?php

use App\Auth\JwtGuard;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    config(['jwt.enabled' => true]);
    config(['jwt.algorithm' => 'HS256']);
    config(['jwt.max_ttl' => 3600]);
    config(['jwt.leeway' => 60]);
    config(['jwt.issuer' => null]);
    config(['jwt.required_claims' => ['sub', 'exp', 'iat']]);
});

function createJwtGuard(Request $request): JwtGuard
{
    return new JwtGuard(
        Auth::createUserProvider('users'),
        $request
    );
}

test('jwt guard returns null for missing authorization header', function () {
    $request = Request::create('/api/assets', 'GET');

    $guard = createJwtGuard($request);

    expect($guard->user())->toBeNull();
});

test('jwt guard returns null for invalid token format', function () {
    $request = Request::create('/api/assets', 'GET');
    $request->headers->set('Authorization', 'Bearer invalid-token');

    $guard = createJwtGuard($request);

    expect($guard->user())->toBeNull();
});

test('jwt guard returns null for unknown user', function () {
    $secret = str_repeat('a', 64);
    $token = JWT::encode([
        'sub' => 99999,
        'iat' => time(),
        'exp' => time() + 3600,
    ], $secret, 'HS256');

    $request = Request::create('/api/assets', 'GET');
    $request->headers->set('Authorization', 'Bearer ' . $token);

    $guard = createJwtGuard($request);

    expect($guard->user())->toBeNull();
});

test('jwt guard returns null for user without jwt secret', function () {
    $user = User::factory()->create(['jwt_secret' => null]);
    $secret = str_repeat('a', 64);

    $token = JWT::encode([
        'sub' => $user->id,
        'iat' => time(),
        'exp' => time() + 3600,
    ], $secret, 'HS256');

    $request = Request::create('/api/assets', 'GET');
    $request->headers->set('Authorization', 'Bearer ' . $token);

    $guard = createJwtGuard($request);

    expect($guard->user())->toBeNull();
});

test('jwt guard returns null for expired token', function () {
    $secret = str_repeat('a', 64);
    $user = User::factory()->create(['jwt_secret' => $secret]);

    $token = JWT::encode([
        'sub' => $user->id,
        'iat' => time() - 7200,
        'exp' => time() - 3600, // Expired 1 hour ago
    ], $secret, 'HS256');

    $request = Request::create('/api/assets', 'GET');
    $request->headers->set('Authorization', 'Bearer ' . $token);

    $guard = createJwtGuard($request);

    expect($guard->user())->toBeNull();
});

test('jwt guard returns null for invalid signature', function () {
    $userSecret = str_repeat('a', 64);
    $wrongSecret = str_repeat('b', 64);

    $user = User::factory()->create(['jwt_secret' => $userSecret]);

    // Sign with wrong secret
    $token = JWT::encode([
        'sub' => $user->id,
        'iat' => time(),
        'exp' => time() + 3600,
    ], $wrongSecret, 'HS256');

    $request = Request::create('/api/assets', 'GET');
    $request->headers->set('Authorization', 'Bearer ' . $token);

    $guard = createJwtGuard($request);

    expect($guard->user())->toBeNull();
});

test('jwt guard returns user for valid token', function () {
    $secret = str_repeat('a', 64);
    $user = User::factory()->create(['jwt_secret' => $secret]);

    $token = JWT::encode([
        'sub' => $user->id,
        'iat' => time(),
        'exp' => time() + 3600,
    ], $secret, 'HS256');

    $request = Request::create('/api/assets', 'GET');
    $request->headers->set('Authorization', 'Bearer ' . $token);

    $guard = createJwtGuard($request);

    $result = $guard->user();
    expect($result)->not->toBeNull();
    expect($result->id)->toBe($user->id);
});

test('jwt guard returns null when token exceeds max ttl', function () {
    config(['jwt.max_ttl' => 1800]); // 30 minutes

    $secret = str_repeat('a', 64);
    $user = User::factory()->create(['jwt_secret' => $secret]);

    // Token issued 2 hours ago but not yet expired
    $token = JWT::encode([
        'sub' => $user->id,
        'iat' => time() - 7200, // 2 hours ago
        'exp' => time() + 3600, // Still valid for 1 hour
    ], $secret, 'HS256');

    $request = Request::create('/api/assets', 'GET');
    $request->headers->set('Authorization', 'Bearer ' . $token);

    $guard = createJwtGuard($request);

    expect($guard->user())->toBeNull();
});

test('jwt guard validates issuer when configured', function () {
    config(['jwt.issuer' => 'https://trusted-app.com']);

    $secret = str_repeat('a', 64);
    $user = User::factory()->create(['jwt_secret' => $secret]);

    // Token with wrong issuer
    $token = JWT::encode([
        'sub' => $user->id,
        'iat' => time(),
        'exp' => time() + 3600,
        'iss' => 'https://wrong-app.com',
    ], $secret, 'HS256');

    $request = Request::create('/api/assets', 'GET');
    $request->headers->set('Authorization', 'Bearer ' . $token);

    $guard = createJwtGuard($request);

    expect($guard->user())->toBeNull();
});

test('jwt guard accepts token with correct issuer', function () {
    config(['jwt.issuer' => 'https://trusted-app.com']);

    $secret = str_repeat('a', 64);
    $user = User::factory()->create(['jwt_secret' => $secret]);

    $token = JWT::encode([
        'sub' => $user->id,
        'iat' => time(),
        'exp' => time() + 3600,
        'iss' => 'https://trusted-app.com',
    ], $secret, 'HS256');

    $request = Request::create('/api/assets', 'GET');
    $request->headers->set('Authorization', 'Bearer ' . $token);

    $guard = createJwtGuard($request);

    $result = $guard->user();
    expect($result)->not->toBeNull();
    expect($result->id)->toBe($user->id);
});

test('jwt guard returns null for missing required claims', function () {
    $secret = str_repeat('a', 64);
    $user = User::factory()->create(['jwt_secret' => $secret]);

    // Token missing 'iat' claim
    $token = JWT::encode([
        'sub' => $user->id,
        'exp' => time() + 3600,
    ], $secret, 'HS256');

    $request = Request::create('/api/assets', 'GET');
    $request->headers->set('Authorization', 'Bearer ' . $token);

    $guard = createJwtGuard($request);

    expect($guard->user())->toBeNull();
});
