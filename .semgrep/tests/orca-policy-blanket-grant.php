<?php

/**
 * Fixture for the orca-policy-blanket-grant rule — run by `semgrep --test`.
 *
 * The rule is scoped to `app/Policies/` by its `paths.include`, so `semgrep --test` is invoked
 * against this directory explicitly; the scan itself will never look here.
 *
 * Not autoloaded, never executed.
 */

use App\Models\User;

class OrcaFixturePolicy
{
    // ruleid: orca-policy-blanket-grant
    public function blanket(User $user): bool
    {
        return true;
    }

    // ok: orca-policy-blanket-grant
    public function checked(User $user): bool
    {
        return $user->isAdmin();
    }

    // ok: orca-policy-blanket-grant
    public function enumerated(User $user): bool
    {
        return $user->isAdmin() || $user->isEditor();
    }

    // Returning true is fine when it is a branch rather than the whole body.
    // ok: orca-policy-blanket-grant
    public function conditional(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return false;
    }
}
