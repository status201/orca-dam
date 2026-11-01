# Asset upload (direct)

```yaml
id: asset-upload
status: implemented
version: 8
owner: core
related:
  - architecture
  - asset-model
  - s3-storage
  - chunked-upload
  - duplicate-detection
  - image-processing
  - ai-tagging
  - upload-policy
  - tag-input
source:
  - app/Http/Controllers/AssetController.php
  - app/Http/Requests/StoreAssetRequest.php
  - app/Services/AssetProcessingService.php
  - app/Services/S3Service.php
  - app/Jobs/GenerateAiTags.php
  - app/Support/UploadMetadata.php
  - app/Services/AssetIngestionService.php
```

## Background / Why

`POST /assets` is the direct-upload path for files under the ~10MB threshold
([ADR-005](../decisions/adr-005-chunked-above-10mb.md); larger files use
[`chunked-upload.md`](chunked-upload.md)). It accepts multiple files per request, uploads each
to S3, creates (or, on a key collision, overwrites) an `Asset` row, generates a thumbnail +
S/M/L resizes, optionally dispatches AI tagging, and applies any batch metadata supplied with
the files.

## Requirements

- **REQ-1** — Upload is authorized via `AssetPolicy::create` before any file is
  processed.
- **REQ-2** — Every file is validated against the shared extension allowlist
  (`App\Rules\AllowedUploadExtension`, `config/uploads.php`) — see
  [`upload-policy.md`](upload-policy.md).
- **REQ-3** — A per-file etag dedup check runs before the `Asset` row is created,
  skipped only when the upload collides with an existing `s3_key`
  ([`duplicate-detection.md`](duplicate-detection.md) REQ-2). Duplicates are skipped and
  reported rather than failing the whole batch.
- **REQ-4** — Thumbnail + resize generation and AI-tag dispatch are delegated to
  `AssetProcessingService::processImageAsset()`, never done inline in the
  controller (REQ-1 of `architecture.md`).
- **REQ-5** — Batch metadata (`metadata_tags`, `metadata_license_type`,
  `metadata_copyright`, `metadata_copyright_source`, `metadata_reference_tag_ids`,
  `metadata_date_obtained`) is applied to every uploaded file in the batch via
  `AssetProcessingService::applyUploadMetadata()`. A field left blank is skipped,
  never written as an empty value.
- **REQ-6** — A batch with some failures and some successes still returns 2xx
  with per-file outcomes; only a batch where *every* file failed returns an
  error status.

## Technical design

### Contract / public interface

`AssetController::store(StoreAssetRequest $request)` — `POST /assets` (`assets.store`,
web-authenticated). Validation lives in `StoreAssetRequest::rules()`: `files.*` (size cap,
`AllowedUploadExtension`, `BoundedFilename` — [`input-validation.md`](input-validation.md)
REQ-13), `folder` (max 100 — keeps the folder `LIKE` range inside the index prefix,
[`input-validation.md`](input-validation.md) REQ-12), `keep_original_filename`, plus the shared
`UploadMetadataRules::rules()` (via the `HasUploadMetadataRules` trait), whose caps come from
`ColumnLimits` ([`input-validation.md`](input-validation.md) REQ-1). Chunked completion and the
tool endpoints use the same rules.

`AssetProcessingService::processImageAsset(Asset $asset, bool $dispatchAiTagging = true): void`
— no-ops for non-images; generates the thumbnail (`S3Service::generateThumbnail()`) and resizes
(`S3Service::generateResizedImages()`), and dispatches `GenerateAiTags::dispatch($asset)->afterResponse()`
when `$dispatchAiTagging` and `RekognitionService::isEnabled()`. Each step is independently
try/caught and logged — a thumbnail failure doesn't block resizes or AI dispatch.

`AssetProcessingService::applyUploadMetadata(Asset $asset, UploadMetadata $metadata): void` —
`App\Support\UploadMetadata` is a readonly value object (`tags`, `referenceTagIds`,
`licenseType`, `copyright`, `copyrightSource`, `dateObtained`, all nullable) built by
`UploadMetadata::fromRequest($request)` (shared by web upload, chunked completion and — via
`HasUploadMetadataRules::uploadMetadata()` — the tool endpoints). Non-empty license fields are
applied in one `update()`; `tags` go through `TagInputParser::parse()` ([`tag-input.md`](tag-input.md))
then `Tag::resolveUserTagIds()` + `syncTagsWithAttribution(..., 'user')`; reference tag ids are
synced with `attached_by = 'reference'` ([`tags.md`](tags.md)).

### Layer touchpoints & ordering

```
StoreAssetRequest validation → AssetPolicy::create
  → per file: S3Service::uploadFile() (folder + keepOriginalFilename)
      → AssetIngestionService::existingAtKey() → findDuplicate() unless the key collided
      → [collision] S3Service::deleteAssetFiles(keepOriginal: true), overwrite the row in place
      → AssetIngestionService::persist()
      → processImageAsset($asset) → applyUploadMetadata($asset, UploadMetadata::fromRequest($request))
  → aggregate response: uploaded assets + duplicates[] (409 payloads)
```

### Persistence

Writes: `assets` row (`user_id = Auth::id()`), `asset_tag` pivot rows, the original at
`assets/{folder}/{uuid}.{ext}` (or the sanitized original filename) plus derived keys. A skipped
duplicate writes nothing but a log line; its S3 object is deleted before the request returns.

## Scenarios (BDD)

```gherkin
Scenario: Batch metadata is applied to every uploaded file
  Given a POST /assets request with two files and metadata_tags/license/copyright/date_obtained
  When the upload completes
  Then both assets carry the same tags, license_type, copyright, copyright_source and date_obtained
# pinned by: tests/Feature/AssetTest.php

Scenario Outline: Upload rejects invalid metadata
  Given a POST /assets request with <field> set to <value>
  Then the request is rejected with a validation error
  Examples:
    | field                  | value                           |
    | metadata_license_type  | a key not in Asset::licenseTypes() |
    | metadata_copyright     | more than 500 characters        |
    | metadata_date_obtained | "not-a-date"                    |
# pinned by: tests/Feature/AssetTest.php

Scenario: Upload succeeds with no metadata fields at all
  Given a POST /assets request with only files, no metadata_* fields
  Then the asset is created without license/copyright/tags set
# pinned by: tests/Feature/AssetTest.php

Scenario: Blank metadata never overwrites stored values
  Given an asset already carrying a date_obtained
  When applyUploadMetadata runs with an empty-string dateObtained, or with every field null/empty
  Then the asset is left unchanged
# pinned by: tests/Unit/AssetProcessingServiceTest.php

Scenario: processImageAsset skips non-image assets
  Given a non-image asset (e.g. a PDF)
  When processImageAsset is called
  Then no thumbnail or resize keys are set, and no AI tagging is dispatched
# pinned by: tests/Unit/AssetProcessingServiceTest.php

Scenario: AI tagging is dispatched only when Rekognition is enabled
  Given an image asset and Rekognition enabled
  When processImageAsset runs
  Then GenerateAiTags is dispatched for that asset
# pinned by: tests/Unit/AssetProcessingServiceTest.php

Scenario: A thumbnail failure does not block resize generation
  Given generateThumbnail() throws
  When processImageAsset runs
  Then resize generation still proceeds and the asset's resize keys are set
# pinned by: tests/Unit/AssetProcessingServiceTest.php

# — browser-level (see e2e-testing.md for the harness; these skip without a bucket) —

Scenario: Uploading an image stores it in S3 and it renders in the grid
  Given the upload page and a RustFS bucket
  When a PNG is selected and uploaded
  Then the row reports "Uploaded"
  And the asset appears in the grid with a generated thumbnail that loads
# pinned by: tests/e2e/asset-upload.spec.js

Scenario: An upload lands in the folder selected on the upload page
  Given a folder chosen in the upload form
  When a PNG is uploaded
  Then the stored asset's folder matches the selection
# pinned by: tests/e2e/asset-upload.spec.js

Scenario: A disallowed file type is rejected in the browser
  Given a file whose extension is not on the allowlist (upload-policy.md)
  When it is selected for upload
  Then the upload is refused
# pinned by: tests/e2e/asset-upload.spec.js
```

## Tests & verification

- Feature: `tests/Feature/AssetTest.php` — `php artisan config:clear && php artisan test`
- Unit: `tests/Unit/AssetProcessingServiceTest.php`, `tests/Unit/Jobs/GenerateAiTagsTest.php`
- Duplicate handling and the `keep_original_filename` matrix: `tests/Feature/DuplicatePreventionTest.php`
  ([`duplicate-detection.md`](duplicate-detection.md)).
- E2E: `tests/e2e/asset-upload.spec.js` (needs `npm run e2e:up`).
