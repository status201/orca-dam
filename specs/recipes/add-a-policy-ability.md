<!--
  Recipe: add a new ability to an existing policy.
-->

# Recipe — Add a policy ability

```yaml
id: add-a-policy-ability
status: implemented
version: 1
owner: core
related:
  - architecture
  - ../features/authorization-policies
  - ../decisions/adr-002-explicit-policy-roles
source:
  - app/Policies/AssetPolicy.php
  - app/Models/User.php
```

A repeatable **playbook**, not a feature. ORCA's three policies
(`AssetPolicy`, `SystemPolicy`, `UserPolicy`) never `return true` — every
ability names its allowed roles explicitly, so a new ability starts closed and
is opened per role on purpose (see
[ADR-002](../decisions/adr-002-explicit-policy-roles.md)). The concrete worked
instance is `AssetPolicy::move`, which additionally requires
`Setting::get('maintenance_mode')` on top of the role check.

## Background / Why

A permissive default (`return true`, or an admin catch-all via
`Gate::before`) makes it easy for a new ability to silently grant access
nobody intended — especially dangerous for the `api` role, which must never
gain a destructive ability by accident. Explicit role lists mean adding a role
to an ability is a deliberate, reviewable one-line change, and the whole
matrix is auditable by reading the policy file top to bottom.

## Steps

### 1. Add the ability method — `app/Policies/AssetPolicy.php`

Name the roles explicitly, using `User::isAdmin()`/`isEditor()`/`isApiUser()`
(never compare `$user->role` inline) — or the `isKnownRole()` helper for "any
of the three real roles":

```php
/**
 * Determine whether the user can archive assets.
 */
public function archive(User $user, Asset $asset): bool
{
    return $user->isAdmin() || $user->isEditor();
}
```

If the ability should also require a runtime toggle (like `move` and
`bulkForceDelete` require `maintenance_mode`), AND it in:

```php
public function archive(User $user): bool
{
    return $user->isAdmin() && (bool) Setting::get('maintenance_mode', false);
}
```

### 2. Wire it into the controller — `authorize()` or route middleware

```php
public function archive(Asset $asset)
{
    $this->authorize('archive', $asset);
    // ...
}
```

Or at the route level for a whole route group:
`Route::middleware('can:archive,Asset')->group(...)`.

### 3. Unit-test every role against the ability — `tests/Unit/Policies/AssetPolicyTest.php`

```php
test('archive is admin and editor only', function () {
    $admin = User::factory()->admin()->make();
    $editor = User::factory()->editor()->make();
    $api = User::factory()->apiUser()->make();
    $asset = Asset::factory()->make();
    $policy = new AssetPolicy;

    expect($policy->archive($admin, $asset))->toBeTrue()
        ->and($policy->archive($editor, $asset))->toBeTrue()
        ->and($policy->archive($api, $asset))->toBeFalse();
});
```

### 4. Update the matrix documentation

Add the new ability to the role × ability table in
[`authorization-policies.md`](../features/authorization-policies.md) (and
`CLAUDE.md`/`architecture.md` if it changes the summary table there) so the
matrix stays the single source of truth.

```bash
./vendor/bin/pint
php artisan config:clear && php artisan test tests/Unit/Policies/AssetPolicyTest.php
```

## Gotchas

- Never add a bare `return true` "for now" — even a temporary stub defeats the
  point of the pattern and is easy to forget about (ADR-002's whole reason for
  existing).
- If the ability needs a double-gate like `maintenance_mode`, test *both*
  factors independently: role-without-setting and setting-without-role should
  both deny (see `AssetPolicyTest`'s `move`/`bulkForceDelete` scenarios).
- `UserPolicy::delete`/`clearPasskeys` add a **self-action** guard
  (`$user->id !== $model->id`) on top of the role check — don't forget an
  analogous guard if a new ability could let an admin act destructively on
  their own account.
- Adding a new **role** (a fourth value for `users.role`) means visiting every
  existing ability across all three policies to decide whether it opts in —
  there is no default to inherit.

## Scenarios (BDD)

```gherkin
Scenario: Move requires both admin and maintenance mode
  Given maintenance_mode is false
  When AssetPolicy::move is checked for admin, editor, and api
  Then all three are denied
  Given maintenance_mode is then set to true
  When AssetPolicy::move is checked again
  Then only admin is allowed
# pinned by: tests/Unit/Policies/AssetPolicyTest.php
```

## Tests & verification

- `tests/Unit/Policies/AssetPolicyTest.php`, `SystemPolicyTest.php`,
  `UserPolicyTest.php` — the pattern for exercising every role against every
  ability, including the `maintenance_mode` double-gate and the self-action
  guards.
- `./vendor/bin/pint --test` / `php artisan config:clear && php artisan test`.
