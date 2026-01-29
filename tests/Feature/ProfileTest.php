<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }

    public function test_profile_page_shows_preferences_form(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
        $response->assertSee('Preferences');
        $response->assertSee('Home Folder');
        $response->assertSee('Items Per Page');
    }

    public function test_user_can_update_preferences(): void
    {
        Setting::set('s3_root_folder', 'assets', 'string', 'aws');
        Setting::set('s3_folders', ['assets', 'assets/marketing', 'assets/docs'], 'json', 'aws');

        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile/preferences', [
                'home_folder' => 'assets/marketing',
                'items_per_page' => 48,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('assets/marketing', $user->getPreference('home_folder'));
        $this->assertSame(48, $user->getPreference('items_per_page'));
    }

    public function test_user_can_clear_preferences_to_use_defaults(): void
    {
        $user = User::factory()->create([
            'preferences' => [
                'home_folder' => 'assets/marketing',
                'items_per_page' => 48,
            ],
        ]);

        $response = $this
            ->actingAs($user)
            ->patch('/profile/preferences', [
                'home_folder' => '',
                'items_per_page' => 0,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertNull($user->getPreference('home_folder'));
        $this->assertNull($user->getPreference('items_per_page'));
    }

    public function test_user_cannot_set_home_folder_outside_root(): void
    {
        Setting::set('s3_root_folder', 'assets', 'string', 'aws');

        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile/preferences', [
                'home_folder' => 'other/folder',
                'items_per_page' => 24,
            ]);

        $response->assertSessionHasErrors('home_folder');
    }

    public function test_user_cannot_set_invalid_items_per_page(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile/preferences', [
                'home_folder' => '',
                'items_per_page' => 50, // Invalid - not in allowed list
            ]);

        $response->assertSessionHasErrors('items_per_page');
    }

    public function test_preferences_update_shows_success_message(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile/preferences', [
                'home_folder' => '',
                'items_per_page' => 0,
            ]);

        $response->assertSessionHas('status', 'preferences-updated');
    }

    public function test_preferences_update_returns_json_for_ajax_requests(): void
    {
        Setting::set('s3_root_folder', 'assets', 'string', 'aws');
        Setting::set('s3_folders', ['assets', 'assets/marketing'], 'json', 'aws');

        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patchJson('/profile/preferences', [
                'home_folder' => 'assets/marketing',
                'items_per_page' => 36,
            ]);

        $response
            ->assertOk()
            ->assertJson([
                'message' => 'Preferences saved successfully',
            ]);

        $user->refresh();
        $this->assertSame('assets/marketing', $user->getPreference('home_folder'));
        $this->assertSame(36, $user->getPreference('items_per_page'));
    }

    public function test_preferences_update_returns_json_validation_errors(): void
    {
        Setting::set('s3_root_folder', 'assets', 'string', 'aws');

        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patchJson('/profile/preferences', [
                'home_folder' => 'invalid/folder',
                'items_per_page' => 24,
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors('home_folder');
    }
}
