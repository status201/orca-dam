<?php

use App\Models\Asset;
use App\Models\User;

test('guests cannot access embed view', function () {
    $response = $this->get(route('assets.embed'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can access embed view', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('assets.embed'));

    $response->assertStatus(200);
});

test('embed view does not include navigation or footer', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('assets.embed'));

    $response->assertStatus(200);
    $response->assertDontSee('id="orca-footer"', false);

    // Regular index should include footer
    $indexResponse = $this->actingAs($user)->get(route('assets.index'));
    $indexResponse->assertSee('id="orca-footer"', false);
});

test('embed view shows assets', function () {
    $user = User::factory()->create();
    Asset::factory()->create(['filename' => 'embed-test.jpg']);

    $response = $this->actingAs($user)->get(route('assets.embed'));

    $response->assertStatus(200);
    $response->assertSee('embed-test.jpg');
});

test('embed view supports type filter', function () {
    $user = User::factory()->create();
    Asset::factory()->create(['filename' => 'photo.jpg', 'mime_type' => 'image/jpeg']);
    Asset::factory()->create(['filename' => 'video.mp4', 'mime_type' => 'video/mp4']);

    $response = $this->actingAs($user)->get(route('assets.embed', ['type' => 'image']));

    $response->assertStatus(200);
    $response->assertSee('photo.jpg');
    $response->assertDontSee('video.mp4');
});

test('embed view supports search filter', function () {
    $user = User::factory()->create();
    Asset::factory()->create(['filename' => 'findme.jpg']);
    Asset::factory()->create(['filename' => 'other.pdf']);

    $response = $this->actingAs($user)->get(route('assets.embed', ['search' => 'findme']));

    $response->assertStatus(200);
    $response->assertSee('findme.jpg');
    $response->assertDontSee('other.pdf');
});

test('embed view uses embed route for filter navigation', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('assets.embed'));

    $response->assertStatus(200);
    $response->assertSee(route('assets.embed'));
});
