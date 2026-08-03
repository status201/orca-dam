<?php

/**
 * Fixture for the orca-user-create-without-role rule — run by `semgrep --test`.
 *
 * `// ruleid:` marks a line the rule MUST flag; `// ok:` marks one it must NOT. If the rule stops
 * matching (a Semgrep upgrade, a pattern typo), --test fails here rather than the scan silently
 * reporting a clean codebase.
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
