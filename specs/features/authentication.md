# Authentication

```yaml
id: authentication
status: implemented
version: 3
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

ORCA serves browser sessions and machine callers (WordPress, RTE integrations, scripts) through
the same asset routes. Each auth mechanism stays in its idiomatic Laravel form (session/Breeze,
Sanctum, JWT, passkeys) and one middleware, `auth.multi` (`AuthenticateMultiple`), resolves between
them per request ([ADR-004](../decisions/adr-004-auth-multi.md)). This spec covers the web session
login flow and the guard-resolution contract; the other mechanisms and 2FA have their own specs.

## Requirements

- **REQ-1** — Web routes authenticate via the session (`web`) guard, built on the Breeze scaffold
  (`AuthenticatedSessionController`, `LoginRequest`).
- **REQ-2** — `auth.multi:<guards>` tries each named guard **in order** and authenticates on the
  first success; if none succeed it returns a `401` JSON response rather than redirecting.
- **REQ-3** — The `jwt` guard is skipped entirely unless **both** `config('jwt.enabled')`
  (`JWT_ENABLED`) and the `jwt_enabled_override` setting are true — see [jwt-auth.md](jwt-auth.md).
- **REQ-4** — A password login with 2FA enabled is diverted to the 2FA challenge instead of
  completing the session — see [two-factor-auth.md](two-factor-auth.md).
- **REQ-5** — Login is rate-limited per `email|ip` (5 attempts) via
  `LoginRequest::ensureIsNotRateLimited()`, independent of route throttle middleware.
- **REQ-6** — The remaining Breeze flows (password reset, email verification, password update,
  password confirmation) ship unmodified and stay behaviourally intact. Users are provisioned via
  `/users` ([user-management.md](user-management.md)), but these routes remain mounted, so their
  contract is pinned.
- **REQ-7** — Email verification is **not enforced**. `User` deliberately does not implement
  `MustVerifyEmail`, so the `verified` middleware on `GET /dashboard` (the only route carrying it)
  passes every authenticated user and `users.email_verified_at` carries no authorization weight.
  **Trap:** `UserController::store` never sets `email_verified_at` and provisioning sends no
  verification mail, so adding `implements MustVerifyEmail` would lock every admin-provisioned user
  out (`UserFactory` and `E2eSeeder` stamp the column, so only the pinned REQ-7 scenario would
  catch it). Enabling the contract must come with a way for provisioned users to become verified.
- **REQ-8** — **There is no self-service registration.** Accounts exist only via `/users`
  ([user-management.md](user-management.md)) or a console command (`token:create` may provision an
  `api` user). The Breeze `register` route, controller and view are **deleted, not unlinked** —
  `GET`/`POST /register` return `404`. Why: an unlinked-but-mounted register route once let an
  unknown party self-register on production as the default `editor` role ([authorization-policies.md](authorization-policies.md)), live immediately
  (REQ-7).

  `users.role` is `NOT NULL` with **no database default**
  (`database/migrations/2026_07_30_120000_drop_role_default_from_users_table.php`), so any creation
  path that omits `role` fails with a driver error instead of minting an editor. This constraint is
  the load-bearing half: it covers every path (`firstOrCreate`, raw inserts, future SSO/invite),
  while the source scan only sees `User::create(`. Any future signup must assign the least
  privilege explicitly. The migration refuses to run while any row holds a `NULL` role — the
  operator assigns it; the app never promotes silently.
- **REQ-9** — The password-reset pair (the only unauthenticated write surface) is hardened:
  - **Rate-limited at the route:** all four reset routes carry `throttle:6,1` (the broker's 60s
    throttle only debounces repeats to one address).
  - **Uniform answers:** `POST /forgot-password` returns the same generic confirmation for every
    syntactically valid address — sent, broker-throttled, or unknown — never `passwords.user`
    (a login-name oracle). Non-sent outcomes are logged server-side (`Password reset link not
    sent`; support relies on it).

  `POST /reset-password` still reports failures verbatim; it requires a valid signed token.

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

deliberately absent (REQ-8):
  GET  /register                    → 404
  POST /register                    → 404

middleware:
  AuthenticateMultiple::handle(Request, Closure, ...$guards)   # app/Http/Middleware/AuthenticateMultiple.php
    - defaults to ['sanctum', 'jwt'] when no guards are named
    - web routes use the plain 'auth' middleware (session guard only)
    - api routes use bare 'auth.multi' (the sanctum, jwt default)
    - the chunked-upload routes use 'auth.multi:web,sanctum,jwt' (session OR token)
    - first guard that authenticates becomes the default (Auth::shouldUse); none → 401
      {"message": "Unauthenticated."}
```

### Layer touchpoints & ordering

```
POST /login
  → LoginRequest::authenticate()     rate-limit check → Auth::attempt() → RateLimiter::clear()
    → AuthenticatedSessionController::store
       if $user->hasTwoFactorEnabled(): stash session, guard('web')->logout(), redirect to challenge
       else: session()->regenerate(), stamp last_login_at, redirect()->intended(dashboard)
```

### Persistence

- `users.last_login_at` — stamped on every completed session login (password, post-2FA, passkey — [passkeys.md](passkeys.md));
  JWT/Sanctum requests do not touch it.
- No separate auth table — mechanisms layer onto `users` (session), `personal_access_tokens`
  (Sanctum) and `users.jwt_secret` (JWT).
- `users.role` — `enum('editor','admin','api')`, `NOT NULL`, **no default** (REQ-8).

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

Scenario: Missing or malformed credentials on a multi-guard route return 401 JSON
  Given a request with no token, or an unparseable Authorization header
  When it hits a route behind auth.multi
  Then the response status is 401
# pinned by: tests/Feature/Middleware/AuthenticateMultipleTest.php

Scenario: A valid Sanctum token still authenticates when JWT is disabled
  Given a user with a valid Sanctum personal access token
  And JWT_ENABLED is false
  When they call a route behind auth.multi
  Then the request authenticates as that user
# pinned by: tests/Feature/Middleware/AuthenticateMultipleTest.php

Scenario: Self-service registration is not reachable (REQ-8)
  Given a guest visitor
  When they GET /register, or POST /register with a valid name, email and password
  Then the response status is 404
  And no route named "register" is registered
  And they remain a guest and no user with that email exists
# pinned by: tests/Feature/Auth/RegistrationTest.php

Scenario: No user-creation path leaves the role implicit (REQ-8)
  Given the users.role column has no database default
  When every User::create call site under app/ is inspected
  Then each one passes an explicit role
  And inserting a row with no role column is rejected by the database
# pinned by: tests/Feature/Auth/RegistrationTest.php

Scenario: A password reset completes with a valid token (REQ-6)
  Given a registered user who requested a reset link
  When they open /reset-password/{token} and POST /reset-password with a new password
  And the reset-link screen and reset screen both render
  Then an App\Notifications\ResetPasswordNotification was sent to them
  And the password is changed with no validation errors
# pinned by: tests/Feature/Auth/PasswordResetTest.php

Scenario: Unknown and broker-throttled addresses get the same answer as a sent one (REQ-9)
  Given a registered address, an unknown address, and an address that just requested a link
  When a guest POSTs /forgot-password for each
  Then all responses carry the same generic status and no validation errors
  And no notification is sent for the unknown or the repeat request
# pinned by: tests/Feature/Auth/PasswordResetThrottleTest.php

Scenario: The password-reset routes are rate-limited per IP (REQ-9)
  Given a guest who has POSTed /forgot-password 6 times in a minute
  When they POST it a 7th time
  Then the response status is 429
  And each of the four reset routes carries throttle:6,1
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

- Feature: `tests/Feature/Auth/AuthenticationTest.php`,
  `tests/Feature/Middleware/AuthenticateMultipleTest.php` (guard order, REQ-3 double gate, 401),
  `tests/Feature/Auth/RegistrationTest.php` (REQ-8),
  `tests/Feature/Auth/PasswordResetThrottleTest.php` (REQ-9; separate from the scaffold test
  because it must clear the rate limiter between cases),
  `tests/Feature/UserManagementTest.php` (REQ-7).
- Breeze scaffold (REQ-6, PHPUnit-style): `tests/Feature/Auth/PasswordResetTest.php`,
  `tests/Feature/Auth/EmailVerificationTest.php`, `tests/Feature/Auth/PasswordUpdateTest.php`,
  `tests/Feature/Auth/PasswordConfirmationTest.php`.
- Run: `php artisan config:clear && php artisan test`
- E2E: `tests/e2e/auth.spec.js` — `npm run test:e2e -- tests/e2e/auth.spec.js`.

## Open questions / future

- The REQ-6 Breeze flows are not exercised end-to-end; `auth.spec.js` drives only login/logout.
- `throttle:6,1` on reset routes is keyed per IP, so a NAT'd office shares a bucket; if it bites,
  use a named limiter keyed on `ip + email`, not a higher ceiling.
- Role changes are audited ([user-audit-log.md](user-audit-log.md)) but nothing pushes a
  notification on admin-role assignment; ORCA has no notification channel beyond reset mail.
