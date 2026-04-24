<?php

use App\Models\User;

test('token:create with existing user email creates a token', function () {
    $user = User::factory()->create(['email' => 'u@example.com']);

    $this->artisan('token:create', ['email' => 'u@example.com', '--name' => 'CLI Token'])
        ->expectsOutputToContain('Token created successfully!')
        ->assertExitCode(0);

    expect($user->tokens()->where('name', 'CLI Token')->count())->toBe(1);
});

test('token:create --new creates new api user and token', function () {
    $this->artisan('token:create', [
        '--new' => true,
        '--user-name' => 'Integration Bot',
        '--name' => 'Bot Token',
    ])
        ->expectsQuestion('Enter email for the API user', 'bot@example.com')
        ->expectsOutputToContain('Token created successfully!')
        ->assertExitCode(0);

    $user = User::where('email', 'bot@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->role)->toBe('api');
    expect($user->tokens()->count())->toBe(1);
});

test('token:create fails when user email not found and creation declined', function () {
    $this->artisan('token:create', ['email' => 'missing@example.com'])
        ->expectsConfirmation('Would you like to create a new API user with this email?', 'no')
        ->assertExitCode(1);
});

test('token:list shows tokens filtered by user email', function () {
    $u1 = User::factory()->create(['email' => 'a@example.com']);
    $u2 = User::factory()->create(['email' => 'b@example.com']);
    $u1->createToken('t1');
    $u2->createToken('t2');

    $this->artisan('token:list', ['--user' => 'a@example.com'])
        ->expectsOutputToContain('Found 1 token')
        ->assertExitCode(0);
});

test('token:list filter by role', function () {
    User::factory()->create(['role' => 'admin'])->createToken('admin-tok');
    User::factory()->create(['role' => 'api'])->createToken('api-tok');

    $this->artisan('token:list', ['--role' => 'admin'])
        ->expectsOutputToContain('Found 1 token')
        ->assertExitCode(0);
});

test('token:list reports empty when no tokens match', function () {
    $this->artisan('token:list')
        ->expectsOutputToContain('No tokens found')
        ->assertExitCode(0);
});

test('token:revoke by id removes the token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('victim');
    $id = $token->accessToken->id;

    $this->artisan('token:revoke', ['id' => $id, '--force' => true])
        ->expectsOutputToContain('Token revoked successfully')
        ->assertExitCode(0);

    expect($user->tokens()->count())->toBe(0);
});

test('token:revoke by user email removes all user tokens', function () {
    $user = User::factory()->create(['email' => 'rev@example.com']);
    $user->createToken('one');
    $user->createToken('two');

    $this->artisan('token:revoke', ['--user' => 'rev@example.com', '--force' => true])
        ->expectsOutputToContain('Revoked 2 token')
        ->assertExitCode(0);

    expect($user->tokens()->count())->toBe(0);
});

test('token:revoke fails when neither id nor user given', function () {
    $this->artisan('token:revoke')->assertExitCode(1);
});

test('token:revoke fails when user email not found', function () {
    $this->artisan('token:revoke', ['--user' => 'ghost@example.com', '--force' => true])
        ->expectsOutputToContain('User not found')
        ->assertExitCode(1);
});
