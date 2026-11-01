# JWT authentication

```yaml
id: jwt-auth
status: implemented
version: 1
owner: core
related:
  - architecture
  - authentication
  - authorization-policies
source:
  - app/Auth/JwtGuard.php
  - config/jwt.php
  - app/Http/Controllers/JwtSecretController.php
  - app/Console/Commands/JwtGenerateCommand.php
  - app/Console/Commands/JwtListCommand.php
  - app/Console/Commands/JwtRevokeCommand.php
  - app/Http/Middleware/AuthenticateMultiple.php
```

## Background / Why

Some programmatic callers (frontend RTE integrations) prefer a short-lived, self-contained
credential over a long-lived Sanctum token. ORCA adds a custom `JwtGuard` (`firebase/php-jwt`) keyed
per user with a symmetric secret stored encrypted on the `User` row, so a compromised secret exposes
only one user. JWT is **off by default** ([ADR-004](../decisions/adr-004-auth-multi.md)).

## Requirements

- **REQ-1** — JWT auth is double-gated: both `JWT_ENABLED` (`config('jwt.enabled')`) and the DB
  setting `jwt_enabled_override` must be true. `AuthenticateMultiple` **skips** the `jwt` guard
  entirely (does not merely deny it) when either is false.
- **REQ-2** — A valid token must carry `sub` (user ID), `exp`, `iat`
  (`config('jwt.required_claims')`); a token missing any is rejected even with a valid signature.
- **REQ-3** — Each user has its own secret (`users.jwt_secret`, `encrypted` cast). A token is
  verified against the secret of the user named in `sub` — there is no global signing secret.
- **REQ-4** — `max_ttl` (default 36000s, `JWT_MAX_TTL`) is enforced against `iat` independent of the
  token's `exp`.
- **REQ-5** — If `jwt.issuer` (`JWT_ISSUER`) is set, `iss` must match exactly; if unset, it is not
  checked.
- **REQ-6** — `JwtGuard` never throws on a malformed/invalid/expired token — every failure returns
  `null` from `user()`, letting `auth.multi` fall through to the next guard (or 401).

## Technical design

### Contract / public interface

```yaml
App\Auth\JwtGuard implements Illuminate\Contracts\Auth\Guard:
  user(): ?Authenticatable          # entry point; caches on $this->user
  validate(array): bool             # always false — stateless

routes (web.php, admin-only via can:access,SystemController — api-docs-admin.md):
  GET    /api-docs/jwt-secrets                JwtSecretController::index     # name: api.jwt-secrets
  POST   /api-docs/jwt-secrets/{user}         JwtSecretController::generate  # name: api.jwt-secrets.generate
  DELETE /api-docs/jwt-secrets/{user}         JwtSecretController::revoke    # name: api.jwt-secrets.revoke

console:
  jwt:list
  jwt:generate <email> [--force]
  jwt:revoke <email> [--force]
```

### Data shapes

```yaml
config/jwt.php:
  enabled: bool            # env JWT_ENABLED, default false
  algorithm: string        # env JWT_ALGORITHM, default HS256
  max_ttl: int             # env JWT_MAX_TTL, default 36000 (seconds)
  leeway: int              # env JWT_LEEWAY, default 60 (seconds, clock skew)
  required_claims: [sub, exp, iat]
  issuer: string|null      # env JWT_ISSUER, default null (skip check)

users:
  jwt_secret: string            # encrypted cast; 64-char random (Str::random(64))
  jwt_secret_generated_at: datetime

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

```
Request with "Authorization: Bearer <jwt>"
  → AuthenticateMultiple: skip 'jwt' unless config('jwt.enabled') AND Setting::get('jwt_enabled_override')
  → JwtGuard::user(): read `sub` from the unverified payload → User::find (needs jwt_secret)
      → JWT::decode with that user's secret → required claims → iss (if configured)
      → (now - iat) <= max_ttl → $user, or null on any failure
```

### Persistence

- `users.jwt_secret` (encrypted), `users.jwt_secret_generated_at` — the only persisted JWT state;
  tokens are never stored.
- `settings.jwt_enabled_override` — DB kill switch on top of the env flag (REQ-1).

## Scenarios (BDD)

```gherkin
Scenario: A valid JWT authenticates the request
  Given a user with a jwt_secret and JWT enabled (env + setting)
  And a JWT signed with that secret containing sub/exp/iat
  When the caller sends it as a Bearer token to an API route
  Then the request authenticates as that user
# pinned by: tests/Feature/JwtAuthTest.php, tests/Unit/JwtGuardTest.php

Scenario: An expired or wrongly-signed JWT is rejected
  Given a JWT whose exp has passed, or one signed with a secret other than the user's
  When it is sent as a Bearer token
  Then the response is 401 (the guard returns null)
# pinned by: tests/Feature/JwtAuthTest.php, tests/Unit/JwtGuardTest.php

Scenario: JWT disabled globally rejects all JWTs
  Given JWT_ENABLED is false
  When a structurally valid JWT is sent as a Bearer token
  Then the response is 401
# pinned by: tests/Feature/JwtAuthTest.php

Scenario: Sanctum tokens keep working when JWT is enabled
  Given a valid Sanctum token and JWT enabled
  When the Sanctum token is sent as a Bearer token
  Then the request still authenticates via the sanctum guard
# pinned by: tests/Feature/JwtAuthTest.php

Scenario Outline: The guard rejects a token that fails a claim check
  Given a validly signed JWT <defect>
  When the guard validates the token
  Then it returns null
  Examples:
    | defect                                                  |
    | whose iat is further in the past than jwt.max_ttl       |
    | whose iss does not match a configured jwt.issuer        |
    | missing iat or exp                                      |
# pinned by: tests/Unit/JwtGuardTest.php

Scenario: Admin generates, regenerates and revokes a user's JWT secret
  Given an admin and a target user with no jwt_secret
  When the admin POSTs /api-docs/jwt-secrets/{user}
  Then a 64-character secret is stored and returned once
  When the admin POSTs again
  Then the message says "regenerated" and the secret changes
  When the admin DELETEs /api-docs/jwt-secrets/{user}
  Then the jwt_secret is cleared, and a second DELETE returns 404
# pinned by: tests/Feature/JwtSecretManagementTest.php

Scenario: Admin lists users with JWT secrets; non-admins cannot manage them
  Given a user with a jwt_secret
  When an admin sends GET /api-docs/jwt-secrets
  Then the response is 200 with users_with_secrets, all_users and jwt_enabled
  And an editor calling any jwt-secrets route gets 403
# pinned by: tests/Feature/JwtSecretManagementTest.php

Scenario: jwt:generate refuses to overwrite an existing secret without --force
  Given a user who already has a jwt_secret
  When `jwt:generate <email>` is run without --force
  Then the command fails and the secret is unchanged
# pinned by: tests/Feature/Console/JwtCommandTest.php

Scenario: jwt:revoke is a no-op for a user with no secret
  Given a user with no jwt_secret
  When `jwt:revoke <email>` is run
  Then the command succeeds without error
# pinned by: tests/Feature/Console/JwtCommandTest.php
```

## Tests & verification

- Unit: `tests/Unit/JwtGuardTest.php` — every guard branch.
- Feature: `tests/Feature/JwtAuthTest.php` (end-to-end, incl. chunked-upload routes),
  `tests/Feature/JwtSecretManagementTest.php` (admin secret routes, hidden-from-serialization),
  `tests/Feature/Console/JwtCommandTest.php`.
- Run: `php artisan config:clear && php artisan test`
