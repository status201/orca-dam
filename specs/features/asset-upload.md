# Asset upload (direct)

```yaml
id: asset-upload
status: implemented
version: 1
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
```

## Background / Why

`POST /assets` is the direct-upload path used for files under the ~10MB threshold
(see [ADR-005](../decisions/adr-005-chunked-above-10mb.md); files at/above it use
[`chunked-upload.md`](chunked-upload.md) instead). It accepts multiple files per
request, uploads each to S3, creates (or updates, for the keep-original-filename
overwrite case) an `Asset` row, generates a thumbnail + S/M/L resizes, optionally
dispatches AI tagging, and applies any batch metadata (tags/license/copyright)
supplied alongside the files.

## Requirements

- **REQ-1** — Upload is authorized via `AssetPolicy::create` before any file is
  processed.
- **REQ-2** — Every file is validated against the shared extension allowlist
  (`App\Rules\AllowedUploadExtension`, `config/uploads.php`) — see
  [`upload-policy.md`](upload-policy.md).
- **REQ-3** — A per-file etag dedup check runs before the `Asset` row is created;
  duplicates are skipped and reported rather than failing the whole batch (see
  [`duplicate-detection.md`](duplicate-detection.md)).
- **REQ-4** — Thumbnail + resize generation and AI-tag dispatch are delegated to
  `AssetProcessingService::processImageAsset()`, never done inline in the
  controller (REQ-1 of `architecture.md`).
- **REQ-5** — Batch metadata (`metadata_tags`, `metadata_license_type`,
  `metadata_copyright`, `metadata_copyright_source`, `metadata_reference_tag_ids`)
  is applied to every uploaded file in the batch via
  `AssetProcessingService::applyUploadMetadata()`.
- **REQ-6** — A batch with some failures and some successes still returns 2xx
  with per-file outcomes; only a batch where *every* file failed returns an
  error status.

## Technical design

### Contract / public interface

`AssetController::store(StoreAssetRequest $request)` — `POST /assets`
(`assets.store`, web-authenticated). Validation
(`StoreAssetRequest::rules()`): `files.*` (required, file, max 500000KB,
`AllowedUploadExtension`), `folder` (nullable string), `keep_original_filename`
(nullable bool), plus the shared upload-metadata rules
(`HasUploadMetadataRules` trait — `metadata_tags.*` max
`Tag::MAX_NAME_LENGTH`, `metadata_license_type` in `Asset::licenseTypes()` keys,
`metadata_copyright`/`metadata_copyright_source` max 500).

`AssetProcessingService::processImageAsset(Asset $asset, bool $dispatchAiTagging = true): void`
— no-ops for non-images; generates thumbnail via
`S3Service::generateThumbnail()`, resizes via
`S3Service::generateResizedImages()`, dispatches `GenerateAiTags::dispatch($asset)->afterResponse()`
when `$dispatchAiTagging` and `RekognitionService::isEnabled()`. Each step is
independently try/caught and logged — a thumbnail failure doesn't block resize
generation or AI dispatch.

`AssetProcessingService::applyUploadMetadata(Asset $asset, ?array $tagNames, ?string $licenseType, ?string $copyright, ?string $copyrightSource, ?array $referenceTagIds = null): void`
— applies non-null/non-empty license fields via one `update()`, parses
`$tagNames` through `TagInputParser::parse()` (comma-splitting — see
[`tag-input.md`](tag-input.md)) then `Tag::resolveUserTagIds()` +
`syncTagsWithAttribution(..., 'user')`; reference tag ids are synced with
`attached_by = 'reference'`.

### Layer touchpoints & ordering

```
StoreAssetRequest validation
  → AssetPolicy::create authorization
  → per file: S3Service::uploadFile() (folder + keepOriginalFilename)
      → etag dedup check (skipped when keepOriginalFilename)          [duplicate-detection.md]
      → s3_key collision handling (keepOriginalFilename overwrite branch:
        deletes old derived files via S3Service::deleteAssetFiles(keepOriginal: true),
        updates the existing Asset row in place instead of creating a new one)
      → Asset::create() (or update, for the overwrite branch)
      → AssetProcessingService::processImageAsset($asset)
      → AssetProcessingService::applyUploadMetadata($asset, ...)
  → aggregate response: uploaded assets + duplicates[] (409 payloads)
```

### Persistence

Writes: `assets` row (with `user_id = Auth::id()`), `asset_tag` pivot rows,
S3 objects at `assets/{folder}/{uuid}.{ext}` (or the sanitized original filename
when `keep_original_filename` is set) plus derived thumbnail/resize keys.
Nothing is written for a skipped duplicate other than a log line — the
just-uploaded S3 object is deleted by `S3Service::deleteFile()` before the
request returns.

## Scenarios (BDD)

```gherkin
Scenario: Batch metadata is applied to every uploaded file
  Given a POST /assets request with two files and metadata_tags/license/copyright
  When the upload completes
  Then both created assets carry the same tags, license_type, copyright, and copyright_source
# pinned by: tests/Feature/AssetTest.php

Scenario: Upload rejects an invalid metadata_license_type
  Given a POST /assets request with metadata_license_type not in Asset::licenseTypes()
  Then the request is rejected with a validation error
# pinned by: tests/Feature/AssetTest.php

Scenario: Upload rejects an over-length metadata_copyright
  Given metadata_copyright longer than 500 characters
  Then the request is rejected with a validation error
# pinned by: tests/Feature/AssetTest.php

Scenario: Upload succeeds with no metadata fields at all
  Given a POST /assets request with only files, no metadata_* fields
  Then the asset is created without license/copyright/tags set
# pinned by: tests/Feature/AssetTest.php

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

Scenario: applyUploadMetadata is a no-op when everything is null/empty
  Given null tags, license, copyright, and copyright_source
  When applyUploadMetadata runs
  Then the asset is left unchanged
# pinned by: tests/Unit/AssetProcessingServiceTest.php
```

## Tests & verification

- Feature: `tests/Feature/AssetTest.php` (upload/store scenarios), run via
  `php artisan config:clear && php artisan test`
- Unit: `tests/Unit/AssetProcessingServiceTest.php`, `tests/Unit/Jobs/GenerateAiTagsTest.php`
- Duplicate handling is pinned separately — see [`duplicate-detection.md`](duplicate-detection.md).

## Open questions / future

- The `keep_original_filename` overwrite branch in `AssetController::store`
  (s3_key collision → update existing asset in place, `deleted_at` reset) has no
  dedicated test in `tests/Feature/AssetTest.php` or elsewhere in the suite —
  only the chunked-upload equivalent path is indirectly covered. Worth adding a
  direct-upload regression test for this branch.
