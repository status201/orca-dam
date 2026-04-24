<?php

use App\Models\User;

test('two-factor:status lists all users with summary', function () {
    User::factory()->create([
        'name' => 'Alice',
        'two_factor_secret' => 'secret',
        'two_factor_confirmed_at' => now(),
    ]);
    User::factory()->create(['name' => 'Bob']);

    $this->artisan('two-factor:status')
        ->expectsOutputToContain('Total users: 2')
        ->assertExitCode(0);
});

test('two-factor:status --enabled filters to only enabled users', function () {
    User::factory()->create([
        'two_factor_secret' => 'x',
        'two_factor_confirmed_at' => now(),
    ]);
    User::factory()->create();

    $this->artisan('two-factor:status', ['--enabled' => true])
        ->expectsOutputToContain('Total users: 1')
        ->assertExitCode(0);
});

test('two-factor:status invalid --role is rejected', function () {
    $this->artisan('two-factor:status', ['--role' => 'superadmin'])
        ->expectsOutputToContain('Invalid role')
        ->assertExitCode(1);
});

test('two-factor:disable clears 2FA for the user', function () {
    $user = User::factory()->create([
        'email' => 't@example.com',
        'two_factor_secret' => 'secret-value',
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => ['c1', 'c2'],
    ]);

    $this->artisan('two-factor:disable', ['email' => 't@example.com', '--force' => true])
        ->expectsOutputToContain('Two-factor authentication has been disabled')
        ->assertExitCode(0);

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull();
    expect($user->two_factor_confirmed_at)->toBeNull();
    expect($user->hasTwoFactorEnabled())->toBeFalse();
});

test('two-factor:disable is a no-op when 2FA not enabled', function () {
    User::factory()->create(['email' => 'n@example.com']);

    $this->artisan('two-factor:disable', ['email' => 'n@example.com', '--force' => true])
        ->expectsOutputToContain('not enabled')
        ->assertExitCode(0);
});

test('two-factor:disable fails for missing user', function () {
    $this->artisan('two-factor:disable', ['email' => 'ghost@example.com', '--force' => true])
        ->expectsOutputToContain('User not found')
        ->assertExitCode(1);
});
