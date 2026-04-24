<?php

use App\Models\User;
use App\Policies\SystemPolicy;

beforeEach(function () {
    $this->policy = new SystemPolicy;
});

test('only admin can access system administration', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $editor = User::factory()->create(['role' => 'editor']);
    $api = User::factory()->create(['role' => 'api']);

    expect($this->policy->access($admin))->toBeTrue();
    expect($this->policy->access($editor))->toBeFalse();
    expect($this->policy->access($api))->toBeFalse();
});
