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

ORCA has no self-service signup path for the roles that matter operationally —
admins provision `editor`/`admin`/`api` accounts directly through `/users`. This is
a separate concern from the profile self-service pages (`ProfileController`, own
name/email/password/preferences) — see [user-preferences.md](user-preferences.md)
for that; this spec covers admin-side CRUD, role assignment, and the asset
reassignment step required before deleting a user who owns assets.

## Requirements

- **REQ-1** — All `/users` routes are admin-only (`UserPolicy::viewAny`/`create`/
  `update`/`delete`, all `$user->isAdmin()`).
- **REQ-2** — `role` must be one of `editor`, `admin`, `api`
  (`Rule::in(['editor', 'admin', 'api'])`) on both create and update; anything else
  fails validation.
- **REQ-3** — An admin cannot delete their own account
  (`UserPolicy::delete`: `$user->id !== $model->id`) — enforced at the authorization
  layer, so it fails as a `403` before the controller's own self-check runs.
- **REQ-4** — Deleting a user who owns assets (including trashed ones) requires a
  `transfer_to_user_id` (validated `exists:users,id`, `Rule::notIn([$user->id])`);
  all of that user's assets are reassigned via a bulk `update()` before the user row
  is deleted. A user with zero assets is deleted immediately, no transfer needed.
- **REQ-5** — `UserController::clearPasskeys` is a distinct admin recovery action
  (not part of `destroy`), gated by `UserPolicy::clearPasskeys` with the same
  self-action prohibition as `delete` — see [passkeys.md](passkeys.md).

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

UserPolicy:
  viewAny(User): bool                       # admin only
  create(User): bool                        # admin only
  update(User, User $model): bool           # admin only
  delete(User, User $model): bool           # admin only, and $user->id !== $model->id
  clearPasskeys(User, User $model): bool    # admin only, and $user->id !== $model->id
```

### Data shapes

```yaml
# store/update validation
name: required|string|max:255
email: required|string|email|max:255|unique:users (ignore self on update)
password: required|min:8|confirmed          # store only
password: nullable|min:8|confirmed          # update only, unchanged if blank
role: required|in:editor,admin,api

# destroy, only when the target user owns >=1 asset (incl. trashed)
transfer_to_user_id: required|exists:users,id|not_in:[target user id]
```

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

- `users.role` — the only role storage; no separate roles/permissions table (see
  [ADR-002](../decisions/adr-002-explicit-policy-roles.md)).
- `assets.user_id` — reassigned in bulk (a single `update()`, not per-row) before a
  user with assets is deleted; both active and soft-deleted rows are included via
  `withTrashed()` so a deleted user leaves no orphaned `user_id` anywhere.
- Deleting a `User` does **not** cascade-delete their assets — asset ownership must
  be transferred first, by design (assets outlive the account that uploaded them).

## Scenarios (BDD)

```gherkin
Scenario: Non-admins cannot view the user list
  Given an editor or api user
  When they GET /users
  Then the response is forbidden
# pinned by: tests/Feature/UserManagementTest.php

Scenario: An admin can view the user list
  Given an admin
  When they GET /users
  Then the response is successful
# pinned by: tests/Feature/UserManagementTest.php

Scenario: An admin creates a new user with a valid role
  Given an admin
  When they POST /users with role "editor"
  Then a new user with that role is created
  And the response redirects to /users
# pinned by: tests/Feature/UserManagementTest.php

Scenario: Creating a user with an invalid role fails validation
  Given an admin
  When they POST /users with role "superadmin"
  Then the response has a validation error on role
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

Scenario: Deleting a user with assets requires a transfer target and reassigns ownership
  Given a target user who owns assets
  And another user to transfer to
  When an admin DELETEs /users/{target} with transfer_to_user_id set
  Then the target user is deleted
  And their assets now belong to the transfer target
# pinned by: tests/Feature/UserManagementTest.php

Scenario: Deleting a user with assets fails without a transfer target
  Given a target user who owns assets
  When an admin DELETEs /users/{target} with no transfer_to_user_id
  Then the response has a validation error on transfer_to_user_id
  And the target user still exists
# pinned by: tests/Feature/UserManagementTest.php, tests/Feature/UserDeletionTest.php

Scenario: Soft-deleted assets are transferred too (REQ-4, withTrashed)
  Given a target user who owns one active and one soft-deleted asset
  When an admin DELETEs /users/{target} with transfer_to_user_id set
  Then both assets belong to the transfer target
  And no asset row is left pointing at the deleted user
# pinned by: tests/Feature/UserDeletionTest.php

Scenario: The transfer target cannot be the user being deleted
  Given a target user who owns assets
  When an admin DELETEs /users/{target} with transfer_to_user_id = the target's own id
  Then the response has a validation error on transfer_to_user_id
  And the target user still exists
# pinned by: tests/Feature/UserDeletionTest.php

Scenario: The transfer target must exist
  Given a target user who owns assets
  When an admin DELETEs /users/{target} with a transfer_to_user_id that matches no user
  Then the response has a validation error on transfer_to_user_id
  And the target user still exists
# pinned by: tests/Feature/UserDeletionTest.php

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

- Feature: `tests/Feature/UserManagementTest.php` — index gating, create (valid/
  invalid role), update role, self-delete prohibition, delete with/without assets,
  transfer validation.
- Feature: `tests/Feature/UserDeletionTest.php` — the deletion/transfer path in depth:
  `withTrashed()` coverage, and both `transfer_to_user_id` rules (`Rule::notIn` on the
  target itself, `exists:users,id`). Overlaps `UserManagementTest.php` on the happy
  paths by design — that file owns the CRUD surface, this one owns deletion.
- Unit: `tests/Unit/Policies/UserPolicyTest.php` — the ability matrix in isolation.
- Run: `php artisan config:clear && php artisan test`
- E2E: `tests/e2e/user-management.spec.js` — create → re-role → delete, plus the transfer-target gate and the self-delete prohibition.

## Open questions / future

- CRUD, role validation, the self-delete guard, and the asset-transfer path are all
  directly pinned. See [passkeys.md](passkeys.md) for the `clearPasskeys` recovery
  action's own scenarios.
- `UserController::store` does not set `email_verified_at`, so a provisioned user is
  blocked from `/dashboard` by the `verified` middleware while retaining full asset
  access — see the same finding in [authentication.md](authentication.md).
