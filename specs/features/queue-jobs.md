# Queue Jobs

```yaml
id: queue-jobs
status: implemented
version: 2
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

ORCA offloads slow or unreliable work (AWS calls, S3 image processing, a full Pest run) to
the queue so a request never blocks on it. Jobs run **synchronously** in tests
(`QUEUE_CONNECTION=sync`, [ADR-008](../decisions/adr-008-sqlite-tests.md)) and under a
Supervisor-managed `queue:work` in production (`deploy/supervisor/orca-queue-worker.conf`,
`DEPLOYMENT.md`; never from the web UI). The overview is in
[`architecture.md`](../architecture.md); this spec owns the per-job contract.

## Requirements

- **REQ-1** — Every job is a **thin dispatch of an ID**, except `GenerateAiTags` (constructed
  with the `Asset`, dispatched `->afterResponse()` so it doesn't delay the response even on the
  sync driver). The ID-based jobs re-fetch the model in `handle()` and are a **silent no-op**
  (logged, not thrown) if the row is gone.
- **REQ-2** — A job that fails mid-way **re-throws** after logging, so `$tries` gets a chance,
  and `failed()` logs the permanent failure once retries are exhausted.
- **REQ-3** — `RunTestSuiteJob`'s contract (`tries=1`, `timeout=900`, `markFailed()` inline and
  in `failed()`) is owned by [`system-admin.md`](system-admin.md) REQ-4.
- **REQ-4** — `GenerateAiTags` skips non-images and animated GIFs rather than erroring, and
  swallows `RekognitionService::autoTagAsset()` exceptions entirely (logged, no rethrow, no
  retry). Why: a tagging failure must never block or retry-storm the upload flow.

## Technical design

### Contract / public interface

```yaml
GenerateAiTags(Asset $asset)
  ::dispatch($asset)->afterResponse()          # AssetProcessingService::processImageAsset (if dispatchAiTagging + Rekognition enabled)
  handle(RekognitionService): skip non-images/GIFs; swallow tagging exceptions

ProcessDiscoveredAsset(int $assetId)
  ::dispatch($assetId)                          # DiscoverController — one per imported asset
  timeout=300 tries=3
  handle(S3Service, RekognitionService, AssetProcessingService):
    extract dimensions -> processImageAsset(dispatchAiTagging: false) -> AI tag inline if enabled

VerifyAssetIntegrity(int $assetId)
  ::dispatch($assetId)                          # assets:verify-integrity — one per asset
  timeout=60 tries=2
  handle(S3Service): sets/clears s3_missing_at; forgets 'assets:missing_count' cache on change

RegenerateResizedImage(int $assetId)
  ::dispatch($assetId)                          # SystemController::regenerateResizedImages — one per image asset
  timeout=300 tries=3
  handle(S3Service): deleteResizedImages() then generateResizedImages(); skips non-images

RunTestSuiteJob(string $runId, string $suite='all', ?string $filter=null)
  ::dispatch(...)                               # SystemController::runTests — see system-admin.md
  tries=1 timeout=900
```

### Persistence

- No job-state table; each job re-derives state from the `Asset` row (`RunTestSuiteJob`: from
  `TestRunnerService`'s cache).
- `VerifyAssetIntegrity` forgets `assets:missing_count` whenever `s3_missing_at` transitions,
  so the System dashboard's missing-count card needn't recompute per request.

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
  Then dimensions are saved and processImageAsset() is called with AI dispatch disabled
  And when Rekognition is enabled, autoTagAsset() runs inline within the same job
# pinned by: tests/Unit/Jobs/ProcessDiscoveredAssetTest.php

Scenario: ProcessDiscoveredAsset is a no-op for a missing row and re-throws on failure
  Given an assetId that does not exist, or processImageAsset() that throws
  When ProcessDiscoveredAsset::handle() runs
  Then the first makes no service calls and throws nothing
  And the second propagates the exception so the job is retried
# pinned by: tests/Unit/Jobs/ProcessDiscoveredAssetTest.php

Scenario: VerifyAssetIntegrity tracks s3_missing_at transitions only
  Given an asset whose S3 object is gone, one that has reappeared, and one still missing
  When VerifyAssetIntegrity::handle() runs for each
  Then s3_missing_at is set, cleared, and left unchanged respectively
  And the missing-count cache is invalidated only on the first two
# pinned by: tests/Unit/Jobs/VerifyAssetIntegrityTest.php

Scenario: RegenerateResizedImage replaces resize variants for images only
  Given an image asset with existing resize_{s,m,l}_s3_key values, and a non-image asset
  When RegenerateResizedImage::handle() runs
  Then the image's old resizes are deleted, new ones generated and its keys updated
  And for the non-image neither delete nor generate is called
# pinned by: tests/Unit/Jobs/RegenerateResizedImageTest.php
```

## Tests & verification

- Unit: `tests/Unit/Jobs/GenerateAiTagsTest.php`, `tests/Unit/Jobs/ProcessDiscoveredAssetTest.php`,
  `tests/Unit/Jobs/VerifyAssetIntegrityTest.php`, `tests/Unit/Jobs/RegenerateResizedImageTest.php`,
  `tests/Unit/Jobs/RunTestSuiteJobTest.php` (scenarios in [`system-admin.md`](system-admin.md)).
- Run: `php artisan config:clear && php artisan test`
- Dispatch call sites are covered by their own feature specs' tests.
