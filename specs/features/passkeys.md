# Passkeys (WebAuthn / FIDO2)

```yaml
id: passkeys
status: implemented
version: 2
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

Passkeys give admins and editors passwordless, phishing-resistant login (WebAuthn / FIDO2) via
`laravel/passkeys` ~0.2.1. ORCA keeps the package's model and event plumbing but owns the URLs
(`/passkey/*`, `/profile/passkeys/*`) and storage (a custom `App\Models\Passkey`). API users are
excluded — passkeys, like 2FA, are a browser-session concept.

## Requirements

- **REQ-1** — Only `admin` and `editor` roles can register passkeys
  (`User::canEnablePasskeys()`); `api` users get a `403` from the options endpoint.
- **REQ-2** — A user may hold at most `PasskeyService::MAX_CREDENTIALS_PER_USER`
  (10) passkeys. The registration-options endpoint returns `422` at the cap; the
  `EnforcePasskeyLimit` listener deletes the newest passkey if a concurrent registration slips
  past and exceeds it.
- **REQ-3** — A successful passkey login (`PasskeyLoginController::login`) logs the user in
  directly and routes to `/dashboard`, **bypassing** the TOTP 2FA challenge. Why: the assertion
  is itself possession + verification.
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
- **REQ-passkey-login-remember** — The login page's single "Remember me" checkbox
  (`#remember_me`, in the password form) applies to passkey sign-in too. Both the button ceremony
  and the conditional-UI autofill pass `@laravel/passkeys` a `remember` *getter*, not a value, so
  a box ticked after the ceremony started (autofill starts on page load) still counts.
  `PasskeyLoginController::login` validates `remember` as boolean and passes it to
  `Auth::guard('web')->login()`: the `remember_web_*` cookie is issued only when `true`;
  otherwise the login is session-only.

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
    → Auth::guard('web')->login($user, $remember) → session regenerate → users.last_login_at stamped
      # $remember = the request's `remember` boolean (REQ-passkey-login-remember)
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
Scenario: Registration options are refused to api users and to users at the cap
  Given an api-role user (canEnablePasskeys() is false; admin and editor are true)
  And an editor with 10 registered passkeys
  When each GETs /profile/passkeys/options
  Then the api user gets 403 and the editor gets 422
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

Scenario: Passkey sign-in with "Remember me" ticked issues the recaller cookie
  Given a user with a registered passkey and stashed login options
  When they POST /passkey/login with a verified assertion and remember = true
  Then they are authenticated
  And the response sets the remember_web_* cookie
# pinned by: tests/Feature/PasskeyTest.php

Scenario: The passkey recaller cookie alone restores the sign-in after the session is gone
  Given a user who signed in by passkey with "Remember me" ticked
  And their session has since expired
  When they request an authenticated page carrying only the remember_web_* cookie
  Then they are let through as that user, not sent to the login page
  And the same request without the cookie is redirected to /login
# pinned by: tests/Feature/PasskeyTest.php

Scenario: Passkey sign-in without "Remember me" is session-only
  Given a user with a registered passkey and stashed login options
  When they POST /passkey/login with a verified assertion and remember = false or absent
  Then they are authenticated
  And no remember_web_* cookie is set
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

# — browser-level (see e2e-testing.md for the harness) —

Scenario: A user with no passkeys is told so and offered a way to add one
  Given a user without passkeys
  When they open their profile
  Then the empty state is shown and the Add Passkey control is enabled
  And the unsupported-browser warning is absent, because Chromium supports WebAuthn
# pinned by: tests/e2e/passkeys.spec.js

Scenario: Registered passkeys are listed
  Given a user with two registered passkeys
  When they open their profile
  Then both are listed by name
# pinned by: tests/e2e/passkeys.spec.js

Scenario: The rename editor can be abandoned without saving
  Given the inline rename editor opened on a passkey
  When a new name is typed and Cancel is clicked
  Then the editor closes and the original name is unchanged
# pinned by: tests/e2e/passkeys.spec.js

Scenario: A passkey can be renamed
  Given the inline rename editor opened on a passkey
  When a new name is saved
  Then the list shows the new name and the old name is gone
# pinned by: tests/e2e/passkeys.spec.js

Scenario: Removing a passkey requires confirming
  Given a registered passkey
  When Remove is clicked and the confirmation accepted
  Then the passkey is gone from the list
# pinned by: tests/e2e/passkeys.spec.js
```

## Tests & verification

- Feature: `tests/Feature/PasskeyTest.php` (`VerifyPasskey` is swapped for a double — no test can
  sign an assertion).
- Unit: `tests/Unit/PasskeyServiceTest.php`.
- E2E: `tests/e2e/passkeys.spec.js` — management UI only.
- Run: `php artisan config:clear && php artisan test`; `npm run test:e2e`

## Open questions / future

- Registration and passkey sign-in are browser-untested: the ceremony needs a CDP virtual
  authenticator the E2E harness does not set up.
