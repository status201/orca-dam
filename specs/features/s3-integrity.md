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

S3 objects can go missing behind ORCA's back (manual bucket edits, lifecycle
rules, accidental deletion outside the app). Rather than checking on every
read, integrity is verified out-of-band: `assets:verify-integrity` dispatches
one queued job per asset, each of which HEADs its S3 object and flips
`assets.s3_missing_at` accordingly. The System dashboard surfaces the current
missing-count and lets an admin trigger a fresh sweep or filter the index down
to just the missing assets (`?missing=1`).

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
php artisan assets:verify-integrity   # dispatches VerifyAssetIntegrity job per Asset id
```

`VerifyAssetIntegrity` job (`public $timeout = 60`, `public $tries = 2`) —
`handle(S3Service $s3Service)`: looks up the asset by id; calls
`S3Service::getObjectMetadata($asset->s3_key)`; `null` result → object
missing → set `s3_missing_at = now()` (only if not already set) + forget
`assets:missing_count`; non-null result → object exists → clear
`s3_missing_at` (only if it was set) + forget the same cache key. Re-throws on
an unexpected exception so the queue retries (`tries = 2`); logs via
`failed()` on permanent failure.

System routes (admin-gated): `GET system/integrity-status`
(`SystemController::integrityStatus` — missing/total counts),
`POST system/verify-integrity` (`SystemController::verifyIntegrity` —
triggers the command).

Model surface: `Asset::scopeMissing($query)` (filters
`whereNotNull('s3_missing_at')`), `Asset::getIsMissingAttribute(): bool` — see
[`asset-model.md`](asset-model.md).

### Persistence

`assets.s3_missing_at` (nullable datetime, cast `datetime`). Cache key
`assets:missing_count` (300s TTL, forgotten eagerly on state change — see
REQ-3).

## Scenarios (BDD)

```gherkin
Scenario: assets:verify-integrity dispatches one job per asset
  Given N assets in the database
  When assets:verify-integrity runs
  Then N VerifyAssetIntegrity jobs are dispatched, one per asset id
# pinned by: tests/Feature/Console/VerifyAssetIntegrityCommandTest.php

Scenario: assets:verify-integrity is a no-op with no assets
  Given zero assets
  When the command runs
  Then no jobs are dispatched and it exits successfully
# pinned by: tests/Feature/Console/VerifyAssetIntegrityCommandTest.php

Scenario: The job sets s3_missing_at when the S3 object is missing
  Given an asset whose S3 object does not exist
  When VerifyAssetIntegrity job runs
  Then s3_missing_at is set to the current time
# pinned by: tests/Unit/Jobs/VerifyAssetIntegrityTest.php, tests/Feature/IntegrityTest.php

Scenario: The job clears s3_missing_at when the object is found again
  Given an asset with s3_missing_at already set, whose S3 object now exists
  When the job runs
  Then s3_missing_at is cleared
# pinned by: tests/Unit/Jobs/VerifyAssetIntegrityTest.php, tests/Feature/IntegrityTest.php

Scenario: The job preserves the original missing timestamp on repeat checks
  Given an asset already flagged missing at time T
  When the job runs again and the object is still missing
  Then s3_missing_at remains T (not updated to now)
# pinned by: tests/Unit/Jobs/VerifyAssetIntegrityTest.php, tests/Feature/IntegrityTest.php

Scenario: The job flushes the missing-count cache when state changes
  Given the assets:missing_count cache is warm
  When a job changes an asset's missing state
  Then the cache key is forgotten
# pinned by: tests/Unit/Jobs/VerifyAssetIntegrityTest.php

Scenario: The job is a silent no-op when the asset no longer exists in the DB
  Given a job dispatched for an asset id that has since been force-deleted
  When the job runs
  Then it completes without error and makes no changes
# pinned by: tests/Unit/Jobs/VerifyAssetIntegrityTest.php

Scenario: scopeMissing returns only assets flagged missing
  Given a mix of flagged and unflagged assets
  When querying Asset::missing()
  Then only the flagged ones are returned
# pinned by: tests/Feature/IntegrityTest.php

Scenario: The asset index with ?missing=1 shows only missing assets
  Given a mix of flagged and unflagged assets
  When the index is requested with missing=1
  Then only flagged assets appear in the results
# pinned by: tests/Feature/IntegrityTest.php

Scenario: The integrity-status and verify-integrity endpoints require admin
  Given a non-admin user
  When they call either endpoint
  Then the response is 403
# pinned by: tests/Feature/IntegrityTest.php

Scenario: The integrity-status endpoint returns correct counts for admin
  Given a mix of missing/present assets and an admin user
  When the endpoint is called
  Then it returns the correct missing/total counts
# pinned by: tests/Feature/IntegrityTest.php

Scenario: The verify-integrity endpoint dispatches jobs for admin
  Given an admin user
  When they POST system/verify-integrity
  Then jobs are dispatched for the current asset set
# pinned by: tests/Feature/IntegrityTest.php
```

## Tests & verification

- Feature: `tests/Feature/Console/VerifyAssetIntegrityCommandTest.php`,
  `tests/Feature/IntegrityTest.php`
- Unit: `tests/Unit/Jobs/VerifyAssetIntegrityTest.php`
- Run: `php artisan config:clear && php artisan test`
