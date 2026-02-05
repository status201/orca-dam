<?php

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Support\Facades\Hash;

test('user can access 2fa setup page', function () {
    $user = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($user)
        ->get(route('two-factor.setup'));

    $response->assertOk();
    $response->assertViewIs('auth.two-factor-setup');
    $response->assertViewHas('secret');
    $response->assertViewHas('qrCodeSvg');
});

test('api users cannot access 2fa setup', function () {
    $user = User::factory()->create(['role' => 'api']);

    $response = $this->actingAs($user)
        ->get(route('two-factor.setup'));

    $response->assertRedirect(route('profile.edit'));
});

test('user can enable 2fa with valid code', function () {
    $user = User::factory()->create(['role' => 'editor']);
    $twoFactorService = app(TwoFactorService::class);
    $secret = $twoFactorService->generateSecret();

    // Simulate setup session
    $this->actingAs($user)
        ->withSession(['two_factor_setup_secret' => $secret]);

    $validCode = (new PragmaRX\Google2FA\Google2FA)->getCurrentOtp($secret);

    $response = $this->actingAs($user)
        ->withSession(['two_factor_setup_secret' => $secret])
        ->post(route('two-factor.confirm'), [
            'code' => $validCode,
        ]);

    $response->assertRedirect(route('two-factor.recovery-codes.show'));
    $response->assertSessionHas('status');
    $response->assertSessionHas('two_factor_recovery_codes');

    $user->refresh();
    expect($user->hasTwoFactorEnabled())->toBeTrue();
    expect($user->two_factor_secret)->not->toBeNull();
    expect($user->two_factor_recovery_codes)->not->toBeNull();
    expect($user->two_factor_confirmed_at)->not->toBeNull();
});

test('user cannot enable 2fa with invalid code', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $twoFactorService = app(TwoFactorService::class);
    $secret = $twoFactorService->generateSecret();

    $response = $this->actingAs($user)
        ->withSession(['two_factor_setup_secret' => $secret])
        ->post(route('two-factor.confirm'), [
            'code' => '000000',
        ]);

    $response->assertSessionHasErrors('code');

    $user->refresh();
    expect($user->hasTwoFactorEnabled())->toBeFalse();
});

test('user with 2fa enabled is redirected to challenge on login', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'two_factor_secret' => 'testsecret123456',
        'two_factor_recovery_codes' => [Hash::make('AAAAA-BBBBB')],
        'two_factor_confirmed_at' => now(),
    ]);

    $response = $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.challenge'));
    expect(session('two_factor_user_id'))->toBe($user->id);
});

test('user without 2fa logs in directly', function () {
    $user = User::factory()->create(['role' => 'admin']);

    $response = $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('dashboard'));
});

test('2fa challenge page requires pending session', function () {
    $response = $this->get(route('two-factor.challenge'));

    $response->assertRedirect(route('login'));
});

test('2fa challenge accepts valid totp code', function () {
    $twoFactorService = app(TwoFactorService::class);
    $secret = $twoFactorService->generateSecret();

    $user = User::factory()->create([
        'role' => 'admin',
        'two_factor_secret' => $secret,
        'two_factor_recovery_codes' => [Hash::make('AAAAA-BBBBB')],
        'two_factor_confirmed_at' => now(),
    ]);

    $validCode = (new PragmaRX\Google2FA\Google2FA)->getCurrentOtp($secret);

    $response = $this->withSession([
        'two_factor_user_id' => $user->id,
        'two_factor_timestamp' => time(),
    ])->post(route('two-factor.challenge'), [
        'code' => $validCode,
    ]);

    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);
});

test('2fa challenge accepts valid recovery code', function () {
    $recoveryCode = 'AAAAA-BBBBB';

    $user = User::factory()->create([
        'role' => 'admin',
        'two_factor_secret' => 'testsecret123456',
        'two_factor_recovery_codes' => [Hash::make($recoveryCode)],
        'two_factor_confirmed_at' => now(),
    ]);

    $response = $this->withSession([
        'two_factor_user_id' => $user->id,
        'two_factor_timestamp' => time(),
    ])->post(route('two-factor.challenge'), [
        'code' => $recoveryCode,
    ]);

    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);

    // Recovery code should be consumed
    $user->refresh();
    expect($user->two_factor_recovery_codes)->toHaveCount(0);
});

test('2fa challenge rejects invalid code', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'two_factor_secret' => 'testsecret123456',
        'two_factor_recovery_codes' => [Hash::make('AAAAA-BBBBB')],
        'two_factor_confirmed_at' => now(),
    ]);

    $response = $this->withSession([
        'two_factor_user_id' => $user->id,
        'two_factor_timestamp' => time(),
    ])->post(route('two-factor.challenge'), [
        'code' => 'invalid',
    ]);

    $response->assertSessionHasErrors('code');
    $this->assertGuest();
});

test('2fa challenge expires after configured ttl', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'two_factor_secret' => 'testsecret123456',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);

    $ttl = config('two-factor.challenge_ttl', 300);

    $response = $this->withSession([
        'two_factor_user_id' => $user->id,
        'two_factor_timestamp' => time() - $ttl - 1, // Expired
    ])->get(route('two-factor.challenge'));

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors('email');
});

test('user can disable 2fa', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'two_factor_secret' => 'testsecret123456',
        'two_factor_recovery_codes' => [Hash::make('AAAAA-BBBBB')],
        'two_factor_confirmed_at' => now(),
    ]);

    // Disable requires password confirmation - simulate confirmed session
    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('two-factor.disable'));

    $response->assertRedirect(route('profile.edit'));

    $user->refresh();
    expect($user->hasTwoFactorEnabled())->toBeFalse();
    expect($user->two_factor_secret)->toBeNull();
    expect($user->two_factor_recovery_codes)->toBeNull();
    expect($user->two_factor_confirmed_at)->toBeNull();
});

test('user can regenerate recovery codes', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'two_factor_secret' => 'testsecret123456',
        'two_factor_recovery_codes' => [Hash::make('OLD-CODE-1')],
        'two_factor_confirmed_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.recovery-codes'));

    $response->assertRedirect(route('two-factor.recovery-codes.show'));
    $response->assertSessionHas('two_factor_recovery_codes');
    $response->assertSessionHas('status');

    $user->refresh();
    // New recovery codes should be generated (default is 8)
    expect(count($user->two_factor_recovery_codes))->toBe(config('two-factor.recovery_codes_count', 8));
});

test('hasTwoFactorEnabled returns correct status', function () {
    $userWithout = User::factory()->create(['role' => 'admin']);
    expect($userWithout->hasTwoFactorEnabled())->toBeFalse();

    $userWith = User::factory()->create([
        'role' => 'admin',
        'two_factor_secret' => 'secret',
        'two_factor_confirmed_at' => now(),
    ]);
    expect($userWith->hasTwoFactorEnabled())->toBeTrue();

    // Secret without confirmation is not enabled
    $userPartial = User::factory()->create([
        'role' => 'admin',
        'two_factor_secret' => 'secret',
        'two_factor_confirmed_at' => null,
    ]);
    expect($userPartial->hasTwoFactorEnabled())->toBeFalse();
});

test('canEnableTwoFactor returns correct status based on role', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    expect($admin->canEnableTwoFactor())->toBeTrue();

    $editor = User::factory()->create(['role' => 'editor']);
    expect($editor->canEnableTwoFactor())->toBeTrue();

    $apiUser = User::factory()->create(['role' => 'api']);
    expect($apiUser->canEnableTwoFactor())->toBeFalse();
});

test('2fa form is shown in profile for eligible users', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)
        ->get(route('profile.edit'));

    $response->assertOk();
    $response->assertSee('Two-Factor Authentication');
});

test('2fa form is hidden for api users', function () {
    $apiUser = User::factory()->create(['role' => 'api']);

    $response = $this->actingAs($apiUser)
        ->get(route('profile.edit'));

    $response->assertOk();
    $response->assertDontSee('Two-Factor Authentication');
});
