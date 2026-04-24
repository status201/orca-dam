<?php

use App\Models\Asset;
use App\Models\Setting;
use App\Models\User;
use App\Services\S3Service;

test('GET /api/folders requires authentication', function () {
    $this->getJson('/api/folders')->assertUnauthorized();
});

test('GET /api/folders returns configured folders for any authenticated user', function () {
    Setting::set('s3_folders', ['assets', 'assets/marketing'], 'json', 'aws');

    $user = User::factory()->create(['role' => 'api']);
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/folders')
        ->assertOk()
        ->assertJson(['folders' => ['assets', 'assets/marketing']]);
});

test('POST /folders/scan is admin-only', function () {
    $this->actingAs(User::factory()->create(['role' => 'editor']))
        ->postJson('/folders/scan')
        ->assertForbidden();
});

test('POST /folders/scan refreshes folder list from S3', function () {
    $mock = Mockery::mock(S3Service::class);
    $mock->shouldReceive('listFolders')->once()->andReturn(['assets', 'assets/new']);
    $this->app->instance(S3Service::class, $mock);

    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->postJson('/folders/scan')
        ->assertOk()
        ->assertJson(['folders' => ['assets', 'assets/new']]);

    expect(Setting::get('s3_folders'))->toBe(['assets', 'assets/new']);
});

test('POST /folders creates a folder (admin only)', function () {
    Setting::set('s3_folders', ['assets'], 'json', 'aws');

    $mock = Mockery::mock(S3Service::class);
    $mock->shouldReceive('createFolder')->once()->with('assets/new-stuff')->andReturn(true);
    $this->app->instance(S3Service::class, $mock);

    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->postJson('/folders', ['name' => 'new-stuff', 'parent' => 'assets'])
        ->assertCreated()
        ->assertJson(['folder' => 'assets/new-stuff']);

    expect(Setting::get('s3_folders'))->toContain('assets/new-stuff');
});

test('POST /folders rejects invalid folder name', function () {
    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->postJson('/folders', ['name' => 'bad name!'])
        ->assertUnprocessable();
});

test('POST /folders forbidden for editor', function () {
    $this->actingAs(User::factory()->create(['role' => 'editor']))
        ->postJson('/folders', ['name' => 'x'])
        ->assertForbidden();
});
