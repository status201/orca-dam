# Two-factor authentication (TOTP)

```yaml
id: two-factor-auth
status: implemented
version: 1
owner: core
related:
  - architecture
  - authentication
  - passkeys
source:
  - app/Services/TwoFactorService.php
  - app/Http/Controllers/Auth/TwoFactorAuthController.php
  - config/two-factor.php
  - app/Console/Commands/TwoFactorStatusCommand.php
  - app/Console/Commands/TwoFactorDisableCommand.php
  - app/Models/User.php
  - routes/auth.php
```

## Background / Why

Password-only login is a single factor; ORCA adds TOTP-based 2FA
(`pragmarx/google2fa-laravel`) for admins and editors as a second factor, with
one-time recovery codes for device loss. It's deliberately **not offered to API
users** — API auth goes through Sanctum/JWT, not the session+password path 2FA
protects. Passkey login is treated as already phishing-resistant and explicitly
bypasses this challenge (see [passkeys.md](passkeys.md) REQ-3) rather than stacking
2FA on top of it.

## Requirements

- **REQ-1** — Only `admin` and `editor` roles can enable 2FA
  (`User::canEnableTwoFactor()`); the setup route redirects `api` users away with an
  error.
- **REQ-2** — Enabling 2FA requires proving possession of the secret: the setup flow
  stashes a freshly generated secret in the session and only persists it
  (`two_factor_secret`, `two_factor_confirmed_at`) after a correct 6-digit code is
  submitted.
- **REQ-3** — Enabling 2FA generates a fixed number of recovery codes
  (`config('two-factor.recovery_codes_count')`, default 8), stored **hashed**
  (`Hash::make`) — never in plaintext — and shown to the user exactly once via a
  one-shot session flash.
- **REQ-4** — During login, a user with 2FA enabled is diverted to a challenge
  screen; the challenge session (`two_factor_user_id`, `two_factor_timestamp`)
  expires after `config('two-factor.challenge_ttl')` (default 300s) and is
  rate-limited to `config('two-factor.challenge_rate_limit')` (default 5) attempts
  per minute per user ID.
- **REQ-5** — The challenge accepts either a 6-digit TOTP code or a recovery code; a
  used recovery code is removed from the stored list immediately
  (`useRecoveryCode`), making it single-use.
- **REQ-6** — Disabling 2FA and regenerating recovery codes both require a
  fresh password confirmation (`password.confirm` middleware on those routes).

## Technical design

### Contract / public interface

```yaml
App\Services\TwoFactorService:
  generateSecret(): string
  getQrCodeUrl(User, secret): string
  getQrCodeSvg(User, secret): string
  verifyCode(secret, code): bool
  generateRecoveryCodes(): array
  hashRecoveryCodes(array): array
  verifyRecoveryCode(code, hashedCodes): int|false          # returns matched index
  enableTwoFactor(User, secret): array                       # returns plaintext recovery codes
  disableTwoFactor(User): void
  regenerateRecoveryCodes(User): array
  useRecoveryCode(User, codeIndex): void
  getRemainingRecoveryCodesCount(User): int

routes (auth.php, guest — during login):
  GET  /two-factor-challenge          TwoFactorAuthController::showChallenge
  POST /two-factor-challenge          TwoFactorAuthController::verifyChallenge

routes (auth.php, session-authed — setup/management):
  GET    /two-factor/setup                    TwoFactorAuthController::showSetup
  POST   /two-factor/confirm                  TwoFactorAuthController::confirmSetup
  DELETE /two-factor                          TwoFactorAuthController::disable            (password.confirm)
  POST   /two-factor/recovery-codes           TwoFactorAuthController::regenerateRecoveryCodes (password.confirm)
  GET    /two-factor/recovery-codes           TwoFactorAuthController::showRecoveryCodes

User:
  hasTwoFactorEnabled(): bool     # two_factor_secret AND two_factor_confirmed_at both set
  canEnableTwoFactor(): bool      # isAdmin() || isEditor()

console:
  two-factor:status [--email=] [--role=] [--enabled] [--disabled]
  two-factor:disable <email> [--force]
```

### Data shapes

```yaml
config/two-factor.php:
  recovery_codes_count: int    # env TWO_FACTOR_RECOVERY_CODES, default 8
  recovery_code_length: int    # 10
  challenge_ttl: int           # env TWO_FACTOR_CHALLENGE_TTL, default 300 (seconds)
  challenge_rate_limit: int    # 5 (attempts/minute)
  issuer: string               # env TWO_FACTOR_ISSUER, default APP_NAME
  qr_code_size: int            # 200 (px)

users:
  two_factor_secret: string              # encrypted cast
  two_factor_recovery_codes: array       # encrypted:array cast; each entry is a bcrypt hash
  two_factor_confirmed_at: ?datetime     # null until setup is confirmed
```

### Layer touchpoints & ordering

```
Setup:
  GET /two-factor/setup
    → canEnableTwoFactor() check → generate/reuse session-stashed secret → QR shown
  POST /two-factor/confirm
    → verifyCode(secret, code) → enableTwoFactor() persists secret+hashed codes+confirmed_at
    → plaintext codes flashed to session (one-shot) → redirect to recovery-codes.show

Login-time challenge (see also authentication.md):
  POST /login → hasTwoFactorEnabled() → stash two_factor_user_id/timestamp,
    guard('web')->logout() → redirect to /two-factor-challenge
  POST /two-factor-challenge
    → session-expiry check (challenge_ttl) → rate-limit check (per user ID)
    → 6-digit code: verifyCode()  |  else: verifyRecoveryCode() + useRecoveryCode()
    → completeLogin(): Auth::login(), session regenerate, stamp last_login_at
```

### Persistence

- `users.two_factor_secret` / `two_factor_recovery_codes` / `two_factor_confirmed_at`
  — the entire persisted state; no separate 2FA table.
- Session-only (never persisted to DB): the in-setup secret
  (`two_factor_setup_secret`), the pending-login challenge state
  (`two_factor_user_id`, `two_factor_timestamp`, `two_factor_remember`), and the
  one-shot plaintext recovery codes flash (`two_factor_recovery_codes` session key —
  same name as the DB column, different store).

## Scenarios (BDD)

```gherkin
Scenario: An eligible user can view the setup page
  Given an admin or editor
  When they GET /two-factor/setup
  Then the response is successful and shows a QR code
# pinned by: tests/Feature/TwoFactorAuthTest.php

Scenario: An API user cannot access 2FA setup
  Given an api-role user
  When they GET /two-factor/setup
  Then they are redirected away with an error
# pinned by: tests/Feature/TwoFactorAuthTest.php

Scenario: Confirming setup with a valid code enables 2FA and issues recovery codes
  Given a user mid-setup with a session-stashed secret
  When they POST /two-factor/confirm with the correct TOTP code
  Then two_factor_secret and two_factor_confirmed_at are persisted
  And recovery codes are shown once
# pinned by: tests/Feature/TwoFactorAuthTest.php

Scenario: Confirming setup with an invalid code does not enable 2FA
  Given a user mid-setup
  When they POST /two-factor/confirm with an incorrect code
  Then 2FA remains disabled
# pinned by: tests/Feature/TwoFactorAuthTest.php

Scenario: A 2FA-enabled user is redirected to the challenge on login
  Given a user with 2FA enabled
  When they log in with correct credentials
  Then they are redirected to the two-factor challenge instead of the dashboard
# pinned by: tests/Feature/TwoFactorAuthTest.php

Scenario: A user without 2FA logs in directly
  Given a user without 2FA enabled
  When they log in with correct credentials
  Then they land on the dashboard without a challenge
# pinned by: tests/Feature/TwoFactorAuthTest.php

Scenario: The challenge accepts a valid TOTP code
  Given a pending 2FA challenge session
  When the correct 6-digit code is submitted
  Then login completes and the session is authenticated
# pinned by: tests/Feature/TwoFactorAuthTest.php

Scenario: The challenge accepts a valid recovery code and consumes it
  Given a pending 2FA challenge session and an unused recovery code
  When the recovery code is submitted
  Then login completes
  And that recovery code can no longer be used
# pinned by: tests/Feature/TwoFactorAuthTest.php

Scenario: The challenge rejects an invalid code
  Given a pending 2FA challenge session
  When an incorrect code is submitted
  Then the challenge is not passed
# pinned by: tests/Feature/TwoFactorAuthTest.php

Scenario: The challenge expires after its configured TTL
  Given a pending 2FA challenge older than challenge_ttl
  When the challenge page is requested
  Then the session is cleared and the user is sent back to /login
# pinned by: tests/Feature/TwoFactorAuthTest.php

Scenario: A user can disable 2FA and regenerate recovery codes
  Given a user with 2FA enabled and a confirmed password session
  When they DELETE /two-factor
  Then 2FA is disabled
  When they instead POST /two-factor/recovery-codes
  Then a new set of recovery codes is issued
# pinned by: tests/Feature/TwoFactorAuthTest.php

Scenario: two-factor:status and two-factor:disable manage 2FA from the CLI
  Given users with varying 2FA status
  When `two-factor:status` is run with --enabled/--disabled/--role filters
  Then only matching users are listed
  When `two-factor:disable <email> --force` is run
  Then that user's 2FA is cleared
# pinned by: tests/Feature/Console/TwoFactorCommandTest.php
```

## Tests & verification

- Feature: `tests/Feature/TwoFactorAuthTest.php` — setup gating, confirm
  valid/invalid, login diversion, challenge accept/reject/expire, disable,
  recovery-code regeneration, profile visibility per role.
- Feature (console): `tests/Feature/Console/TwoFactorCommandTest.php` —
  `two-factor:status` (list/filter/invalid role), `two-factor:disable`
  (clears/no-op/missing user).
- Unit: `tests/Unit/TwoFactorServiceTest.php` — every `TwoFactorService` method
  (secret generation, code verify, recovery code generate/hash/verify/consume, QR
  code/URL generation).
- Run: `php artisan config:clear && php artisan test`

## Open questions / future

- None — setup, the login-time challenge, disable/regenerate, and the console
  recovery commands are all directly pinned.
