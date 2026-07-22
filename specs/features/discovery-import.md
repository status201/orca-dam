# Discovery & import

```yaml
id: discovery-import
status: implemented
version: 1
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
```

## Background / Why

Files can land in the S3 bucket outside of ORCA's upload flow (manual console
upload, migration scripts, another system writing to the same bucket).
Discovery lets an admin scan for S3 objects that have no matching `Asset` row,
review them, and import a chosen subset — creating `Asset` rows immediately
(cheap, synchronous) while deferring the expensive per-file work (dimensions,
thumbnail, resizes, AI tagging) to a background job so the import request
itself returns fast even for a large batch.

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

`scan(Request)` — validates none (folder is optional query input); calls
`S3Service::findUnmappedObjects($prefix)`, enriches each with
`getObjectMetadata()` and soft-delete status (`Asset::onlyTrashed()` lookup
batched in one query), returns `{ count, objects: [...] }`.

`import(Request)` — validates `keys` (required array of strings); for each
key not already mapped (batched pre-fetch of existing `s3_key`s) and whose
etag doesn't already exist: creates the `Asset` row, dispatches
`ProcessDiscoveredAsset::dispatch($asset->id)`. Returns
`{ success, message, imported, skipped, queued_asset_ids }`.

`ProcessDiscoveredAsset` job (`timeout = 300`, `tries = 3`) —
`handle(S3Service, RekognitionService, AssetProcessingService)`: backfills
missing width/height via `S3Service::extractImageDimensions()`; runs
`AssetProcessingService::processImageAsset($asset, dispatchAiTagging: false)`
for thumbnail/resizes; then, if Rekognition is enabled and the asset is an
image, calls `RekognitionService::autoTagAsset()` directly (see REQ-5).
Re-throws on failure to trigger the job's own retry.

### Data shapes

```yaml
# scan() response object shape
key: string
filename: string
size: int
last_modified: datetime
mime_type: string
url: string
is_deleted: bool
deleted_at: datetime-string|null
```

### Layer touchpoints & ordering

```
scan(): S3Service::findUnmappedObjects() → batch pre-fetch trashed s3_keys → per-object getObjectMetadata()
import(): batch pre-fetch existing s3_keys (withTrashed)
  → per key: [already mapped? skip] → getObjectMetadata() → [etag dup? skip]
    → DB::transaction(Asset::create(...)) → ProcessDiscoveredAsset::dispatch($asset->id)
ProcessDiscoveredAsset::handle():
  → extractImageDimensions() (if missing)
  → AssetProcessingService::processImageAsset($asset, dispatchAiTagging: false)
  → RekognitionService::autoTagAsset() directly (if enabled + isImage)
```

## Scenarios (BDD)

```gherkin
Scenario: Discovery index and scan are forbidden for non-admins
  Given an editor or api-role user
  When they access the discover index or trigger a scan
  Then the response is 403
# pinned by: tests/Feature/DiscoverTest.php

Scenario: Scan returns unmapped S3 objects enriched with soft-delete status
  Given an S3 object with no matching Asset, and another matching a soft-deleted Asset
  When scan runs
  Then the results include both, with is_deleted true only for the soft-deleted match
# pinned by: tests/Feature/DiscoverTest.php

Scenario: Import creates an asset and dispatches ProcessDiscoveredAsset
  Given an unmapped S3 key
  When it is imported
  Then a new Asset row exists and ProcessDiscoveredAsset was dispatched for its id
# pinned by: tests/Feature/DiscoverTest.php

Scenario: Import skips a key matching a soft-deleted asset (prevents re-import)
  Given an S3 key whose asset row is soft-deleted
  When it is submitted for import
  Then it is skipped, not recreated
# pinned by: tests/Feature/DiscoverTest.php

Scenario: Import skips a key whose etag duplicates an existing asset
  Given an unmapped key whose content etag matches an existing asset
  When it is submitted for import
  Then it is skipped
# pinned by: tests/Feature/DiscoverTest.php

Scenario: Import requires admin
  Given a non-admin user
  When they call the import endpoint
  Then the response is 403
# pinned by: tests/Feature/DiscoverTest.php

Scenario: ProcessDiscoveredAsset extracts dimensions and processes the image
  Given a discovered image asset with no width/height set
  When the job runs
  Then dimensions are backfilled and processImageAsset is called
# pinned by: tests/Unit/Jobs/ProcessDiscoveredAssetTest.php

Scenario: ProcessDiscoveredAsset runs AI tagging synchronously when Rekognition is enabled
  Given Rekognition is enabled and the asset is an image
  When the job runs
  Then autoTagAsset is called directly within the job (no further dispatch)
# pinned by: tests/Unit/Jobs/ProcessDiscoveredAssetTest.php

Scenario: ProcessDiscoveredAsset is a silent no-op when the asset is missing from the DB
  Given a job dispatched for an asset id that doesn't exist
  When it runs
  Then it completes without error
# pinned by: tests/Unit/Jobs/ProcessDiscoveredAssetTest.php

Scenario: ProcessDiscoveredAsset re-throws on failure to trigger a retry
  Given an internal failure during processing
  When the job runs
  Then the exception propagates (so the queue retries per tries=3)
# pinned by: tests/Unit/Jobs/ProcessDiscoveredAssetTest.php
```

## Tests & verification

- Feature: `tests/Feature/DiscoverTest.php`
- Unit: `tests/Unit/Jobs/ProcessDiscoveredAssetTest.php`
- Run: `php artisan config:clear && php artisan test`
