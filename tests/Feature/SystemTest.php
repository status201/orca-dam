<?php

use App\Models\Setting;
use App\Models\User;

test('guests cannot access system page', function () {
    $response = $this->get(route('system.index'));

    $response->assertRedirect(route('login'));
});

test('editors cannot access system page', function () {
    $user = User::factory()->create(['role' => 'editor']);

    $response = $this->actingAs($user)->get(route('system.index'));

    $response->assertForbidden();
});

test('admins can access system page', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->get(route('system.index'));

    $response->assertStatus(200);
});

test('admin can update items_per_page setting', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->postJson(route('system.update-setting'), [
        'key' => 'items_per_page',
        'value' => '48',
    ]);

    $response->assertOk();
    $response->assertJson(['success' => true]);
    expect(Setting::get('items_per_page'))->toBe(48);
});

test('admin can update rekognition_max_labels setting', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->postJson(route('system.update-setting'), [
        'key' => 'rekognition_max_labels',
        'value' => '10',
    ]);

    $response->assertOk();
    $response->assertJson(['success' => true]);
    expect(Setting::get('rekognition_max_labels'))->toBe(10);
});

test('admin can update rekognition_min_confidence setting', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->postJson(route('system.update-setting'), [
        'key' => 'rekognition_min_confidence',
        'value' => '85',
    ]);

    $response->assertOk();
    $response->assertJson(['success' => true]);
    expect(Setting::get('rekognition_min_confidence'))->toBe(85);
});

test('rekognition_min_confidence rejects values below 65', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->postJson(route('system.update-setting'), [
        'key' => 'rekognition_min_confidence',
        'value' => '50',
    ]);

    $response->assertStatus(422);
    $response->assertJson(['success' => false]);
});

test('rekognition_min_confidence rejects values above 99', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->postJson(route('system.update-setting'), [
        'key' => 'rekognition_min_confidence',
        'value' => '100',
    ]);

    $response->assertStatus(422);
    $response->assertJson(['success' => false]);
});

test('admin can update rekognition_language setting', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->postJson(route('system.update-setting'), [
        'key' => 'rekognition_language',
        'value' => 'nl',
    ]);

    $response->assertOk();
    $response->assertJson(['success' => true]);
    expect(Setting::get('rekognition_language'))->toBe('nl');
});

test('admin can update s3_root_folder setting', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->postJson(route('system.update-setting'), [
        'key' => 's3_root_folder',
        'value' => 'assets/media',
    ]);

    $response->assertOk();
    $response->assertJson(['success' => true]);
    expect(Setting::get('s3_root_folder'))->toBe('assets/media');
});

test('s3_root_folder allows empty string', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->postJson(route('system.update-setting'), [
        'key' => 's3_root_folder',
        'value' => '',
    ]);

    $response->assertOk();
    $response->assertJson(['success' => true]);
    expect(Setting::get('s3_root_folder'))->toBe('');
});

test('admin can update timezone setting', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->postJson(route('system.update-setting'), [
        'key' => 'timezone',
        'value' => 'Europe/Amsterdam',
    ]);

    $response->assertOk();
    $response->assertJson(['success' => true]);
    expect(Setting::get('timezone'))->toBe('Europe/Amsterdam');
});

test('timezone rejects invalid values', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->postJson(route('system.update-setting'), [
        'key' => 'timezone',
        'value' => 'Invalid/Timezone',
    ]);

    $response->assertStatus(422);
    $response->assertJson(['success' => false]);
});

test('items_per_page rejects invalid values', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->postJson(route('system.update-setting'), [
        'key' => 'items_per_page',
        'value' => '5', // Below minimum of 10
    ]);

    $response->assertStatus(422);
    $response->assertJson(['success' => false]);
});

test('editors cannot update settings', function () {
    $editor = User::factory()->create(['role' => 'editor']);

    $response = $this->actingAs($editor)->postJson(route('system.update-setting'), [
        'key' => 'items_per_page',
        'value' => '48',
    ]);

    $response->assertForbidden();
});

test('admin can update locale setting', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->postJson(route('system.update-setting'), [
        'key' => 'locale',
        'value' => 'nl',
    ]);

    $response->assertOk();
    $response->assertJson(['success' => true]);
    expect(Setting::get('locale'))->toBe('nl');
});

test('locale rejects invalid values', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->postJson(route('system.update-setting'), [
        'key' => 'locale',
        'value' => 'xx',
    ]);

    $response->assertStatus(422);
    $response->assertJson(['success' => false]);
});

test('admin can update custom_domain setting', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->postJson(route('system.update-setting'), [
        'key' => 'custom_domain',
        'value' => 'https://cdn.example.com',
    ]);

    $response->assertOk();
    $response->assertJson(['success' => true]);
    expect(Setting::get('custom_domain'))->toBe('https://cdn.example.com');
});

test('custom_domain allows empty string', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->postJson(route('system.update-setting'), [
        'key' => 'custom_domain',
        'value' => '',
    ]);

    $response->assertOk();
    $response->assertJson(['success' => true]);
});

test('custom_domain rejects invalid urls', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->postJson(route('system.update-setting'), [
        'key' => 'custom_domain',
        'value' => 'not-a-url',
    ]);

    $response->assertStatus(422);
    $response->assertJson(['success' => false]);
});

test('system page loads all settings correctly', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    // Set up some settings
    Setting::set('items_per_page', 36, 'integer', 'display');
    Setting::set('rekognition_max_labels', 5, 'integer', 'aws');
    Setting::set('rekognition_min_confidence', 85, 'integer', 'aws');
    Setting::set('rekognition_language', 'nl', 'string', 'aws');

    $response = $this->actingAs($admin)->get(route('system.index'));

    $response->assertStatus(200);
    // Verify settings are passed to view
    expect($response['settings'])->toBeArray();
});
