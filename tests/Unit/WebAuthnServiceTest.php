<?php

use App\Models\User;
use App\Services\WebAuthnService;
use Illuminate\Support\Str;
use Laragear\WebAuthn\Models\WebAuthnCredential;

function makeCredential(User $user, array $overrides = []): WebAuthnCredential
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

beforeEach(function () {
    $this->service = new WebAuthnService;
});

test('listCredentials returns user passkeys ordered newest first', function () {
    $user = User::factory()->create();
    $older = makeCredential($user, ['alias' => 'old']);
    $older->created_at = now()->subDay();
    $older->save();
    $newer = makeCredential($user, ['alias' => 'new']);

    $list = $this->service->listCredentials($user);

    expect($list)->toHaveCount(2);
    expect($list->first()->alias)->toBe('new');
});

test('hasReachedLimit reports the per-user maximum', function () {
    $user = User::factory()->create();

    expect($this->service->hasReachedLimit($user))->toBeFalse();

    for ($i = 0; $i < WebAuthnService::MAX_CREDENTIALS_PER_USER; $i++) {
        makeCredential($user);
    }

    expect($this->service->hasReachedLimit($user))->toBeTrue();
});

test('renameCredential updates the alias on an owned credential', function () {
    $user = User::factory()->create();
    $credential = makeCredential($user, ['alias' => 'old']);

    $updated = $this->service->renameCredential($user, $credential->id, 'My MacBook');

    expect($updated)->not->toBeNull();
    expect($updated->fresh()->alias)->toBe('My MacBook');
});

test('renameCredential trims whitespace and stores empty as null', function () {
    $user = User::factory()->create();
    $credential = makeCredential($user, ['alias' => 'old']);

    $this->service->renameCredential($user, $credential->id, '   ');

    expect($credential->fresh()->alias)->toBeNull();
});

test('renameCredential refuses to rename a credential owned by another user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $credential = makeCredential($owner, ['alias' => 'mine']);

    $result = $this->service->renameCredential($other, $credential->id, 'stolen');

    expect($result)->toBeNull();
    expect($credential->fresh()->alias)->toBe('mine');
});

test('deleteCredential removes an owned credential', function () {
    $user = User::factory()->create();
    $credential = makeCredential($user);

    $deleted = $this->service->deleteCredential($user, $credential->id);

    expect($deleted)->toBeTrue();
    expect(WebAuthnCredential::find($credential->id))->toBeNull();
});

test('deleteCredential does not delete another users credential', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $credential = makeCredential($owner);

    $deleted = $this->service->deleteCredential($other, $credential->id);

    expect($deleted)->toBeFalse();
    expect(WebAuthnCredential::find($credential->id))->not->toBeNull();
});

test('clearAllCredentials wipes every passkey for a user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    makeCredential($user);
    makeCredential($user);
    makeCredential($other);

    $count = $this->service->clearAllCredentials($user);

    expect($count)->toBe(2);
    expect($user->webAuthnCredentials()->count())->toBe(0);
    expect($other->webAuthnCredentials()->count())->toBe(1);
});
