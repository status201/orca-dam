# Chunked upload

```yaml
id: chunked-upload
status: implemented
version: 4
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
  - app/Services/AssetIngestionService.php
```

## Background / Why

Files at or above 10MB can't go through the single-request direct-upload path (see `CLAUDE.md`
"PHP for large files"). `ChunkedUploadService` drives an S3 Multipart Upload instead: the browser
PUTs the file one chunk per request. See [ADR-005](../decisions/adr-005-chunked-above-10mb.md)
for the threshold decision.

## Requirements

- **REQ-1** — The client picks the path; the server dictates the chunking. The
  browser uploader sends files at or above 10MB (`CHUNKED_THRESHOLD` in
  `resources/js/alpine/asset-uploader.js`) through this feature; the server doesn't enforce the
  threshold, so an API client may use either path within its limits. The part size is the
  server's alone (`ChunkedUploadService::CHUNK_SIZE`): `init` returns `chunk_size` and
  `total_chunks`, and the uploader keeps no chunk size of its own.
- **REQ-2** — Chunk uploads are idempotent, and the guarantee lives in
  `ChunkedUploadService::uploadChunk()`, not the controller: re-sending a recorded `chunk_number`
  skips the S3 `uploadPart` and returns the existing progress. Parts are merged by `PartNumber`
  under a row lock (`lockForUpdate()`), and `uploaded_chunks` is the count of distinct recorded
  parts — never a running `+ 1` — so parallel chunks can neither drop nor double-count a part.
- **REQ-3** — On completion, an etag dedup check runs exactly like the direct
  path (skipped when overwriting an existing `s3_key` in `keep_original_filename`
  mode) — see [`duplicate-detection.md`](duplicate-detection.md).
- **REQ-4** — SVGs are sanitized *after* assembly, on a staging key: the multipart for an SVG
  assembles at `chunked-staging/{session_token}.svg` (`upload_sessions.staging_s3_key`), never at
  the asset's key. Completion sanitizes it onto `s3_key` (`S3Service::sanitizeStoredSvg($staging,
  $s3Key)`) and deletes the staging object on success and on failure, so a failure never touches
  `s3_key` (an overwrite-mode asset keeps its original). Sanitizing changes the ETag, so it runs
  before the dedup check. Staging-prefix rules: [`s3-storage.md`](s3-storage.md)
  REQ-staging-prefix-hidden.
- **REQ-5** — A session is scoped to the user who created it
  (`user_id = Auth::id()`); the chunk/complete/abort endpoints answer **404** for a
  `session_token` that is unknown or belongs to another user — the same answer
  for both, so a token's existence is not disclosed.
- **REQ-6** — A failed completion attempts to abort the underlying multipart
  upload so S3 doesn't accumulate orphaned parts. The "not all chunks uploaded"
  precondition is checked before any S3 call and does **not** abort: the session
  stays resumable, so the client can send the missing chunks and complete again.
- **REQ-chunk-number-out-of-range** — A `chunk_number` outside
  `1..total_chunks` is a client error: the chunk endpoint answers **422** and
  sends nothing to S3.

## Technical design

### Contract / public interface

Routes (`routes/web.php`, prefix `api/chunked-upload`, `auth.multi:web,sanctum,jwt` +
`throttle:chunked-upload` — limits in [`upload-policy.md`](upload-policy.md) REQ-7):

```yaml
POST init:     ChunkedUploadController::initiate      # chunked-upload.init
POST chunk:    ChunkedUploadController::uploadChunk    # chunked-upload.chunk
POST complete: ChunkedUploadController::complete        # chunked-upload.complete
POST abort:    ChunkedUploadController::abort            # chunked-upload.abort
```

`ChunkedUploadService` holds no S3 client; every S3 call goes through `S3Service`
([`s3-storage.md`](s3-storage.md) REQ-sole-s3-gateway).

```yaml
initiateUpload(filename, fileSize, userId, ?folder, keepOriginalFilename): UploadSession
  # S3 key = UUID or sanitized original name; createMultipartUpload with Content-Type /
  # Content-Disposition from the extension (upload-policy REQ-content-type-from-extension, REQ-5);
  # mime_type = UploadPolicy::contentType(filename); chunk_size 10MB; total_chunks = ceil(size/chunk).
  # SVG: multipart opened on CHUNKED_STAGING_PREFIX.{token}.svg, recorded as staging_s3_key.
uploadChunk(UploadSession, UploadedFile, int $chunkNumber): array
  # InvalidArgumentException outside 1..total_chunks; already recorded → {PartNumber, ETag, skipped: true}
  # without S3. uploadPart runs OUTSIDE the transaction; the merge into part_etags runs under lockForUpdate().
completeUpload(UploadSession): Asset
  # requires uploaded_chunks === total_chunks; completeMultipartUpload (parts sorted); SVG sanitize from
  # staging (a legacy session without staging key sanitizes in place); dedup (DuplicateAssetException);
  # create/overwrite Asset (dimensions via S3Service::extractImageDimensions); session → completed
abortUpload(UploadSession): void      # abortMultipartUpload + status aborted
```

Every multipart call addresses `UploadSession::multipartKey()` (`staging_s3_key ?? s3_key`).

Controller error mapping: chunk/complete/abort resolve the session through one owner-scoped
lookup (`findOwnedSession()`) → **404** `Upload session not found.` (REQ-5); `InvalidArgumentException`
in `uploadChunk` → **422**; anything else → 500 via `Controller::clientError()`. A `skipped` result
is reported as `Chunk already uploaded` with the current progress.

Controller validation: `initiate` — `filename` (required, `AllowedUploadExtension`), `mime_type`
(optional and **ignored** — never stored or sent to S3), `file_size` (max 500MB), `folder` (max
100), `keep_original_filename`; non-web-guard callers are gated by the `api_upload_enabled`
setting. `complete` validates `metadata_*` through the shared `UploadMetadataRules::rules()`
([`asset-upload.md`](asset-upload.md) REQ-5), inline rather than via a `FormRequest` so
`authorize('create', Asset::class)` runs first, then runs `processImageAsset()` +
`applyUploadMetadata()` — identical post-processing to the direct path.

### Data shapes

```yaml
UploadSession:                # upload_sessions table
  upload_id: string            # S3 multipart UploadId
  session_token: uuid          # client-facing handle
  filename / mime_type / file_size:
  s3_key: string                # the asset's final key
  staging_s3_key: ?string       # SVG only: chunked-staging/{session_token}.svg
  chunk_size: int               # 10MB
  total_chunks / uploaded_chunks: int
  part_etags: json              # [{PartNumber, ETag}, ...]
  status: pending|uploading|completed|aborted|failed
  user_id: int
  last_activity_at: datetime
```

### Persistence

`upload_sessions` rows are the only persistent state of an in-flight upload; stale sessions are
aborted by `uploads:cleanup` ([`maintenance-commands.md`](maintenance-commands.md)).

## Scenarios (BDD)

```gherkin
Scenario: The browser uploader cuts chunks to the size init returns (REQ-1)
  Given a 10.5MB file staged on the upload page
  And the init response says chunk_size is 6MB
  When the upload is submitted
  Then the first chunk sent is 6MB, not the server's default 10MB, and the upload completes
# pinned by: tests/e2e/asset-upload.spec.js

Scenario: Initiating a chunked upload opens a session for the caller
  Given an authenticated editor
  When POST .../init is called for a 25MB "archive.zip"
  Then the response is 201 with a session_token, chunk_size 10MB and total_chunks 3
  And a pending upload_sessions row is stored for that user under a UUID key
  And the S3 multipart upload is opened with ContentDisposition attachment (non-inline type)
  And with keep_original_filename = true the s3_key ends in the sanitized original filename
# pinned by: tests/Feature/ChunkedUploadTest.php

Scenario: Uploading a chunk records the part; re-sending it is a no-op (REQ-2)
  Given a pending session with 2 total chunks
  When chunk 1 is uploaded
  Then part_etags holds {PartNumber 1, ETag}, uploaded_chunks is 1, status uploading
  And sending chunk 1 again reports "Chunk already uploaded" with no UploadPart sent to S3
# pinned by: tests/Feature/ChunkedUploadTest.php

Scenario: Chunks recorded through stale copies of a session are merged, not lost (REQ-2)
  Given two in-memory copies of one session, both loaded before any chunk arrived
  When chunk 1 is recorded through the first copy and chunks 2 and 1 through the second
  Then part_etags holds exactly parts 1 and 2, in order, and uploaded_chunks is 2
# pinned by: tests/Feature/ChunkedUploadTest.php

Scenario: A chunk number outside the session's range is a 422 (REQ-chunk-number-out-of-range)
  Given a session with 2 total chunks
  When chunk 3 is uploaded
  Then the response is 422 and nothing is sent to S3
# pinned by: tests/Feature/ChunkedUploadTest.php

Scenario: Another user's session token is a 404 on chunk, complete and abort (REQ-5)
  Given a session created by user A
  When user B calls .../chunk, .../complete or .../abort with A's session_token
  Then each response is 404 and A's session is untouched
# pinned by: tests/Feature/ChunkedUploadTest.php

Scenario: Aborting a session aborts the S3 multipart upload
  When POST .../abort is called for the caller's session
  Then AbortMultipartUpload is sent for the session's key and UploadId
  And the session is marked aborted
# pinned by: tests/Feature/ChunkedUploadTest.php

Scenario: A failed completion aborts the multipart upload (REQ-6)
  Given a session with every chunk uploaded
  And S3 rejects CompleteMultipartUpload
  When POST .../complete is called
  Then AbortMultipartUpload is sent, the session is marked aborted and the response is 500
# pinned by: tests/Feature/ChunkedUploadTest.php

Scenario: Completing a session with missing chunks leaves it resumable (REQ-6)
  Given a session with 1 of 2 chunks uploaded
  When POST .../complete is called
  Then the response is 500, nothing is sent to S3, and the session keeps its status
# pinned by: tests/Feature/ChunkedUploadTest.php

Scenario: Completing a chunked upload applies batch metadata under the direct-upload rules
  Given a completed session with metadata_tags/license/copyright/date_obtained (or none at all)
  When POST .../complete is called
  Then the asset carries the same metadata as the direct path would set
  And invalid metadata is rejected exactly as asset-upload.md specifies
# pinned by: tests/Feature/ChunkedUploadTest.php

Scenario: Completing a chunked upload that duplicates an existing asset returns 409
  Given a session whose assembled file's etag matches an existing asset
  When POST .../complete is called
  Then the response is 409 with the same duplicate payload shape as the direct-upload path
# pinned by: tests/Feature/ChunkedUploadTest.php, tests/Feature/DuplicatePayloadTest.php

Scenario: A chunked SVG assembles on a staging key and is sanitized onto the final key (REQ-4)
  When POST .../init is called for a 12MB "drawing.svg"
  Then the multipart is opened on chunked-staging/{session_token}.svg, recorded as staging_s3_key
  And on completion sanitizeStoredSvg() reads the staging key and writes the asset's key
  And the staging object is deleted and the asset carries the sanitized ETag
# pinned by: tests/Feature/ChunkedUploadTest.php

Scenario: A chunked SVG that fails sanitization is never stored (REQ-4)
  Given an SVG session whose content cannot be sanitized (e.g. a custom DTD entity)
  When completeUpload() runs
  Then the staging object is deleted, the asset's key is never written, the session is failed
  And completeUpload() throws, so no Asset row is created or updated
  And in overwrite mode the existing original and its Asset row are unchanged
# pinned by: tests/Feature/ChunkedUploadTest.php

Scenario: A session created before staging keys existed still sanitizes in place
  Given an SVG session with no staging_s3_key
  When completeUpload() runs and sanitization fails
  Then the multipart completes on s3_key, that object is deleted, and the session is marked failed
# pinned by: tests/Feature/ChunkedUploadTest.php

Scenario: Stale upload sessions are aborted by the cleanup command
  Given an upload_sessions row inactive past the cleanup threshold
  When uploads:cleanup runs
  Then ChunkedUploadService::abortUpload is called for that session
# pinned by: tests/Feature/Console/AssetMaintenanceCommandTest.php
```

## Tests & verification

- Feature: `tests/Feature/ChunkedUploadTest.php`, `tests/Feature/DuplicatePayloadTest.php`,
  `tests/Feature/Console/AssetMaintenanceCommandTest.php`
- Unit: `tests/Unit/Services/S3ServiceTest.php` (`sanitizeStoredSvg` to a destination key,
  staging keys hidden from discovery, multipart part ordering)
- E2E: `tests/e2e/asset-upload.spec.js` — a 10.5MB upload into RustFS (needs `npm run e2e:up`)
- Run: `php artisan config:clear && php artisan test`

## Open questions / future

- `complete` doesn't refuse an already-`completed` session: a retry after a lost response
  re-runs it and takes the overwrite branch against the asset it just created. A guard returning
  the existing asset would close it.
