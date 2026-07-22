# Chunked upload

```yaml
id: chunked-upload
status: implemented
version: 1
owner: core
related:
  - architecture
  - asset-model
  - asset-upload
  - s3-storage
  - duplicate-detection
  - upload-policy
source:
  - app/Services/ChunkedUploadService.php
  - app/Http/Controllers/ChunkedUploadController.php
  - app/Models/UploadSession.php
  - routes/web.php
```

## Background / Why

Files at or above 10MB can't go through the single-request direct-upload path
(PHP's `upload_max_filesize`/`post_max_size` in chunked mode caps at 15MB/16MB —
see `CLAUDE.md` "PHP for large files"). `ChunkedUploadService` drives an S3
Multipart Upload instead: the browser splits the file into 10MB chunks and
PUTs them one at a time, so no single HTTP request ever carries more than one
chunk. See [ADR-005](../decisions/adr-005-chunked-above-10mb.md) for the
10MB/direct-vs-chunked threshold decision.

## Requirements

- **REQ-1** — `ChunkedUploadService::shouldUseChunkedUpload()` is the single
  source of truth for the 10MB threshold; the frontend uses it to pick a path.
- **REQ-2** — Chunk uploads are idempotent: re-uploading an already-received
  `chunk_number` for a session is a no-op that returns the existing progress,
  not a duplicate part.
- **REQ-3** — On completion, an etag dedup check runs exactly like the direct
  path (skipped when overwriting an existing `s3_key` in `keep_original_filename`
  mode) — see [`duplicate-detection.md`](duplicate-detection.md).
- **REQ-4** — SVGs assembled via chunked upload are sanitized *after*
  completion (`S3Service::sanitizeStoredSvg()`), since the direct-upload
  in-memory sanitization path isn't available for an S3-side multipart
  assembly. This re-upload changes the ETag, so sanitization must run before
  the dedup check.
- **REQ-5** — A session is scoped to the user who created it
  (`user_id = Auth::id()`); the chunk/complete/abort endpoints reject a
  `session_token` that doesn't belong to the requesting user via `firstOrFail()`.
- **REQ-6** — A failed completion attempts to abort the underlying multipart
  upload so S3 doesn't accumulate orphaned parts.

## Technical design

### Contract / public interface

Routes (`routes/web.php`, `auth.multi:web,sanctum,jwt` + `throttle:100,1`,
prefix `api/chunked-upload`):

```yaml
POST init:     ChunkedUploadController::initiate      # chunked-upload.init
POST chunk:    ChunkedUploadController::uploadChunk    # chunked-upload.chunk
POST complete: ChunkedUploadController::complete        # chunked-upload.complete
POST abort:    ChunkedUploadController::abort            # chunked-upload.abort
```

`ChunkedUploadService::initiateUpload(filename, mimeType, fileSize, userId, ?folder, keepOriginalFilename): UploadSession`
— computes the S3 key (UUID or sanitized original name), opens an S3
`createMultipartUpload` (with `ContentDisposition: attachment` for non-inline
types per `UploadPolicy::isInline()`), and persists an `UploadSession` row with
`chunk_size = 10MB` and `total_chunks = ceil(fileSize / chunkSize)`.

`ChunkedUploadService::uploadChunk(UploadSession, UploadedFile, int $chunkNumber): array`
— streams the chunk via `uploadPart`, appends `{PartNumber, ETag}` to
`part_etags`, increments `uploaded_chunks`. Throws `InvalidArgumentException`
for a chunk number outside `1..total_chunks`.

`ChunkedUploadService::completeUpload(UploadSession): Asset` — requires
`uploaded_chunks === total_chunks`; sorts parts by `PartNumber` and calls
`completeMultipartUpload`; sanitizes stored SVGs; runs the etag dedup check
(throws `DuplicateAssetException` on a match); creates or updates the `Asset`
row; marks the session `completed`.

`ChunkedUploadService::abortUpload(UploadSession): void` — S3
`abortMultipartUpload` + session status `aborted`.

Controller validation: `initiate` — `filename` (required, `AllowedUploadExtension`),
`mime_type`, `file_size` (max 500MB in bytes), `folder`, `keep_original_filename`;
also gated by `api_upload_enabled` setting for non-web-guard callers. `complete`
validates the same `metadata_*` shape as the direct-upload path (REQ-5 of
[`asset-upload.md`](asset-upload.md)) and, on success, runs
`AssetProcessingService::processImageAsset()` +
`AssetProcessingService::applyUploadMetadata()` — identical post-processing to
the direct path.

### Data shapes

```yaml
UploadSession:                # upload_sessions table
  upload_id: string            # S3 multipart UploadId
  session_token: uuid          # client-facing handle
  filename / mime_type / file_size: 
  s3_key: string
  chunk_size: int               # 10MB
  total_chunks / uploaded_chunks: int
  part_etags: json              # [{PartNumber, ETag}, ...]
  status: pending|uploading|completed|aborted|failed
  user_id: int
  last_activity_at: datetime
```

### Persistence

`upload_sessions` rows are the only persistent state during an in-flight
upload; stale sessions are cleaned up by `uploads:cleanup` (aborts the S3
multipart upload via `ChunkedUploadService`) rather than by this feature
itself — see `CLAUDE.md`'s maintenance commands.

## Scenarios (BDD)

```gherkin
Scenario: Completing a chunked upload applies batch metadata
  Given a completed chunked-upload session with metadata_tags/license/copyright
  When POST .../complete is called
  Then the created asset carries the same tags, license_type, copyright, and copyright_source as the direct-upload path
# pinned by: tests/Feature/ChunkedUploadTest.php

Scenario: Completing a chunked upload works without any metadata fields
  Given a completed session with no metadata_* fields
  Then the asset is created without license/copyright/tags set
# pinned by: tests/Feature/ChunkedUploadTest.php

Scenario: Complete rejects an invalid metadata_license_type
  Given metadata_license_type not in Asset::licenseTypes()
  Then the request is rejected with a validation error
# pinned by: tests/Feature/ChunkedUploadTest.php

Scenario: Complete rejects an over-length metadata_copyright
  Given metadata_copyright longer than 500 characters
  Then the request is rejected with a validation error
# pinned by: tests/Feature/ChunkedUploadTest.php

Scenario: Completing a chunked upload that duplicates an existing asset returns 409
  Given a chunked-upload session whose assembled file's etag matches an existing asset
  When POST .../complete is called
  Then the response is 409 with the same duplicate payload shape as the direct-upload path
# pinned by: tests/Feature/ChunkedUploadTest.php, tests/Feature/DuplicatePayloadTest.php

Scenario: Stale upload sessions are aborted by the cleanup command
  Given an upload_sessions row inactive past the cleanup threshold
  When uploads:cleanup runs
  Then ChunkedUploadService::abortUpload is called for that session
# pinned by: tests/Feature/Console/AssetMaintenanceCommandTest.php
```

## Tests & verification

- Feature: `tests/Feature/ChunkedUploadTest.php`, `tests/Feature/DuplicatePayloadTest.php`,
  `tests/Feature/Console/AssetMaintenanceCommandTest.php`
- Run: `php artisan config:clear && php artisan test`

## Open questions / future

- There is no dedicated test suite exercising `ChunkedUploadController::initiate`,
  `uploadChunk`, or `abort` directly (session creation, the idempotent-retry
  branch in `uploadChunk` — REQ-2 — or `abort`'s success path). The only
  chunked-upload coverage in `tests/Feature/ChunkedUploadTest.php` targets the
  `complete` endpoint. Session-lifecycle and idempotency scenarios above (REQ-2,
  REQ-6) are documented from source but currently unpinned — a gap worth
  closing with dedicated tests.
