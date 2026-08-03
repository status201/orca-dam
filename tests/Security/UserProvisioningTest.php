<?php

use Illuminate\Support\Facades\Schema;
use Tests\Security\Support\SourceScanner;

/**
 * REQ-4 of specs/features/security-invariants.md — no path creates a user without saying what
 * they may do.
 *
 * The registration hole was not `User::create` being called; it was `User::create` being called
 * with no `role`, letting the column default fill in `editor`. That default is gone
 * (specs/features/authentication.md REQ-8), and RegistrationTest pins both the deleted route and
 * the column constraint.
 *
 * What RegistrationTest cannot do is see past its own two limits: it scans `app/` only, and it
 * matches only the literal `User::create(`. Both limits were real. `DatabaseSeeder` creates a
 * user through `User::factory()->create()` in `database/seeders/`, took the factory's `editor`
 * default implicitly, and passed that scan every time.
 *
 * So this widens both axes — every directory that can mint a user, and every idiom that can mint
 * one — and requires each call site to name the role out loud.
 */

/** Every way a user can be brought into existence in this codebase. */
function userCreationIdioms(): array
{
    return [
        'User::create(',
        'User::factory(',
        'User::firstOrCreate(',
        'User::updateOrCreate(',
        "DB::table('users')->insert(",
        'DB::table("users")->insert(',
    ];
}

/**
 * Everywhere a creation path can run in production.
 *
 * `database/seeders` is in scope because `php artisan db:seed` runs against whatever database is
 * configured, and that is exactly how DatabaseSeeder's unroled `User::factory()->create()`
 * became reachable. `database/factories` is deliberately *out* of scope: a factory only runs
 * from a test, its `role` default is its role declaration, and other factories legitimately
 * reference `User::factory()` to satisfy a `user_id` relation — including it produced findings
 * that could not be acted on.
 */
function userCreationSearchPaths(): array
{
    return [
        app_path(),
        base_path('database/seeders'),
        base_path('routes'),
    ];
}

/**
 * Call sites that mint a user without naming a role.
 *
 * @return list<string>
 */
function implicitRoleCallSites(): array
{
    $offenders = [];

    foreach (userCreationIdioms() as $idiom) {
        foreach (SourceScanner::statementSitesUnder(userCreationSearchPaths(), $idiom) as $site) {
            if (str_contains($site['statement'], "'role'") || str_contains($site['statement'], '"role"')) {
                continue;
            }

            // A factory *state* is the role declaration: ->admin(), ->editor(), ->apiUser().
            if (preg_match('/->(admin|editor|apiUser)\(/', $site['statement']) === 1) {
                continue;
            }

            $offenders[] = $site['file'].': '.trim(preg_replace('/\s+/', ' ', $site['statement']));
        }
    }

    return $offenders;
}

// ─── REQ-4 ────────────────────────────────────────────────────────────────────

test('no user-creation path leaves the role implicit', function () {
    $offenders = implicitRoleCallSites();

    expect($offenders)->toBe([],
        "These call sites create a user without naming a role:\n  ".implode("\n  ", $offenders)
        ."\n\nThe users.role column no longer has a default, so most of these would now fail at "
        .'the driver rather than minting an editor — but a call site that relies on a factory '
        .'default is relying on a default all the same. Name the role, or use an explicit '
        .'factory state (->admin() / ->editor() / ->apiUser()). '
        .'See specs/features/authentication.md REQ-8.'
    );
});

/**
 * The scan above only means something if it is still finding call sites. A scanner that has
 * quietly stopped matching — a renamed method, a refactor to a service, a typo in an idiom —
 * produces the same empty list as a clean codebase.
 */
test('the provisioning scanner still sees every known creation path', function () {
    $found = [];

    foreach (userCreationIdioms() as $idiom) {
        foreach (SourceScanner::statementSitesUnder(userCreationSearchPaths(), $idiom) as $site) {
            $found[$site['file']] = true;
        }
    }

    $files = array_keys($found);

    expect(count($files))->toBeGreaterThanOrEqual(5,
        'Expected at least the UserController, TokenController, TokenCreateCommand, '
        .'AdminUserSeeder, E2eSeeder and DatabaseSeeder paths; found: '.implode(', ', $files)
    );

    // Normalised so the assertion reads the same on Windows and Linux.
    $normalised = array_map(fn ($file) => str_replace('\\', '/', $file), $files);

    expect($normalised)->toContain(
        'app/Http/Controllers/UserController.php',
        'app/Console/Commands/TokenCreateCommand.php',
        'database/seeders/DatabaseSeeder.php',
    );
});

/** Proves the role check itself fires, rather than the idiom list having gone stale. */
test('the implicit-role detector actually catches an unroled creation', function () {
    $withRole = "User::factory()->create(['name' => 'x', 'role' => 'admin']);";
    $withState = "User::factory()->admin()->create(['name' => 'x']);";
    $without = "User::factory()->create(['name' => 'x', 'email' => 'x@example.com']);";

    $names = fn (string $source) => SourceScanner::statementsContaining($source, 'User::factory(');

    expect($names($withRole)[0])->toContain("'role'");
    expect($names($withState)[0])->toMatch('/->admin\(/');
    expect($names($without)[0])->not->toContain("'role'");
    expect($names($without)[0])->not->toMatch('/->(admin|editor|apiUser)\(/');
});

// ─── the constraint that covers what no scan can see ──────────────────────────

/**
 * Deliberately not duplicated here: that `users.role` is NOT NULL with no default. That is the
 * load-bearing half of REQ-8 and it is pinned by tests/Feature/Auth/RegistrationTest.php, which
 * owns specs/features/authentication.md. This file covers the source-level half — the call sites
 * a database constraint cannot explain to whoever wrote them.
 */
test('the role column has no default, so an unroled insert cannot silently succeed', function () {
    $column = collect(Schema::getColumns('users'))->firstWhere('name', 'role');

    expect($column['default'] ?? null)->toBeNull(
        'users.role has regained a database default. Every scan in this file becomes advisory '
        .'the moment the column will fill the role in by itself — see '
        .'tests/Feature/Auth/RegistrationTest.php and specs/features/authentication.md REQ-8.'
    );
});
