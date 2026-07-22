# Queue Jobs

```yaml
id: queue-jobs
status: implemented
version: 1
owner: core
related:
  - architecture
  - decisions/adr-008-sqlite-tests
source:
  - app/Jobs/GenerateAiTags.php
  - app/Jobs/ProcessDiscoveredAsset.php
  - app/Jobs/VerifyAssetIntegrity.php
  - app/Jobs/RegenerateResizedImage.php
  - app/Jobs/RunTestSuiteJob.php
```

## Background / Why

ORCA offloads slow or unreliable work (AWS calls, S3 image processing, running the
whole Pest suite) to the queue so a request/response cycle never blocks on it. All
five jobs run **synchronously** in tests (`QUEUE_CONNECTION=sync` per
`phpunit.xml`, see [ADR-008](../decisions/adr-008-sqlite-tests.md)) and via a
Supervisor-managed `queue:work` process in production
(`deploy/supervisor/orca-queue-worker.conf` — see `DEPLOYMENT.md`; never run
`queue:work` from the web UI).

## Requirements

- **REQ-1** — Every job is a **thin dispatch of an ID**, not a serialized model
  reference, except `GenerateAiTags` (constructed with the `Asset` model directly,
  dispatched `->afterResponse()` so it doesn't delay the HTTP response even on the
  sync driver). The ID-based jobs (`ProcessDiscoveredAsset`, `VerifyAssetIntegrity`,
  `RegenerateResizedImage`) re-fetch the model in `handle()` and are a **silent
  no-op** (logged, not thrown) if the row is gone by the time the job runs.
- **REQ-2** — A job that fails mid-way **re-throws** after logging, so Laravel's
  retry mechanism (`$tries`) gets a chance, and `failed()` logs the permanent
  failure once retries are exhausted.
- **REQ-3** — `RunTestSuiteJob` is `$tries = 1` (a flaky Pest run should not silently
  retry) with a long `$timeout = 900` (15 min, the full suite can be slow); it marks
  the run row failed via `TestRunnerService::markFailed()` both inline (`catch`) and
  in `failed()` so the web UI's poll always sees a terminal state.
- **REQ-4** — `GenerateAiTags` skips non-images and animated GIFs (Rekognition
  doesn't support GIF) rather than erroring, and swallows
  `RekognitionService::autoTagAsset()` exceptions entirely (logged, no rethrow, no
  retry) — a tagging failure must never block or retry-storm the upload flow.

## Technical design

### Contract / public interface

```yaml
GenerateAiTags(Asset $asset)
  ::dispatch($asset)->afterResponse()          # app/Services/AssetProcessingService.php
  handle(RekognitionService): skip non-images/GIFs; swallow tagging exceptions

ProcessDiscoveredAsset(int $assetId)
  ::dispatch($assetId)                          # app/Http/Controllers/DiscoverController.php
  timeout=300 tries=3
  handle(S3Service, RekognitionService, AssetProcessingService):
    extract dimensions -> processImageAsset(dispatchAiTagging: false) -> AI tag inline if enabled

VerifyAssetIntegrity(int $assetId)
  ::dispatch($assetId)                          # app/Console/Commands/VerifyAssetIntegrity.php (assets:verify-integrity)
  timeout=60 tries=2
  handle(S3Service): sets/clears s3_missing_at; forgets 'assets:missing_count' cache on change

RegenerateResizedImage(int $assetId)
  ::dispatch($assetId)                          # app/Http/Controllers/SystemController.php (regenerateResizedImages)
  timeout=300 tries=3
  handle(S3Service): deleteResizedImages() then generateResizedImages(); skips non-images

RunTestSuiteJob(string $runId, string $suite='all', ?string $filter=null)
  ::dispatch($runId, $suite, $filter)            # app/Http/Controllers/SystemController.php (web test runner)
  tries=1 timeout=900
  handle(TestRunnerService): runStreaming(); markFailed() on exception (inline + failed())
```

### Layer touchpoints & ordering

```
GenerateAiTags:          AssetProcessingService::processImageAsset() (if dispatchAiTagging + Rekognition enabled)
                          -> used by AssetController / AssetApiController / ChunkedUploadController / ProcessDiscoveredAsset
ProcessDiscoveredAsset:   DiscoverController (S3 discovery import) -> one job per imported asset
VerifyAssetIntegrity:     `php artisan assets:verify-integrity` console command -> one job per asset (batch integrity sweep)
RegenerateResizedImage:   SystemController::regenerateResizedImages() -> one job per image asset (bulk admin action)
RunTestSuiteJob:          SystemController's web test runner (System -> Tests, admin only) -> one job per triggered run
```

### Persistence

- No dedicated job-state table; each job re-derives its state from the `Asset` row
  (or, for `RunTestSuiteJob`, from `TestRunnerService`'s cache-backed run records).
- `VerifyAssetIntegrity` forgets the `assets:missing_count` cache key whenever
  `s3_missing_at` transitions (set or cleared), so the System dashboard's missing-count
  card doesn't need to recompute on every request.

## Scenarios (BDD)

```gherkin
Scenario: GenerateAiTags tags an eligible image
  Given a non-GIF image asset
  When GenerateAiTags::handle() runs
  Then RekognitionService::autoTagAsset() is called with that asset
# pinned by: tests/Unit/Jobs/GenerateAiTagsTest.php

Scenario: GenerateAiTags skips non-images and GIFs
  Given a PDF asset, or a GIF image asset
  When GenerateAiTags::handle() runs
  Then RekognitionService::autoTagAsset() is never called
# pinned by: tests/Unit/Jobs/GenerateAiTagsTest.php

Scenario: GenerateAiTags swallows a Rekognition failure
  Given autoTagAsset() throws
  When GenerateAiTags::handle() runs
  Then the exception is caught and logged, not propagated
# pinned by: tests/Unit/Jobs/GenerateAiTagsTest.php

Scenario: ProcessDiscoveredAsset extracts dimensions and processes the image
  Given a discovered image asset with no width/height
  When ProcessDiscoveredAsset::handle() runs
  Then dimensions are extracted and saved, and processImageAsset() is called with AI dispatch disabled
# pinned by: tests/Unit/Jobs/ProcessDiscoveredAssetTest.php

Scenario: ProcessDiscoveredAsset runs AI tagging inline when Rekognition is enabled
  Given Rekognition is enabled in config
  When ProcessDiscoveredAsset::handle() runs for an image
  Then autoTagAsset() is called synchronously within the same job
# pinned by: tests/Unit/Jobs/ProcessDiscoveredAssetTest.php

Scenario: A discovered-asset job is a silent no-op if the row is gone
  Given an assetId that does not exist in the DB
  When ProcessDiscoveredAsset::handle() runs
  Then no S3/service calls are made and no exception is thrown
# pinned by: tests/Unit/Jobs/ProcessDiscoveredAssetTest.php

Scenario: ProcessDiscoveredAsset re-throws on failure to allow a retry
  Given processImageAsset() throws
  When ProcessDiscoveredAsset::handle() runs
  Then the exception propagates (job is retried per $tries)
# pinned by: tests/Unit/Jobs/ProcessDiscoveredAssetTest.php

Scenario: VerifyAssetIntegrity marks a missing S3 object
  Given an asset whose S3 object no longer exists
  When VerifyAssetIntegrity::handle() runs
  Then s3_missing_at is set and the missing-count cache is invalidated
# pinned by: tests/Unit/Jobs/VerifyAssetIntegrityTest.php

Scenario: VerifyAssetIntegrity clears a recovered asset
  Given an asset with s3_missing_at set, whose S3 object now exists
  When VerifyAssetIntegrity::handle() runs
  Then s3_missing_at is cleared and the cache is invalidated
# pinned by: tests/Unit/Jobs/VerifyAssetIntegrityTest.php

Scenario: VerifyAssetIntegrity does not re-touch an already-missing asset
  Given an asset already flagged missing at a known timestamp
  When the object is still missing on the next check
  Then s3_missing_at is left unchanged (no redundant write/cache-forget)
# pinned by: tests/Unit/Jobs/VerifyAssetIntegrityTest.php

Scenario: RegenerateResizedImage replaces resize variants for an image
  Given an image asset with existing resize_{s,m,l}_s3_key values
  When RegenerateResizedImage::handle() runs
  Then the old resize files are deleted, new ones generated, and the asset's keys updated
# pinned by: tests/Unit/Jobs/RegenerateResizedImageTest.php

Scenario: RegenerateResizedImage skips non-image assets
  Given a non-image asset
  When RegenerateResizedImage::handle() runs
  Then neither deleteResizedImages() nor generateResizedImages() is called
# pinned by: tests/Unit/Jobs/RegenerateResizedImageTest.php

Scenario: RunTestSuiteJob delegates to TestRunnerService with the right arguments
  Given a runId, suite, and filter
  When RunTestSuiteJob::handle() runs
  Then TestRunnerService::runStreaming() is called with exactly those arguments
# pinned by: tests/Unit/Jobs/RunTestSuiteJobTest.php

Scenario: RunTestSuiteJob marks the run failed and re-throws on exception
  Given runStreaming() throws
  When RunTestSuiteJob::handle() runs
  Then markFailed() is called and the exception propagates
# pinned by: tests/Unit/Jobs/RunTestSuiteJobTest.php
```

## Tests & verification

- Unit: `tests/Unit/Jobs/GenerateAiTagsTest.php`,
  `tests/Unit/Jobs/ProcessDiscoveredAssetTest.php`,
  `tests/Unit/Jobs/VerifyAssetIntegrityTest.php`,
  `tests/Unit/Jobs/RegenerateResizedImageTest.php`,
  `tests/Unit/Jobs/RunTestSuiteJobTest.php` — each mocks its service dependencies
  and calls `handle()` directly (sync queue means this exercises real dispatch
  behaviour too).
- Run: `php artisan config:clear && php artisan test`

## Open questions / future

- None open for the five jobs' `handle()`/`failed()` contracts — every branch
  (happy path, missing row, skip conditions, retry-via-rethrow) has a pinned test.
  Dispatch **call sites** themselves (`DiscoverController`, `SystemController`, the
  `assets:verify-integrity` console command, `AssetProcessingService`) are covered
  indirectly through their own Feature specs/tests rather than here.
