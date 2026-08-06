# Maintenance console commands

```yaml
id: maintenance-commands
status: implemented
version: 2
owner: core
related:
  - architecture
  - system-admin
  - user-audit-log
source:
  - app/Console/Commands/
```

## Background / Why

ORCA ships 18 `artisan` commands covering asset hygiene, auth-secret lifecycle
management, translation safety, reading the user audit trail, and verifying the live
schema. They are the CLI counterpart to the System
admin dashboard (`SystemService::getSuggestedCommands()` surfaces the asset/queue
ones there) and the primary tool for emergency recovery (JWT/2FA/passkey/token
revocation) when the web UI itself is inaccessible.

## Requirements

- **REQ-1** — Every destructive command (`token:revoke`, `jwt:revoke`,
  `two-factor:disable`, `passkeys:revoke`, `assets:deduplicate --force`) prompts for
  confirmation unless `--force` is passed.
- **REQ-2** — Commands that look up a user or token by identifier fail with
  `Command::FAILURE` and a clear message when the identifier doesn't resolve,
  rather than silently no-oping.
- **REQ-3** — `assets:deduplicate` defaults to dry-run; only `--force` soft-deletes,
  and assets carrying a `reference` tag are always skipped (never auto-deleted)
  regardless of `--force`.
- **REQ-4** — `lang:safe-update` is the only sanctioned way to refresh
  laravel-lang framework translations; it diffs `lang/nl.json` before/after the
  underlying `lang:update` call and restores any project-owned key the publisher
  clobbered, then strips the English `lang/en.json`/`lang/en/*.php` files ORCA
  doesn't ship. See [ADR-009](../decisions/adr-009-project-owns-nl-json.md).
- **REQ-5** — `reference-tag:create` refuses to silently repurpose an existing
  non-reference tag: colliding with a `user`-type tag of the same name is skipped
  with a warning and the exit code reflects the failure only when *every*
  requested name collided.
- **REQ-6** — `db:verify-schema` makes the schema assertions the suite structurally
  cannot, and **never reports success for a check it did not run**. Its exit code is
  three-valued: `0` verified, `1` a check failed, `2` the driver cannot answer. `2`
  is distinct from `1` because a pipeline must be able to tell "verified wrong" from
  "not verified" — SQLite is a supported deployment (`DEPLOYMENT.md`), so refusing to
  run is not the same as a broken schema, and returning `0` would turn an unverified
  run into a passing one. See [`input-validation.md`](input-validation.md) REQ-1 and
  REQ-10 to REQ-12 for what it checks and why nothing else can.

## Technical design

### Contract / public interface — grouped by concern

```yaml
# Uploads / assets maintenance
uploads:cleanup {--hours=24}:
  # Aborts stale (pending|uploading, no activity past --hours) UploadSession rows
  # via ChunkedUploadService::abortUpload(); prints an Aborted/Failed/Total table.
assets:verify-integrity:
  # Dispatches VerifyAssetIntegrity job per asset id (queue-only; see system-admin.md).
assets:backfill-etags:
  # For assets with a null/empty etag, calls S3Service::getObjectMetadata() and
  # persists the etag; reports Updated/Failed counts.
assets:deduplicate {--force}:
  # Groups assets by etag (excluding null/empty), keeps the oldest per group,
  # skips duplicates carrying a reference tag, soft-deletes the rest under --force.

# API token lifecycle (Sanctum)
token:create {email?} {--name=} {--new} {--user-name=}:
  # Creates a token for an existing user, or --new provisions a role=api user first.
  # Plaintext token is shown once.
token:list {--user=} {--role=}:
  # Lists PersonalAccessToken rows with owning user name/email/role.
token:revoke {id?} {--user=} {--force}:
  # Revokes one token by id, or all tokens for --user=email.

# JWT secret lifecycle
jwt:generate {email} {--force}:
  # Generates a 64-char random secret; --force required to regenerate an existing one.
jwt:list:
  # Lists users with a non-null jwt_secret + whether JWT_ENABLED is on.
jwt:revoke {email} {--force}:
  # Clears jwt_secret + jwt_secret_generated_at.

# Two-factor emergency recovery
two-factor:status {--email=} {--role=} {--enabled} {--disabled}:
  # Lists users with 2FA status, recovery-code count, enabled-at.
two-factor:disable {email} {--force}:
  # Emergency disable: clears secret/confirmed_at via TwoFactorService::disableTwoFactor().

# Passkey (WebAuthn) lifecycle
passkeys:list {--user=} {--role=}:
  # Lists registered passkeys with owner + last_used_at.
passkeys:revoke {id?} {--user=} {--force}:
  # Revokes by exact credential_id or unambiguous prefix, or all passkeys for --user.

# Reference tags
reference-tag:create {names?*}:
  # Pre-creates reference-type tags (normalized: trim + lowercase + dedup) so
  # editors can attach them from the asset edit page — reference tags are
  # otherwise API-created only (ADR-012).

# User audit trail
users:audit {--user=} {--event=} {--limit=50}:
  # Read-only report over user_audit_logs, newest first. --user matches the subject by
  # current email OR by the recorded label, so a deleted account's history stays
  # reachable. --event rejects anything outside created|updated|deleted. The trail has
  # no UI, so this is the only way to read it — see user-audit-log.md.

# Schema verification (MySQL/MariaDB only — REQ-6)
db:verify-schema:
  # Reads information_schema and compares the LIVE schema against what the application
  # believes: every ColumnLimits::CHARS varchar width, every TEXT_BYTES capacity, the
  # row format (DYNAMIC, or the index and row-size limits collapse), and the byte size
  # of every non-PRIMARY index against InnoDB's 3072-byte key limit.
  # The index check is deliberately generic rather than a list of the known indexes:
  # those are already pinned by name in tests/Feature/S3KeyWidthTest.php, which SQLite
  # can run. What SQLite cannot do is add up the bytes — so this is what tells the next
  # person to widen an indexed column before they meet errno 1071 in production.
  # Exit 0 verified / 1 failed / 2 not verifiable on this driver.

# Translations
lang:safe-update:
  # Wraps laravel-lang's lang:update; protects lang/nl.json project keys; removes
  # published English lang files.
```

### Data shapes

```yaml
# uploads:cleanup targets UploadSession rows where:
status: pending|uploading
last_activity_at: "< now()->subHours(--hours)"

# assets:deduplicate grouping
Asset:
  etag: string        # group key; null/empty excluded
  created_at: datetime # oldest per etag group is the "keeper"
  tags: [{type: reference, ...}]  # presence on a non-keeper skips it from deletion
```

### Layer touchpoints & ordering

Asset-maintenance commands are thin wrappers over existing services
(`ChunkedUploadService`, `S3Service`) and jobs (`VerifyAssetIntegrity`) — no new
business logic lives in the command classes themselves. Auth-secret commands
write directly to `User` columns (`jwt_secret`, `two_factor_*`) or delegate to
`TwoFactorService`. `lang:safe-update` is the odd one out: it shells into another
artisan command (`$this->call('lang:update')`) and post-processes the file it
just wrote, rather than orchestrating a service.

## Scenarios (BDD)

```gherkin
Scenario: uploads:cleanup aborts only sessions past the activity threshold
  Given one upload session idle for 3 days and one idle for 0 seconds
  When uploads:cleanup --hours=24 runs
  Then only the stale session's abortUpload() is called
# pinned by: tests/Feature/Console/AssetMaintenanceCommandTest.php

Scenario: assets:backfill-etags fetches missing etags from S3
  Given an asset with a null etag
  When assets:backfill-etags runs and S3Service::getObjectMetadata returns an etag
  Then the asset's etag column is updated
# pinned by: tests/Feature/Console/AssetMaintenanceCommandTest.php

Scenario: assets:verify-integrity dispatches one job per asset
  Given 3 assets exist
  When assets:verify-integrity runs
  Then 3 VerifyAssetIntegrity jobs are pushed
# pinned by: tests/Feature/Console/VerifyAssetIntegrityCommandTest.php

Scenario: assets:deduplicate dry-run reports without deleting
  Given 3 assets share one etag and 1 asset has a unique etag
  When assets:deduplicate runs without --force
  Then it reports 2 duplicates and 4 assets still exist afterward
# pinned by: tests/Feature/DuplicatePreventionTest.php

Scenario: assets:deduplicate --force soft-deletes duplicates, keeping the oldest
  Given 3 assets share one etag
  When assets:deduplicate --force runs
  Then the oldest survives and the other 2 are soft-deleted
# pinned by: tests/Feature/DuplicatePreventionTest.php

Scenario: assets:deduplicate never deletes a duplicate carrying a reference tag
  Given a duplicate asset has a reference tag attached
  When assets:deduplicate --force runs
  Then that asset is skipped and reported, not soft-deleted
# pinned by: tests/Feature/DuplicatePreventionTest.php

Scenario: token:create --new provisions a fresh api-role user and a token
  Given no user exists with the given email
  When token:create --new --user-name=... runs and the email is supplied at the prompt
  Then a user with role api is created along with a token
# pinned by: tests/Feature/Console/TokenCommandTest.php

Scenario: token:revoke requires either an id or --user
  When token:revoke runs with neither argument
  Then it exits 1
# pinned by: tests/Feature/Console/TokenCommandTest.php

Scenario: jwt:generate refuses to overwrite an existing secret without --force
  Given a user already has a jwt_secret
  When jwt:generate runs without --force
  Then it exits 1 and the secret is unchanged
# pinned by: tests/Feature/Console/JwtCommandTest.php

Scenario: jwt:generate --force regenerates the secret
  Given a user has jwt_secret "old"
  When jwt:generate --force runs
  Then the secret changes
# pinned by: tests/Feature/Console/JwtCommandTest.php

Scenario: jwt:revoke clears the secret and its generated-at timestamp
  Given a user has a jwt_secret
  When jwt:revoke --force runs
  Then both jwt_secret and jwt_secret_generated_at are null
# pinned by: tests/Feature/Console/JwtCommandTest.php

Scenario: two-factor:disable clears 2FA state for a user
  Given a user has 2FA enabled with recovery codes
  When two-factor:disable --force runs
  Then two_factor_secret and two_factor_confirmed_at are cleared
# pinned by: tests/Feature/Console/TwoFactorCommandTest.php

Scenario: two-factor:status --role rejects an invalid role
  When two-factor:status --role=superadmin runs
  Then it exits 1 with an "Invalid role" message
# pinned by: tests/Feature/Console/TwoFactorCommandTest.php

Scenario: passkeys:revoke matches by credential_id prefix when no exact hit exists
  Given a passkey with a long credential_id
  When passkeys:revoke <short-prefix> --force runs
  Then that passkey is revoked
# pinned by: tests/Feature/PasskeyTest.php

Scenario: passkeys:list filters by user email and by role
  Given passkeys belonging to different users/roles
  When passkeys:list --user= or --role= is passed
  Then only matching passkeys are listed
# pinned by: tests/Feature/PasskeyTest.php

Scenario: reference-tag:create normalizes and dedups names
  When reference-tag:create is run with "  LinkedIn  "
  Then a reference tag named "linkedin" is created (not "LinkedIn")
# pinned by: tests/Feature/ReferenceTagCreateCommandTest.php

Scenario: reference-tag:create skips collision with an existing non-reference tag
  Given a tag "collide" already exists with type=user
  When reference-tag:create is run with names=[collide]
  Then it exits 1 and the existing tag's type is unchanged
# pinned by: tests/Feature/ReferenceTagCreateCommandTest.php

Scenario: reference-tag:create succeeds when at least one of several names is created
  Given "collide" already exists as a user tag and "fresh" does not exist
  When reference-tag:create runs with names=[collide, fresh]
  Then it exits 0, "fresh" is created as reference, and "collide" is untouched
# pinned by: tests/Feature/ReferenceTagCreateCommandTest.php

Scenario: db:verify-schema declines rather than passing on a driver it cannot read
  Given the connection is SQLite
  When db:verify-schema runs
  Then it exits 2 and explains that no check was performed
# pinned by: tests/Feature/Console/VerifySchemaCommandTest.php

Scenario: db:verify-schema measures a prefixed utf8mb4 index at four bytes per character
  Given a varchar(1024) utf8mb4 column indexed at a 255-character prefix
  When its key size is computed
  Then it is 1020 bytes, inside InnoDB's 3072-byte limit
# pinned by: tests/Feature/Console/VerifySchemaCommandTest.php

Scenario: project translations survive a laravel-lang refresh
  Given lang/nl.json has project-owned overrides of laravel-lang defaults
  When the underlying lang:update publisher would overwrite them
  Then lang:safe-update restores the pre-existing values afterward
# pinned by: tests/Feature/TranslationIntegrityTest.php (sentinel-key guard; does not invoke lang:safe-update itself — see Open questions)
```

## Tests & verification

- Feature: `tests/Feature/Console/AssetMaintenanceCommandTest.php` (uploads:cleanup,
  assets:backfill-etags), `tests/Feature/Console/VerifyAssetIntegrityCommandTest.php`,
  `tests/Feature/DuplicatePreventionTest.php` (assets:deduplicate),
  `tests/Feature/Console/TokenCommandTest.php`, `tests/Feature/Console/JwtCommandTest.php`,
  `tests/Feature/Console/TwoFactorCommandTest.php`, `tests/Feature/PasskeyTest.php`
  (passkeys:list/revoke), `tests/Feature/ReferenceTagCreateCommandTest.php` —
  `php artisan config:clear && php artisan test`
- Feature: `tests/Feature/Console/VerifySchemaCommandTest.php` — REQ-6's refusal to
  return success on an unverifiable driver, plus the index byte arithmetic, which is
  pure and therefore the only part of that command SQLite *can* exercise. The checks
  themselves need a real MariaDB and are run by the command, not by the suite.

## Open questions / future

- `lang:safe-update` itself (the diff/restore/English-file-removal logic in
  `App\Console\Commands\LangSafeUpdate`) has no test that invokes the command —
  `TranslationIntegrityTest` only guards the *outcome* (sentinel keys in
  `lang/nl.json` matching expected project values), independent of whether
  `lang:safe-update` ran. A command test that seeds a stale `nl.json`, fakes/stubs
  `lang:update` mutating it, and asserts the protect+restore+cleanup behavior would
  close this gap.
