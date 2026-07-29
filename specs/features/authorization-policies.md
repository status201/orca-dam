# Authorization policies

```yaml
id: authorization-policies
status: implemented
version: 1
owner: core
related:
  - architecture
  - authentication
source:
  - app/Policies/AssetPolicy.php
  - app/Policies/SystemPolicy.php
  - app/Policies/UserPolicy.php
  - app/Models/User.php
```

## Background / Why

ORCA has three roles (`admin`, `editor`, `api`) with a deliberately uneven ability
matrix: API tokens may read and upload but must never delete, and the two most
destructive bulk operations require admin **and** an explicit maintenance-mode
toggle. See [ADR-002](../decisions/adr-002-explicit-policy-roles.md) for why every
ability names its allowed roles explicitly instead of a permissive default or a
roles/permissions package.

## Requirements

- **REQ-1** — Every policy ability lists its allowed roles explicitly
  (`$user->isAdmin() || $user->isEditor()` style, or `isKnownRole()` for "any real
  role"). No ability ever `return true`s unconditionally.
- **REQ-2** — `AssetPolicy::move` and `AssetPolicy::bulkForceDelete` require **both**
  `$user->isAdmin()` **and** `Setting::get('maintenance_mode', false)` — admin alone
  is not sufficient for these two abilities.
- **REQ-3** — `SystemPolicy::access` gates the entire `/system/*` and `/api-docs/*`
  admin surface to `admin` only.
- **REQ-4** — `UserPolicy::delete` and `UserPolicy::clearPasskeys` additionally
  forbid an admin acting on **themselves** (`$user->id !== $model->id`), independent
  of the role check.
- **REQ-5** — `User::isAdmin()` / `isEditor()` / `isApiUser()` are the single source
  of truth for role checks; policies and controllers call these rather than
  comparing `$user->role` inline.

## Technical design

### Contract / public interface

```yaml
AssetPolicy:
  viewAny(User): bool                        # admin, editor, api
  view(User, Asset): bool                    # admin, editor, api
  create(User): bool                         # admin, editor, api
  update(User, Asset): bool                  # admin, editor, api
  bulkDownload(User): bool                   # admin, editor, api
  replace(User): bool                        # admin, editor
  delete(User, Asset): bool                  # admin, editor
  restore(User, Asset|string|null): bool     # admin, editor
  bulkTrash(User): bool                      # admin, editor
  bulkRestore(User): bool                    # admin, editor
  forceDelete(User, Asset|string|null): bool # admin only
  discover(User): bool                       # admin only
  export(User): bool                         # admin only
  move(User): bool                           # admin AND maintenance_mode
  bulkForceDelete(User): bool                # admin AND maintenance_mode

SystemPolicy:
  access(User): bool                         # admin only

UserPolicy:
  viewAny(User): bool                        # admin only
  create(User): bool                         # admin only
  update(User, User $model): bool            # admin only
  delete(User, User $model): bool            # admin only, and $user->id !== $model->id
  clearPasskeys(User, User $model): bool     # admin only, and $user->id !== $model->id
```

### Data shapes

The role × ability matrix (also mirrored in `architecture.md` and `CLAUDE.md`):

```yaml
Action:
  view/viewAny/create/update/bulkDownload:            {admin: true,  editor: true,  api: true}
  replace/delete/restore/bulkTrash/bulkRestore:        {admin: true,  editor: true,  api: false}
  forceDelete/discover/export:                         {admin: true,  editor: false, api: false}
  move/bulkForceDelete (+ requires maintenance_mode):  {admin: true,  editor: false, api: false}
```

### Layer touchpoints & ordering

Controllers call `$this->authorize('<ability>', <model-or-class>)` (Laravel's
`AuthorizesRequests` trait) or route-level `can:<ability>,<Model>` middleware (see
`routes/web.php` groups for discover/export/folders/trash/force-delete/system).
`AssetApiController::destroy` routes through `authorize('delete', $asset)`, so an
`api`-role Sanctum/JWT caller gets a `403` for free — no separate API-specific
check is needed.

## Scenarios (BDD)

```gherkin
Scenario: The full asset ability matrix is enforced per role
  Given an admin, an editor, and an api user
  When each ability in the matrix is checked against each role
  Then the result matches the documented matrix exactly
# pinned by: tests/Unit/Policies/AssetPolicyTest.php

Scenario: Move requires both admin and maintenance mode
  Given maintenance_mode is false
  When AssetPolicy::move is checked for admin, editor, and api
  Then all three are denied
  Given maintenance_mode is then set to true
  When AssetPolicy::move is checked again
  Then only admin is allowed
# pinned by: tests/Unit/Policies/AssetPolicyTest.php

Scenario: BulkForceDelete requires both admin and maintenance mode
  Given maintenance_mode is false
  When AssetPolicy::bulkForceDelete is checked for admin, editor, and api
  Then all three are denied
  Given maintenance_mode is then set to true
  When AssetPolicy::bulkForceDelete is checked again
  Then only admin is allowed
# pinned by: tests/Unit/Policies/AssetPolicyTest.php

Scenario: Only admins can access system administration
  Given a non-admin user
  When SystemPolicy::access is checked
  Then it is denied
# pinned by: tests/Unit/Policies/SystemPolicyTest.php

Scenario: Only admins may view, create, or update users
  Given an admin and a non-admin
  When UserPolicy::viewAny/create/update are checked
  Then only the admin is allowed
# pinned by: tests/Unit/Policies/UserPolicyTest.php

Scenario: An admin can delete another user but not themselves
  Given an admin user and a different target user
  When UserPolicy::delete is checked for the target
  Then it is allowed
  When UserPolicy::delete is checked for the admin against themselves
  Then it is denied
# pinned by: tests/Unit/Policies/UserPolicyTest.php

Scenario: Non-admins cannot delete any user
  Given an editor or api user
  When UserPolicy::delete is checked against any target
  Then it is denied
# pinned by: tests/Unit/Policies/UserPolicyTest.php

# — browser-level: the same matrix as the UI presents it (see e2e-testing.md) —

Scenario: An admin sees every privileged navigation entry and can open each admin page
  Given a session for admin@e2e.test
  Then the Users, System, API-docs, Import, Export and Discover entries are present
  And each of those routes responds 200
# pinned by: tests/e2e/role-matrix.spec.js

Scenario: An editor sees no admin-only navigation and is refused /system
  Given a session for editor@e2e.test
  Then the Users, System and API navigation entries are absent
  And GET /system responds 403
  And they can trash and restore but are offered no permanent-delete or move control
# pinned by: tests/e2e/role-matrix.spec.js

Scenario: An api-role user cannot trash assets from the UI or the API
  Given a session for api@e2e.test
  Then the bulk bar offers no "move to trash" control
  And DELETE /api/assets/{id} responds 403
  While the same call as an editor succeeds
# pinned by: tests/e2e/role-matrix.spec.js

Scenario: An api-role user may read any asset but only update its own
  Given a session for api@e2e.test
  When it PATCHes an asset owned by the editor
  Then the response is 403, while PATCHing its own asset succeeds
# pinned by: tests/e2e/role-matrix.spec.js

Scenario: Every role lands on a dashboard that names them
  Given a saved session per role
  When /dashboard is opened
  Then the user menu shows that role's user name
# pinned by: tests/e2e/role-matrix.spec.js
```

## Tests & verification

- Unit: `tests/Unit/Policies/AssetPolicyTest.php`, `SystemPolicyTest.php`,
  `UserPolicyTest.php` — `php artisan config:clear && php artisan test`
- E2E: `tests/e2e/role-matrix.spec.js` — the same matrix as the UI presents it (hidden nav + absent controls per role) plus the 403s.

## Open questions / future

- None — the matrix, the `maintenance_mode` double-gate, and the self-action guards
  all have direct unit coverage.
