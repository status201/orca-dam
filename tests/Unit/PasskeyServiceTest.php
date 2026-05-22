<?php

use App\Models\Passkey;
use App\Models\User;
use App\Services\PasskeyService;

function makePasskey(User $user, array $overrides = []): Passkey
{
    return Passkey::forceCreate(array_merge([
        'user_id' => $user->getKey(),
        'name' => 'Test Passkey',
        'credential_id' => bin2hex(random_bytes(16)),
        'credential' => [],
    ], $overrides));
}

beforeEach(function () {
    $this->service = new PasskeyService;
});

test('listCredentials returns user passkeys ordered newest first', function () {
    $user = User::factory()->create();
    $older = makePasskey($user, ['name' => 'old']);
    $older->created_at = now()->subDay();
    $older->save();
    makePasskey($user, ['name' => 'new']);

    $list = $this->service->listCredentials($user);

    expect($list)->toHaveCount(2);
    expect($list->first()->name)->toBe('new');
});

test('hasReachedLimit reports the per-user maximum', function () {
    $user = User::factory()->create();

    expect($this->service->hasReachedLimit($user))->toBeFalse();

    for ($i = 0; $i < PasskeyService::MAX_CREDENTIALS_PER_USER; $i++) {
        makePasskey($user);
    }

    expect($this->service->hasReachedLimit($user))->toBeTrue();
});

test('renameCredential updates the name on an owned passkey', function () {
    $user = User::factory()->create();
    $passkey = makePasskey($user, ['name' => 'old']);

    $updated = $this->service->renameCredential($user, $passkey->credential_id, 'My MacBook');

    expect($updated)->not->toBeNull();
    expect($updated->fresh()->name)->toBe('My MacBook');
});

test('renameCredential trims whitespace and falls back to default when empty', function () {
    $user = User::factory()->create();
    $passkey = makePasskey($user, ['name' => 'old']);

    $this->service->renameCredential($user, $passkey->credential_id, '   ');

    expect($passkey->fresh()->name)->toBe(__('Passkey'));
});

test('renameCredential refuses to rename a passkey owned by another user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $passkey = makePasskey($owner, ['name' => 'mine']);

    $result = $this->service->renameCredential($other, $passkey->credential_id, 'stolen');

    expect($result)->toBeNull();
    expect($passkey->fresh()->name)->toBe('mine');
});

test('deleteCredential removes an owned passkey', function () {
    $user = User::factory()->create();
    $passkey = makePasskey($user);

    $deleted = $this->service->deleteCredential($user, $passkey->credential_id);

    expect($deleted)->toBeTrue();
    expect(Passkey::find($passkey->id))->toBeNull();
});

test('deleteCredential does not delete another users passkey', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $passkey = makePasskey($owner);

    $deleted = $this->service->deleteCredential($other, $passkey->credential_id);

    expect($deleted)->toBeFalse();
    expect(Passkey::find($passkey->id))->not->toBeNull();
});

test('clearAllCredentials wipes every passkey for a user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    makePasskey($user);
    makePasskey($user);
    makePasskey($other);

    $count = $this->service->clearAllCredentials($user);

    expect($count)->toBe(2);
    expect($user->passkeys()->count())->toBe(0);
    expect($other->passkeys()->count())->toBe(1);
});
