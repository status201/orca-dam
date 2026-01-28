<?php

use App\Models\User;

test('admin can list jwt secrets', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $userWithSecret = User::factory()->create(['jwt_secret' => str_repeat('a', 64)]);

    $response = $this->actingAs($admin)
        ->getJson(route('api.jwt-secrets'));

    $response->assertOk();
    $response->assertJsonStructure([
        'users_with_secrets',
        'all_users',
        'jwt_enabled',
    ]);
});

test('non-admin cannot list jwt secrets', function () {
    $editor = User::factory()->create(['role' => 'editor']);

    $response = $this->actingAs($editor)
        ->getJson(route('api.jwt-secrets'));

    $response->assertForbidden();
});

test('admin can generate jwt secret for user', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $targetUser = User::factory()->create();

    $response = $this->actingAs($admin)
        ->postJson(route('api.jwt-secrets.generate', $targetUser));

    $response->assertOk();
    $response->assertJsonStructure([
        'success',
        'message',
        'secret',
        'user' => ['id', 'name', 'email', 'role'],
        'generated_at',
    ]);

    // Secret should be 64 characters
    expect(strlen($response->json('secret')))->toBe(64);

    // User should now have a secret
    $targetUser->refresh();
    expect($targetUser->hasJwtSecret())->toBeTrue();
});

test('admin can regenerate jwt secret', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $targetUser = User::factory()->create(['jwt_secret' => str_repeat('a', 64)]);
    $oldSecretTime = $targetUser->jwt_secret_generated_at;

    $response = $this->actingAs($admin)
        ->postJson(route('api.jwt-secrets.generate', $targetUser));

    $response->assertOk();
    $response->assertJsonPath('message', 'JWT secret regenerated successfully');

    // New secret should be different
    $targetUser->refresh();
    expect($targetUser->jwt_secret)->not->toBe(str_repeat('a', 64));
});

test('admin can revoke jwt secret', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $targetUser = User::factory()->create(['jwt_secret' => str_repeat('a', 64)]);

    $response = $this->actingAs($admin)
        ->deleteJson(route('api.jwt-secrets.revoke', $targetUser));

    $response->assertOk();
    $response->assertJsonPath('success', true);

    // User should no longer have a secret
    $targetUser->refresh();
    expect($targetUser->hasJwtSecret())->toBeFalse();
});

test('revoking non-existent secret returns 404', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $targetUser = User::factory()->create(['jwt_secret' => null]);

    $response = $this->actingAs($admin)
        ->deleteJson(route('api.jwt-secrets.revoke', $targetUser));

    $response->assertNotFound();
});

test('non-admin cannot generate jwt secret', function () {
    $editor = User::factory()->create(['role' => 'editor']);
    $targetUser = User::factory()->create();

    $response = $this->actingAs($editor)
        ->postJson(route('api.jwt-secrets.generate', $targetUser));

    $response->assertForbidden();
});

test('non-admin cannot revoke jwt secret', function () {
    $editor = User::factory()->create(['role' => 'editor']);
    $targetUser = User::factory()->create(['jwt_secret' => str_repeat('a', 64)]);

    $response = $this->actingAs($editor)
        ->deleteJson(route('api.jwt-secrets.revoke', $targetUser));

    $response->assertForbidden();
});

test('jwt secret is hidden from user model serialization', function () {
    $user = User::factory()->create(['jwt_secret' => str_repeat('a', 64)]);

    $array = $user->toArray();

    expect($array)->not->toHaveKey('jwt_secret');
});

test('hasJwtSecret helper works correctly', function () {
    $userWithSecret = User::factory()->create(['jwt_secret' => str_repeat('a', 64)]);
    $userWithoutSecret = User::factory()->create(['jwt_secret' => null]);

    expect($userWithSecret->hasJwtSecret())->toBeTrue();
    expect($userWithoutSecret->hasJwtSecret())->toBeFalse();
});
