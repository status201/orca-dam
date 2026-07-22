# Passkeys (WebAuthn / FIDO2)

```yaml
id: passkeys
status: implemented
version: 1
owner: core
related:
  - architecture
  - authentication
  - two-factor-auth
  - user-management
source:
  - app/Services/PasskeyService.php
  - app/Models/Passkey.php
  - app/Http/Controllers/Auth/PasskeyController.php
  - app/Http/Controllers/Auth/PasskeyLoginController.php
  - app/Listeners/EnforcePasskeyLimit.php
  - app/Listeners/TouchPasskeyLastUsed.php
  - app/Providers/AppServiceProvider.php
  - config/passkeys.php
  - routes/auth.php
  - routes/web.php
```

## Background / Why

Passkeys give admins and editors passwordless, phishing-resistant login (WebAuthn /
FIDO2) via `laravel/passkeys` ~0.2.1. ORCA keeps the package's model and event
plumbing but takes over the URLs and storage: `Passkeys::ignoreRoutes()` disables
the package's own `/passkeys/*` routes so ORCA's own `/passkey/*` and
`/profile/passkeys/*` stay authoritative, and a custom `App\Models\Passkey` adds an
`encrypted:json` cast on the credential blob. API users are excluded — passkeys (like
2FA) are a browser-session concept.

## Requirements

- **REQ-1** — Only `admin` and `editor` roles can register passkeys
  (`User::canEnablePasskeys()`); `api` users get a `403` from the options endpoint.
- **REQ-2** — A user may hold at most `PasskeyService::MAX_CREDENTIALS_PER_USER`
  (10) passkeys. The registration-options endpoint pre-flight-checks this and
  returns `422` when already at the cap; `EnforcePasskeyLimit` is a belt-and-braces
  listener that deletes the newest passkey again if a concurrent registration
  squeaks past the pre-flight check and puts the user over the cap.
- **REQ-3** — A successful passkey login (`PasskeyLoginController::login`) logs the
  user in directly and routes to `/dashboard`, **bypassing** the TOTP 2FA challenge
  that a password login would otherwise trigger — the passkey assertion is itself
  treated as sufficient possession+verification.
- **REQ-4** — Passkey login attempts are rate-limited at 10/minute per IP
  (`PasskeyLoginController::RATE_LIMIT`), independent of any route throttle
  middleware.
- **REQ-5** — The credential blob is encrypted at rest (`App\Models\Passkey`'s
  `encrypted:json` cast on `credential`) — the raw DB column must never contain the
  plaintext public key material.
- **REQ-6** — The package's own routes must stay disabled
  (`Passkeys::ignoreRoutes()` in `AppServiceProvider::register()`) so ORCA's
  `/passkey/*` URLs remain the only registered surface.
- **REQ-7** — A user can only rename/delete their **own** passkeys
  (`PasskeyService::renameCredential`/`deleteCredential` scope by `$user->passkeys()`).
  An admin can recovery-clear **another** user's passkeys
  (`UserController::clearPasskeys`, gated by `UserPolicy::clearPasskeys`) but not
  their own (self-action forbidden, same rule as `UserPolicy::delete`).

## Technical design

### Contract / public interface

```yaml
App\Services\PasskeyService:
  MAX_CREDENTIALS_PER_USER: int = 10
  listCredentials(User): Collection<Passkey>
  hasReachedLimit(User): bool
  renameCredential(User, credentialId, ?name): ?Passkey    # null if not found/not owned
  deleteCredential(User, credentialId): bool
  clearAllCredentials(User): int                            # admin recovery action

routes (auth.php, guest — login ceremony):
  GET  /passkey/options              PasskeyLoginController::options
  POST /passkey/login                PasskeyLoginController::login

routes (auth.php, session-authed — management ceremony):
  GET    /profile/passkeys/options        PasskeyController::options
  POST   /profile/passkeys                PasskeyController::store
  PATCH  /profile/passkeys/{credential}   PasskeyController::update
  DELETE /profile/passkeys/{credential}   PasskeyController::destroy

routes (web.php, admin-only — recovery):
  DELETE /users/{user}/passkeys       UserController::clearPasskeys

User (via PasskeyAuthenticatable / App\Models\User):
  canEnablePasskeys(): bool          # isAdmin() || isEditor()
  hasPasskeysEnabled(): bool         # package trait — true once any passkey exists
  passkeys(): HasMany<Passkey>
  last_passkey_used_at: ?datetime    # stamped by TouchPasskeyLastUsed
```

### Data shapes

```yaml
passkeys table (package, App\Models\Passkey):
  id: int
  user_id: int              # FK users, cascade delete
  name: string
  credential_id: string     # unique
  credential: json          # cast encrypted:json via App\Models\Passkey — never plaintext at rest
  last_used_at: ?datetime   # maintained by the package's VerifyPasskey action
  created_at / updated_at

users:
  last_passkey_used_at: ?datetime   # mirrored by TouchPasskeyLastUsed listener
```

### Layer touchpoints & ordering

```
Registration:
  GET /profile/passkeys/options
    → canEnablePasskeys() check (403) → hasReachedLimit() check (422)
    → GenerateRegistrationOptions action → options stashed in session
  POST /profile/passkeys
    → re-check role + limit (concurrent-registration guard)
    → StorePasskey action verifies attestation against session-stashed options
    → PasskeyRegistered event fires → EnforcePasskeyLimit listener (deletes if over cap)

Login:
  GET /passkey/options  (guest, discoverable — no email scoping)
    → GenerateVerificationOptions action → options stashed in session
  POST /passkey/login
    → rate limit check (10/min/IP)
    → VerifyPasskey action verifies assertion against session-stashed options
    → PasskeyVerified event fires → TouchPasskeyLastUsed listener stamps last_passkey_used_at
    → Auth::guard('web')->login($user) → session regenerate → users.last_login_at stamped
    → JSON { redirect: '/dashboard' }   # bypasses the 2FA challenge (REQ-3)
```

### Persistence

- `passkeys` table (package-owned schema, ORCA-owned model class via
  `Passkeys::usePasskeyModel(Passkey::class)` in `AppServiceProvider`).
- `users.last_passkey_used_at` — ORCA-added mirror column, distinct from the
  package's own `passkeys.last_used_at` per-credential timestamp.
- Session keys `passkey.registration_options` / `passkey.verification_options` hold
  the in-flight WebAuthn ceremony challenge between the options call and the
  store/login call — never persisted to the DB.

## Scenarios (BDD)

```gherkin
Scenario: Admins and editors can enable passkeys, API users cannot
  Given users with role admin, editor, and api
  When canEnablePasskeys() is checked
  Then admin and editor are true, api is false
# pinned by: tests/Feature/PasskeyTest.php

Scenario: A user reaching the cap is denied at the options step
  Given a user with 10 registered passkeys
  When they GET /profile/passkeys/options
  Then the response status is 422
# pinned by: tests/Feature/PasskeyTest.php

Scenario: An API user cannot request passkey registration options
  Given an api-role user
  When they GET /profile/passkeys/options
  Then the response status is 403
# pinned by: tests/Feature/PasskeyTest.php

Scenario: A user can rename and delete their own passkey
  Given a user with a registered passkey
  When they PATCH /profile/passkeys/{credential} with a new name
  Then the passkey's name is updated
  When they DELETE /profile/passkeys/{credential}
  Then the passkey is removed
# pinned by: tests/Feature/PasskeyTest.php

Scenario: A user cannot rename or delete someone else's passkey
  Given a passkey owned by another user
  When the caller attempts to rename or delete it
  Then the request fails with a validation error and the passkey is untouched
# pinned by: tests/Feature/PasskeyTest.php

Scenario: An admin can clear another user's passkeys but not their own
  Given an admin and a target user with passkeys
  When the admin DELETEs /users/{target}/passkeys
  Then all of the target's passkeys are removed
  When the admin attempts the same action against themselves
  Then the response is forbidden
# pinned by: tests/Feature/PasskeyTest.php

Scenario: A concurrent registration over the cap is pruned by the safety-net listener
  Given a user already at the 10-passkey cap
  When PasskeyRegistered fires for an 11th passkey
  Then EnforcePasskeyLimit deletes that newest passkey
  And the user's passkey count stays at the cap
# pinned by: tests/Feature/PasskeyTest.php

Scenario: A successful passkey verification stamps last_passkey_used_at
  Given a user with a registered passkey
  When PasskeyVerified fires for that user and passkey
  Then users.last_passkey_used_at is set
# pinned by: tests/Feature/PasskeyTest.php

Scenario: The credential blob is encrypted at rest
  Given a stored passkey with known credential payload data
  When the raw passkeys.credential column is read directly (bypassing the cast)
  Then it does not contain the plaintext payload
  And the model cast still round-trips the original array
# pinned by: tests/Feature/PasskeyTest.php

Scenario: The package's own default routes stay disabled
  Given the application is booted
  When the package's default /passkeys/login/options route is requested
  Then it returns 404
# pinned by: tests/Feature/PasskeyTest.php

Scenario: passkeys:list and passkeys:revoke manage credentials from the CLI
  Given registered passkeys across several users
  When `passkeys:list` is run with --user or --role filters
  Then only the matching passkeys are shown
  When `passkeys:revoke <id>` or `passkeys:revoke --user=<email>` is run with --force
  Then the matching passkey(s) are deleted
# pinned by: tests/Feature/PasskeyTest.php
```

## Tests & verification

- Feature: `tests/Feature/PasskeyTest.php` — the full surface: role gating, cap
  enforcement (options + listener safety net), rename/delete ownership, admin
  recovery clear (+ self-action forbidden), login route wiring + rate limiting,
  encrypted-at-rest credential, package-route disablement, listener wiring,
  `passkeys:list`/`passkeys:revoke` console commands.
- Unit: `tests/Unit/PasskeyServiceTest.php` — `PasskeyService` methods in isolation
  (list ordering, limit check, rename/delete ownership scoping, clear-all).
- Run: `php artisan config:clear && php artisan test`

## Open questions / future

- None found — this is one of the most thoroughly pinned specs in the backfill;
  every requirement above traces to a scenario in `PasskeyTest.php` or
  `PasskeyServiceTest.php`.
