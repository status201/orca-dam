<?php

/**
 * Fixture for the orca-policy-blanket-grant rule — run by `semgrep --test`.
 *
 * The rule carries no `paths:` filter (none of them do — that would also exclude this directory
 * and make `--test` vacuously green). It is scoped by *class name*: `metavariable-regex` requires
 * the enclosing class to end in `Policy`. That is what keeps the three FormRequests whose
 * `authorize()` is a bare `return true;` — LoginRequest, StoreAssetRequest, UpdateAssetRequest —
 * out of the findings, so OrcaFixtureRequest below asserts it rather than leaving it assumed.
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

    // PHP keywords are case-insensitive, so this is the same literal to the parser.
    // ruleid: orca-policy-blanket-grant
    public function shouty(User $user): bool
    {
        return TRUE;
    }

    // PolicyMatrixTest::policyIsBlanketGrant() accepts `return 1;` too, so this layer must.
    // ruleid: orca-policy-blanket-grant
    public function truthy(User $user)
    {
        return 1;
    }
}

/**
 * The class-name scoping, asserted. A FormRequest's `authorize()` returning true is legitimate —
 * authorization there is the policy's job, not the request's — and there are three real ones in
 * app/Http/Requests/. If the `.*Policy$` constraint ever stops binding, this is what fails.
 */
class OrcaFixtureRequest
{
    // ok: orca-policy-blanket-grant
    public function authorize(): bool
    {
        return true;
    }
}
