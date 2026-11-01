# Discovery & import

```yaml
id: discovery-import
status: implemented
version: 2
owner: core
related:
  - architecture
  - asset-model
  - s3-storage
  - duplicate-detection
  - ai-tagging
  - image-processing
source:
  - app/Http/Controllers/DiscoverController.php
  - app/Jobs/ProcessDiscoveredAsset.php
  - app/Services/AssetIngestionService.php
```

## Background / Why

Files can land in the bucket outside ORCA's upload flow (console uploads, migration scripts,
another system). Discovery lets an admin scan for S3 objects with no `Asset` row, review them, and
import a chosen subset — creating rows synchronously (cheap) while deferring dimensions,
thumbnails, resizes and AI tagging to a background job so a large import returns fast.

## Requirements

- **REQ-1** — Discovery, scanning, and importing are all admin-only
  (`AssetPolicy::discover`).
- **REQ-2** — A soft-deleted asset whose `s3_key` matches an unmapped S3
  object is surfaced with `is_deleted: true` and its `deleted_at` timestamp in
  the scan results, rather than being silently offered for re-import — this
  prevents accidentally recreating a Row a user intentionally trashed.
- **REQ-3** — Import checks both `s3_key` (already-mapped, including trashed)
  and `etag` (duplicate content under a different key) before creating a row;
  either match causes the object to be skipped, not imported.
- **REQ-4** — Asset-row creation and job dispatch happen together per key:
  the row is created inside a `DB::transaction`, then `ProcessDiscoveredAsset`
  is dispatched with the new asset's id — a failure partway through one key
  doesn't affect the others in the same import request.
- **REQ-5** — `ProcessDiscoveredAsset` runs AI tagging **synchronously within
  the job** (not via a further `GenerateAiTags::dispatch()`), since it's
  already executing inside a queue worker — dispatching another job would
  just add latency without any concurrency benefit.

## Technical design

### Contract / public interface

Routes (`routes/web.php`, admin-gated):

```yaml
GET  discover:         DiscoverController::index    # discover.index
POST discover/scan:    DiscoverController::scan      # discover.scan
POST discover/import:  DiscoverController::import    # discover.import
```

```yaml
scan(Request):     # optional folder; S3Service::findUnmappedObjects($prefix) + getObjectMetadata(),
                   # trashed status in one batched lookup → { count, objects: [...] }
import(Request):   # keys: required string[]; skips mapped keys (batched, withTrashed) and duplicate etags
                   # (duplicate-detection.md) → { success, message, imported, skipped, queued_asset_ids }
ProcessDiscoveredAsset:   # $timeout = 300, $tries = 3
  handle(S3Service, RekognitionService, AssetProcessingService)
    # backfill width/height (extractImageDimensions) → processImageAsset($asset, dispatchAiTagging: false)
    # → autoTagAsset() directly if Rekognition enabled + image (REQ-5); missing asset id = no-op;
    # re-throws on failure so the queue retries
```

### Data shapes

```yaml
# scan() response object
key: string
filename: string
size: int                       # cast: the SDK parses ListObjectsV2 Size as a string
last_modified: datetime
mime_type: string
url: string
is_deleted: bool
deleted_at: datetime-string|null
```

### Layer touchpoints & ordering

```
import(): per key: [mapped? skip] → getObjectMetadata() → [etag dup? skip]
  → DB::transaction(AssetIngestionService::persist(...)) → ProcessDiscoveredAsset::dispatch($asset->id)
```

## Scenarios (BDD)

```gherkin
Scenario: Discovery is forbidden for non-admins (REQ-1)
  Given an editor or api-role user
  When they access the discover index, trigger a scan, or call import
  Then the response is 403
# pinned by: tests/Feature/DiscoverTest.php

Scenario: Scan returns unmapped S3 objects enriched with soft-delete status
  Given an S3 object with no matching Asset, and another matching a soft-deleted Asset
  When scan runs
  Then the results include both, with is_deleted true only for the soft-deleted match
# pinned by: tests/Feature/DiscoverTest.php

Scenario: Scanned object sizes are integers
  Given a ListObjectsV2 response, which the AWS SDK parses into a string Size
  When the bucket is listed for a scan
  Then each object's size is the integer 512, not the string "512"
# pinned by: tests/Unit/Services/S3ServiceTest.php

Scenario: Import creates rows and skips mapped or duplicate keys (REQ-3, REQ-4)
  Given an unmapped S3 key
  When it is imported
  Then a new Asset row exists and ProcessDiscoveredAsset was dispatched for its id
  And a key whose asset row is soft-deleted is skipped, not recreated
  And a key whose etag duplicates an existing asset is skipped
# pinned by: tests/Feature/DiscoverTest.php

Scenario: ProcessDiscoveredAsset processes the asset in-job (REQ-5)
  Given a discovered image asset with no width/height set and Rekognition enabled
  When the job runs
  Then dimensions are backfilled, processImageAsset is called, and autoTagAsset is called directly
  And for an asset id missing from the DB it completes without error
  And an internal failure propagates so the queue retries
# pinned by: tests/Unit/Jobs/ProcessDiscoveredAssetTest.php

# — browser-level (see e2e-testing.md for the harness) —

Scenario: An object in the bucket with no database row is found and imported
  Given an object whose asset row has been force-deleted, leaving the object
  When the bucket is scanned from the discover page
  Then that object is listed as unmapped
  When it is selected and the import confirmed
  Then the import is reported, the row leaves the unmapped list, and the object is an asset
# pinned by: tests/e2e/discover.spec.js

Scenario: Select all and deselect all drive the import button
  Given a scan listing at least one unmapped object
  When Select All is clicked, then Deselect All
  Then the import button becomes enabled, then disabled again
# pinned by: tests/e2e/discover.spec.js
```

## Tests & verification

- Feature: `tests/Feature/DiscoverTest.php`
- Unit: `tests/Unit/Jobs/ProcessDiscoveredAssetTest.php`, `tests/Unit/Services/S3ServiceTest.php`
  (runs the SDK's real response parsers — a `MockHandler` result would hide the string `Size`)
- E2E: `tests/e2e/discover.spec.js` — `requiresS3()` is load-bearing: without a bucket
  `listObjects` returns `[]` and the assertions would pass for the wrong reason.
- Run: `php artisan config:clear && php artisan test`; `npm run e2e:up && npm run test:e2e`
