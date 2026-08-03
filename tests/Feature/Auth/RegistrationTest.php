<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Security\Support\SourceScanner;
use Tests\TestCase;

/**
 * ORCA has no self-service registration — see specs/features/authentication.md REQ-8.
 *
 * This file used to assert the opposite. The Breeze register route shipped mounted but
 * unlinked, and `RegisteredUserController::store` passed no role to `User::create`, so
 * anyone who guessed /register got an account with the `users.role` column default of
 * `editor` — full read/write/delete over every asset, live immediately because email
 * verification is inert (REQ-7). At least one unknown party did exactly that on
 * production. The routes, controller and view are gone, and the column default with them;
 * these tests keep both gone.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_is_not_reachable(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_no_route_named_register_is_registered(): void
    {
        $this->assertFalse(
            Route::has('register'),
            'A route named "register" is mounted again. ORCA provisions users via /users '
            .'(admin-only); see specs/features/authentication.md REQ-8.'
        );
    }

    public function test_posting_a_registration_payload_creates_no_account(): void
    {
        $response = $this->post('/register', [
            'name' => 'Intruder',
            'email' => 'intruder@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertNotFound();
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'intruder@example.com']);
    }

    /**
     * The load-bearing half of REQ-8: the column itself refuses an implicit role, so every
     * creation path is covered — including the ones the source scan below cannot see
     * (`firstOrCreate`, raw inserts, a future SSO or invite flow). If this test ever
     * passes without the try/catch firing, the database default is back.
     */
    public function test_creating_a_user_without_a_role_is_a_database_error(): void
    {
        $column = collect(Schema::getColumns('users'))->firstWhere('name', 'role');
        $default = $column['default'] ?? null;

        $this->assertNull($default,
            'users.role carries a database default of '.var_export($default, true).', so any insert '
            .'that omits the role silently takes it. See specs/features/authentication.md REQ-8.'
        );

        try {
            DB::table('users')->insert([
                'name' => 'No Role',
                'email' => 'norole@example.com',
                'password' => Hash::make('password'),
            ]);

            $this->fail('Inserting a user with no role succeeded. The users.role column must be '
                .'NOT NULL with no default; see specs/features/authentication.md REQ-8.');
        } catch (QueryException) {
            // Expected: NOT NULL constraint failed (SQLite) / no default value (MySQL strict).
        }

        $this->assertDatabaseMissing('users', ['email' => 'norole@example.com']);
    }

    /**
     * The hole was not the route on its own — it was the route *plus* an implicit role.
     * Any unauthenticated creation path added later would inherit `editor` the same way,
     * so every call site has to name the role it intends.
     */
    public function test_no_user_creation_path_relies_on_the_role_column_default(): void
    {
        $offenders = [];

        foreach (SourceScanner::callSitesUnder([app_path()], 'User::create(') as $site) {
            if (! str_contains($site['call'], "'role'")) {
                $offenders[] = $site['file'];
            }
        }

        $this->assertSame([], $offenders,
            'These User::create() call sites pass no explicit role, so they silently take '
            ."the users.role default of 'editor': ".implode(', ', $offenders)
            .'. See specs/features/authentication.md REQ-8.'
        );
    }

    /** Sanity check on the scanner itself — a passing scan must mean it found something. */
    public function test_the_role_scanner_sees_the_known_creation_paths(): void
    {
        $sites = SourceScanner::callSitesUnder([app_path()], 'User::create(');

        $this->assertGreaterThanOrEqual(3, count($sites),
            'Expected to find the UserController, TokenController and TokenCreateCommand '
            .'creation paths. Finding fewer means the scanner silently stopped working.'
        );
    }
}
