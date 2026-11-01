# API tokens (Sanctum)

```yaml
id: api-tokens-sanctum
status: implemented
version: 1
owner: core
related:
  - architecture
  - authentication
  - authorization-policies
source:
  - app/Http/Controllers/TokenController.php
  - app/Console/Commands/TokenListCommand.php
  - app/Console/Commands/TokenCreateCommand.php
  - app/Console/Commands/TokenRevokeCommand.php
  - app/Models/User.php
```

## Background / Why

Long-lived backend integrations (WordPress plugin, RTE integrations, scripts) need a stable
credential that neither expires like a session nor needs per-user secret handling like JWT. Sanctum
personal access tokens (`HasApiTokens` on `User`) fit; ORCA adds `TokenController` (admin UI under
`/api-docs/tokens`) and three `token:*` commands. Sanctum is the first default guard of
`auth.multi` ([ADR-004](../decisions/adr-004-auth-multi.md)).

## Requirements

- **REQ-1** — A token belongs to exactly one `User`; issuing a token can create a fresh
  `role => 'api'` user in the same step (`create_new` / `--new`).
- **REQ-2** — The plaintext token is shown **exactly once**, at creation
  (`$token->plainTextToken`); only the hashed row persists.
- **REQ-3** — Token management (web routes) lives under `/api-docs/tokens`, gated by
  `SystemPolicy::access` (admin only) via the `can:access,...SystemController` route group.
- **REQ-4** — `token:revoke` targets a single token by ID or every token of a user
  (`--user=email`); both support `--force` to skip confirmation.
- **REQ-5** — API users created via "create new" get a random, never-used password
  (`Hash::make(Str::random(32))`) — they authenticate by token only.

## Technical design

### Contract / public interface

```yaml
routes (web.php, admin-only via can:access,SystemController — api-docs-admin.md):
  GET    /api-docs/tokens                    TokenController::index              # name: api.tokens
  POST   /api-docs/tokens                    TokenController::store              # name: api.tokens.store
  DELETE /api-docs/tokens/{id}               TokenController::destroy            # name: api.tokens.destroy
  DELETE /api-docs/tokens/user/{userId}      TokenController::destroyUserTokens  # name: api.tokens.destroy-user

console:
  token:list [--user=email] [--role=admin|editor|api]
  token:create [email] [--name=] [--new] [--user-name=]
  token:revoke [id] [--user=email] [--force]
```

### Data shapes

```yaml
# GET /api-docs/tokens
{ tokens: [{ id, name, user_name, user_email, user_role, user_id, created_at, last_used_at }],
  users: [{ id, name, email, role }] }   # plain arrays — bypasses User::$hidden so email is visible

# POST /api-docs/tokens
request: { token_name: string, create_new: bool, user_id?: int, new_user_name?: string, new_user_email?: string }
response_200: { success: true, message: string,
                token: { id, name, plain_text, user_name, user_email, user_role, created_new_user } }  # plain_text shown once
response_403: { success: false, message: string, debug: { user, role } }   # AuthorizationException
response_500: { success: false, message: string, type: string }
```

### Layer touchpoints & ordering

```
POST /api-docs/tokens
  → validate (create_new ? new_user_name/new_user_email : user_id)
  → User::create(role: 'api') if create_new, else User::findOrFail
  → $user->createToken($name)
  → respond with plain_text token (once)
```

Downstream, `auth.multi` resolves the bearer token via `Auth::guard('sanctum')` and the request
proceeds as the token's owner ([authentication.md](authentication.md)).

### Persistence

- `personal_access_tokens` (package table) — `tokenable_type`/`tokenable_id` → `User`, hashed token,
  `name`, `last_used_at`. No ORCA-specific token table.

## Scenarios (BDD)

```gherkin
Scenario: Creating a token for an existing user by email
  Given an existing user
  When `token:create <email> --name="TinyMCE"` is run
  Then a Sanctum token is created for that user
  And the plaintext token is printed once
# pinned by: tests/Feature/Console/TokenCommandTest.php

Scenario: Creating a token with --new provisions a fresh API user
  Given no user exists with the given email
  When `token:create --new --user-name=... ` is run with an email
  Then a new user with role "api" is created
  And a token is issued for that new user
# pinned by: tests/Feature/Console/TokenCommandTest.php

Scenario: token:create fails gracefully when the user is missing and creation is declined
  Given no user exists with the given email
  When `token:create <email>` is run and creation is declined at the prompt
  Then the command fails without creating a user or a token
# pinned by: tests/Feature/Console/TokenCommandTest.php

Scenario: Listing tokens filtered by user or role
  Given tokens belonging to users of different roles
  When `token:list --user=<email>` or `token:list --role=<role>` is run
  Then only the matching tokens are listed
# pinned by: tests/Feature/Console/TokenCommandTest.php

Scenario: Revoking by token ID or by user
  Given a user with multiple tokens
  When `token:revoke <id> --force` or `token:revoke --user=<email> --force` is run
  Then that token, or every token of that user, is deleted
# pinned by: tests/Feature/Console/TokenCommandTest.php

Scenario: token:revoke requires an id or --user
  Given neither an id nor --user is supplied
  When `token:revoke` is run
  Then the command fails with a usage message
# pinned by: tests/Feature/Console/TokenCommandTest.php
```

## Tests & verification

- Feature (console): `tests/Feature/Console/TokenCommandTest.php`.
- Run: `php artisan config:clear && php artisan test`

## Open questions / future

- `TokenController` has no Feature test of its JSON shapes or the `AuthorizationException` debug
  payload; non-admin 403s are pinned in `tests/Security/PrivilegeEscalationTest.php` and the admin
  create/revoke flow in `tests/e2e/api-docs.spec.js` ([api-docs-admin.md](api-docs-admin.md)).
