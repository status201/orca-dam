<?php

// error-handling.md REQ-flash-messages-shown — the layout renders a redirect's flash, once.

use App\Models\Asset;
use App\Models\User;

function flashCount(string $html, string $type): int
{
    return substr_count($html, 'data-testid="flash-'.$type.'"');
}

test('deleting a user shows the success message on the user list', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'editor']);

    $response = $this->actingAs($admin)->followingRedirects()->delete(route('users.destroy', $user));

    $response->assertOk()->assertSee(__('User deleted successfully.'));
    expect(flashCount($response->getContent(), 'success'))->toBe(1);
});

test('trashing an asset shows the success message on the asset index', function () {
    $user = User::factory()->create(['role' => 'editor']);
    $asset = Asset::factory()->create();

    $response = $this->actingAs($user)->followingRedirects()->delete(route('assets.destroy', $asset));

    $response->assertOk()->assertSee(__('Asset moved to trash successfully'));
    expect(flashCount($response->getContent(), 'success'))->toBe(1);
});

test('error and warning flashes render once on an asset page', function (string $type) {
    $user = User::factory()->create(['role' => 'editor']);
    $asset = Asset::factory()->create();

    $html = $this->actingAs($user)
        ->withSession([$type => 'Flash '.$type.' text'])
        ->get(route('assets.show', $asset))
        ->assertOk()
        ->assertSee('Flash '.$type.' text')
        ->getContent();

    expect(flashCount($html, $type))->toBe(1);
})->with(['error', 'warning']);

test('a page without a flash renders no message', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $html = $this->actingAs($admin)->get(route('users.index'))->assertOk()->getContent();

    expect(flashCount($html, 'success') + flashCount($html, 'error') + flashCount($html, 'warning'))->toBe(0);
});
