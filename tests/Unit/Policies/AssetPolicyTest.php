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

/**
 * Each row: [ability, admin allowed?, editor allowed?, api allowed?]
 * Adding a new role means adding one column here, not N new tests.
 */
dataset('role_matrix', [
    'viewAny' => ['viewAny', true, true, true],
    'view' => ['view', true, true, true],
    'create' => ['create', true, true, true],
    'update' => ['update', true, true, true],
    'replace' => ['replace', true, true, false],
    'delete' => ['delete', true, true, false],
    'restore' => ['restore', true, true, false],
    'forceDelete' => ['forceDelete', true, false, false],
    'discover' => ['discover', true, false, false],
    'export' => ['export', true, false, false],
    'bulkTrash' => ['bulkTrash', true, true, false],
    'bulkRestore' => ['bulkRestore', true, true, false],
    'bulkDownload' => ['bulkDownload', true, true, true],
]);

test('role matrix is enforced for ability', function (string $ability, bool $admin, bool $editor, bool $api) {
    $needsAsset = in_array($ability, ['view', 'update', 'delete'], true);

    $call = fn (User $u) => $needsAsset
        ? $this->policy->$ability($u, $this->asset)
        : $this->policy->$ability($u);

    expect($call($this->admin))->toBe($admin, "admin → $ability");
    expect($call($this->editor))->toBe($editor, "editor → $ability");
    expect($call($this->api))->toBe($api, "api → $ability");
})->with('role_matrix');

test('move requires admin AND maintenance mode', function () {
    Setting::set('maintenance_mode', false);
    expect($this->policy->move($this->admin))->toBeFalse();
    expect($this->policy->move($this->editor))->toBeFalse();
    expect($this->policy->move($this->api))->toBeFalse();

    Setting::set('maintenance_mode', true);
    expect($this->policy->move($this->admin))->toBeTrue();
    expect($this->policy->move($this->editor))->toBeFalse();
    expect($this->policy->move($this->api))->toBeFalse();
});

test('bulkForceDelete requires admin AND maintenance mode', function () {
    Setting::set('maintenance_mode', false);
    expect($this->policy->bulkForceDelete($this->admin))->toBeFalse();
    expect($this->policy->bulkForceDelete($this->editor))->toBeFalse();
    expect($this->policy->bulkForceDelete($this->api))->toBeFalse();

    Setting::set('maintenance_mode', true);
    expect($this->policy->bulkForceDelete($this->admin))->toBeTrue();
    expect($this->policy->bulkForceDelete($this->editor))->toBeFalse();
    expect($this->policy->bulkForceDelete($this->api))->toBeFalse();
});
