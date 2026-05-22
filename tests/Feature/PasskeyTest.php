<?php

use App\Models\Passkey;
use App\Models\User;
use App\Services\PasskeyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Events\PasskeyVerified;
use Laravel\Passkeys\Passkeys;

function attachPasskey(User $user, array $overrides = []): Passkey
{
    return Passkey::forceCreate(array_merge([
        'user_id' => $user->getKey(),
        'name' => 'Test Passkey',
        'credential_id' => bin2hex(random_bytes(16)),
        // Real ceremony writes a structured CredentialRecord payload here;
        // an empty object is enough for tests that don't exercise verification.
        'credential' => [],
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

test('hasPasskeysEnabled is false until a passkey is registered', function () {
    $user = User::factory()->create(['role' => 'admin']);
    expect($user->hasPasskeysEnabled())->toBeFalse();

    attachPasskey($user);
    expect($user->fresh()->hasPasskeysEnabled())->toBeTrue();
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
    $passkey = attachPasskey($user, ['name' => 'old']);

    $response = $this->actingAs($user)
        ->patch(route('profile.passkeys.update', $passkey->credential_id), [
            'name' => 'My MacBook',
        ]);

    $response->assertRedirect();
    expect($passkey->fresh()->name)->toBe('My MacBook');
});

test('user cannot rename someone elses passkey', function () {
    $owner = User::factory()->create(['role' => 'admin']);
    $other = User::factory()->create(['role' => 'admin']);
    $passkey = attachPasskey($owner, ['name' => 'mine']);

    $response = $this->actingAs($other)
        ->patch(route('profile.passkeys.update', $passkey->credential_id), [
            'name' => 'stolen',
        ]);

    $response->assertSessionHasErrors('passkey');
    expect($passkey->fresh()->name)->toBe('mine');
});

test('user can delete their own passkey', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $passkey = attachPasskey($user);

    $response = $this->actingAs($user)
        ->delete(route('profile.passkeys.destroy', $passkey->credential_id));

    $response->assertRedirect();
    expect(Passkey::find($passkey->id))->toBeNull();
});

test('user cannot delete someone elses passkey', function () {
    $owner = User::factory()->create(['role' => 'admin']);
    $other = User::factory()->create(['role' => 'admin']);
    $passkey = attachPasskey($owner);

    $response = $this->actingAs($other)
        ->delete(route('profile.passkeys.destroy', $passkey->credential_id));

    $response->assertSessionHasErrors('passkey');
    expect(Passkey::find($passkey->id))->not->toBeNull();
});

test('admin can clear another users passkeys', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $target = User::factory()->create(['role' => 'editor']);
    attachPasskey($target);
    attachPasskey($target);

    $response = $this->actingAs($admin)
        ->delete(route('users.passkeys.clear', $target));

    $response->assertRedirect();
    expect($target->passkeys()->count())->toBe(0);
});

test('admin cannot clear their own passkeys via the recovery action', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    attachPasskey($admin);

    $response = $this->actingAs($admin)
        ->delete(route('users.passkeys.clear', $admin));

    $response->assertForbidden();
    expect($admin->passkeys()->count())->toBe(1);
});

test('non-admin cannot clear other users passkeys', function () {
    $editor = User::factory()->create(['role' => 'editor']);
    $target = User::factory()->create(['role' => 'editor']);
    attachPasskey($target);

    $response = $this->actingAs($editor)
        ->delete(route('users.passkeys.clear', $target));

    $response->assertForbidden();
    expect($target->passkeys()->count())->toBe(1);
});

test('passkey login options route is reachable as guest', function () {
    $response = $this->getJson(route('passkey.options'));

    // The package returns a JSON challenge on success; we just verify the
    // route is wired and accepts guest traffic.
    $response->assertOk();
    $response->assertJsonStructure(['options']);
});

test('passkey login route rejects unauthenticated invalid payloads', function () {
    $response = $this->postJson(route('passkey.login'), []);

    expect($response->status())->toBeIn([422, 429]);
});

test('user with reached limit gets 422 on options request', function () {
    $user = User::factory()->create(['role' => 'admin']);
    for ($i = 0; $i < PasskeyService::MAX_CREDENTIALS_PER_USER; $i++) {
        attachPasskey($user);
    }

    $response = $this->actingAs($user)
        ->getJson(route('profile.passkeys.options'));

    $response->assertStatus(422);
});

test('api user gets 403 on passkey options request', function () {
    $user = User::factory()->create(['role' => 'api']);

    $response = $this->actingAs($user)
        ->getJson(route('profile.passkeys.options'));

    $response->assertStatus(403);
});

test('logged-in user can list their passkeys via passkeys relation', function () {
    $user = User::factory()->create(['role' => 'admin']);
    attachPasskey($user, ['name' => 'A']);
    attachPasskey($user, ['name' => 'B']);

    expect($user->fresh()->passkeys()->count())->toBe(2);
});

test('TouchPasskeyLastUsed listener stamps last_passkey_used_at on assertion', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $passkey = attachPasskey($user);

    PasskeyVerified::dispatch($user, $passkey);

    expect($user->fresh()->last_passkey_used_at)->not->toBeNull();
});

test('EnforcePasskeyLimit deletes a passkey that puts the user over the cap', function () {
    $user = User::factory()->create(['role' => 'admin']);
    for ($i = 0; $i < PasskeyService::MAX_CREDENTIALS_PER_USER; $i++) {
        attachPasskey($user);
    }
    $extra = attachPasskey($user);

    PasskeyRegistered::dispatch($user, $extra);

    expect(Passkey::find($extra->id))->toBeNull();
    expect($user->fresh()->passkeys()->count())->toBe(PasskeyService::MAX_CREDENTIALS_PER_USER);
});

test('EnforcePasskeyLimit leaves a passkey alone when the user is exactly at the cap', function () {
    $user = User::factory()->create(['role' => 'admin']);
    for ($i = 0; $i < PasskeyService::MAX_CREDENTIALS_PER_USER - 1; $i++) {
        attachPasskey($user);
    }
    $last = attachPasskey($user); // exactly the 10th

    PasskeyRegistered::dispatch($user, $last);

    expect(Passkey::find($last->id))->not->toBeNull();
    expect($user->fresh()->passkeys()->count())->toBe(PasskeyService::MAX_CREDENTIALS_PER_USER);
});

test('credential blob is encrypted at rest', function () {
    $user = User::factory()->create();
    $payload = ['public_key' => 'sensitive-bytes-do-not-leak', 'counter' => 42];
    $passkey = attachPasskey($user, ['credential' => $payload]);

    // Read the raw column, bypassing the model cast.
    $raw = DB::table('passkeys')->where('id', $passkey->id)->value('credential');

    expect($raw)->toBeString();
    // Encrypted blob must not contain the sensitive marker, and must not parse as JSON.
    expect($raw)->not->toContain('sensitive-bytes-do-not-leak');
    expect($raw)->not->toContain('public_key');
    expect(json_decode($raw, true))->toBeNull();

    // And the cast must round-trip the original array on reload.
    expect($passkey->fresh()->credential)->toBe($payload);
});

test('Passkeys facade uses the custom App\\Models\\Passkey model', function () {
    // Locks down AppServiceProvider::register's usePasskeyModel() call —
    // removing it silently disables the encrypted credential cast.
    expect(Passkeys::passkeyModel())->toBe(Passkey::class);
});

test('package-default passkey routes are disabled so ORCA URLs stay authoritative', function () {
    expect(Passkeys::shouldRegisterRoutes())->toBeFalse();

    // Sanity: the package's default URL is not registered.
    $this->getJson('/passkeys/login/options')->assertNotFound();
});

test('passkey event listeners are wired in the application', function () {
    expect(Event::hasListeners(PasskeyVerified::class))->toBeTrue();
    expect(Event::hasListeners(PasskeyRegistered::class))->toBeTrue();
});

test('passkeys:list command shows registered passkeys', function () {
    $user = User::factory()->create(['role' => 'admin', 'email' => 'list@example.com']);
    attachPasskey($user, ['name' => 'MacBook']);

    $this->artisan('passkeys:list')
        ->expectsOutputToContain('Found 1 passkey')
        ->expectsOutputToContain('MacBook')
        ->assertSuccessful();
});

test('passkeys:list filters by user email', function () {
    $a = User::factory()->create(['role' => 'admin', 'email' => 'a@example.com']);
    $b = User::factory()->create(['role' => 'admin', 'email' => 'b@example.com']);
    attachPasskey($a, ['name' => 'A-key']);
    attachPasskey($b, ['name' => 'B-key']);

    $this->artisan('passkeys:list', ['--user' => 'a@example.com'])
        ->expectsOutputToContain('A-key')
        ->doesntExpectOutputToContain('B-key')
        ->assertSuccessful();
});

test('passkeys:revoke removes the passkey when confirmed', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $passkey = attachPasskey($user);

    $this->artisan('passkeys:revoke', ['id' => $passkey->credential_id, '--force' => true])
        ->expectsOutputToContain('revoked successfully')
        ->assertSuccessful();

    expect(Passkey::find($passkey->id))->toBeNull();
});

test('passkeys:revoke --user removes all of a users passkeys', function () {
    $user = User::factory()->create(['role' => 'admin', 'email' => 'wipe@example.com']);
    attachPasskey($user);
    attachPasskey($user);

    $this->artisan('passkeys:revoke', ['--user' => 'wipe@example.com', '--force' => true])
        ->assertSuccessful();

    expect($user->passkeys()->count())->toBe(0);
});

test('passkeys:revoke with no arguments fails', function () {
    $this->artisan('passkeys:revoke')
        ->expectsOutputToContain('Please provide either a credential ID or --user')
        ->assertFailed();
});

test('passkeys:revoke matches by credential_id prefix when no exact hit', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $passkey = attachPasskey($user, ['credential_id' => 'abcdef0123456789ffff']);

    $this->artisan('passkeys:revoke', ['id' => 'abcdef', '--force' => true])
        ->expectsOutputToContain('revoked successfully')
        ->assertSuccessful();

    expect(Passkey::find($passkey->id))->toBeNull();
});

test('passkeys:revoke fails when no passkey matches the given id', function () {
    $this->artisan('passkeys:revoke', ['id' => 'nonexistent-credential'])
        ->expectsOutputToContain('Passkey not found')
        ->assertFailed();
});

test('passkeys:list --role filters by user role', function () {
    $admin = User::factory()->create(['role' => 'admin', 'email' => 'role-admin@example.com']);
    $editor = User::factory()->create(['role' => 'editor', 'email' => 'role-editor@example.com']);
    attachPasskey($admin, ['name' => 'AdminKey']);
    attachPasskey($editor, ['name' => 'EditorKey']);

    $this->artisan('passkeys:list', ['--role' => 'admin'])
        ->expectsOutputToContain('AdminKey')
        ->doesntExpectOutputToContain('EditorKey')
        ->assertSuccessful();
});

test('passkeys:list fails when --user does not match any user', function () {
    $this->artisan('passkeys:list', ['--user' => 'missing@example.com'])
        ->expectsOutputToContain('User not found')
        ->assertFailed();
});

test('profile edit page renders when the user has a passkey with a stamped last_used_at', function () {
    // Regression: blade was calling ->format() on a string column from the
    // package model when last_used_at was set.
    $user = User::factory()->create(['role' => 'admin']);
    attachPasskey($user, ['name' => 'Hydrated', 'last_used_at' => now()->subDay()]);

    $response = $this->actingAs($user)->get(route('profile.edit'));

    $response->assertOk();
    $response->assertSee('Hydrated', false);
});
