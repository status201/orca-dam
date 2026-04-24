<?php

use App\Models\User;

test('jwt:generate creates a secret for a user without one', function () {
    $user = User::factory()->create(['email' => 'j@example.com', 'jwt_secret' => null]);

    $this->artisan('jwt:generate', ['email' => 'j@example.com'])
        ->expectsOutputToContain('JWT secret generated successfully')
        ->assertExitCode(0);

    $user->refresh();
    expect($user->jwt_secret)->not->toBeNull();
    expect($user->jwt_secret_generated_at)->not->toBeNull();
});

test('jwt:generate fails if secret exists without --force', function () {
    $user = User::factory()->create(['email' => 'j@example.com', 'jwt_secret' => 'existing-secret']);

    $this->artisan('jwt:generate', ['email' => 'j@example.com'])
        ->expectsOutputToContain('already has a JWT secret')
        ->assertExitCode(1);

    expect($user->fresh()->jwt_secret)->toBe('existing-secret');
});

test('jwt:generate --force regenerates existing secret', function () {
    $user = User::factory()->create(['email' => 'j@example.com', 'jwt_secret' => 'old']);

    $this->artisan('jwt:generate', ['email' => 'j@example.com', '--force' => true])
        ->expectsOutputToContain('JWT secret regenerated successfully')
        ->assertExitCode(0);

    expect($user->fresh()->jwt_secret)->not->toBe('old');
});

test('jwt:generate fails for missing user', function () {
    $this->artisan('jwt:generate', ['email' => 'ghost@example.com'])
        ->expectsOutputToContain('User not found')
        ->assertExitCode(1);
});

test('jwt:list shows users with secrets', function () {
    User::factory()->create(['jwt_secret' => 'x', 'jwt_secret_generated_at' => now()]);
    User::factory()->create(['jwt_secret' => null]);

    $this->artisan('jwt:list')
        ->expectsOutputToContain('Found 1 user')
        ->assertExitCode(0);
});

test('jwt:list reports empty when no secrets exist', function () {
    User::factory()->create(['jwt_secret' => null]);

    $this->artisan('jwt:list')
        ->expectsOutputToContain('No users have JWT secrets')
        ->assertExitCode(0);
});

test('jwt:revoke clears the secret', function () {
    $user = User::factory()->create([
        'email' => 'r@example.com',
        'jwt_secret' => 'secret',
        'jwt_secret_generated_at' => now(),
    ]);

    $this->artisan('jwt:revoke', ['email' => 'r@example.com', '--force' => true])
        ->expectsOutputToContain('JWT secret revoked successfully')
        ->assertExitCode(0);

    $user->refresh();
    expect($user->jwt_secret)->toBeNull();
    expect($user->jwt_secret_generated_at)->toBeNull();
});

test('jwt:revoke no-op when user has no secret', function () {
    User::factory()->create(['email' => 'n@example.com', 'jwt_secret' => null]);

    $this->artisan('jwt:revoke', ['email' => 'n@example.com', '--force' => true])
        ->expectsOutputToContain('does not have a JWT secret')
        ->assertExitCode(0);
});

test('jwt:revoke fails for missing user', function () {
    $this->artisan('jwt:revoke', ['email' => 'ghost@example.com', '--force' => true])
        ->expectsOutputToContain('User not found')
        ->assertExitCode(1);
});
