# Authentication

```yaml
id: authentication
status: implemented
version: 1
owner: core
related:
  - architecture
  - authorization-policies
  - user-audit-log
  - api-tokens-sanctum
  - jwt-auth
  - passkeys
  - two-factor-auth
source:
  - app/Http/Middleware/AuthenticateMultiple.php
  - app/Http/Controllers/Auth/AuthenticatedSessionController.php
  - app/Http/Controllers/Auth/PasswordResetLinkController.php
  - app/Http/Controllers/Auth/NewPasswordController.php
  - app/Http/Controllers/Auth/PasswordController.php
  - app/Http/Controllers/Auth/ConfirmablePasswordController.php
  - app/Http/Controllers/Auth/VerifyEmailController.php
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
- **REQ-6** — The remaining Breeze scaffold flows (password reset, email verification,
  password update, password confirmation) ship unmodified and stay behaviourally
  intact. ORCA provisions users via `/users`
  ([user-management.md](user-management.md)) rather than through these flows, but the
  routes remain mounted, so the contract is pinned rather than left to rot.
- **REQ-7** — Email verification is **not enforced**. `User` deliberately does not
  implement `MustVerifyEmail`, so the `verified` middleware on `GET /dashboard` passes
  every authenticated user through and `users.email_verified_at` carries no
  authorization weight. Any authenticated user reaches every route their role allows,
  verified or not. Enabling the contract is a behaviour change that must be paired with
  a way for admin-provisioned users to become verified — see "Open questions".
- **REQ-8** — **There is no self-service registration.** Accounts exist only because an
  admin created them through `/users` ([user-management.md](user-management.md)) or
  through a console command (`token:create`, which may provision an `api`-role user).
  The Breeze `register` route, controller and Blade view are **deleted, not merely
  unlinked** — `GET /register` and `POST /register` resolve to `404`.

  This is a security requirement, not a tidiness one. While the routes were mounted but
  unlinked, `RegisteredUserController::store` passed no `role` to `User::create`, so a
  self-registered account took the `users.role` column default of `editor`
  (`database/migrations/2025_01_01_000001_add_role_to_users_table.php`) — which
  [authorization-policies.md](authorization-policies.md) grants view, create, update,
  replace, soft-delete, restore and bulk-download over **every asset in the library**.
  Because email verification is inert (REQ-7), that access was live from the moment of
  signup, with no confirmation step. Unlinked is not unreachable: the URL is guessable,
  and at least one unknown party did register on production this way. Any future
  reintroduction of open signup must therefore assign a role explicitly and default it
  to the least privilege available, not to the column default.
- **REQ-9** — The password-reset pair is the only unauthenticated write surface left, and
  it is hardened two ways:
  - **Rate-limited at the route.** All four routes carry `throttle:6,1`. The broker's
    `config('auth.passwords.users.throttle')` (60s) only debounces *repeat* links to the
    same address, so it does nothing against a caller walking a list of addresses; the
    route limit bounds per-IP volume regardless of which address is submitted.
  - **Uniform answers.** `POST /forgot-password` returns the same generic confirmation
    for every syntactically valid address — sent, broker-throttled, or no such user. It
    no longer surfaces `passwords.user` ("We can't find a user with that email address"),
    which was a login-name oracle: an unauthenticated caller could confirm whether any
    given address held an ORCA account. Non-sent outcomes are logged server-side, so
    operators keep the signal the user no longer gets.

  `POST /reset-password` still reports failures verbatim; it requires a valid signed
  token, so it discloses nothing to a caller who does not already hold one.

## Technical design

### Contract / public interface

```yaml
routes (routes/auth.php, guest-only unless noted):
  GET  /login                       AuthenticatedSessionController::create
  POST /login                       AuthenticatedSessionController::store
  GET  /forgot-password             PasswordResetLinkController::create   # + throttle:6,1
  POST /forgot-password             PasswordResetLinkController::store    # + throttle:6,1
  GET  /reset-password/{token}      NewPasswordController::create         # + throttle:6,1
  POST /reset-password              NewPasswordController::store          # + throttle:6,1
  GET  /two-factor-challenge        TwoFactorAuthController::showChallenge
  POST /two-factor-challenge        TwoFactorAuthController::verifyChallenge
routes (auth-only):
  POST /logout                      AuthenticatedSessionController::destroy
  PUT  /password                    PasswordController::update
  GET  /confirm-password            ConfirmablePasswordController::show
  POST /confirm-password            ConfirmablePasswordController::store
  GET  /verify-email                EmailVerificationPromptController
  GET  /verify-email/{id}/{hash}    VerifyEmailController                 # + signed, throttle:6,1
  POST /email/verification-notification
                                    EmailVerificationNotificationController::store

deliberately absent (REQ-8) — asserted by tests/Feature/Auth/RegistrationTest.php:
  GET  /register                    → 404
  POST /register                    → 404

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

Scenario: Self-service registration is not reachable (REQ-8)
  Given a guest visitor
  When they GET /register
  Then the response status is 404
  And no route named "register" is registered
# pinned by: tests/Feature/Auth/RegistrationTest.php

Scenario: Posting a registration payload creates no account (REQ-8)
  Given a guest visitor
  When they POST /register with a valid name, email and password
  Then the response status is 404
  And they remain a guest
  And no user with that email exists
# pinned by: tests/Feature/Auth/RegistrationTest.php

Scenario: No user-creation path relies on the role column default (REQ-8)
  Given the users.role column defaults to 'editor'
  When every User::create call site under app/ is inspected
  Then each one passes an explicit role
# pinned by: tests/Feature/Auth/RegistrationTest.php

Scenario: A password reset completes with a valid token (REQ-6)
  Given a registered user who requested a reset link
  When they open /reset-password/{token} and POST /reset-password with a new password
  And the reset-link screen and reset screen both render
  Then an App\Notifications\ResetPasswordNotification was sent to them
  And the password is changed with no validation errors
# pinned by: tests/Feature/Auth/PasswordResetTest.php

Scenario: An unknown address gets the same answer as a known one (REQ-9)
  Given no user with the address nobody@example.com
  When a guest POSTs /forgot-password for a registered address
  And a guest POSTs /forgot-password for nobody@example.com
  Then both responses carry the same generic status and no validation errors
  And no notification was sent for the unknown address
# pinned by: tests/Feature/Auth/PasswordResetThrottleTest.php

Scenario: A broker-throttled repeat is indistinguishable from the first request (REQ-9)
  Given a user who just requested a reset link
  When they immediately POST /forgot-password again
  Then the response carries the same generic status and no validation errors
  And the broker sent no second notification
# pinned by: tests/Feature/Auth/PasswordResetThrottleTest.php

Scenario: The password-reset routes are rate-limited per IP (REQ-9)
  Given a guest who has POSTed /forgot-password 6 times in a minute
  When they POST it a 7th time
  Then the response status is 429
# pinned by: tests/Feature/Auth/PasswordResetThrottleTest.php

Scenario: Every password-reset route carries the throttle (REQ-9)
  Given the four /forgot-password and /reset-password routes
  When their gathered middleware is inspected
  Then each one includes throttle:6,1
# pinned by: tests/Feature/Auth/PasswordResetThrottleTest.php

Scenario: Email verification accepts a signed link and rejects a tampered hash (REQ-6)
  Given an unverified authenticated user
  When they open the signed verification URL
  Then users.email_verified_at is set and a Verified event fires
  But an invalid hash leaves the address unverified
# pinned by: tests/Feature/Auth/EmailVerificationTest.php

Scenario: Updating a password requires the current one (REQ-6)
  Given an authenticated user
  When they PUT /password with the correct current password and a new one
  Then the password is updated
  But a wrong current_password returns a validation error on current_password
# pinned by: tests/Feature/Auth/PasswordUpdateTest.php

Scenario: Password confirmation gates sensitive screens (REQ-6)
  Given an authenticated user
  When they GET /confirm-password, then POST it with their password
  Then the screen renders and the confirmation is accepted
  But a wrong password returns a validation error on password
# pinned by: tests/Feature/Auth/PasswordConfirmationTest.php

Scenario: An unverified user still reaches every route their role allows (REQ-7)
  Given an authenticated user whose email_verified_at is null
  When they open /dashboard, the route carrying the `verified` middleware
  Then the response is 200, because User does not implement MustVerifyEmail
  And the same holds for a user just created through /users, which never sets the column
# pinned by: tests/Feature/UserManagementTest.php

# — browser-level (see e2e-testing.md for the harness) —

Scenario: A seeded editor logs in through the real form and lands on the asset library
  Given the login page in a real browser
  When they submit editor@e2e.test with the seeded password
  Then the asset grid is visible
  And the navigation shows their name
# pinned by: tests/e2e/auth.spec.js

Scenario: A wrong password keeps the user on the login page with an error
  Given the login page
  When they submit a bad password
  Then an email validation error is shown and no session is created
# pinned by: tests/e2e/auth.spec.js

Scenario: An unauthenticated visitor is redirected to login
  Given no session
  When they open /assets
  Then they land on /login
# pinned by: tests/e2e/auth.spec.js

Scenario: Logging out really ends the session
  Given a session created by this test (never a shared role session — e2e-testing.md REQ-13)
  When they log out from the user menu
  Then /assets redirects to /login
# pinned by: tests/e2e/auth.spec.js
```

## Tests & verification

- Feature: `tests/Feature/Auth/AuthenticationTest.php` — login render, authenticate,
  reject bad password, logout.
- Feature: `tests/Feature/Middleware/AuthenticateMultipleTest.php` — guard-order
  resolution, the JWT env/setting double-gate (REQ-3), 401 fallback.
- Feature (REQ-9): `tests/Feature/Auth/PasswordResetThrottleTest.php` — the uniform
  answer for unknown/throttled addresses, the 429 after 6 attempts, and that all four
  reset routes actually carry the middleware. Kept separate from
  `PasswordResetTest.php`, which is scaffold-shaped and exercises the happy path; a
  throttle test has to clear the rate limiter between cases and would otherwise make
  those four tests order-dependent.
- Feature (REQ-8): `tests/Feature/Auth/RegistrationTest.php` — that `/register` is gone
  (both verbs 404, no named route, no account created) and that no `User::create` call
  site under `app/` leans on the `role` column default. It inverts what this file
  asserted before the routes were removed.
- Feature (Breeze scaffold, REQ-6): `tests/Feature/Auth/PasswordResetTest.php`,
  `tests/Feature/Auth/EmailVerificationTest.php`,
  `tests/Feature/Auth/PasswordUpdateTest.php`,
  `tests/Feature/Auth/PasswordConfirmationTest.php` — these came with the scaffold and
  are PHPUnit-style (`test_*` methods) rather than Pest, as are
  `AuthenticationTest.php` and `RegistrationTest.php` above; together with
  `tests/Feature/ProfileTest.php` they are the only seven such files in the suite.
- Feature (REQ-7): `tests/Feature/UserManagementTest.php` — that an unverified user, and
  specifically a freshly provisioned one, still reaches the `verified`-gated
  `/dashboard`. Lives with the provisioning tests it guards
  ([user-management.md](user-management.md)).
- Run: `php artisan config:clear && php artisan test`
- E2E: `tests/e2e/auth.spec.js` — real-browser login, bad-password error, logout, guest redirect.

## Open questions / future

- The Breeze flows in REQ-6 are pinned but not exercised end-to-end; `auth.spec.js`
  drives only login/logout.
- REQ-9 closed the enumeration oracle on `POST /forgot-password`, at a real usability
  cost: someone who typos their address now waits for mail that will never arrive,
  because the app can no longer tell them the address is unknown. That is the standard
  trade for not having a login-name oracle, but it makes the server-side log the only
  place the truth exists — so the `Password reset requested for an unknown address` log
  line is load-bearing for support, not just for security.
- The `throttle:6,1` on the reset routes is keyed per-IP by Laravel's default resolver,
  so a NAT'd office shares one bucket while a distributed caller gets one bucket each.
  6/min is generous enough that the former is unlikely to bite; if it ever does, the fix
  is a named limiter keyed on `ip + email`, not a higher ceiling.
- The audit trail ([user-audit-log.md](user-audit-log.md)) records role changes but
  nothing *pushes* — an operator still has to run `users:audit` or read the log. A mail
  or Slack notification on admin-role assignment is the obvious next step; it was left
  out because ORCA has no configured notification channel beyond password-reset mail.
- **Email verification is wired but inert, and that is a trap** (REQ-7). `GET /dashboard`
  is the only route carrying `verified` (`routes/web.php`), and `User` does **not**
  implement `Illuminate\Contracts\Auth\MustVerifyEmail`, so `EnsureEmailIsVerified`
  short-circuits and lets every authenticated user through regardless of
  `email_verified_at`. The column, the `/verify-email` routes and
  `EmailVerificationTest.php` all work in isolation; nothing enforces them.
  The trap: `UserController::store` never sets `email_verified_at`
  ([user-management.md](user-management.md)), so adding `implements MustVerifyEmail` to
  `User` — a small, plausible change — would instantly lock every admin-provisioned user
  out of the dashboard, with no way back because provisioning sends no verification
  mail. `UserFactory` and `E2eSeeder` both stamp the column, so no existing test would
  catch it. The scenario pinned above exists to fail loudly if that happens; whoever
  enables the contract must also decide how provisioned users get verified.
