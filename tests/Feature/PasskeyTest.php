<?php

use App\Models\User;
use App\Services\WebAuthnService;
use Illuminate\Support\Str;
use Laragear\WebAuthn\Events\CredentialAsserted;
use Laragear\WebAuthn\Models\WebAuthnCredential;

function attachCredential(User $user, array $overrides = []): WebAuthnCredential
{
    return WebAuthnCredential::forceCreate(array_merge([
        'id' => bin2hex(random_bytes(16)),
        'authenticatable_type' => $user->getMorphClass(),
        'authenticatable_id' => $user->getKey(),
        'user_id' => (string) Str::uuid(),
        'counter' => 0,
        'rp_id' => 'localhost',
        'origin' => 'http://localhost',
        'transports' => null,
        'aaguid' => '00000000-0000-0000-0000-000000000000',
        'public_key' => 'fake-public-key',
        'attestation_format' => 'none',
        'certificates' => null,
        'disabled_at' => null,
    ], $overrides));
}

test('admins can enable passkeys', function () {
    $user = User::factory()->create(['role' => 'admin']);
    expect($user->canEnablePasskeys())->toBeTrue();
});

test('editors can enable passkeys', function () {
    $user = User::factory()->create(['role' => 'editor']);
    expect($user->canEnablePasskeys())->toBeTrue();
});

test('api users cannot enable passkeys', function () {
    $user = User::factory()->create(['role' => 'api']);
    expect($user->canEnablePasskeys())->toBeFalse();
});

test('hasPasskeysEnabled is false until a credential is registered', function () {
    $user = User::factory()->create(['role' => 'admin']);
    expect($user->hasPasskeysEnabled())->toBeFalse();

    attachCredential($user);
    expect($user->fresh()->hasPasskeysEnabled())->toBeTrue();
});

test('hasPasskeysEnabled ignores disabled credentials', function () {
    $user = User::factory()->create(['role' => 'admin']);
    attachCredential($user, ['disabled_at' => now()]);

    expect($user->fresh()->hasPasskeysEnabled())->toBeFalse();
});

test('profile edit page shows the passkeys section for admins', function () {
    $user = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($user)->get(route('profile.edit'));

    $response->assertOk();
    $response->assertSee(__('Passkeys'));
});

test('profile edit page hides the passkeys section for api users', function () {
    $user = User::factory()->create(['role' => 'api']);

    $response = $this->actingAs($user)->get(route('profile.edit'));

    $response->assertOk();
    $response->assertDontSee(__('Sign in with your fingerprint, face, screen lock, or security key. Passkeys are phishing-resistant and skip the two-factor code on login.'));
});

test('user can rename their own passkey', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $credential = attachCredential($user, ['alias' => 'old']);

    $response = $this->actingAs($user)
        ->patch(route('profile.passkeys.update', $credential->id), [
            'alias' => 'My MacBook',
        ]);

    $response->assertRedirect();
    expect($credential->fresh()->alias)->toBe('My MacBook');
});

test('user cannot rename someone elses passkey', function () {
    $owner = User::factory()->create(['role' => 'admin']);
    $other = User::factory()->create(['role' => 'admin']);
    $credential = attachCredential($owner, ['alias' => 'mine']);

    $response = $this->actingAs($other)
        ->patch(route('profile.passkeys.update', $credential->id), [
            'alias' => 'stolen',
        ]);

    $response->assertSessionHasErrors('passkey');
    expect($credential->fresh()->alias)->toBe('mine');
});

test('user can delete their own passkey', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $credential = attachCredential($user);

    $response = $this->actingAs($user)
        ->delete(route('profile.passkeys.destroy', $credential->id));

    $response->assertRedirect();
    expect(WebAuthnCredential::find($credential->id))->toBeNull();
});

test('user cannot delete someone elses passkey', function () {
    $owner = User::factory()->create(['role' => 'admin']);
    $other = User::factory()->create(['role' => 'admin']);
    $credential = attachCredential($owner);

    $response = $this->actingAs($other)
        ->delete(route('profile.passkeys.destroy', $credential->id));

    $response->assertSessionHasErrors('passkey');
    expect(WebAuthnCredential::find($credential->id))->not->toBeNull();
});

test('admin can clear another users passkeys', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $target = User::factory()->create(['role' => 'editor']);
    attachCredential($target);
    attachCredential($target);

    $response = $this->actingAs($admin)
        ->delete(route('users.passkeys.clear', $target));

    $response->assertRedirect();
    expect($target->webAuthnCredentials()->count())->toBe(0);
});

test('admin cannot clear their own passkeys via the recovery action', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    attachCredential($admin);

    $response = $this->actingAs($admin)
        ->delete(route('users.passkeys.clear', $admin));

    $response->assertForbidden();
    expect($admin->webAuthnCredentials()->count())->toBe(1);
});

test('non-admin cannot clear other users passkeys', function () {
    $editor = User::factory()->create(['role' => 'editor']);
    $target = User::factory()->create(['role' => 'editor']);
    attachCredential($target);

    $response = $this->actingAs($editor)
        ->delete(route('users.passkeys.clear', $target));

    $response->assertForbidden();
    expect($target->webAuthnCredentials()->count())->toBe(1);
});

test('passkey login options route is reachable as guest', function () {
    $response = $this->postJson(route('passkey.options'), []);

    // The package returns a JSON challenge on success; we just verify the
    // route is wired and accepts guest traffic.
    expect($response->status())->toBeIn([200, 422]);
});

test('passkey login route rejects unauthenticated invalid payloads', function () {
    $response = $this->postJson(route('passkey.login'), []);

    expect($response->status())->toBeIn([422, 429]);
});

test('user with reached limit gets 422 on options request', function () {
    $user = User::factory()->create(['role' => 'admin']);
    for ($i = 0; $i < WebAuthnService::MAX_CREDENTIALS_PER_USER; $i++) {
        attachCredential($user);
    }

    $response = $this->actingAs($user)
        ->postJson(route('profile.passkeys.options'));

    $response->assertStatus(422);
});

test('api user gets 403 on passkey options request', function () {
    $user = User::factory()->create(['role' => 'api']);

    $response = $this->actingAs($user)
        ->postJson(route('profile.passkeys.options'));

    $response->assertStatus(403);
});

test('logged-in user can list their passkeys via webAuthnCredentials relation', function () {
    $user = User::factory()->create(['role' => 'admin']);
    attachCredential($user, ['alias' => 'A']);
    attachCredential($user, ['alias' => 'B']);

    expect($user->fresh()->webAuthnCredentials()->count())->toBe(2);
});

test('TouchPasskeyLastUsed listener stamps both timestamps on assertion', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $credential = attachCredential($user);

    CredentialAsserted::dispatch($user, $credential);

    expect($user->fresh()->last_passkey_used_at)->not->toBeNull();
    expect($credential->fresh()->last_used_at)->not->toBeNull();
});

test('passkeys:list command shows registered passkeys', function () {
    $user = User::factory()->create(['role' => 'admin', 'email' => 'list@example.com']);
    attachCredential($user, ['alias' => 'MacBook']);

    $this->artisan('passkeys:list')
        ->expectsOutputToContain('Found 1 passkey')
        ->expectsOutputToContain('MacBook')
        ->assertSuccessful();
});

test('passkeys:list filters by user email', function () {
    $a = User::factory()->create(['role' => 'admin', 'email' => 'a@example.com']);
    $b = User::factory()->create(['role' => 'admin', 'email' => 'b@example.com']);
    attachCredential($a, ['alias' => 'A-key']);
    attachCredential($b, ['alias' => 'B-key']);

    $this->artisan('passkeys:list', ['--user' => 'a@example.com'])
        ->expectsOutputToContain('A-key')
        ->doesntExpectOutputToContain('B-key')
        ->assertSuccessful();
});

test('passkeys:revoke removes the passkey when confirmed', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $credential = attachCredential($user);

    $this->artisan('passkeys:revoke', ['id' => $credential->id, '--force' => true])
        ->expectsOutputToContain('revoked successfully')
        ->assertSuccessful();

    expect(WebAuthnCredential::find($credential->id))->toBeNull();
});

test('passkeys:revoke --user removes all of a users passkeys', function () {
    $user = User::factory()->create(['role' => 'admin', 'email' => 'wipe@example.com']);
    attachCredential($user);
    attachCredential($user);

    $this->artisan('passkeys:revoke', ['--user' => 'wipe@example.com', '--force' => true])
        ->assertSuccessful();

    expect($user->webAuthnCredentials()->count())->toBe(0);
});

test('passkeys:revoke with no arguments fails', function () {
    $this->artisan('passkeys:revoke')
        ->expectsOutputToContain('Please provide either a credential ID or --user')
        ->assertFailed();
});

test('passkeys:list works against credentials hydrated from the database', function () {
    // Regression: the package model does not cast created_at / last_used_at,
    // so freshly-queried instances return strings. Querying back from the DB
    // (instead of using the cached forceCreate() instance) exercises the
    // string-formatting path.
    $user = User::factory()->create(['role' => 'admin', 'email' => 'rehydrate@example.com']);
    $created = attachCredential($user, ['alias' => 'Hydrated', 'last_used_at' => now()->subHour()]);

    // Force a fresh DB read so created_at / last_used_at come back as strings.
    expect(WebAuthnCredential::find($created->id))->not->toBeNull();

    $this->artisan('passkeys:list', ['--user' => 'rehydrate@example.com'])
        ->expectsOutputToContain('Hydrated')
        ->assertSuccessful();
});

test('profile edit page renders when the user has a passkey with a stamped last_used_at', function () {
    // Regression: blade was calling ->format() on a string column from the
    // package model when last_used_at was set.
    $user = User::factory()->create(['role' => 'admin']);
    attachCredential($user, ['alias' => 'Hydrated', 'last_used_at' => now()->subDay()]);

    $response = $this->actingAs($user)->get(route('profile.edit'));

    $response->assertOk();
    $response->assertSee('Hydrated', false);
});
