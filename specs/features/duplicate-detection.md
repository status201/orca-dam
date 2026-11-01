# Duplicate detection

```yaml
id: duplicate-detection
status: implemented
version: 4
owner: core
related:
  - architecture
  - asset-model
  - asset-upload
  - chunked-upload
  - discovery-import
source:
  - app/Exceptions/DuplicateAssetException.php
  - app/Services/AssetIngestionService.php
  - app/Http/Controllers/AssetController.php
  - app/Http/Controllers/Api/AssetApiController.php
  - app/Services/ChunkedUploadService.php
  - app/Http/Controllers/DiscoverController.php
  - app/Console/Commands/DeduplicateAssets.php
```

## Background / Why

ORCA dedups uploads by S3 **etag** rather than filename, so the same bytes under a different
name are still recognised. Every ingestion path — direct, chunked, REST API and discovery — runs
the check through `AssetIngestionService` and returns one duplicate payload shape, so a single
"Duplicates" panel renders for all of them. The comparison is string equality on what S3
returned (a storage-representation hash, not a content hash), and dedup is an application-level
invariant: there is no `UNIQUE` on `assets.etag`, deliberately — a null etag is legal and a
constraint would turn the 409 payload of REQ-4 into a driver error.

## Requirements

- **REQ-1** — Dedup matches against `Asset::withTrashed()->where('etag', ...)`,
  so a soft-deleted asset still counts as an existing duplicate.
- **REQ-2** — Dedup is skipped **only when the upload actually collides with an existing
  `s3_key`** (an intentional overwrite; that row already carries the incoming etag and would
  otherwise match itself). The condition is the collision, **never the `keep_original_filename`
  flag** — the flag only makes a collision possible; identical bytes under a different name are
  still a duplicate.
- **REQ-3** — On a detected duplicate, the just-uploaded/just-assembled S3
  object is deleted so no orphaned object is left behind.
- **REQ-4** — `DuplicateAssetException::formatDuplicate()` is the single
  source of truth for the duplicate JSON payload shape, shared by the direct,
  chunked **and REST API** upload controllers. The API path additionally returns
  `existing_asset_url` (not in the shared payload; `public_url` does not replace it, since
  `existing_asset_url` is null for a trashed asset). See [`rest-api.md`](rest-api.md).
- **REQ-5** — `can_restore` in the payload reflects the *requesting user's*
  authorization (`Gate::allows('restore', $existing)`), so an API-role caller
  never sees `can_restore: true` for a trashed duplicate.

## Technical design

### Contract / public interface

`DuplicateAssetException` (extends `RuntimeException`) — carries the matched `existingAsset`;
thrown by `ChunkedUploadService::completeUpload()`. The direct path (`AssetController::store`)
does not throw it: it collects `duplicates[]` and continues, so other files in the batch proceed.

`DuplicateAssetException::formatDuplicate(Asset $existing, ?string $attemptedFilename = null): array`
— the shared payload builder.

`App\Services\AssetIngestionService` — the one place ingestion decides "duplicate, overwrite, or
new"; no path queries `etag` itself:

```yaml
existingAtKey(string $s3Key): ?Asset     # row (trashed or not) at this key — an overwrite target
findDuplicate(?string $etag, ?Asset $existingAtKey = null): ?Asset
  # null when $existingAtKey is given (REQ-2) or $etag is empty; else withTrashed()->duplicateOf($etag)
discardDuplicate(string $s3Key, Asset $existing, string $filename): void   # REQ-3, plus a log line
persist(array $attributes, ?Asset $overwrite = null): Asset
  # create, or overwrite in place: delete derived S3 objects + null their columns, restore
  # (deleted_at set directly — not fillable), clear s3_missing_at, last_modified_by = uploader
```

`Asset::scopeDuplicateOf($query, ?string $etag)` — etag match; a null/empty etag matches nothing.

### Data shapes

```yaml
# duplicate payload — identical from every upload path
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

Fully-duplicate direct batch: `{ message, duplicates: [...] }`, HTTP 409. Chunked completion:
`{ message, duplicates: [formatDuplicate(...)] }`, HTTP 409 — always exactly one entry.

### Layer touchpoints & ordering

```
Direct (AssetController::store):
  S3Service::uploadFile() → existingAtKey() FIRST (it decides REQ-2) → etag check unless collided
    → [dup] deleteFile() → collect duplicates[] → continue   [collided] overwrite row in place
Chunked (ChunkedUploadService::completeUpload):
  completeMultipartUpload → sanitizeStoredSvg (if SVG) → etag check → [dup] deleteFile() → throw
  ChunkedUploadController::complete catches → formatDuplicate() → 409
Discovery (DiscoverController::import):
  per key findDuplicate() → skip (not queued); the discovered object is never deleted
REST API (Api\AssetApiController::store):
  as direct; keys are always fresh UUIDs, so the etag check always applies
```

## Scenarios (BDD)

```gherkin
Scenario: An overwrite cleans up after the old bytes in one place
  Given a trashed asset flagged missing, with a thumbnail
  When AssetIngestionService::persist() overwrites it
  Then its derived S3 objects are deleted once, their columns are null, it is restored,
  And s3_missing_at is cleared and last_modified_by is the uploader
# pinned by: tests/Unit/Services/AssetIngestionServiceTest.php

Scenario: Direct and API uploads with a matching etag are blocked
  Given an existing asset with a known etag, live or soft-deleted
  When the same content is uploaded via POST /assets or the API
  Then it is reported as a duplicate and no new asset is created
  And a non-duplicate API upload records its own etag on the asset
# pinned by: tests/Feature/DuplicatePreventionTest.php

Scenario Outline: keep_original_filename never disables dedup (REQ-2)
  Given <existing>
  When the file is uploaded with keep_original_filename set
  Then <outcome>
  Examples:
    | existing                                   | outcome                                            |
    | same bytes under a different filename      | 409, the new S3 object is deleted, no second asset |
    | a row at the same s3_key, different bytes  | the row is updated in place, no duplicate reported |
    | a row at the same s3_key with the same etag| overwrite, not a duplicate of itself               |
    | no row sharing the etag or the s3_key      | a second asset is created normally                 |
# pinned by: tests/Feature/DuplicatePreventionTest.php, tests/e2e/asset-upload.spec.js

Scenario: The duplicate payload has one shape across paths
  Given a duplicate detected on the direct, chunked or API path
  Then it carries every formatDuplicate() field, with identical keys on direct and chunked
  And the API payload adds existing_asset_url
  And for a trashed duplicate show_url is null and is_trashed is true
  And an API-role caller sees can_restore false
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

# — browser-level (see e2e-testing.md for the harness; skips without a bucket) —

Scenario: Re-uploading identical bytes is reported as a duplicate, not stored twice
  Given a PNG that was already uploaded
  When the same bytes are uploaded again
  Then the row is flagged as a duplicate and the duplicates panel offers the existing asset
  And no second object is stored in the bucket
# pinned by: tests/e2e/asset-upload.spec.js
```

## Tests & verification

- Feature: `tests/Feature/DuplicatePreventionTest.php` (also `assets:deduplicate`: dry-run,
  `--force`, reference-tag skip, null etags), `tests/Feature/DuplicatePayloadTest.php`,
  `tests/Feature/DiscoverTest.php`, `tests/Feature/ChunkedUploadTest.php` —
  `php artisan config:clear && php artisan test`
- E2E: `tests/e2e/asset-upload.spec.js` — the only test whose etags come from real bytes
  (needs `npm run e2e:up`; skips without a bucket).

## Open questions / future

- An etag is not a content hash: `PutObject` gives a plain MD5, multipart a composite
  `md5-of-md5s-N` (a sanitized chunked SVG is re-put single-part), so identical bytes on either
  side of the 10 MB threshold never match. A content-hash column would close it.
- `assets:deduplicate` uses a bare `Asset::where`, so unlike REQ-1 it ignores trashed rows — the
  retroactive and preventive definitions of "duplicate" differ slightly.
