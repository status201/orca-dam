<?php

/**
 * Fixture for the orca-db-raw-from-variable rule — run by `semgrep --test`.
 *
 * There are no DB::raw calls in app/ at all, so this fixture is the only thing keeping the rule
 * honest: without it, the rule would report nothing whether it worked or not.
 *
 * Not autoloaded, never executed.
 */

use Illuminate\Support\Facades\DB;

function orcaFixtureDbRaw(string $column, string $direction): void
{
    // ruleid: orca-db-raw-from-variable
    DB::raw($column);

    // ruleid: orca-db-raw-from-variable
    DB::raw("order by {$column} {$direction}");

    // ruleid: orca-db-raw-from-variable
    DB::raw('count(' . $column . ')');

    // A literal is fine — it cannot carry user input.
    // ok: orca-db-raw-from-variable
    DB::raw('count(*) as aggregate');

    // ok: orca-db-raw-from-variable
    DB::raw("lower(email)");
}
