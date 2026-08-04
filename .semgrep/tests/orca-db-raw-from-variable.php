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

    // Fully-qualified spellings. Semgrep does not resolve these back to the imported short name,
    // so the first version of this rule missed both — found by mutation-testing, not by review.
    // ruleid: orca-db-raw-from-variable
    \Illuminate\Support\Facades\DB::raw($column);

    // Ordinary style inside a namespaced class with no `use` statement.
    // ruleid: orca-db-raw-from-variable
    \DB::raw($column);

    // Still a literal, still fine, whichever way the facade is spelled.
    // ok: orca-db-raw-from-variable
    \DB::raw('count(*) as aggregate');

    // A project class that merely happens to be called DB is not the facade.
    // ok: orca-db-raw-from-variable
    \App\Support\DB::raw($column);
}
