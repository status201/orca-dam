<?php

use App\Models\Asset;
use App\Models\User;

test('admin can delete user with no assets', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'editor']);

    $response = $this->actingAs($admin)->delete(route('users.destroy', $user));

    $response->assertRedirect(route('users.index'));
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
});

test('admin can delete user and transfer assets to another user', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'editor']);
    $targetUser = User::factory()->create(['role' => 'editor']);

    $asset1 = Asset::factory()->create(['user_id' => $user->id]);
    $asset2 = Asset::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($admin)->delete(route('users.destroy', $user), [
        'transfer_to_user_id' => $targetUser->id,
    ]);

    $response->assertRedirect(route('users.index'));
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseHas('assets', ['id' => $asset1->id, 'user_id' => $targetUser->id]);
    $this->assertDatabaseHas('assets', ['id' => $asset2->id, 'user_id' => $targetUser->id]);
});

test('soft-deleted assets are also transferred on user deletion', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'editor']);
    $targetUser = User::factory()->create(['role' => 'editor']);

    $activeAsset = Asset::factory()->create(['user_id' => $user->id]);
    $trashedAsset = Asset::factory()->create(['user_id' => $user->id, 'deleted_at' => now()]);

    $response = $this->actingAs($admin)->delete(route('users.destroy', $user), [
        'transfer_to_user_id' => $targetUser->id,
    ]);

    $response->assertRedirect(route('users.index'));
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseHas('assets', ['id' => $activeAsset->id, 'user_id' => $targetUser->id]);
    $this->assertDatabaseHas('assets', ['id' => $trashedAsset->id, 'user_id' => $targetUser->id]);
});

test('deletion fails without transfer target when user has assets', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'editor']);

    Asset::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($admin)->delete(route('users.destroy', $user));

    $response->assertSessionHasErrors('transfer_to_user_id');
    $this->assertDatabaseHas('users', ['id' => $user->id]);
});

test('cannot transfer assets to the user being deleted', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'editor']);

    Asset::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($admin)->delete(route('users.destroy', $user), [
        'transfer_to_user_id' => $user->id,
    ]);

    $response->assertSessionHasErrors('transfer_to_user_id');
    $this->assertDatabaseHas('users', ['id' => $user->id]);
});

test('cannot transfer assets to non-existent user', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['role' => 'editor']);

    Asset::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($admin)->delete(route('users.destroy', $user), [
        'transfer_to_user_id' => 99999,
    ]);

    $response->assertSessionHasErrors('transfer_to_user_id');
    $this->assertDatabaseHas('users', ['id' => $user->id]);
});

test('non-admin cannot delete users', function () {
    $editor = User::factory()->create(['role' => 'editor']);
    $user = User::factory()->create(['role' => 'editor']);

    $response = $this->actingAs($editor)->delete(route('users.destroy', $user));

    $response->assertForbidden();
    $this->assertDatabaseHas('users', ['id' => $user->id]);
});

test('admin cannot delete themselves', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->delete(route('users.destroy', $admin));

    $response->assertForbidden();
    $this->assertDatabaseHas('users', ['id' => $admin->id]);
});
