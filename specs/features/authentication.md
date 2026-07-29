# Authentication

```yaml
id: authentication
status: implemented
version: 1
owner: core
related:
  - architecture
  - authorization-policies
  - api-tokens-sanctum
  - jwt-auth
  - passkeys
  - two-factor-auth
source:
  - app/Http/Middleware/AuthenticateMultiple.php
  - app/Http/Controllers/Auth/AuthenticatedSessionController.php
  - app/Http/Controllers/Auth/RegisteredUserController.php
  - app/Http/Requests/Auth/LoginRequest.php
  - routes/auth.php
  - routes/web.php
```

## Background / Why

ORCA serves human browser sessions and machine callers (WordPress, RTE integrations,
scripts) through the same asset routes. Rather than a single unified guard, ORCA
keeps each auth mechanism in its idiomatic Laravel form (session/Breeze, Sanctum,
JWT, passkeys) and resolves between them per-request with one middleware,
`auth.multi` (`AuthenticateMultiple`) — see
[ADR-004](../decisions/adr-004-auth-multi.md) for the full rationale and rejected
alternatives. This spec covers the web session login flow and the guard-resolution
contract; the other three mechanisms and 2FA each have their own spec.

## Requirements

- **REQ-1** — Web routes authenticate via Laravel's session (`web`) guard, built on
  the Breeze scaffold (`AuthenticatedSessionController`, `LoginRequest`).
- **REQ-2** — `auth.multi:<guards>` tries each named guard **in order** and
  authenticates on the first success; if none succeed it returns a `401` JSON
  response rather than redirecting to a login page (API-shaped, not browser-shaped).
- **REQ-3** — The `jwt` guard is skipped by `AuthenticateMultiple` entirely (not just
  denied) unless **both** `config('jwt.enabled')` (env `JWT_ENABLED`) and the
  `jwt_enabled_override` DB setting are true — see [jwt-auth.md](jwt-auth.md).
- **REQ-4** — A successful password login that has 2FA enabled is diverted to the
  2FA challenge instead of completing the session — see
  [two-factor-auth.md](two-factor-auth.md).
- **REQ-5** — Login is rate-limited per `email|ip` (5 attempts) via
  `LoginRequest::ensureIsNotRateLimited()`, independent of any route-level throttle
  middleware.

## Technical design

### Contract / public interface

```yaml
routes (routes/auth.php, guest-only unless noted):
  GET  /login                       AuthenticatedSessionController::create
  POST /login                       AuthenticatedSessionController::store
  GET  /register                    RegisteredUserController::create
  POST /register                    RegisteredUserController::store
  GET  /forgot-password             PasswordResetLinkController::create
  POST /forgot-password             PasswordResetLinkController::store
  GET  /reset-password/{token}      NewPasswordController::create
  POST /reset-password              NewPasswordController::store
  GET  /two-factor-challenge        TwoFactorAuthController::showChallenge
  POST /two-factor-challenge        TwoFactorAuthController::verifyChallenge
routes (auth-only):
  POST /logout                      AuthenticatedSessionController::destroy
  PUT  /password                    PasswordController::update
  GET  /confirm-password            ConfirmablePasswordController::show

middleware:
  AuthenticateMultiple::handle(Request, Closure, ...$guards)   # app/Http/Middleware/AuthenticateMultiple.php
    - defaults to ['sanctum', 'jwt'] when no guards are named
    - web routes use the plain 'auth' middleware (session guard only)
    - api routes use 'auth.multi:sanctum,jwt'
    - the chunked-upload routes use 'auth.multi:web,sanctum,jwt' (session OR token)
```

### Layer touchpoints & ordering

```
POST /login
  → LoginRequest::authenticate()     rate-limit check → Auth::attempt() → RateLimiter::clear()
    → AuthenticatedSessionController::store
       if $user->hasTwoFactorEnabled(): stash session, guard('web')->logout(), redirect to challenge
       else: session()->regenerate(), stamp last_login_at, redirect()->intended(dashboard)
```

`AuthenticateMultiple::handle()` iterates `$guards` (or the default
`['sanctum', 'jwt']`), calling `Auth::guard($guard)->check()`; the first guard that
authenticates is set as the request's default guard via `Auth::shouldUse($guard)`
and the request proceeds. If every guard fails, the middleware returns
`response()->json(['message' => 'Unauthenticated.'], 401)` directly — it does not
redirect to `/login`, since API callers expect a JSON 401.

### Persistence

- `users.last_login_at` — stamped on every completed session login (password path
  and the post-2FA `completeLogin()` path). Passkey login stamps it too (see
  [passkeys.md](passkeys.md)); JWT/Sanctum requests do not touch it (stateless).
- No separate "auth" table — mechanisms layer directly onto `users` (session guard),
  `personal_access_tokens` (Sanctum), and `users.jwt_secret` (JWT).

## Scenarios (BDD)

```gherkin
Scenario: Login screen renders
  Given a guest visitor
  When they GET /login
  Then the response status is 200
# pinned by: tests/Feature/Auth/AuthenticationTest.php

Scenario: Valid credentials authenticate and redirect to the dashboard
  Given a registered user with no 2FA enabled
  When they POST /login with the correct email and password
  Then they are authenticated on the web guard
  And the response redirects to the dashboard
  And users.last_login_at is stamped
# pinned by: tests/Feature/Auth/AuthenticationTest.php

Scenario: Invalid password does not authenticate
  Given a registered user
  When they POST /login with the wrong password
  Then they remain a guest
  And users.last_login_at is not stamped
# pinned by: tests/Feature/Auth/AuthenticationTest.php

Scenario: Logout ends the session
  Given an authenticated user
  When they POST /logout
  Then they become a guest
  And the response redirects to /
# pinned by: tests/Feature/Auth/AuthenticationTest.php

Scenario: No credentials on a multi-guard route returns 401 JSON
  Given a request with no Sanctum token and no JWT
  When it hits a route behind auth.multi
  Then the response status is 401
# pinned by: tests/Feature/Middleware/AuthenticateMultipleTest.php

Scenario: A malformed bearer token is rejected
  Given a request with an unparseable Authorization header
  When it hits a route behind auth.multi
  Then the response status is 401
# pinned by: tests/Feature/Middleware/AuthenticateMultipleTest.php

Scenario: A valid Sanctum token still authenticates when JWT is disabled
  Given a user with a valid Sanctum personal access token
  And JWT_ENABLED is false
  When they call a route behind auth.multi:sanctum,jwt
  Then the request authenticates as that user
# pinned by: tests/Feature/Middleware/AuthenticateMultipleTest.php
```

## Tests & verification

- Feature: `tests/Feature/Auth/AuthenticationTest.php` — login render, authenticate,
  reject bad password, logout.
- Feature: `tests/Feature/Middleware/AuthenticateMultipleTest.php` — guard-order
  resolution, the JWT env/setting double-gate (REQ-3), 401 fallback.
- Run: `php artisan config:clear && php artisan test`
- E2E: `tests/e2e/auth.spec.js` — real-browser login, bad-password error, logout, guest redirect.

## Open questions / future

- Registration (`RegisteredUserController`) has no dedicated feature test in the
  suite grepped for this spec; it ships as standard Breeze scaffolding and is not
  linked from the app's navigation (users are provisioned via `/users`, see
  [user-management.md](user-management.md)), so it is documented here without a
  pinned scenario.
