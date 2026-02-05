<?php

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Support\Facades\Hash;

test('generateSecret returns valid base32 string', function () {
    $service = app(TwoFactorService::class);
    $secret = $service->generateSecret();

    expect($secret)->toBeString();
    expect(strlen($secret))->toBe(16); // Default Google2FA secret length
    expect(preg_match('/^[A-Z2-7]+$/', $secret))->toBe(1); // Base32 characters
});

test('verifyCode validates correct totp code', function () {
    $service = app(TwoFactorService::class);
    $secret = $service->generateSecret();

    $google2fa = new PragmaRX\Google2FA\Google2FA;
    $validCode = $google2fa->getCurrentOtp($secret);

    expect($service->verifyCode($secret, $validCode))->toBeTrue();
});

test('verifyCode rejects invalid totp code', function () {
    $service = app(TwoFactorService::class);
    $secret = $service->generateSecret();

    expect($service->verifyCode($secret, '000000'))->toBeFalse();
    expect($service->verifyCode($secret, 'invalid'))->toBeFalse();
});

test('generateRecoveryCodes returns correct number of codes', function () {
    $service = app(TwoFactorService::class);
    $expectedCount = config('two-factor.recovery_codes_count', 8);

    $codes = $service->generateRecoveryCodes();

    expect($codes)->toHaveCount($expectedCount);
});

test('generateRecoveryCodes returns codes in correct format', function () {
    $service = app(TwoFactorService::class);
    $codes = $service->generateRecoveryCodes();

    foreach ($codes as $code) {
        // Should be format XXXXX-XXXXX (default length 10 = 5-5)
        expect($code)->toMatch('/^[A-Z0-9]{5}-[A-Z0-9]{5}$/');
    }
});

test('hashRecoveryCodes returns hashed array', function () {
    $service = app(TwoFactorService::class);
    $codes = ['CODE1-CODE2', 'CODE3-CODE4'];

    $hashed = $service->hashRecoveryCodes($codes);

    expect($hashed)->toHaveCount(2);
    expect($hashed[0])->not->toBe('CODE1-CODE2');
    expect($hashed[1])->not->toBe('CODE3-CODE4');

    // Verify the hashes work with Laravel's Hash
    expect(Hash::check('CODE1-CODE2', $hashed[0]))->toBeTrue();
    expect(Hash::check('CODE3-CODE4', $hashed[1]))->toBeTrue();
});

test('verifyRecoveryCode finds matching hashed code', function () {
    $service = app(TwoFactorService::class);
    $codes = ['AAAAA-BBBBB', 'CCCCC-DDDDD'];
    $hashed = $service->hashRecoveryCodes($codes);

    expect($service->verifyRecoveryCode('AAAAA-BBBBB', $hashed))->toBe(0);
    expect($service->verifyRecoveryCode('CCCCC-DDDDD', $hashed))->toBe(1);
    expect($service->verifyRecoveryCode('XXXXX-YYYYY', $hashed))->toBeFalse();
});

test('enableTwoFactor sets all required fields', function () {
    $service = app(TwoFactorService::class);
    $user = User::factory()->create(['role' => 'admin']);
    $secret = $service->generateSecret();

    $recoveryCodes = $service->enableTwoFactor($user, $secret);

    $user->refresh();

    expect($user->two_factor_secret)->toBe($secret);
    expect($user->two_factor_confirmed_at)->not->toBeNull();
    expect($user->two_factor_recovery_codes)->toHaveCount(config('two-factor.recovery_codes_count', 8));
    expect($recoveryCodes)->toHaveCount(config('two-factor.recovery_codes_count', 8));
});

test('disableTwoFactor clears all fields', function () {
    $service = app(TwoFactorService::class);
    $user = User::factory()->create([
        'role' => 'admin',
        'two_factor_secret' => 'testsecret',
        'two_factor_recovery_codes' => [Hash::make('CODE')],
        'two_factor_confirmed_at' => now(),
    ]);

    $service->disableTwoFactor($user);

    $user->refresh();

    expect($user->two_factor_secret)->toBeNull();
    expect($user->two_factor_recovery_codes)->toBeNull();
    expect($user->two_factor_confirmed_at)->toBeNull();
});

test('regenerateRecoveryCodes returns new codes and updates user', function () {
    $service = app(TwoFactorService::class);
    $user = User::factory()->create([
        'role' => 'admin',
        'two_factor_secret' => 'testsecret',
        'two_factor_recovery_codes' => [Hash::make('OLD-CODE')],
        'two_factor_confirmed_at' => now(),
    ]);

    $oldCodes = $user->two_factor_recovery_codes;
    $newCodes = $service->regenerateRecoveryCodes($user);

    $user->refresh();

    expect($newCodes)->toHaveCount(config('two-factor.recovery_codes_count', 8));
    expect($user->two_factor_recovery_codes)->not->toBe($oldCodes);
    expect(count($user->two_factor_recovery_codes))->toBe(config('two-factor.recovery_codes_count', 8));
});

test('useRecoveryCode removes the code from list', function () {
    $service = app(TwoFactorService::class);
    $codes = ['CODE1-CODE2', 'CODE3-CODE4', 'CODE5-CODE6'];
    $hashed = $service->hashRecoveryCodes($codes);

    $user = User::factory()->create([
        'role' => 'admin',
        'two_factor_secret' => 'testsecret',
        'two_factor_recovery_codes' => $hashed,
        'two_factor_confirmed_at' => now(),
    ]);

    $service->useRecoveryCode($user, 1); // Remove index 1 (CODE3-CODE4)

    $user->refresh();

    expect($user->two_factor_recovery_codes)->toHaveCount(2);
    // Verify the removed code no longer works
    $index = $service->verifyRecoveryCode('CODE3-CODE4', $user->two_factor_recovery_codes);
    expect($index)->toBeFalse();
    // But the others still work
    expect($service->verifyRecoveryCode('CODE1-CODE2', $user->two_factor_recovery_codes))->not->toBeFalse();
    expect($service->verifyRecoveryCode('CODE5-CODE6', $user->two_factor_recovery_codes))->not->toBeFalse();
});

test('getRemainingRecoveryCodesCount returns correct count', function () {
    $service = app(TwoFactorService::class);

    $userWithCodes = User::factory()->create([
        'role' => 'admin',
        'two_factor_secret' => 'testsecret',
        'two_factor_recovery_codes' => [Hash::make('A'), Hash::make('B'), Hash::make('C')],
        'two_factor_confirmed_at' => now(),
    ]);

    expect($service->getRemainingRecoveryCodesCount($userWithCodes))->toBe(3);

    $userWithoutCodes = User::factory()->create(['role' => 'admin']);
    expect($service->getRemainingRecoveryCodesCount($userWithoutCodes))->toBe(0);
});

test('getQrCodeSvg generates valid svg', function () {
    $service = app(TwoFactorService::class);
    $user = User::factory()->create(['role' => 'admin']);
    $secret = $service->generateSecret();

    $svg = $service->getQrCodeSvg($user, $secret);

    expect($svg)->toContain('<svg');
    expect($svg)->toContain('</svg>');
});

test('getQrCodeUrl generates valid otpauth url', function () {
    $service = app(TwoFactorService::class);
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'role' => 'admin',
    ]);
    $secret = $service->generateSecret();

    $url = $service->getQrCodeUrl($user, $secret);

    expect($url)->toContain('otpauth://totp/');
    expect($url)->toContain(urlencode($user->email));
    expect($url)->toContain('secret='.$secret);
});
