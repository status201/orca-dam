<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

test('setting get returns default when key does not exist', function () {
    $value = Setting::get('nonexistent_key', 'default_value');

    expect($value)->toBe('default_value');
});

test('setting get returns stored value', function () {
    Setting::create([
        'key' => 'test_key',
        'value' => 'test_value',
        'type' => 'string',
        'group' => 'test',
    ]);

    $value = Setting::get('test_key');

    expect($value)->toBe('test_value');
});

test('setting get casts integer type', function () {
    Setting::create([
        'key' => 'test_integer_setting',
        'value' => '42',
        'type' => 'integer',
        'group' => 'test',
    ]);

    $value = Setting::get('test_integer_setting');

    expect($value)->toBe(42);
    expect($value)->toBeInt();
});

test('setting get casts boolean type', function () {
    Setting::create([
        'key' => 'feature_enabled',
        'value' => '1',
        'type' => 'boolean',
        'group' => 'features',
    ]);

    $value = Setting::get('feature_enabled');

    expect($value)->toBeTrue();
    expect($value)->toBeBool();
});

test('setting get casts json type', function () {
    Setting::create([
        'key' => 'config',
        'value' => '{"foo":"bar","baz":123}',
        'type' => 'json',
        'group' => 'config',
    ]);

    $value = Setting::get('config');

    expect($value)->toBeArray();
    expect($value['foo'])->toBe('bar');
    expect($value['baz'])->toBe(123);
});

test('setting set updates existing value', function () {
    Setting::create([
        'key' => 'test_key',
        'value' => 'original_value',
        'type' => 'string',
        'group' => 'test',
    ]);

    $result = Setting::set('test_key', 'new_value');

    expect($result)->toBeTrue();
    expect(Setting::get('test_key'))->toBe('new_value');
});

test('setting set returns false for nonexistent key', function () {
    $result = Setting::set('nonexistent_key', 'value');

    expect($result)->toBeFalse();
});

test('setting set clears cache', function () {
    Setting::create([
        'key' => 'cached_key',
        'value' => 'original',
        'type' => 'string',
        'group' => 'test',
    ]);

    // Prime the cache
    Setting::get('cached_key');

    // Update directly in database
    Setting::set('cached_key', 'updated');

    // Should return updated value, not cached
    expect(Setting::get('cached_key'))->toBe('updated');
});

test('setting allSettings returns all settings as key-value array', function () {
    Setting::create(['key' => 'key1', 'value' => 'value1', 'type' => 'string', 'group' => 'test']);
    Setting::create(['key' => 'key2', 'value' => '42', 'type' => 'integer', 'group' => 'test']);

    $settings = Setting::allSettings();

    expect($settings)->toHaveKey('key1');
    expect($settings)->toHaveKey('key2');
    expect($settings['key1'])->toBe('value1');
    expect($settings['key2'])->toBe(42);
});

test('setting allGrouped returns settings grouped by group', function () {
    Setting::create(['key' => 'key1', 'value' => 'value1', 'type' => 'string', 'group' => 'group1']);
    Setting::create(['key' => 'key2', 'value' => 'value2', 'type' => 'string', 'group' => 'group1']);
    Setting::create(['key' => 'key3', 'value' => 'value3', 'type' => 'string', 'group' => 'group2']);

    $grouped = Setting::allGrouped();

    expect($grouped)->toHaveKey('group1');
    expect($grouped)->toHaveKey('group2');
    expect($grouped['group1'])->toHaveCount(2);
    expect($grouped['group2'])->toHaveCount(1);
});
