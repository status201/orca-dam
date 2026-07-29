# Duplicate detection

```yaml
id: duplicate-detection
status: implemented
version: 1
owner: core
related:
  - architecture
  - asset-model
  - asset-upload
  - chunked-upload
  - discovery-import
source:
  - app/Exceptions/DuplicateAssetException.php
  - app/Http/Controllers/AssetController.php
  - app/Services/ChunkedUploadService.php
  - app/Http/Controllers/DiscoverController.php
  - app/Console/Commands/DeduplicateAssets.php
```

## Background / Why

ORCA dedups uploads by S3 **etag** rather than filename, so the same binary
content uploaded under a different name (or re-uploaded after being renamed) is
still recognized as the same asset. All three ingestion paths — direct upload,
chunked upload, and S3 discovery/import — perform the identical etag check and
must present an identical duplicate payload shape to the frontend so a single
"Duplicates" results panel can render regardless of which path produced them.

## Requirements

- **REQ-1** — Dedup matches against `Asset::withTrashed()->where('etag', ...)`,
  so a soft-deleted asset still counts as an existing duplicate.
- **REQ-2** — Dedup is skipped when `keep_original_filename` is set — an
  intentional overwrite of an existing key is not a duplicate.
- **REQ-3** — On a detected duplicate, the just-uploaded/just-assembled S3
  object is deleted so no orphaned object is left behind.
- **REQ-4** — `DuplicateAssetException::formatDuplicate()` is the single
  source of truth for the duplicate JSON payload shape, shared by the direct
  and chunked upload controllers.
- **REQ-5** — `can_restore` in the payload reflects the *requesting user's*
  authorization (`Gate::allows('restore', $existing)`), so an API-role caller
  never sees `can_restore: true` for a trashed duplicate.

## Technical design

### Contract / public interface

`DuplicateAssetException` (extends `RuntimeException`) — carries the matched
`existingAsset`; thrown by `ChunkedUploadService::completeUpload()` on a
completion-time match. The direct-upload path
(`AssetController::store`) does not throw it — it detects the match inline and
continues the loop, collecting `duplicates[]` instead, since a direct-upload
batch must let *other* files in the same request continue even if one is a
dup.

`DuplicateAssetException::formatDuplicate(Asset $existing, ?string $attemptedFilename = null): array`
— the shared payload builder (see Data shapes).

### Data shapes

```yaml
# duplicate payload — identical shape from direct and chunked paths
filename: string              # the ATTEMPTED upload's filename (not existing's)
existing_asset_id: int
existing_filename: string
existing_folder: string
mime_type: string
size: int
thumbnail_url: string|null
public_url: string
show_url: string|null         # null when existing is trashed
is_trashed: bool
can_restore: bool             # Gate::allows('restore', $existing) for the CURRENT user
uploaded_at: iso8601|null
```

Response envelope on a fully-duplicate direct-upload batch:
`{ message, duplicates: [...] }`, HTTP 409. Chunked-upload completion:
`{ message, duplicates: [formatDuplicate(...)] }`, HTTP 409 — always exactly
one entry (a chunked session represents a single file).

### Layer touchpoints & ordering

```
Direct (AssetController::store):
  S3Service::uploadFile() → etag check → [dup] → S3Service::deleteFile() → collect duplicates[] → continue loop
Chunked (ChunkedUploadService::completeUpload):
  completeMultipartUpload → sanitizeStoredSvg (if SVG) → etag check → [dup] → S3Service::deleteFile() → throw DuplicateAssetException
  ChunkedUploadController::complete catches it → formatDuplicate() → 409
Discovery (DiscoverController::import):
  per-key etag check against Asset::withTrashed() → skip (not queued) on match
```

## Scenarios (BDD)

```gherkin
Scenario: A direct web upload with a matching etag is blocked
  Given an existing asset with a known etag
  When a file with the same content is uploaded via POST /assets
  Then the upload is reported as a duplicate and no new asset is created
# pinned by: tests/Feature/DuplicatePreventionTest.php

Scenario: A direct web upload with a unique etag succeeds
  Given no existing asset shares the uploaded file's etag
  Then a new asset is created normally
# pinned by: tests/Feature/DuplicatePreventionTest.php

Scenario: A duplicate is still detected when the existing asset is trashed
  Given a soft-deleted asset with a known etag
  When a file with the same content is uploaded
  Then it is still reported as a duplicate (withTrashed() match)
# pinned by: tests/Feature/DuplicatePreventionTest.php

Scenario: An API upload with a matching etag is blocked and the etag is recorded
  Given an existing asset's etag
  When the same content is uploaded via the API
  Then the upload is blocked as a duplicate
  And a successful non-duplicate API upload records its own etag on the asset
# pinned by: tests/Feature/DuplicatePreventionTest.php

Scenario: The direct-upload duplicate payload carries the enriched panel fields
  Given a duplicate detected on direct upload
  Then the response includes existing_filename, existing_folder, thumbnail_url, public_url, show_url, is_trashed, can_restore, uploaded_at
# pinned by: tests/Feature/DuplicatePayloadTest.php

Scenario: The chunked-upload duplicate payload is identical in shape to the direct one
  Given the same underlying duplicate on both upload paths
  Then formatDuplicate() produces the same set of keys for both
# pinned by: tests/Feature/DuplicatePayloadTest.php

Scenario: A trashed duplicate's payload nulls show_url
  Given the existing duplicate asset is soft-deleted
  Then show_url is null and is_trashed is true
# pinned by: tests/Feature/DuplicatePayloadTest.php

Scenario: An API-role user never sees can_restore true for a trashed duplicate
  Given an authenticated API-role token and a trashed duplicate
  Then can_restore is false in the payload
# pinned by: tests/Feature/DuplicatePayloadTest.php

Scenario: DuplicateAssetException exposes the matched asset
  Given a DuplicateAssetException constructed with an existing asset
  Then existingAsset resolves to that asset
# pinned by: tests/Feature/DuplicatePreventionTest.php

Scenario: Discovery import skips a duplicate etag without queuing a job
  Given an unmapped S3 object whose etag matches an existing asset
  When it is imported via the discovery flow
  Then it is skipped, not queued for ProcessDiscoveredAsset
# pinned by: tests/Feature/DiscoverTest.php

# — browser-level (see e2e-testing.md for the harness; skips without MinIO) —

Scenario: Re-uploading identical bytes is reported as a duplicate, not stored twice
  Given a PNG that was already uploaded
  When the same bytes are uploaded again
  Then the row is flagged as a duplicate and the duplicates panel offers the existing asset
  And no second object is stored in the bucket
# pinned by: tests/e2e/asset-upload.spec.js
```

## Tests & verification

- Feature: `tests/Feature/DuplicatePreventionTest.php`, `tests/Feature/DuplicatePayloadTest.php`,
  `tests/Feature/DiscoverTest.php`, `tests/Feature/ChunkedUploadTest.php`
- Run: `php artisan config:clear && php artisan test`
- E2E: `tests/e2e/asset-upload.spec.js` — re-uploading identical bytes through the browser, against real etags from the bucket.

## Open questions / future

- `DeduplicateAssets` (the `assets:deduplicate` maintenance command, listed in
  `CLAUDE.md`) soft-deletes *existing* duplicates found after the fact — a
  distinct, retroactive dedup pass rather than upload-time prevention. Its
  scenarios (dry-run reporting, `--force` soft-delete, skipping assets with
  reference tags, ignoring null etags) are pinned by
  `tests/Feature/DuplicatePreventionTest.php` but that command's contract
  arguably deserves its own recipe under `specs/recipes/` rather than living
  only in this feature spec's source list.
