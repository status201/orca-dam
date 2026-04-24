<?php

use App\Models\User;
use App\Policies\UserPolicy;

beforeEach(function () {
    $this->policy = new UserPolicy;
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->editor = User::factory()->create(['role' => 'editor']);
    $this->api = User::factory()->create(['role' => 'api']);
    $this->target = User::factory()->create(['role' => 'editor']);
});

test('viewAny, create, update allowed only for admin', function () {
    expect($this->policy->viewAny($this->admin))->toBeTrue();
    expect($this->policy->viewAny($this->editor))->toBeFalse();
    expect($this->policy->viewAny($this->api))->toBeFalse();

    expect($this->policy->create($this->admin))->toBeTrue();
    expect($this->policy->create($this->editor))->toBeFalse();

    expect($this->policy->update($this->admin, $this->target))->toBeTrue();
    expect($this->policy->update($this->editor, $this->target))->toBeFalse();
});

test('admin can delete other users but not themselves', function () {
    expect($this->policy->delete($this->admin, $this->target))->toBeTrue();
    expect($this->policy->delete($this->admin, $this->admin))->toBeFalse();
});

test('non-admins cannot delete any user', function () {
    expect($this->policy->delete($this->editor, $this->target))->toBeFalse();
    expect($this->policy->delete($this->api, $this->target))->toBeFalse();
});
