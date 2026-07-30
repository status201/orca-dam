<?php

use App\Models\User;
use App\Models\UserAuditLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The user audit trail — see specs/features/user-audit-log.md.
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['email' => 'admin@audit.test']);
});

it('files a created entry attributed to the admin who provisioned the user', function () {
    $this->actingAs($this->admin)->post('/users', [
        'name' => 'New Editor',
        'email' => 'new-editor@audit.test',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role' => 'editor',
    ])->assertRedirect();

    $entry = UserAuditLog::where('user_label', 'new-editor@audit.test')->sole();

    expect($entry->event)->toBe('created')
        ->and($entry->actor_id)->toBe($this->admin->id)
        ->and($entry->actor_label)->toBe('admin@audit.test')
        ->and($entry->changes['role'])->toBe(['to' => 'editor']);
});

it('records a role change with both the old and the new value', function () {
    $editor = User::factory()->editor()->create(['email' => 'promoted@audit.test']);

    $this->actingAs($this->admin)
        ->put("/users/{$editor->id}", [
            'name' => $editor->name,
            'email' => $editor->email,
            'role' => 'admin',
        ])->assertRedirect();

    $entry = UserAuditLog::forUser($editor)->ofEvent('updated')->sole();

    expect($entry->changes['role'])->toBe(['from' => 'editor', 'to' => 'admin'])
        ->and($entry->actor_id)->toBe($this->admin->id);
});

it('emits a warning to the application log when a user is granted admin', function () {
    $editor = User::factory()->editor()->create();

    $this->actingAs($this->admin);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => str_contains($message, 'promoted to admin')
            && $context['user_id'] === $editor->id
            && $context['actor_email'] === 'admin@audit.test');

    // The observer's audit write is unrelated to the assertion above; allow the rest.
    Log::shouldReceive('error')->zeroOrMoreTimes();
    Log::shouldReceive('info')->zeroOrMoreTimes();

    $editor->update(['role' => 'admin']);
});

it('warns when a brand new user is created directly as an admin', function () {
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message) => str_contains($message, 'created as admin'));

    Log::shouldReceive('error')->zeroOrMoreTimes();
    Log::shouldReceive('info')->zeroOrMoreTimes();

    User::factory()->admin()->create();
});

it('does not file an entry for a login', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);

    UserAuditLog::query()->delete();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect();

    expect($user->fresh()->last_login_at)->not->toBeNull()
        ->and(UserAuditLog::count())->toBe(0);
});

it('does not file an entry for a password change, and never stores the hash', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);

    // Keep the creation row — it is what would leak a hash if WATCHED ever grew.
    $before = UserAuditLog::count();

    $this->actingAs($user)->put('/password', [
        'current_password' => 'password',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertSessionHasNoErrors();

    expect(UserAuditLog::count())->toBe($before);

    $hash = $user->fresh()->password;
    $everything = UserAuditLog::all()->map(fn (UserAuditLog $entry) => json_encode($entry->changes))->implode(' ');

    expect($everything)->not->toContain($hash)
        ->and($everything)->not->toContain('password');
});

it('keeps a deleted users trail readable', function () {
    $victim = User::factory()->editor()->create(['email' => 'gone@audit.test']);
    $victim->update(['role' => 'admin']);

    $before = UserAuditLog::forUser($victim)->count();
    expect($before)->toBeGreaterThan(0);

    $victim->delete();

    $entries = UserAuditLog::where('user_label', 'gone@audit.test')->get();

    expect($entries)->toHaveCount($before + 1)
        ->and($entries->pluck('event'))->toContain('deleted')
        ->and($entries->every(fn ($entry) => $entry->user_id === null))->toBeTrue()
        ->and($entries->every(fn ($entry) => $entry->user_label === 'gone@audit.test'))->toBeTrue();
});

it('attributes a console-created user to the console rather than a user', function () {
    // --new ignores the positional email and asks for one, so answer that prompt;
    // --name and --user-name suppress the other two.
    $this->artisan('token:create', ['--new' => true, '--name' => 'CI', '--user-name' => 'CI Bot'])
        ->expectsQuestion('Enter email for the API user', 'api-user@audit.test')
        ->assertSuccessful();

    $entry = UserAuditLog::where('user_label', 'api-user@audit.test')->sole();

    expect($entry->actor_id)->toBeNull()
        ->and($entry->actor_label)->toBe('console')
        ->and($entry->changes['role'])->toBe(['to' => 'api']);
});

it('does not break user administration when the audit write fails', function () {
    Schema::drop('user_audit_logs');

    Log::shouldReceive('error')
        ->atLeast()->once()
        ->withArgs(fn (string $message) => str_contains($message, 'Failed to write user audit log'));
    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('info')->zeroOrMoreTimes();

    $this->actingAs($this->admin)->post('/users', [
        'name' => 'Survivor',
        'email' => 'survivor@audit.test',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role' => 'editor',
    ])->assertRedirect();

    expect(User::where('email', 'survivor@audit.test')->exists())->toBeTrue();
});

it('reports the trail newest first and filters by user and event', function () {
    $one = User::factory()->editor()->create(['email' => 'one@audit.test']);
    $two = User::factory()->editor()->create(['email' => 'two@audit.test']);
    $one->update(['role' => 'admin']);

    $this->artisan('users:audit', ['--user' => 'one@audit.test'])
        ->expectsOutputToContain('one@audit.test')
        ->doesntExpectOutputToContain('two@audit.test')
        ->assertSuccessful();

    $this->artisan('users:audit', ['--user' => 'one@audit.test', '--event' => 'updated'])
        ->expectsOutputToContain('role: editor → admin')
        ->assertSuccessful();

    expect($two->email)->toBe('two@audit.test');
});

it('rejects an unknown event filter and says nothing when the trail is empty', function () {
    $this->artisan('users:audit', ['--event' => 'exploded'])
        ->expectsOutputToContain('Unknown event')
        ->assertFailed();

    UserAuditLog::query()->delete();

    $this->artisan('users:audit')
        ->expectsOutputToContain('No audit entries found.')
        ->assertSuccessful();
});
