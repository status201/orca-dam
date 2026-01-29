<?php

use App\Models\Setting;
use App\Models\User;

test('user getPreference returns default when preference does not exist', function () {
    $user = User::factory()->create();

    $value = $user->getPreference('nonexistent', 'default');

    expect($value)->toBe('default');
});

test('user getPreference returns stored preference value', function () {
    $user = User::factory()->create([
        'preferences' => ['home_folder' => 'assets/marketing'],
    ]);

    $value = $user->getPreference('home_folder');

    expect($value)->toBe('assets/marketing');
});

test('user setPreference stores value and saves', function () {
    $user = User::factory()->create();

    $result = $user->setPreference('items_per_page', 48);

    expect($result)->toBeTrue();
    expect($user->fresh()->getPreference('items_per_page'))->toBe(48);
});

test('user setPreference preserves existing preferences', function () {
    $user = User::factory()->create([
        'preferences' => ['home_folder' => 'assets/docs'],
    ]);

    $user->setPreference('items_per_page', 36);

    $user->refresh();
    expect($user->getPreference('home_folder'))->toBe('assets/docs');
    expect($user->getPreference('items_per_page'))->toBe(36);
});

test('user getHomeFolder returns user preference when valid', function () {
    Setting::set('s3_root_folder', 'assets', 'string', 'aws');

    $user = User::factory()->create([
        'preferences' => ['home_folder' => 'assets/marketing'],
    ]);

    expect($user->getHomeFolder())->toBe('assets/marketing');
});

test('user getHomeFolder returns global root when no preference set', function () {
    Setting::set('s3_root_folder', 'assets', 'string', 'aws');

    $user = User::factory()->create();

    expect($user->getHomeFolder())->toBe('assets');
});

test('user getHomeFolder returns global root when preference is invalid', function () {
    Setting::set('s3_root_folder', 'assets', 'string', 'aws');

    $user = User::factory()->create([
        'preferences' => ['home_folder' => 'other/folder'], // Outside root
    ]);

    expect($user->getHomeFolder())->toBe('assets');
});

test('user isValidHomeFolder returns true for folder within root', function () {
    Setting::set('s3_root_folder', 'assets', 'string', 'aws');

    $user = User::factory()->create();

    expect($user->isValidHomeFolder('assets'))->toBeTrue();
    expect($user->isValidHomeFolder('assets/marketing'))->toBeTrue();
    expect($user->isValidHomeFolder('assets/docs/2024'))->toBeTrue();
});

test('user isValidHomeFolder returns false for folder outside root', function () {
    Setting::set('s3_root_folder', 'assets', 'string', 'aws');

    $user = User::factory()->create();

    expect($user->isValidHomeFolder('other'))->toBeFalse();
    expect($user->isValidHomeFolder('assets2/folder'))->toBeFalse();
});

test('user isValidHomeFolder returns true for any folder when no root configured', function () {
    Setting::set('s3_root_folder', '', 'string', 'aws');

    $user = User::factory()->create();

    expect($user->isValidHomeFolder('any/folder'))->toBeTrue();
    expect($user->isValidHomeFolder('assets'))->toBeTrue();
});

test('user getItemsPerPage returns user preference when set', function () {
    Setting::set('items_per_page', 24, 'integer', 'display');

    $user = User::factory()->create([
        'preferences' => ['items_per_page' => 48],
    ]);

    expect($user->getItemsPerPage())->toBe(48);
});

test('user getItemsPerPage returns global setting when no preference', function () {
    Setting::set('items_per_page', 36, 'integer', 'display');

    $user = User::factory()->create();

    expect($user->getItemsPerPage())->toBe(36);
});

test('user getItemsPerPage returns global setting when preference is zero', function () {
    Setting::set('items_per_page', 24, 'integer', 'display');

    $user = User::factory()->create([
        'preferences' => ['items_per_page' => 0],
    ]);

    expect($user->getItemsPerPage())->toBe(24);
});

test('user preferences column is cast to array', function () {
    $user = User::factory()->create([
        'preferences' => ['key' => 'value'],
    ]);

    expect($user->preferences)->toBeArray();
    expect($user->preferences['key'])->toBe('value');
});

test('user preferences can be null', function () {
    $user = User::factory()->create([
        'preferences' => null,
    ]);

    expect($user->preferences)->toBeNull();
    expect($user->getPreference('any_key', 'default'))->toBe('default');
});
