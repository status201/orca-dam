# API docs admin dashboard

```yaml
id: api-docs-admin
status: implemented
version: 1
owner: core
related:
  - architecture
  - rest-api
  - authorization-policies
source:
  - app/Http/Controllers/ApiDocsController.php
  - app/Http/Controllers/TokenController.php
  - app/Http/Controllers/JwtSecretController.php
  - app/Policies/SystemPolicy.php
  - routes/web.php
```

## Background / Why

Admins need a single place to see and manage everything the REST API surface
depends on — how many Sanctum tokens and JWT secrets exist, whether the
optional API endpoints (uploads, meta) are switched on, and to issue/revoke
tokens and JWT secrets — without shelling in to run the `token:*`/`jwt:*`
console commands. `/api-docs/*` is that admin-only dashboard: a Swagger/API
reference page plus the token and JWT-secret management UI, backed by three
controllers that share the same admin gate as `/system/*`.

## Requirements

- **REQ-1** — Every route under `/api-docs/*` is gated by
  `SystemPolicy::access` (`can:access,App\Http\Controllers\SystemController`
  in `routes/web.php`) — **admin only**; editors and API-role users get `403`.
- **REQ-2** — `GET /api-docs/dashboard` reports live counts (Sanctum token
  count, users with a JWT secret, users with role `api`) and the current value
  of the three API toggles, read straight from `Setting`/config — no caching
  beyond `Setting`'s own 1h cache.
- **REQ-3** — `POST /api-docs/settings` only accepts one of exactly three keys
  (`jwt_enabled_override`, `api_upload_enabled`, `api_meta_endpoint_enabled`)
  and a boolean value; anything else is a validation error.
- **REQ-4** — Token issuance (`POST /api-docs/tokens`) can either mint a token
  for an existing user or create a brand-new `role: api` user in the same
  request (`create_new` + `new_user_name`/`new_user_email`); the plaintext
  token is returned exactly once in the response.
- **REQ-5** — JWT secret management (`/api-docs/jwt-secrets*`) generates a
  64-character random secret, distinguishes "generated" vs "regenerated" in
  the response message, and revoke on a user with no secret returns `404`.

## Technical design

### Contract / public interface

```yaml
# routes/web.php — inside Route::middleware(['can:access,App\Http\Controllers\SystemController'])
GET    /api-docs:                          ApiDocsController::index            # name: api.index
GET    /api-docs/dashboard:                ApiDocsController::dashboard        # name: api.dashboard
POST   /api-docs/settings:                 ApiDocsController::updateSettings   # name: api.settings.update

GET    /api-docs/tokens:                   TokenController::index              # name: api.tokens
POST   /api-docs/tokens:                   TokenController::store              # name: api.tokens.store
DELETE /api-docs/tokens/user/{userId}:     TokenController::destroyUserTokens  # name: api.tokens.destroy-user
DELETE /api-docs/tokens/{id}:              TokenController::destroy            # name: api.tokens.destroy

GET    /api-docs/jwt-secrets:              JwtSecretController::index          # name: api.jwt-secrets
POST   /api-docs/jwt-secrets/{user}:       JwtSecretController::generate       # name: api.jwt-secrets.generate
DELETE /api-docs/jwt-secrets/{user}:       JwtSecretController::revoke         # name: api.jwt-secrets.revoke
```

### Data shapes

```yaml
# GET /api-docs/dashboard
{ tokenCount: int, jwtSecretCount: int, apiUserCount: int,
  jwtEnvEnabled: bool, jwtSettingEnabled: bool,
  uploadEndpointEnabled: bool, metaEndpointEnabled: bool }

# POST /api-docs/settings
request: { key: 'jwt_enabled_override'|'api_upload_enabled'|'api_meta_endpoint_enabled', value: bool }
response: { success: true, message: string }

# GET /api-docs/tokens
{ tokens: [{ id, name, user_name, user_email, user_role, user_id, created_at, last_used_at }],
  users: [{ id, name, email, role }] }   # plain arrays — bypasses User::$hidden so email is visible here

# POST /api-docs/tokens
request: { token_name: string, create_new: bool, user_id?: int, new_user_name?: string, new_user_email?: string }
response_200: { success: true, message: string, token: { id, name, plain_text, user_name, user_email, user_role, created_new_user } }
response_403: { success: false, message: string, debug: { user, role } }   # AuthorizationException
response_500: { success: false, message: string, type: string }

# GET /api-docs/jwt-secrets
{ users_with_secrets: [{ id, name, email, role, generated_at }],
  all_users: [{ id, name, email, role }],
  jwt_enabled: bool, jwt_setting_enabled: bool }

# POST /api-docs/jwt-secrets/{user}
response: { success: true, message: 'JWT secret generated successfully'|'JWT secret regenerated successfully',
            user: { id, name, email, role }, secret: string(64), generated_at: string }   # secret shown once only

# DELETE /api-docs/jwt-secrets/{user}
response_200: { success: true, message: 'JWT secret revoked successfully' }
response_404: { success: false, message: 'User does not have a JWT secret' }
```

### Layer touchpoints & ordering

`auth` (session) → `can:access,App\Http\Controllers\SystemController`
(`SystemPolicy::access`, admin-only) → controller (no dedicated service layer
— these three controllers read/write `Setting`/`User`/`PersonalAccessToken`
directly; there is no `ApiDocsService`). `TokenController`/`JwtSecretController`
intentionally re-map `User` records to plain arrays before returning them so
`User::$hidden` (which would otherwise strip `email`) doesn't apply — this is
an admin-only surface, so exposing email/role here is deliberate.

### Persistence

No new tables. Reads/writes: `personal_access_tokens` (Sanctum), `users.role`,
`users.jwt_secret` / `jwt_secret_generated_at`, and the `settings` table keys
`jwt_enabled_override`, `api_upload_enabled`, `api_meta_endpoint_enabled`
(see [ADR-011](../decisions/adr-011-settings-in-db.md)).

## Scenarios (BDD)

```gherkin
Scenario: Admin can list users with JWT secrets and the API toggles
  Given an authenticated admin and a user with a jwt_secret set
  When they send GET /api-docs/jwt-secrets
  Then the response is 200 with users_with_secrets, all_users, and jwt_enabled
# pinned by: tests/Feature/JwtSecretManagementTest.php

Scenario: Non-admin is forbidden from the JWT secrets dashboard
  Given an authenticated editor
  When they send GET /api-docs/jwt-secrets
  Then the response status is 403
# pinned by: tests/Feature/JwtSecretManagementTest.php

Scenario: Admin generates a JWT secret for a user with none
  Given an authenticated admin and a target user with no jwt_secret
  When they send POST /api-docs/jwt-secrets/{user}
  Then the response is 200 with a 64-character secret
  And the target user now has a JWT secret
# pinned by: tests/Feature/JwtSecretManagementTest.php

Scenario: Admin regenerates an existing JWT secret
  Given a target user who already has a jwt_secret
  When an admin sends POST /api-docs/jwt-secrets/{user}
  Then the response message says "regenerated" and the secret value changes
# pinned by: tests/Feature/JwtSecretManagementTest.php

Scenario: Admin revokes a JWT secret
  Given a target user with a jwt_secret
  When an admin sends DELETE /api-docs/jwt-secrets/{user}
  Then the response is 200 and the user no longer has a JWT secret
# pinned by: tests/Feature/JwtSecretManagementTest.php

Scenario: Revoking a JWT secret that doesn't exist returns 404
  Given a target user with no jwt_secret
  When an admin sends DELETE /api-docs/jwt-secrets/{user}
  Then the response status is 404
# pinned by: tests/Feature/JwtSecretManagementTest.php

Scenario: Non-admin cannot generate or revoke a JWT secret
  Given an authenticated editor
  When they send POST or DELETE /api-docs/jwt-secrets/{user}
  Then the response status is 403
# pinned by: tests/Feature/JwtSecretManagementTest.php

# — browser-level: these are the only coverage TokenController has (see Open questions) —

Scenario: An admin issues and revokes an API token from /api-docs
  Given the API tokens page
  When a token is created and then revoked
  Then it is listed once and then gone
# pinned by: tests/e2e/api-docs.spec.js

Scenario: A token issued in the browser authenticates a REST call
  Given a token just created on /api-docs
  When it is sent as a Bearer token to GET /api/assets
  Then the response is 200 with a paginated payload
# pinned by: tests/e2e/api-docs.spec.js

Scenario: An admin generates a JWT secret for a user
  Given the JWT tab
  When a secret is generated for api@e2e.test
  Then the secret is shown once and the user appears in the secret list
# pinned by: tests/e2e/api-docs.spec.js

Scenario: The public health endpoint needs no authentication
  Given no session and no token
  When GET /api/health is called
  Then the response is 200
# pinned by: tests/e2e/api-docs.spec.js
```

## Tests & verification

- Feature: `tests/Feature/JwtSecretManagementTest.php` — covers the
  `/api-docs/jwt-secrets*` routes end-to-end (admin gate + list/generate/
  regenerate/revoke).
- Run: `php artisan config:clear && php artisan test tests/Feature/JwtSecretManagementTest.php`.
- Style: `./vendor/bin/pint --test`.
- E2E: `tests/e2e/api-docs.spec.js` — token create/revoke in the browser, and the issued token authenticating a REST call.

## Open questions / future

- No HTTP-level test exercises `ApiDocsController::index`/`dashboard`/
  `updateSettings` (`/api-docs`, `/api-docs/dashboard`, `/api-docs/settings`)
  under either an admin or non-admin actor — grepping `tests/` for
  `ApiDocsController`, `api.dashboard`, `api.settings.update`, and `api.index`
  found nothing. The admin gate is almost certainly enforced the same way as
  the JWT-secrets routes (same `can:access` middleware group), but that's
  inferred from the route file, not verified by a green test.
- No HTTP-level test exercises `TokenController` (`/api-docs/tokens*`) —
  `tests/Feature/Console/TokenCommandTest.php` only covers the `token:*`
  **console** commands (a separate code path, out of scope for this spec —
  see the future `api-tokens-sanctum.md`), not the web dashboard's
  index/store/destroy/destroyUserTokens actions.
- Both gaps above are worth closing with a small `ApiDocsControllerTest` /
  `TokenControllerTest` (admin can list/create/revoke a token and a JWT
  secret; a non-admin gets 403 on each) before this spec's scenarios can cite
  real pins for the token-management half of the dashboard.
