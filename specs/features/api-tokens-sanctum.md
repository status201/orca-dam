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

Long-lived backend integrations (WordPress plugin, RTE integrations, scripts) need
a stable credential that doesn't expire like a session and doesn't require
per-request secret rotation like the JWT path. Laravel Sanctum's personal access
tokens fit this: `laravel/sanctum`'s `HasApiTokens` trait on `User` provides
`createToken()`/`tokens()`, and ORCA layers a `TokenController` (web admin UI, under
`/api-docs/tokens`) and three `token:*` Artisan commands on top for token lifecycle
management. Sanctum is one of the two default guards `auth.multi` tries on API
routes (`auth.multi:sanctum,jwt`) — see [ADR-004](../decisions/adr-004-auth-multi.md).

## Requirements

- **REQ-1** — A token belongs to exactly one `User`; creating a token for a
  not-yet-existing integration can create a fresh `role => 'api'` user in the same
  step (`create_new` / `--new`).
- **REQ-2** — The plaintext token is shown **exactly once**, at creation time
  (`$token->plainTextToken`); it is never retrievable again — only the hashed row
  persists.
- **REQ-3** — Token management (web routes) lives under `/api-docs/tokens`, gated by
  `SystemPolicy::access` (admin only) via the `can:access,...SystemController` route
  group in `routes/web.php`.
- **REQ-4** — `token:revoke` can target a single token by ID or every token owned by
  a user (`--user=email`); both paths support `--force` to skip the confirmation
  prompt.
- **REQ-5** — API-role users created via the "create new" path get a random,
  never-used password (`Hash::make(Str::random(32))`) — they authenticate via token
  only, never via the login form.

## Technical design

### Contract / public interface

```yaml
routes (web.php, admin-only via can:access,SystemController):
  GET    /api-docs/tokens                    TokenController::index
  POST   /api-docs/tokens                    TokenController::store
  DELETE /api-docs/tokens/{id}                TokenController::destroy
  DELETE /api-docs/tokens/user/{userId}       TokenController::destroyUserTokens

console:
  token:list [--user=email] [--role=admin|editor|api]
  token:create [email] [--name=] [--new] [--user-name=]
  token:revoke [id] [--user=email] [--force]
```

### Data shapes

```yaml
# TokenController::store JSON response
token:
  id: int
  name: string
  plain_text: string        # shown once — the actual bearer token
  user_name: string
  user_email: string
  user_role: string
  created_new_user: bool
```

### Layer touchpoints & ordering

```
POST /api-docs/tokens
  → validate (create_new ? new_user_name/new_user_email : user_id)
  → User::create(role: 'api') if create_new, else User::findOrFail
  → $user->createToken($name)              # laravel/sanctum HasApiTokens
  → respond with plain_text token (once)
```

Downstream, any route behind `auth.multi:sanctum,...` resolves the bearer token via
`Auth::guard('sanctum')->check()`; a successful match sets `Auth::shouldUse('sanctum')`
and the request proceeds as that token's owning `User` (see
[authentication.md](authentication.md)).

### Persistence

- `personal_access_tokens` (package table) — `tokenable_type`/`tokenable_id` →
  `User`, hashed token, `name`, `last_used_at`.
- No ORCA-specific token metadata table; `TokenController::index` joins token rows
  to `tokenable` to surface user name/email/role in the admin UI.

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

Scenario: Revoking a single token by ID
  Given an existing token
  When `token:revoke <id> --force` is run
  Then the token row is deleted
# pinned by: tests/Feature/Console/TokenCommandTest.php

Scenario: Revoking all tokens for a user
  Given a user with multiple tokens
  When `token:revoke --user=<email> --force` is run
  Then every token for that user is deleted
# pinned by: tests/Feature/Console/TokenCommandTest.php

Scenario: token:revoke requires an id or --user
  Given neither an id nor --user is supplied
  When `token:revoke` is run
  Then the command fails with a usage message
# pinned by: tests/Feature/Console/TokenCommandTest.php
```

## Tests & verification

- Feature (console): `tests/Feature/Console/TokenCommandTest.php` — create
  (existing/new/declined), list (by user/role/empty), revoke (by id/by
  user/missing args/missing user).
- Run: `php artisan config:clear && php artisan test`

## Open questions / future

- `TokenController`'s HTTP routes (`/api-docs/tokens` index/store/destroy/
  destroyUserTokens) have no dedicated Feature test in the current suite — only the
  equivalent `token:*` console commands are covered. The controller and the console
  commands share the same underlying Sanctum calls (`createToken`, `tokens()->
  delete()`), so behaviour is exercised, but the HTTP layer itself (JSON shapes,
  `SystemPolicy::access` gating on these specific routes, the `AuthorizationException`
  catch block's debug payload) is an untested surface — worth a
  `tests/Feature/TokenControllerTest.php` in a follow-up.
