<?php

use App\Models\Asset;
use App\Models\User;

test('users index forbidden for non-admin', function () {
    $this->actingAs(User::factory()->create(['role' => 'editor']))
        ->get('/users')
        ->assertForbidden();
});

test('users index accessible for admin', function () {
    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->get('/users')
        ->assertOk();
});

test('admin can create a new user', function () {
    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->post('/users', [
            'name' => 'New Editor',
            'email' => 'editor@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'editor',
        ])
        ->assertRedirect('/users');

    $user = User::where('email', 'editor@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->role)->toBe('editor');
});

test('user creation rejects invalid role', function () {
    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->post('/users', [
            'name' => 'Invalid',
            'email' => 'bad@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'superadmin',
        ])
        ->assertSessionHasErrors('role');
});

test('admin can update user role', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $target = User::factory()->create(['role' => 'editor', 'email' => 'x@example.com']);

    $this->actingAs($admin)
        ->put("/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role' => 'api',
        ])
        ->assertRedirect('/users');

    expect($target->fresh()->role)->toBe('api');
});

test('admin cannot delete themselves', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)
        ->delete("/users/{$admin->id}")
        ->assertForbidden();

    expect(User::find($admin->id))->not->toBeNull();
});

test('delete without assets removes user immediately', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $target = User::factory()->create(['role' => 'editor']);

    $this->actingAs($admin)
        ->delete("/users/{$target->id}")
        ->assertRedirect('/users');

    expect(User::find($target->id))->toBeNull();
});

test('delete with assets requires transfer_to_user_id and reassigns ownership', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $target = User::factory()->create(['role' => 'editor']);
    $transferTo = User::factory()->create(['role' => 'editor']);
    Asset::factory()->image()->count(2)->create(['user_id' => $target->id]);

    $this->actingAs($admin)
        ->delete("/users/{$target->id}", ['transfer_to_user_id' => $transferTo->id])
        ->assertRedirect('/users');

    expect(User::find($target->id))->toBeNull();
    expect(Asset::where('user_id', $transferTo->id)->count())->toBe(2);
});

test('delete with assets rejects missing transfer_to_user_id', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $target = User::factory()->create(['role' => 'editor']);
    Asset::factory()->image()->create(['user_id' => $target->id]);

    $this->actingAs($admin)
        ->delete("/users/{$target->id}")
        ->assertSessionHasErrors('transfer_to_user_id');

    expect(User::find($target->id))->not->toBeNull();
});
