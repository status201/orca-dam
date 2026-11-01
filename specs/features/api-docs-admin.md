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

`/api-docs/*` is the admin-only dashboard for everything the REST API depends on: an API reference
page, live counts, the runtime API toggles, and the UI for Sanctum tokens and JWT secrets — so
admins need not shell in for `token:*` / `jwt:*`. Token behaviour is owned by
[api-tokens-sanctum.md](api-tokens-sanctum.md), JWT secrets by [jwt-auth.md](jwt-auth.md); this
spec owns the page, its gate, the dashboard and the settings endpoint.

## Requirements

- **REQ-1** — Every route under `/api-docs/*` is gated by `SystemPolicy::access`
  (`can:access,App\Http\Controllers\SystemController` in `routes/web.php`) — **admin only**; editors
  and API-role users get `403`.
- **REQ-2** — `GET /api-docs/dashboard` reports live counts (Sanctum tokens, users with a JWT
  secret, users with role `api`) and the current value of the three API toggles, read from
  `Setting`/config with no caching beyond `Setting`'s own 1h cache.
- **REQ-3** — `POST /api-docs/settings` accepts exactly one of three keys (`jwt_enabled_override`,
  `api_upload_enabled`, `api_meta_endpoint_enabled`) and a boolean value; anything else is a
  validation error.
- **REQ-4** — Token issuance (`POST /api-docs/tokens`) mints a token for an existing user or creates
  a new `role: api` user in the same request; the plaintext is returned once — see
  [api-tokens-sanctum.md](api-tokens-sanctum.md).
- **REQ-5** — JWT secret management (`/api-docs/jwt-secrets*`) generates a 64-char secret,
  distinguishes "generated" vs "regenerated", and revoking a missing secret returns `404` — see
  [jwt-auth.md](jwt-auth.md).

## Technical design

### Contract / public interface

```yaml
# routes/web.php — inside Route::middleware(['can:access,App\Http\Controllers\SystemController'])
GET    /api-docs:                          ApiDocsController::index            # name: api.index
GET    /api-docs/dashboard:                ApiDocsController::dashboard        # name: api.dashboard
POST   /api-docs/settings:                 ApiDocsController::updateSettings   # name: api.settings.update
/api-docs/tokens*:                         TokenController       # names api.tokens*      — api-tokens-sanctum.md
/api-docs/jwt-secrets*:                    JwtSecretController   # names api.jwt-secrets* — jwt-auth.md
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
```

### Layer touchpoints & ordering

`auth` (session) → `can:access,…SystemController` → controller. No service layer: the three
controllers read/write `Setting` / `User` / `PersonalAccessToken` directly. `TokenController` and
`JwtSecretController` re-map `User` rows to plain arrays so `User::$hidden` does not strip `email` —
deliberate on this admin-only surface.

### Persistence

No new tables. Settings keys `jwt_enabled_override`, `api_upload_enabled`,
`api_meta_endpoint_enabled` ([ADR-011](../decisions/adr-011-settings-in-db.md)); tokens and secrets
per their owning specs.

## Scenarios (BDD)

```gherkin
Scenario: A non-admin is forbidden from the /api-docs surface
  Given an authenticated editor or api-role user
  When they request a /api-docs route (e.g. GET /api-docs/jwt-secrets, POST /api-docs/settings)
  Then the response status is 403
# pinned by: tests/Feature/JwtSecretManagementTest.php, tests/Security/PrivilegeEscalationTest.php, tests/Security/RuntimeExposureTogglesTest.php

# — browser-level (see e2e-testing.md for the harness); the only coverage TokenController has —

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

- Feature: `tests/Feature/JwtSecretManagementTest.php` (JWT secret routes, see jwt-auth.md).
- Security: `tests/Security/PrivilegeEscalationTest.php`,
  `tests/Security/RuntimeExposureTogglesTest.php` (non-admin 403s, toggles).
- E2E: `tests/e2e/api-docs.spec.js` — `npm run test:e2e -- tests/e2e/api-docs.spec.js`.
- Run: `php artisan config:clear && php artisan test`; style `./vendor/bin/pint --test`.

## Open questions / future

- No Feature test asserts the admin-side JSON of `ApiDocsController::dashboard` or
  `TokenController::destroyUserTokens`.
