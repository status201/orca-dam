# S3 integrity verification

```yaml
id: s3-integrity
status: implemented
version: 1
owner: core
related:
  - architecture
  - asset-model
  - s3-storage
source:
  - app/Console/Commands/VerifyAssetIntegrity.php
  - app/Jobs/VerifyAssetIntegrity.php
  - app/Http/Controllers/SystemController.php
```

## Background / Why

S3 objects can go missing behind ORCA's back (manual bucket edits, lifecycle rules, deletion
outside the app). Rather than checking on every read, integrity is verified out-of-band:
`assets:verify-integrity` dispatches one queued job per asset, each HEADing its object and
flipping `assets.s3_missing_at`. The System dashboard shows the missing count, can trigger a
sweep, and the index filters to missing assets with `?missing=1`.

## Requirements

- **REQ-1** — The command dispatches one `VerifyAssetIntegrity` job per asset
  id rather than checking synchronously, so a large library doesn't block the
  artisan process or a web request.
- **REQ-2** — The job only writes to `s3_missing_at` when the state actually
  *changes* — it does not re-stamp an already-missing asset's timestamp on
  every re-check, preserving the original detection time.
- **REQ-3** — Whenever `s3_missing_at` changes (set or cleared), the
  `assets:missing_count` cache key is flushed so the dashboard/index badge
  reflects the new count immediately rather than waiting out its TTL.
- **REQ-4** — A job for an asset id that no longer exists in the DB is a
  silent no-op (the asset may have been force-deleted between dispatch and
  execution) — it does not fail the job.
- **REQ-5** — Triggering a fresh integrity sweep from the System dashboard
  requires the admin role (`SystemPolicy`).

## Technical design

### Contract / public interface

```yaml
php artisan assets:verify-integrity   # dispatches VerifyAssetIntegrity per Asset id

App\Jobs\VerifyAssetIntegrity:        # $timeout = 60, $tries = 2
  handle(S3Service): void
    # S3Service::getObjectMetadata(s3_key): null → set s3_missing_at (if unset); non-null → clear it (if set);
    # either change forgets assets:missing_count. Unexpected exceptions are re-thrown (queue retries);
    # failed() logs a permanent failure.

GET  system/integrity-status:  SystemController::integrityStatus   # admin — missing/total counts
POST system/verify-integrity:  SystemController::verifyIntegrity   # admin — runs the command

Asset::scopeMissing($query)            # whereNotNull('s3_missing_at') — owned here
Asset::is_missing                      # accessor, asset-model.md
```

### Persistence

`assets.s3_missing_at` (nullable datetime). Cache key `assets:missing_count` (300s TTL, forgotten
eagerly on state change — REQ-3).

## Scenarios (BDD)

```gherkin
Scenario: assets:verify-integrity dispatches one job per asset
  Given N assets in the database
  When assets:verify-integrity runs
  Then N VerifyAssetIntegrity jobs are dispatched, one per asset id
  And with zero assets nothing is dispatched and it exits successfully
# pinned by: tests/Feature/Console/VerifyAssetIntegrityCommandTest.php

Scenario: The job flips s3_missing_at only on a state change (REQ-2)
  Given an asset whose S3 object does not exist
  When the job runs
  Then s3_missing_at is set to now
  And a later run while still missing keeps the original timestamp
  And a run after the object reappears clears it
# pinned by: tests/Unit/Jobs/VerifyAssetIntegrityTest.php, tests/Feature/IntegrityTest.php

Scenario: The job flushes the missing-count cache and ignores vanished assets (REQ-3, REQ-4)
  Given the assets:missing_count cache is warm
  When a job changes an asset's missing state
  Then the cache key is forgotten
  And a job for a force-deleted asset id completes without error or changes
# pinned by: tests/Unit/Jobs/VerifyAssetIntegrityTest.php

Scenario: Missing assets can be listed
  Given a mix of flagged and unflagged assets
  Then Asset::missing() and the index with ?missing=1 return only the flagged ones
# pinned by: tests/Feature/IntegrityTest.php

Scenario: The System endpoints are admin-only (REQ-5)
  Given a non-admin user
  Then integrity-status and verify-integrity are 403
  And for an admin integrity-status returns correct missing/total counts
  And verify-integrity dispatches jobs for the current asset set
# pinned by: tests/Feature/IntegrityTest.php
```

## Tests & verification

- Feature: `tests/Feature/Console/VerifyAssetIntegrityCommandTest.php`,
  `tests/Feature/IntegrityTest.php`
- Unit: `tests/Unit/Jobs/VerifyAssetIntegrityTest.php`
- Run: `php artisan config:clear && php artisan test`
