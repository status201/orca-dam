# Maintenance console commands

```yaml
id: maintenance-commands
status: implemented
version: 5
owner: core
related:
  - architecture
  - system-admin
  - user-audit-log
source:
  - app/Console/Commands/
```

## Background / Why

ORCA ships 19 `artisan` commands covering asset hygiene, auth-secret lifecycle, translation
safety, the user audit trail and live-schema verification. They are the CLI counterpart to the
System dashboard (`ArtisanCommandRunner::suggestedCommands()` surfaces some there) and the
emergency-recovery tool (JWT/2FA/passkey/token revocation) when the web UI is inaccessible.
`CLAUDE.md` lists them for day-to-day use; this spec owns their contracts.

## Requirements

- **REQ-1** — Every destructive command (`token:revoke`, `jwt:revoke`,
  `two-factor:disable`, `passkeys:revoke`, `assets:deduplicate --force`) prompts for
  confirmation unless `--force` is passed.
- **REQ-2** — Commands that look up a user or token by identifier fail with
  `Command::FAILURE` and a clear message when the identifier doesn't resolve,
  rather than silently no-oping.
- **REQ-3** — `assets:deduplicate` defaults to dry-run; only `--force` soft-deletes,
  and assets carrying a `reference` tag are always skipped regardless of `--force`.
- **REQ-4** — `lang:safe-update` is the only sanctioned way to refresh laravel-lang
  translations: it diffs `lang/nl.json` around the underlying `lang:update`, restores any
  project-owned key the publisher clobbered, and strips the English `lang/en.json` /
  `lang/en/*.php` files ORCA doesn't ship. See [ADR-009](../decisions/adr-009-project-owns-nl-json.md).
- **REQ-5** — `reference-tag:create` refuses to repurpose an existing non-reference tag: a
  collision with a `user`-type tag is skipped with a warning, and the exit code is a failure
  only when *every* requested name collided.
- **REQ-6** — `db:verify-schema` makes the schema assertions the suite cannot, and **never
  reports success for a check it did not run**: "Schema verified." only when every check ran
  and passed; otherwise it names the failed checks, or says nothing was checked on a driver it
  cannot read. Exit `0` for all three (REQ-findings-exit-zero); under `--strict`: `0`
  verified, `1` a check failed, `2` the driver cannot answer. Why: a pipeline must tell
  "verified wrong" from "not verified" (SQLite is a supported deployment). What it checks:
  [`input-validation.md`](input-validation.md) REQ-1, REQ-10 to REQ-12.
- **REQ-findings-exit-zero** — A report command exits `0` when it ran, whatever it found;
  findings are output, not failure. Why: System → Commands and Forge label any non-zero exit
  "Failed". Non-zero is for a run that could not do its job (unresolved lookup, bad input, a
  failed repair). A report a script may gate on takes `--strict`, which makes findings non-zero.
- **REQ-audit-content-types** — `assets:audit-content-types` finds stored objects whose served
  headers disagree with [`upload-policy.md`](upload-policy.md) REQ-content-type-from-extension.
  It checks every asset **including soft-deleted** ones, compares `Content-Type` and
  `Content-Disposition` with what an upload would set today, and flags as **dangerous** any
  mismatch served inline under an active type (HTML, XHTML, XML, JavaScript, or SVG behind a
  non-SVG extension). It also counts every asset whose `mime_type` differs from its
  extension's type (allowlisted extensions only — off-list files keep their classification).
  Dry-run by default, exit `0` (REQ-findings-exit-zero); `--strict` exits `1` while either kind
  of mismatch remains. `--fix` rewrites headers in place (same key, same bytes — s3-storage
  REQ-1), records the copy's new ETag, purges the URL from Cloudflare when configured, and
  syncs `mime_type`; rows whose headers were already right get no copy and no purge. Both
  column writes leave `updated_at` alone (a repair is not an edit: no model events, no audit,
  no re-sort). A missing object is counted, not repaired. `--fix` exits `1` when it could not
  rewrite an object.

## Technical design

### Contract / public interface

```yaml
# Uploads / assets
uploads:cleanup {--hours=24}            # abort stale pending|uploading UploadSessions via ChunkedUploadService::abortUpload()
assets:verify-integrity                 # dispatch VerifyAssetIntegrity per asset (queue-jobs.md)
assets:backfill-etags                   # null/empty etag → S3Service::getObjectMetadata(); Updated/Failed counts
assets:deduplicate {--force}            # group by etag, keep oldest, skip reference-tagged, soft-delete rest under --force
assets:audit-content-types {--fix} {--strict}
  # withTrashed, chunked by id; S3Service::getObjectHeaders + UploadPolicy::servedHeaderMismatch();
  # dangerous first, list capped at 100; --fix: S3Service::repairUploadHeaders(), toBase() updates,
  # CloudflareService::purgeUrls() per 30 URLs; mime_type sync one whereIn update per type
# Sanctum tokens
token:create {email?} {--name=} {--new} {--user-name=}   # --new provisions a role=api user; plaintext shown once
token:list {--user=} {--role=}
token:revoke {id?} {--user=} {--force}                    # one by id, or all for --user
# JWT secrets
jwt:generate {email} {--force}          # 64-char secret; --force required to regenerate
jwt:list                                # users with a jwt_secret + whether JWT_ENABLED
jwt:revoke {email} {--force}            # clears jwt_secret + jwt_secret_generated_at
# Two-factor recovery
two-factor:status {--email=} {--role=} {--enabled} {--disabled}
two-factor:disable {email} {--force}    # TwoFactorService::disableTwoFactor()
# Passkeys
passkeys:list {--user=} {--role=}
passkeys:revoke {id?} {--user=} {--force}   # exact credential_id or unambiguous prefix, or all for --user
# Reference tags (ADR-012)
reference-tag:create {names?*}          # normalized (trim + lowercase + dedup)
# User audit trail (user-audit-log.md) — the only reader; no UI
users:audit {--user=} {--event=} {--limit=50}
  # newest first; --user matches current email OR recorded label; --event ∈ created|updated|deleted
# Schema (MySQL/MariaDB only — REQ-6)
db:verify-schema {--strict}
  # information_schema vs ColumnLimits::CHARS widths, TEXT_BYTES capacities, row format DYNAMIC,
  # and every non-PRIMARY B-TREE index's byte size vs InnoDB's 3072-byte limit
  # (FULLTEXT/SPATIAL listed as exempt, not dropped)
# Translations
lang:safe-update                        # REQ-4
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

Asset commands are thin wrappers over services (`ChunkedUploadService`, `S3Service`) and jobs
— no business logic in the command classes. Auth-secret commands write `User` columns or
delegate to `TwoFactorService`. `lang:safe-update` calls `lang:update` and post-processes
the files it wrote.

## Scenarios (BDD)

```gherkin
Scenario: The content-type audit reports mismatches without rewriting (REQ-audit-content-types)
  Given an asset photo.jpg stored as text/html with no Content-Disposition
  And a trashed asset notes.txt stored as text/plain without attachment
  And an asset logo.png stored correctly as image/png
  When assets:audit-content-types runs
  Then photo.jpg is listed as dangerous and notes.txt as a mismatch, logo.png is not listed
  And nothing is rewritten, and the command exits 0
  When it runs again with --strict
  Then it exits 1
# pinned by: tests/Feature/Console/AssetMaintenanceCommandTest.php

Scenario: The content-type audit exits 0 when every object matches (REQ-audit-content-types)
  Given assets whose stored headers all match their extensions, and one missing object
  When assets:audit-content-types runs
  Then it reports no mismatches, counts the missing object, and exits 0
# pinned by: tests/Feature/Console/AssetMaintenanceCommandTest.php

Scenario: --fix rewrites headers in place, records the etag and purges (REQ-audit-content-types)
  Given an asset photo.jpg stored as text/html, with a multipart etag
  When assets:audit-content-types --fix runs
  Then its headers are rewritten in place, the asset's etag becomes the copy's etag
  And its URL is purged from Cloudflare, and the command exits 0
  But when the rewrite fails, it reports the failure and exits 1
# pinned by: tests/Feature/Console/AssetMaintenanceCommandTest.php

Scenario: --fix syncs mime_type without touching updated_at (REQ-audit-content-types)
  Given diagram.tex stored as text/x-tex inline with mime_type text/x-tex
  And another .tex served correctly but with mime_type text/x-tex
  When assets:audit-content-types runs, then --fix
  Then the dry run counts both as database types out of step, and only the first as a header mismatch
  And --fix sets both mime_types to application/x-tex, copying and purging only the first
  And updated_at is unchanged
# pinned by: tests/Feature/Console/AssetMaintenanceCommandTest.php

Scenario: An off-list extension keeps its mime_type (REQ-audit-content-types)
  Given a discovered asset scan.bmp with mime_type image/bmp, served as image/bmp
  When assets:audit-content-types --fix runs
  Then its mime_type is still image/bmp
# pinned by: tests/Feature/Console/AssetMaintenanceCommandTest.php

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

Scenario: assets:deduplicate reports by default and soft-deletes only under --force
  Given 3 assets share one etag, 1 has a unique etag, and one duplicate carries a reference tag
  When assets:deduplicate runs without --force, then with --force
  Then the dry run reports the duplicates and deletes nothing
  And --force keeps the oldest, soft-deletes the rest, and skips the reference-tagged one
# pinned by: tests/Feature/DuplicatePreventionTest.php

Scenario: token commands provision and validate their input
  When token:create --new --user-name=... runs with the email supplied at the prompt
  Then a role=api user is created along with a token
  And token:revoke with neither an id nor --user exits 1
# pinned by: tests/Feature/Console/TokenCommandTest.php

Scenario: jwt commands guard and clear the secret
  Given a user with a jwt_secret
  When jwt:generate runs without --force
  Then it exits 1 and the secret is unchanged; with --force the secret changes
  And jwt:revoke --force nulls jwt_secret and jwt_secret_generated_at
# pinned by: tests/Feature/Console/JwtCommandTest.php

Scenario: two-factor commands disable 2FA and validate filters
  Given a user with 2FA enabled
  When two-factor:disable --force runs
  Then two_factor_secret and two_factor_confirmed_at are cleared
  And two-factor:status --role=superadmin exits 1 with "Invalid role"
# pinned by: tests/Feature/Console/TwoFactorCommandTest.php

Scenario: passkey commands filter and revoke by prefix
  Given passkeys belonging to different users/roles, one with a long credential_id
  When passkeys:list --user= / --role= runs, and passkeys:revoke <short-prefix> --force
  Then only matching passkeys are listed, and the prefixed passkey is revoked
# pinned by: tests/Feature/PasskeyTest.php

Scenario: reference-tag:create normalizes names and never repurposes a user tag
  Given a user-type tag "collide"
  When reference-tag:create runs with "  LinkedIn  ", then [collide], then [collide, fresh]
  Then "linkedin" is created; [collide] exits 1 leaving its type unchanged
  And [collide, fresh] exits 0, creating "fresh" as reference
# pinned by: tests/Feature/ReferenceTagCreateCommandTest.php

Scenario: db:verify-schema declines rather than passing on a driver it cannot read
  Given the connection is SQLite
  When db:verify-schema runs
  Then it explains that no check was performed, never prints "Schema verified.", and exits 0
  When it runs with --strict
  Then it exits 2
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
# pinned by: tests/Feature/TranslationIntegrityTest.php (guards the outcome only — see Open questions)
```

## Tests & verification

- Feature: `tests/Feature/Console/AssetMaintenanceCommandTest.php`,
  `tests/Feature/Console/VerifyAssetIntegrityCommandTest.php`,
  `tests/Feature/DuplicatePreventionTest.php`, `tests/Feature/Console/TokenCommandTest.php`,
  `tests/Feature/Console/JwtCommandTest.php`, `tests/Feature/Console/TwoFactorCommandTest.php`,
  `tests/Feature/PasskeyTest.php`, `tests/Feature/ReferenceTagCreateCommandTest.php`,
  `tests/Feature/Console/VerifySchemaCommandTest.php` (driver refusal + index byte arithmetic;
  the live checks need MariaDB) — `php artisan config:clear && php artisan test`

## Open questions / future

- `lang:safe-update` has no test that invokes it; `TranslationIntegrityTest` guards only the
  outcome. A command test stubbing `lang:update` would close the gap.
