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

Some programmatic callers (frontend RTE integrations) prefer a short-lived,
self-contained credential over a long-lived Sanctum token. ORCA adds a custom
`JwtGuard` (`firebase/php-jwt`) rather than reusing Sanctum, keyed per-user with a
symmetric secret stored encrypted on the `User` row, so a compromised JWT secret
only exposes one user's token-issuing capability. JWT is **off by default** — see
[ADR-004](../decisions/adr-004-auth-multi.md) for why it's a separate mechanism
rather than folded into Sanctum.

## Requirements

- **REQ-1** — JWT auth is double-gated: both the env flag `JWT_ENABLED`
  (`config('jwt.enabled')`) and the DB setting `jwt_enabled_override` must be true.
  `AuthenticateMultiple` **skips** the `jwt` guard entirely (does not merely deny it)
  when either is false.
- **REQ-2** — A valid token must carry the required claims `sub` (user ID), `exp`,
  `iat` (`config('jwt.required_claims')`); a token missing any of these is rejected
  even if the signature is valid.
- **REQ-3** — Each user has its own JWT secret (`users.jwt_secret`, `encrypted`
  cast). A token is verified against the secret of the user named in its `sub`
  claim — there is no shared/global signing secret.
- **REQ-4** — `max_ttl` (default 36000s / 10h, env `JWT_MAX_TTL`) is enforced against
  `iat` independent of the token's own `exp` — a token cannot outlive this ceiling
  even if issued with a longer expiry.
- **REQ-5** — If `jwt.issuer` (env `JWT_ISSUER`) is configured, the token's `iss`
  claim must match exactly; if unset, issuer is not checked.
- **REQ-6** — `JwtGuard` never throws on a malformed/invalid/expired token — every
  failure path returns `null` from `user()`, letting `auth.multi` fall through to
  the next guard (or 401).

## Technical design

### Contract / public interface

```yaml
App\Auth\JwtGuard implements Illuminate\Contracts\Auth\Guard:
  user(): ?Authenticatable          # entry point; caches on $this->user
  validate(array): bool             # always false — stateless, no credential validation path
  getTokenFromRequest(): ?string    # protected — parses "Bearer <token>"
  validateToken(string): ?User      # protected — full verify pipeline

routes (web.php, admin-only via can:access,SystemController):
  GET    /api-docs/jwt-secrets                JwtSecretController::index
  POST   /api-docs/jwt-secrets/{user}         JwtSecretController::generate
  DELETE /api-docs/jwt-secrets/{user}         JwtSecretController::revoke

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
```

### Layer touchpoints & ordering

```
Request with "Authorization: Bearer <jwt>"
  → AuthenticateMultiple (guards include 'jwt')
      skip 'jwt' entirely unless config('jwt.enabled') AND Setting::get('jwt_enabled_override')
      → Auth::guard('jwt')->check()
         → JwtGuard::user()
            → getTokenFromRequest()             regex-extract the bearer token
            → validateToken()
               1. split into 3 dot-separated parts, else null
               2. base64-decode payload (unverified) to read `sub`
               3. User::find($sub); null if missing or no jwt_secret
               4. JWT::decode() with Key($user->jwt_secret, algorithm)
               5. verify required_claims present
               6. verify iss if configured
               7. verify (now - iat) <= max_ttl
               8. return $user, or null on any exception
```

### Persistence

- `users.jwt_secret` (encrypted), `users.jwt_secret_generated_at` — the only
  persisted JWT state; tokens themselves are never stored (stateless, verified on
  every request).
- `settings.jwt_enabled_override` — DB-level kill switch layered on top of the env
  flag (REQ-1), so ops can disable JWT without a deploy.

## Scenarios (BDD)

```gherkin
Scenario: A valid JWT authenticates the request
  Given a user with a jwt_secret and JWT enabled (env + setting)
  And a JWT signed with that secret containing sub/exp/iat
  When the caller sends it as a Bearer token to an API route
  Then the request authenticates as that user
# pinned by: tests/Feature/JwtAuthTest.php, tests/Unit/JwtGuardTest.php

Scenario: An expired JWT is rejected
  Given a JWT whose exp has passed
  When it is sent as a Bearer token
  Then the response is 401 (or the guard returns null)
# pinned by: tests/Feature/JwtAuthTest.php, tests/Unit/JwtGuardTest.php

Scenario: A JWT signed with the wrong secret is rejected
  Given a JWT signed with a secret that does not match the user's jwt_secret
  When it is sent as a Bearer token
  Then the response is 401
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

Scenario: A token exceeding max_ttl is rejected even with a valid signature
  Given a JWT issued (iat) further in the past than jwt.max_ttl
  When it is sent as a Bearer token
  Then the guard returns null
# pinned by: tests/Unit/JwtGuardTest.php

Scenario: Issuer mismatch is rejected when an issuer is configured
  Given jwt.issuer is set and the token's iss claim does not match
  When the guard validates the token
  Then it returns null
# pinned by: tests/Unit/JwtGuardTest.php

Scenario: A token missing a required claim is rejected
  Given a JWT missing iat or exp
  When the guard validates the token
  Then it returns null
# pinned by: tests/Unit/JwtGuardTest.php

Scenario: Admin generates and later revokes a user's JWT secret
  Given an admin and a target user
  When the admin POSTs /api-docs/jwt-secrets/{user}
  Then a new jwt_secret is stored and returned once
  When the admin DELETEs /api-docs/jwt-secrets/{user}
  Then the jwt_secret is cleared
# pinned by: tests/Feature/JwtSecretManagementTest.php

Scenario: Non-admins cannot manage JWT secrets
  Given a non-admin user
  When they call the jwt-secrets routes
  Then the response is forbidden
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

- Unit: `tests/Unit/JwtGuardTest.php` — every guard branch (missing header,
  malformed token, unknown user, no secret, expired, bad signature, valid, max_ttl,
  issuer match/mismatch, missing claims).
- Feature: `tests/Feature/JwtAuthTest.php` — end-to-end API auth via JWT, including
  chunked-upload routes and role propagation.
- Feature: `tests/Feature/JwtSecretManagementTest.php` — admin secret
  generate/regenerate/revoke, non-admin denial, hidden-from-serialization.
- Feature (console): `tests/Feature/Console/JwtCommandTest.php` — `jwt:generate`,
  `jwt:list`, `jwt:revoke`.
- Run: `php artisan config:clear && php artisan test`

## Open questions / future

- None — the guard's every branch, the admin management UI, and the console
  commands all have direct pinned coverage.
