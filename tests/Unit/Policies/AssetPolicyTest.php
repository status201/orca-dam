<?php

use App\Models\Asset;
use App\Models\Setting;
use App\Models\User;
use App\Policies\AssetPolicy;

beforeEach(function () {
    $this->policy = new AssetPolicy;
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->editor = User::factory()->create(['role' => 'editor']);
    $this->api = User::factory()->create(['role' => 'api']);
    $this->asset = Asset::factory()->image()->create();
});

test('all authenticated roles can view, viewAny, create, update', function () {
    foreach ([$this->admin, $this->editor, $this->api] as $user) {
        expect($this->policy->viewAny($user))->toBeTrue();
        expect($this->policy->view($user, $this->asset))->toBeTrue();
        expect($this->policy->create($user))->toBeTrue();
        expect($this->policy->update($user, $this->asset))->toBeTrue();
    }
});

test('api users cannot replace or delete', function () {
    expect($this->policy->replace($this->api))->toBeFalse();
    expect($this->policy->delete($this->api, $this->asset))->toBeFalse();
});

test('admin and editor can replace and delete', function () {
    foreach ([$this->admin, $this->editor] as $user) {
        expect($this->policy->replace($user))->toBeTrue();
        expect($this->policy->delete($user, $this->asset))->toBeTrue();
    }
});

test('restore allowed only for admin and editor', function () {
    expect($this->policy->restore($this->admin))->toBeTrue();
    expect($this->policy->restore($this->editor))->toBeTrue();
    expect($this->policy->restore($this->api))->toBeFalse();
});

test('forceDelete allowed only for admin', function () {
    expect($this->policy->forceDelete($this->admin))->toBeTrue();
    expect($this->policy->forceDelete($this->editor))->toBeFalse();
    expect($this->policy->forceDelete($this->api))->toBeFalse();
});

test('discover and export admin-only', function () {
    foreach (['discover', 'export'] as $ability) {
        expect($this->policy->$ability($this->admin))->toBeTrue();
        expect($this->policy->$ability($this->editor))->toBeFalse();
        expect($this->policy->$ability($this->api))->toBeFalse();
    }
});

test('move requires admin AND maintenance mode', function () {
    Setting::set('maintenance_mode', false);
    expect($this->policy->move($this->admin))->toBeFalse();

    Setting::set('maintenance_mode', true);
    expect($this->policy->move($this->admin))->toBeTrue();
    expect($this->policy->move($this->editor))->toBeFalse();
    expect($this->policy->move($this->api))->toBeFalse();
});

test('bulkForceDelete requires admin AND maintenance mode', function () {
    Setting::set('maintenance_mode', true);
    expect($this->policy->bulkForceDelete($this->admin))->toBeTrue();
    expect($this->policy->bulkForceDelete($this->editor))->toBeFalse();

    Setting::set('maintenance_mode', false);
    expect($this->policy->bulkForceDelete($this->admin))->toBeFalse();
});

test('bulkTrash forbidden for api users, allowed for admin and editor', function () {
    expect($this->policy->bulkTrash($this->admin))->toBeTrue();
    expect($this->policy->bulkTrash($this->editor))->toBeTrue();
    expect($this->policy->bulkTrash($this->api))->toBeFalse();
});

test('bulkDownload allowed for all authenticated users', function () {
    foreach ([$this->admin, $this->editor, $this->api] as $user) {
        expect($this->policy->bulkDownload($user))->toBeTrue();
    }
});
