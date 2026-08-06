# Duplicate detection

```yaml
id: duplicate-detection
status: implemented
version: 2
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
  - app/Http/Controllers/Api/AssetApiController.php
  - app/Services/ChunkedUploadService.php
  - app/Http/Controllers/DiscoverController.php
  - app/Console/Commands/DeduplicateAssets.php
```

## Background / Why

ORCA dedups uploads by S3 **etag** rather than filename, so the same binary
content uploaded under a different name (or re-uploaded after being renamed) is
still recognized as the same asset. All three ingestion paths — direct upload,
chunked upload, and S3 discovery/import — perform the same etag check and
must present an identical duplicate payload shape to the frontend so a single
"Duplicates" results panel can render regardless of which path produced them.

Two limits on "the same check" are worth stating, because the wording used to
overclaim. The comparison is a string equality on whatever S3 returned, and an
etag is a **storage-representation** hash, not a content hash: a single
`PutObject` yields a plain MD5, while `completeMultipartUpload` yields a
composite `md5-of-md5s-N`. The same bytes therefore cannot match across the
10 MB direct/chunked threshold (see Open questions). And dedup is an
application-level invariant only — there is no `UNIQUE` on `assets.etag`, and
the check is open-coded at each call site rather than behind a model scope.

## Requirements

- **REQ-1** — Dedup matches against `Asset::withTrashed()->where('etag', ...)`,
  so a soft-deleted asset still counts as an existing duplicate.
- **REQ-2** — Dedup is skipped **only when the upload actually collides with an existing
  `s3_key`** — an intentional overwrite of an existing key is not a duplicate, and on such an
  overwrite the existing row already carries the incoming etag, so without the skip the upload
  would report as a duplicate of itself. The condition is the collision, **never the
  `keep_original_filename` flag**: the flag makes a collision *possible* (it reuses the name
  instead of minting a UUID) but does not mean one happened. This requirement previously named the
  flag, and `AssetController::store` implemented it literally, so ticking the box disabled dedup
  outright and identical bytes uploaded under a *different* name became a second asset — the exact
  case the Background paragraph promises is caught. `ChunkedUploadService` always gated on the
  collision; the two paths now agree.
- **REQ-3** — On a detected duplicate, the just-uploaded/just-assembled S3
  object is deleted so no orphaned object is left behind.
- **REQ-4** — `DuplicateAssetException::formatDuplicate()` is the single
  source of truth for the duplicate JSON payload shape, shared by the direct,
  chunked **and REST API** upload controllers. The API path additionally returns
  `existing_asset_url`, which pre-dates the shared payload and is not in it —
  and which `public_url` does not replace, since that stays non-null for a
  trashed asset where `existing_asset_url` is null. See
  [`rest-api.md`](rest-api.md).
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
  S3Service::uploadFile() → s3_key lookup → etag check (skipped iff the key collided)
    → [dup] → S3Service::deleteFile() → collect duplicates[] → continue loop
    → [key collided] → overwrite the existing row in place
  The s3_key lookup comes FIRST because its result is what decides whether the etag
  check applies (REQ-2). Ordering it after was the bug.
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

# — REQ-2, the keep_original_filename matrix. All four rows are pinned: the two that must
#   NOT report a duplicate are what stop the rule collapsing into "always dedup", which
#   would break the intentional overwrite.

Scenario: Keeping the original filename still blocks identical bytes under a different name
  Given an existing asset and an upload of the same bytes under a different filename
  When it is uploaded with keep_original_filename set
  Then the response is 409, the new S3 object is deleted, and no second asset exists
# pinned by: tests/Feature/DuplicatePreventionTest.php, tests/e2e/asset-upload.spec.js

Scenario: Keeping the original filename overwrites in place when the key collides
  Given an existing asset at the same s3_key and an upload of different bytes
  When it is uploaded with keep_original_filename set
  Then the existing row is updated in place and no duplicate is reported
# pinned by: tests/Feature/DuplicatePreventionTest.php

Scenario: Re-uploading identical bytes to the same key is an overwrite, not a self-duplicate
  Given an existing asset whose etag equals the uploaded file's
  When it is uploaded to that same s3_key with keep_original_filename set
  Then it overwrites that row rather than reporting it as a duplicate of itself
# pinned by: tests/Feature/DuplicatePreventionTest.php

Scenario: Keeping the original filename still creates a new asset for different bytes
  Given no existing asset shares the etag or the s3_key
  When it is uploaded with keep_original_filename set
  Then a second asset is created normally
# pinned by: tests/Feature/DuplicatePreventionTest.php

Scenario: The API duplicate payload carries the shared shape and its legacy url key
  Given an API upload whose etag matches an existing asset
  Then the payload contains every formatDuplicate() field plus existing_asset_url
# pinned by: tests/Feature/DuplicatePayloadTest.php

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
- E2E: `tests/e2e/asset-upload.spec.js` — re-uploading identical bytes through the browser, against real etags from the bucket, with and without `keep_original_filename`. This is the **only** place an etag is derived from the bytes rather than stipulated by a mock, so it is the only test that could ever have caught REQ-2's failure end to end. It needs MinIO (`npm run e2e:up`) and skips silently without it; CI provisions it.

## Open questions / future

- **An etag is a storage-representation hash, so dedup cannot cross the 10 MB threshold.**
  `PutObject` returns a plain MD5; `completeMultipartUpload` returns a composite
  `md5-of-md5s-N` that also depends on the part size. Identical bytes uploaded below and above
  `CHUNKED_THRESHOLD` therefore never match, and a chunked SVG is a third flavour again, because
  `sanitizeStoredSvg()` re-puts it and it lands with a single-part etag. Latent rather than live —
  the threshold is deterministic, so the same file always takes the same path — but it breaks if
  the threshold or `CHUNK_SIZE` ever changes, or if an object arrives via another tool. The real
  fix is a `content_hash` column (sha256 computed locally: `hash_file()` on the direct path,
  `hash_init`/`hash_update` across parts on the chunked one, since chunks do stream through the
  server), with dedup preferring it and falling back to etag. Deliberately **not** done here: the
  backfill would have to download every existing object from S3 to hash it, which is an
  operational job with real cost and deserves its own change.
- **`assets.etag` has no `UNIQUE` constraint and no model scope.** The invariant is enforced by
  four hand-copied queries (`AssetController`, `Api\AssetApiController`, `ChunkedUploadService`,
  `DiscoverController`); one of them being subtly wrong is exactly how REQ-2 shipped broken. An
  `Asset::scopeDuplicateOf()` would give the four one definition to share. A DB-level `UNIQUE` is
  *not* the answer — a null etag is legal, and a hard constraint would turn a duplicate upload
  into a driver error rather than the 409 with a payload that REQ-4 specifies.
- **`assets:deduplicate` uses a bare `Asset::where`**, so unlike upload-time dedup (REQ-1) it does
  not see trashed rows. The retroactive and the preventive definitions of "duplicate" therefore
  differ slightly, and the command will leave a live row that an upload would have refused.
- `DeduplicateAssets` (the `assets:deduplicate` maintenance command, listed in
  `CLAUDE.md`) soft-deletes *existing* duplicates found after the fact — a
  distinct, retroactive dedup pass rather than upload-time prevention. Its
  scenarios (dry-run reporting, `--force` soft-delete, skipping assets with
  reference tags, ignoring null etags) are pinned by
  `tests/Feature/DuplicatePreventionTest.php` but that command's contract
  arguably deserves its own recipe under `specs/recipes/` rather than living
  only in this feature spec's source list.
