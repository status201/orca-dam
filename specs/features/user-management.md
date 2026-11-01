# User management

```yaml
id: user-management
status: implemented
version: 1
owner: core
related:
  - architecture
  - authorization-policies
  - passkeys
  - user-preferences
source:
  - app/Http/Controllers/UserController.php
  - app/Policies/UserPolicy.php
  - app/Models/User.php
  - routes/web.php
```

## Background / Why

There is no self-service signup ([authentication.md](authentication.md) REQ-8): admins provision
`editor`/`admin`/`api` accounts through `/users`. This spec covers admin-side CRUD, role assignment,
and the asset reassignment required before deleting a user who owns assets. Profile self-service
(own name/email/password/preferences) is [user-preferences.md](user-preferences.md).

## Requirements

- **REQ-1** — All `/users` routes are admin-only (`UserPolicy::viewAny`/`create`/`update`/`delete`,
  all `$user->isAdmin()`).
- **REQ-2** — `role` must be one of `editor`, `admin`, `api` (`Rule::in`) on both create and update.
- **REQ-3** — An admin cannot delete their own account (`UserPolicy::delete`:
  `$user->id !== $model->id`) — a `403` at the authorization layer.
- **REQ-4** — Deleting a user who owns assets (including trashed ones) requires a
  `transfer_to_user_id` (`exists:users,id`, `Rule::notIn([$user->id])`); all their assets are
  reassigned via one bulk `update()` before the user row is deleted. A user with no assets is
  deleted immediately.
- **REQ-5** — `UserController::clearPasskeys` is a distinct admin recovery action, gated by
  `UserPolicy::clearPasskeys` with the same self-action prohibition as `delete` — see
  [passkeys.md](passkeys.md).

## Technical design

### Contract / public interface

```yaml
routes (web.php, Route::resource('users', ...)->except(['show']), admin-only):
  GET    /users            UserController::index
  GET    /users/create     UserController::create
  POST   /users            UserController::store
  GET    /users/{user}/edit UserController::edit
  PUT    /users/{user}      UserController::update
  DELETE /users/{user}      UserController::destroy
  DELETE /users/{user}/passkeys  UserController::clearPasskeys
```

Abilities: `UserPolicy` in [authorization-policies.md](authorization-policies.md).

### Data shapes

Validation: `UserController::store` / `::update` (`name`, `email` unique, `password` confirmed —
optional on update, unchanged if blank — and `role`). Widths come from `ColumnLimits`
([input-validation.md](input-validation.md)). On `destroy`, `transfer_to_user_id` is required only
when the target owns ≥1 asset (incl. trashed). Provisioning never sets `email_verified_at` — see
the trap in [authentication.md](authentication.md) REQ-7.

### Layer touchpoints & ordering

```
DELETE /users/{user}
  → authorize('delete', $user)                       # 403 if self or non-admin
  → count Asset::withTrashed()->where('user_id', $user->id)
  → if count > 0: validate transfer_to_user_id
      → Asset::withTrashed()->where('user_id', $user->id)->update(['user_id' => $transferTo])
  → $user->delete()
  → redirect to /users with a success message
```

### Persistence

- `users.role` — the only role storage; no roles/permissions table
  ([ADR-002](../decisions/adr-002-explicit-policy-roles.md)).
- `assets.user_id` — reassigned in bulk, `withTrashed()`, so no row points at a deleted user.
- Deleting a `User` does **not** cascade to assets — assets outlive the account that uploaded them.

## Scenarios (BDD)

```gherkin
Scenario: Only admins can view the user list
  Given an admin, an editor and an api user
  When each GETs /users
  Then the admin succeeds and the others are forbidden
# pinned by: tests/Feature/UserManagementTest.php

Scenario: An admin creates a new user with a valid role
  Given an admin
  When they POST /users with role "editor"
  Then a new user with that role is created
  And the response redirects to /users
  But role "superadmin" is a validation error on role
# pinned by: tests/Feature/UserManagementTest.php

Scenario: An admin updates another user's role
  Given an admin and a target editor
  When the admin PUTs /users/{target} with role "api"
  Then the target's role becomes "api"
# pinned by: tests/Feature/UserManagementTest.php

Scenario: An admin cannot delete their own account
  Given an admin
  When they DELETE /users/{themselves}
  Then the response is forbidden
  And the admin's account still exists
# pinned by: tests/Feature/UserManagementTest.php

Scenario: Deleting a user with no assets removes them immediately
  Given a target user who owns no assets
  When an admin DELETEs /users/{target}
  Then the user is deleted with no further input required
# pinned by: tests/Feature/UserManagementTest.php

Scenario: Deleting a user with assets reassigns all of them, trashed included
  Given a target user who owns one active and one soft-deleted asset
  And another user to transfer to
  When an admin DELETEs /users/{target} with transfer_to_user_id set
  Then the target user is deleted
  And both assets belong to the transfer target
# pinned by: tests/Feature/UserManagementTest.php, tests/Feature/UserDeletionTest.php

Scenario Outline: An invalid transfer target blocks deletion
  Given a target user who owns assets
  When an admin DELETEs /users/{target} with transfer_to_user_id <value>
  Then the response has a validation error on transfer_to_user_id
  And the target user still exists
  Examples:
    | value                      |
    | missing                    |
    | equal to the target's id   |
    | matching no user           |
# pinned by: tests/Feature/UserManagementTest.php, tests/Feature/UserDeletionTest.php

Scenario: A provisioned user is unverified but fully usable
  Given an admin creates a user through /users
  Then the new user's email_verified_at is null and no verification mail is sent
  And they can still reach /dashboard (authentication.md REQ-7)
# pinned by: tests/Feature/UserManagementTest.php

# — browser-level (see e2e-testing.md for the harness) —

Scenario: An admin creates, re-roles and deletes a user
  Given the users page
  When a new editor is created, promoted to admin and deleted
  Then each step is reflected in the users table
# pinned by: tests/e2e/user-management.spec.js

Scenario: The users table lists the seeded accounts with their roles
  Given the users page as an admin
  Then each seeded account appears with its role
# pinned by: tests/e2e/user-management.spec.js

Scenario: Deleting a user who owns assets demands a transfer target in the UI
  Given a user who owns assets
  When an admin tries to delete them
  Then the UI requires a transfer target before proceeding
  And the current admin is offered no way to delete their own account
# pinned by: tests/e2e/user-management.spec.js
```

## Tests & verification

- Feature: `tests/Feature/UserManagementTest.php` (CRUD surface),
  `tests/Feature/UserDeletionTest.php` (deletion/transfer in depth).
- Unit: `tests/Unit/Policies/UserPolicyTest.php`.
- Run: `php artisan config:clear && php artisan test`
- E2E: `tests/e2e/user-management.spec.js` — `npm run test:e2e -- tests/e2e/user-management.spec.js`.
