<?php

/**
 * Fixture for the orca-user-create-without-role rule — run by `semgrep --test`.
 *
 * A "ruleid" annotation marks the following line as one the rule MUST flag; an "ok" annotation
 * marks one it must NOT. If the rule stops matching (a Semgrep upgrade, a pattern typo), --test
 * fails here rather than the scan silently reporting a clean codebase.
 *
 * Do not write those two annotation words followed by a colon in prose, here or in any other
 * fixture: Semgrep's annotation parser scans comments for that token and does not care that this
 * one is documentation. It reads the phrase as an annotation naming a rule called "marks a
 * line the rule MUST flag…", finds no such rule, and fails the whole file with a rule-id mismatch.
 *
 * Not autoloaded, never executed. `.semgrep/` is outside every autoload path in composer.json.
 */

use App\Models\User;

function orcaFixtureCreateWithoutRole(): void
{
    // ruleid: orca-user-create-without-role
    User::create([
        'name' => 'No Role',
        'email' => 'norole@example.com',
        'password' => 'x',
    ]);

    // ok: orca-user-create-without-role
    User::create([
        'name' => 'Explicit',
        'email' => 'explicit@example.com',
        'password' => 'x',
        'role' => 'editor',
    ]);

    // The key may sit anywhere in the array, including first or last.
    // ok: orca-user-create-without-role
    User::create(['role' => 'admin', 'name' => 'First Key', 'email' => 'a@example.com']);

    // ruleid: orca-user-create-without-role
    User::firstOrCreate(['email' => 'b@example.com'], ['name' => 'Roleless']);

    // ok: orca-user-create-without-role
    User::firstOrCreate(['email' => 'c@example.com'], ['name' => 'Roled', 'role' => 'api']);

    // ruleid: orca-user-create-without-role
    User::updateOrCreate(['email' => 'd@example.com'], ['name' => 'Roleless Update']);

    // ok: orca-user-create-without-role
    User::updateOrCreate(['email' => 'e@example.com'], ['name' => 'Ok', 'role' => 'editor']);

    // A double-quoted key must count too.
    // ok: orca-user-create-without-role
    User::create(['name' => 'Double Quoted', 'email' => 'f@example.com', "role" => 'editor']);
}

/**
 * Factory creation paths. UserFactory::definition() defaults role to 'editor', but inheriting that
 * default is not the same as naming it: database/seeders/DatabaseSeeder.php is reachable by
 * `db:seed` against any configured database, which is why it writes ->editor() explicitly. A
 * factory state counts as the declaration; nothing counts as nothing.
 */
function orcaFixtureFactoryPaths(): void
{
    // ruleid: orca-user-create-without-role
    User::factory()->create(['name' => 'Roleless Factory']);

    // ok: orca-user-create-without-role
    User::factory()->editor()->create(['name' => 'Stated']);

    // ok: orca-user-create-without-role
    User::factory()->admin()->create(['name' => 'Stated Admin']);

    // ok: orca-user-create-without-role
    User::factory()->apiUser()->create(['name' => 'Stated Api']);

    // ok: orca-user-create-without-role
    User::factory()->create(['name' => 'Keyed', 'role' => 'editor']);

    // The state can sit anywhere in the chain, which is why the exemption is a regex over the
    // whole matched expression rather than a positional pattern.
    // ok: orca-user-create-without-role
    User::factory()->count(3)->editor()->create(['name' => 'Deep State']);

    // ruleid: orca-user-create-without-role
    User::factory()->count(3)->create(['name' => 'Deep Roleless']);

    // A non-role state is not a role declaration.
    // ruleid: orca-user-create-without-role
    User::factory()->unverified()->create(['name' => 'Unverified Roleless']);

    // ruleid: orca-user-create-without-role
    User::factory(3)->create(['name' => 'Counted Roleless']);
}

/**
 * The whole point of matching the parse tree rather than the text: neither of these is a call, so
 * neither may be reported. A `str_contains`-based scan cannot tell the difference.
 */
function orcaFixtureNotActuallyCalls(): string
{
    // ok: orca-user-create-without-role
    $documentation = 'Call User::create([...]) with an explicit role.';

    // ok: orca-user-create-without-role
    // User::create(['name' => 'Commented Out']);

    return $documentation;
}
