<?php

use App\Models\Asset;
use App\Models\User;

/**
 * REQ-5 of specs/features/security-invariants.md — the escalation paths, driven as an attacker.
 *
 * The invariants elsewhere in this suite read the app's own declarations: route middleware,
 * policy methods, source text. That catches a hole nobody thought of, but it proves nothing
 * about what a request actually does. These tests take the other side — a real session for a
 * real role, sending the request an attacker would send, asserting the outcome rather than the
 * configuration.
 *
 * The escalation these guard against is the one that already happened once: ending up with a
 * role you were not given. `role` is mass-assignable on the User model (it has to be, for the
 * admin user form), so every self-service write surface is one careless `$request->all()` away
 * from handing out admin.
 */

// ─── self-service surfaces cannot change the caller's own role ─────────────────

test('a non-admin cannot escalate their own role through the profile form', function (string $role) {
    $user = User::factory()->create(['role' => $role]);

    $response = $this->actingAs($user)->patch(route('profile.update'), [
        'name' => 'Still '.$role,
        'email' => $user->email,
        'role' => 'admin',
    ]);

    $response->assertSessionHasNoErrors();

    expect($user->fresh()->role)->toBe($role,
        "A {$role} promoted themselves to admin by posting role=admin to /profile. "
        .'ProfileUpdateRequest must never accept the role field; see '
        .'specs/features/security-invariants.md REQ-5.'
    );
})->with(['editor', 'api']);

test('a non-admin cannot escalate their own role through the preferences form', function (string $role) {
    $user = User::factory()->create(['role' => $role]);

    $this->actingAs($user)->patch(route('profile.preferences.update'), [
        'items_per_page' => 24,
        'role' => 'admin',
    ]);

    expect($user->fresh()->role)->toBe($role);
})->with(['editor', 'api']);

test('an admin cannot be demoted or deleted by a non-admin', function () {
    $admin = User::factory()->admin()->create();
    $editor = User::factory()->editor()->create();

    $this->actingAs($editor)->patch(route('users.update', $admin), [
        'name' => $admin->name,
        'email' => $admin->email,
        'role' => 'editor',
    ])->assertForbidden();

    $this->actingAs($editor)->delete(route('users.destroy', $admin))->assertForbidden();

    expect($admin->fresh()->role)->toBe('admin');
    expect(User::find($admin->id))->not->toBeNull();
});

// ─── user provisioning is admin-only, over HTTP ───────────────────────────────

test('a non-admin cannot reach any user-management endpoint', function (string $role) {
    $actor = User::factory()->create(['role' => $role]);
    $target = User::factory()->editor()->create();

    $this->actingAs($actor)->get(route('users.index'))->assertForbidden();
    $this->actingAs($actor)->get(route('users.create'))->assertForbidden();
    $this->actingAs($actor)->get(route('users.edit', $target))->assertForbidden();
    $this->actingAs($actor)->delete(route('users.passkeys.clear', $target))->assertForbidden();
})->with(['editor', 'api']);

test('a non-admin cannot provision a new account', function (string $role) {
    $actor = User::factory()->create(['role' => $role]);

    $this->actingAs($actor)->post(route('users.store'), [
        'name' => 'Smuggled In',
        'email' => 'smuggled@example.com',
        'password' => 'password-that-is-long-enough',
        'password_confirmation' => 'password-that-is-long-enough',
        'role' => 'admin',
    ])->assertForbidden();

    $this->assertDatabaseMissing('users', ['email' => 'smuggled@example.com']);
})->with(['editor', 'api']);

/** specs/features/authorization-policies.md REQ-4 — an admin cannot lock the last door behind them. */
test('an admin cannot delete their own account through user management', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->delete(route('users.destroy', $admin))->assertForbidden();

    expect(User::find($admin->id))->not->toBeNull();
});

// ─── forced browsing: the admin-only surface ──────────────────────────────────

test('a non-admin cannot reach the admin-only pages', function (string $role) {
    $actor = User::factory()->create(['role' => $role]);

    foreach ([
        'system.index',
        'system.logs',
        'system.queue-status',
        'api.index',
        'api.tokens',
        'api.jwt-secrets',
        'import.index',
        'export.index',
        'discover.index',
    ] as $name) {
        $this->actingAs($actor)->get(route($name))
            ->assertForbidden("Route {$name} served a {$role} instead of 403.");
    }
})->with(['editor', 'api']);

/**
 * SystemController::executeCommand runs artisan commands from the browser. It is the highest
 * consequence endpoint in the app, so it gets its own assertion rather than living in the loop.
 */
test('only an admin can execute a command through the system panel', function () {
    foreach (['editor', 'api'] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]))
            ->post(route('system.execute-command'), ['command' => 'route:list'])
            ->assertForbidden();
    }
});

test('a non-admin cannot mint or revoke API credentials', function (string $role) {
    $actor = User::factory()->create(['role' => $role]);
    $target = User::factory()->editor()->create();

    $this->actingAs($actor)->post(route('api.tokens.store'), [
        'user_id' => $target->id,
        'name' => 'stolen',
    ])->assertForbidden();

    $this->actingAs($actor)->post(route('api.jwt-secrets.generate', $target))->assertForbidden();
    $this->actingAs($actor)->delete(route('api.jwt-secrets.revoke', $target))->assertForbidden();

    expect($target->fresh()->tokens()->count())->toBe(0);
    expect($target->fresh()->jwt_secret)->toBeNull();
})->with(['editor', 'api']);

// ─── the api role is read/write, never destructive ────────────────────────────

test('an api-role caller cannot delete an asset through the REST API', function () {
    $apiUser = User::factory()->apiUser()->create();
    $asset = Asset::factory()->create(['user_id' => $apiUser->id]);

    $this->actingAs($apiUser)->deleteJson("/api/assets/{$asset->id}")->assertForbidden();

    expect($asset->fresh()->trashed())->toBeFalse();
});

test('an api-role caller cannot delete an asset through the web routes either', function () {
    $apiUser = User::factory()->apiUser()->create();
    $asset = Asset::factory()->create(['user_id' => $apiUser->id]);

    $this->actingAs($apiUser)->delete(route('assets.destroy', $asset))->assertForbidden();

    expect($asset->fresh()->trashed())->toBeFalse();
});

test('an api-role caller cannot reach the trash or restore an asset', function () {
    $apiUser = User::factory()->apiUser()->create();
    $asset = Asset::factory()->create();
    $asset->delete();

    $this->actingAs($apiUser)->get(route('assets.trash'))->assertForbidden();
    $this->actingAs($apiUser)->post(route('assets.restore', $asset))->assertForbidden();

    expect($asset->fresh()->trashed())->toBeTrue();
});

test('an api-role caller cannot replace an asset', function () {
    $apiUser = User::factory()->apiUser()->create();
    $asset = Asset::factory()->image()->create();

    $this->actingAs($apiUser)->get(route('assets.replace', $asset))->assertForbidden();
});

// ─── the api role holds no second-factor enrolment surface ────────────────────

/**
 * specs/features/two-factor-auth.md REQ-1 and passkeys.md REQ-1 keep the api role out of both
 * enrolment flows. An api account is a machine credential with no interactive login, so a
 * passkey or TOTP secret registered against one would be a login path that should not exist.
 *
 * The two flows refuse differently — 2FA redirects to the profile with an error, passkeys
 * return 403 — so this asserts the property that actually matters in both cases: the caller
 * does not get a usable enrolment surface, and nothing is persisted. Asserting a specific
 * status here would pin a UX detail rather than the security boundary. The status codes
 * themselves are pinned by tests/Feature/TwoFactorAuthTest.php and PasskeyTest.php.
 */
test('an api-role caller cannot enrol a second factor', function () {
    $apiUser = User::factory()->apiUser()->create();

    $setup = $this->actingAs($apiUser)->get(route('two-factor.setup'));
    expect($setup->getStatusCode())->not->toBe(200);
    $setup->assertRedirect(route('profile.edit'));

    $options = $this->actingAs($apiUser)->getJson(route('profile.passkeys.options'));
    expect($options->getStatusCode())->not->toBe(200);

    $store = $this->actingAs($apiUser)->postJson(route('profile.passkeys.store'), []);
    expect($store->getStatusCode())->not->toBe(200);

    // The load-bearing half: no credential of either kind now exists for this account.
    $fresh = $apiUser->fresh();
    expect($fresh->two_factor_secret)->toBeNull();
    expect($fresh->two_factor_confirmed_at)->toBeNull();
    expect($fresh->hasTwoFactorEnabled())->toBeFalse();
    expect($fresh->passkeys()->count())->toBe(0);
});

// ─── the baseline: a guest gets nowhere ───────────────────────────────────────

test('a guest is refused the admin surface outright', function () {
    foreach (['system.index', 'users.index', 'api.tokens', 'export.index'] as $name) {
        $response = $this->get(route($name));

        expect($response->getStatusCode())->not->toBe(200);
        $response->assertRedirect(route('login'));
    }
});
