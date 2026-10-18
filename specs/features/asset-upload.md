# Asset upload (direct)

```yaml
id: asset-upload
status: implemented
version: 7
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
- **REQ-3** — A per-file etag dedup check runs before the `Asset` row is created,
  skipped only when the upload collides with an existing `s3_key` (an overwrite is
  not a duplicate — [`duplicate-detection.md`](duplicate-detection.md) REQ-2, and
  **not** the `keep_original_filename` flag, which is what this used to say).
  Duplicates are skipped and reported rather than failing the whole batch.
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

`AssetController::store(StoreAssetRequest $request)` — `POST /assets`
(`assets.store`, web-authenticated). Validation
(`StoreAssetRequest::rules()`): `files.*` (required, file, max 500000KB,
`AllowedUploadExtension`, `BoundedFilename` — `max:512000` is *kilobytes* and
bounds the bytes, so the name needs its own cap; see
[`input-validation.md`](input-validation.md) REQ-13), `folder` (nullable string, max 100 — matching
`FolderController`'s creation cap; folder + filename both feed `s3_key`, now
`varchar(1024)`, so this is no longer an overflow guard but what keeps the folder
`LIKE` range inside the 255-character index prefix of
[`input-validation.md`](input-validation.md) REQ-12), `keep_original_filename`
(nullable bool), plus the
shared upload-metadata rules (`UploadMetadataRules::rules()`, reached through the
`HasUploadMetadataRules` trait — `metadata_tags.*` max `Tag::MAX_NAME_LENGTH`,
`metadata_license_type` in `Asset::licenseTypes()` keys,
`metadata_copyright`/`metadata_copyright_source` capped at
`ColumnLimits::for('assets', 'copyright')` / `…'copyright_source'` rather than a
literal — see [`input-validation.md`](input-validation.md) REQ-1,
`metadata_date_obtained` `nullable|date` — a DATE column has no character width, so
it carries no `ColumnLimits` cap and no `<x-char-counter>`).

`AssetProcessingService::processImageAsset(Asset $asset, bool $dispatchAiTagging = true): void`
— no-ops for non-images; generates thumbnail via
`S3Service::generateThumbnail()`, resizes via
`S3Service::generateResizedImages()`, dispatches `GenerateAiTags::dispatch($asset)->afterResponse()`
when `$dispatchAiTagging` and `RekognitionService::isEnabled()`. Each step is
independently try/caught and logged — a thumbnail failure doesn't block resize
generation or AI dispatch.

`AssetProcessingService::applyUploadMetadata(Asset $asset, UploadMetadata $metadata): void`
— `App\Support\UploadMetadata` is a small readonly value object (`tags`,
`referenceTagIds`, `licenseType`, `copyright`, `copyrightSource`, `dateObtained`, all
nullable) built by `UploadMetadata::fromRequest($request)` from the `metadata_*` fields
(the web upload, chunked completion, and — via `HasUploadMetadataRules::uploadMetadata()` —
the tool endpoints all share it). It replaced seven positional parameters, which is what had
forced `dateObtained` to be appended at the end rather than kept beside its neighbours.
The method applies non-null/non-empty license fields via one `update()`, parses
`tags` through `TagInputParser::parse()` (comma-splitting — see
[`tag-input.md`](tag-input.md)) then `Tag::resolveUserTagIds()` +
`syncTagsWithAttribution(..., 'user')`; reference tag ids are synced with
`attached_by = 'reference'`.

### Layer touchpoints & ordering

```
StoreAssetRequest validation
  → AssetPolicy::create authorization
  → per file: S3Service::uploadFile() (folder + keepOriginalFilename)
      → AssetIngestionService::existingAtKey() FIRST — it gates the next step [duplicate-detection.md]
      → AssetIngestionService::findDuplicate() (skipped only when that key collided)
      → s3_key collision handling (keepOriginalFilename overwrite branch:
        deletes old derived files via S3Service::deleteAssetFiles(keepOriginal: true),
        updates the existing Asset row in place instead of creating a new one)
      → AssetIngestionService::persist() (create, or overwrite the collided row in place)
      → AssetProcessingService::processImageAsset($asset)
      → AssetProcessingService::applyUploadMetadata($asset, UploadMetadata::fromRequest($request))
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

Scenario: A batch Date Obtained is applied to every uploaded file
  Given a POST /assets request with two files and metadata_date_obtained 2026-05-01
  When the upload completes
  Then both created assets carry date_obtained 2026-05-01
# pinned by: tests/Feature/AssetTest.php

Scenario: Upload rejects a metadata_date_obtained that is not a date
  Given a POST /assets request with metadata_date_obtained "not-a-date"
  Then the request is rejected with a validation error
# pinned by: tests/Feature/AssetTest.php

Scenario: A blank Date Obtained does not overwrite a stored one
  Given an asset already carrying a date_obtained
  When applyUploadMetadata runs with an empty-string dateObtained
  Then the stored date_obtained is unchanged
# pinned by: tests/Unit/AssetProcessingServiceTest.php

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

- Feature: `tests/Feature/AssetTest.php` (upload/store scenarios), run via
  `php artisan config:clear && php artisan test`
- Unit: `tests/Unit/AssetProcessingServiceTest.php`, `tests/Unit/Jobs/GenerateAiTagsTest.php`
- Duplicate handling is pinned separately — see [`duplicate-detection.md`](duplicate-detection.md).
- E2E: `tests/e2e/asset-upload.spec.js` — a real browser upload that round-trips bytes to the RustFS bucket and back as a thumbnail.

## Open questions / future

- ~~`applyUploadMetadata()` takes seven positional parameters.~~ **Resolved** — it takes an
  `UploadMetadata` value object, so a new field is one property, not a re-ordered signature.

- ~~The `keep_original_filename` overwrite branch has no dedicated test.~~ **Resolved** — all four
  combinations of the flag are now pinned in `tests/Feature/DuplicatePreventionTest.php`
  ([`duplicate-detection.md`](duplicate-detection.md) REQ-2). Worth recording *why* this mattered:
  the note said the overwrite branch was untested, and the branch that decided whether to *reach*
  it was untested too — `keep_original_filename` appeared nowhere under `tests/` at all. That is
  how a dedup check gated on the wrong condition shipped.
