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

- **REQ-1** — The client picks the path. The browser uploader sends files at or
  above 10MB (`CHUNKED_THRESHOLD` in `resources/js/alpine/asset-uploader.js`)
  through this feature and smaller ones through the direct path; the server does
  not enforce the threshold, so an API client may use either path within each
  path's own limits.
- **REQ-2** — Chunk uploads are idempotent, and the guarantee lives in
  `ChunkedUploadService::uploadChunk()`, not the controller: re-sending a
  `chunk_number` the session has already recorded skips the S3 `uploadPart` and
  returns the existing progress. Part bookkeeping is merged by `PartNumber` under
  a row lock (`lockForUpdate()`), and `uploaded_chunks` is the count of distinct
  recorded parts — never a running `+ 1` — so chunks sent in parallel by an API
  client can neither drop nor double-count a part.
- **REQ-3** — On completion, an etag dedup check runs exactly like the direct
  path (skipped when overwriting an existing `s3_key` in `keep_original_filename`
  mode) — see [`duplicate-detection.md`](duplicate-detection.md).
- **REQ-4** — SVGs assembled via chunked upload are sanitized *after*
  completion, since the direct-upload in-memory sanitization path isn't
  available for an S3-side multipart assembly. The multipart for an SVG never
  assembles at the asset's key: it assembles at a staging key,
  `chunked-staging/{session_token}.svg` (`upload_sessions.staging_s3_key`).
  Completion reads the staging object, sanitizes it, writes the sanitized
  content to `s3_key` (`S3Service::sanitizeStoredSvg($staging, $s3Key)`), and
  deletes the staging object — on success and on failure. A sanitization
  failure therefore never touches `s3_key`: in `keep_original_filename`
  overwrite mode the existing asset keeps its original and its row, and raw
  SVG markup never sits at an asset's public URL. Sanitizing re-puts the
  content, which changes the ETag, so it runs before the dedup check.
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

Routes (`routes/web.php`, `auth.multi:web,sanctum,jwt` + `throttle:chunked-upload` — 100/min per user,
its own counter per [`upload-policy.md`](upload-policy.md) REQ-7 — prefix
`api/chunked-upload`):

```yaml
POST init:     ChunkedUploadController::initiate      # chunked-upload.init
POST chunk:    ChunkedUploadController::uploadChunk    # chunked-upload.chunk
POST complete: ChunkedUploadController::complete        # chunked-upload.complete
POST abort:    ChunkedUploadController::abort            # chunked-upload.abort
```

`ChunkedUploadService` talks to S3 through a client from
`S3Service::makeClient()` — the same endpoint, path-style and timeout
configuration as `S3Service` ([`s3-storage.md`](s3-storage.md) REQ-7, REQ-8).

`ChunkedUploadService::initiateUpload(filename, mimeType, fileSize, userId, ?folder, keepOriginalFilename): UploadSession`
— computes the S3 key (UUID or sanitized original name), opens an S3
`createMultipartUpload` (with `ContentDisposition: attachment` for non-inline
types per `UploadPolicy::isInline()`), and persists an `UploadSession` row with
`chunk_size = 10MB` and `total_chunks = ceil(fileSize / chunkSize)`. For an SVG
the multipart is opened on `S3Service::CHUNKED_STAGING_PREFIX.{token}.svg`
instead, recorded as `staging_s3_key` (REQ-4). Every multipart call —
`uploadPart`, `completeMultipartUpload`, `abortMultipartUpload` — addresses
`UploadSession::multipartKey()` (`staging_s3_key ?? s3_key`), so a session
created before the column existed still completes and aborts on its own key.

`ChunkedUploadService::uploadChunk(UploadSession, UploadedFile, int $chunkNumber): array`
— throws `InvalidArgumentException` for a chunk number outside `1..total_chunks`.
If the part is already recorded it returns `{PartNumber, ETag, skipped: true}`
without calling S3 (REQ-2). Otherwise it streams the chunk via `uploadPart`
*outside* any transaction (holding a row lock through a 10MB PUT would serialize
every chunk; re-uploading a `PartNumber` is itself idempotent in S3), then, in a
`DB::transaction` holding `lockForUpdate()` on the session row, merges
`{PartNumber, ETag}` into `part_etags` keyed by `PartNumber` and sets
`uploaded_chunks = count(part_etags)`. The passed `$session` is refreshed from
the locked row.

`ChunkedUploadService::completeUpload(UploadSession): Asset` — requires
`uploaded_chunks === total_chunks`; sorts parts by `PartNumber` and calls
`completeMultipartUpload`; sanitizes SVGs from the staging key onto `s3_key`
and deletes the staging object (a legacy session with no staging key is
sanitized in place); runs the etag dedup check
(throws `DuplicateAssetException` on a match); creates or updates the `Asset`
row; marks the session `completed`.

`ChunkedUploadService::abortUpload(UploadSession): void` — S3
`abortMultipartUpload` + session status `aborted`.

Controller error mapping (chunk/complete/abort): all three resolve the session
through one owner-scoped lookup (`findOwnedSession()`), whose
`ModelNotFoundException` is **404** `Upload session not found.`
(REQ-5); in `uploadChunk`, `InvalidArgumentException` is **422**
(REQ-chunk-number-out-of-range); everything else stays a 500 through
`Controller::clientError()`. A `skipped` result from the service is reported as
`Chunk already uploaded` with the current progress.

Controller validation: `initiate` — `filename` (required, `AllowedUploadExtension`),
`mime_type`, `file_size` (max 500MB in bytes), `folder` (max 100),
`keep_original_filename`; also gated by `api_upload_enabled` setting for
non-web-guard callers. `complete` validates the same `metadata_*` shape as the
direct-upload path **through the shared `UploadMetadataRules::rules()`**, not a
copy of it (REQ-5 of [`asset-upload.md`](asset-upload.md); the duplicate is what
let the copyright cap drift past its column — [`input-validation.md`](input-validation.md)
REQ-4). It validates inline rather than via a `FormRequest` so that
`authorize('create', Asset::class)` still runs first. On success it
runs
`AssetProcessingService::processImageAsset()` +
`AssetProcessingService::applyUploadMetadata()` — identical post-processing to
the direct path.

### Data shapes

```yaml
UploadSession:                # upload_sessions table
  upload_id: string            # S3 multipart UploadId
  session_token: uuid          # client-facing handle
  filename / mime_type / file_size: 
  s3_key: string                # the asset's final key
  staging_s3_key: ?string       # SVG only: chunked-staging/{session_token}.svg, where the multipart assembles
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
itself — see [`maintenance-commands.md`](maintenance-commands.md).

## Scenarios (BDD)

```gherkin
Scenario: Initiating a chunked upload opens a session for the caller
  Given an authenticated editor
  When POST .../init is called for a 25MB "archive.zip"
  Then the response is 201 with a session_token, chunk_size 10MB and total_chunks 3
  And a pending upload_sessions row is stored for that user under a UUID key
  And the S3 multipart upload is opened with ContentDisposition attachment (non-inline type)
# pinned by: tests/Feature/ChunkedUploadTest.php

Scenario: keep_original_filename keeps the sanitized original name as the key
  When POST .../init is called with keep_original_filename = true
  Then the session's s3_key ends in the sanitized original filename, not a UUID
# pinned by: tests/Feature/ChunkedUploadTest.php

Scenario: Uploading a chunk records the part (REQ-2)
  Given a pending session with 2 total chunks
  When chunk 1 is uploaded
  Then part_etags holds {PartNumber 1, ETag} and uploaded_chunks is 1, status uploading
# pinned by: tests/Feature/ChunkedUploadTest.php

Scenario: Re-sending a recorded chunk is a no-op (REQ-2)
  Given a session that has already recorded chunk 1
  When chunk 1 is uploaded again
  Then the response reports "Chunk already uploaded" with the unchanged progress
  And no UploadPart is sent to S3
# pinned by: tests/Feature/ChunkedUploadTest.php

Scenario: Chunks recorded through stale copies of a session are merged, not lost (REQ-2, regression)
  Given two in-memory copies of one session, both loaded before any chunk arrived
    (what two parallel chunk requests see)
  When chunk 1 is recorded through the first copy and chunks 2 and 1 through the second
  Then part_etags holds exactly parts 1 and 2, in order, and uploaded_chunks is 2
# Regression: part_etags was appended to the request's in-memory copy and written back,
# and uploaded_chunks was `+ 1` from a stale value — a concurrent chunk lost its part,
# and a concurrent duplicate counted twice and could make an incomplete upload "complete".
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
  Then AbortMultipartUpload is sent for the session's key and UploadId
  And the session is marked aborted and the response is 500
# pinned by: tests/Feature/ChunkedUploadTest.php

Scenario: Completing a session with missing chunks leaves it resumable (REQ-6)
  Given a session with 1 of 2 chunks uploaded
  When POST .../complete is called
  Then the response is 500, nothing is sent to S3, and the session keeps its status
# pinned by: tests/Feature/ChunkedUploadTest.php

Scenario: Completing a chunked upload applies batch metadata
  Given a completed chunked-upload session with metadata_tags/license/copyright/date_obtained
  When POST .../complete is called
  Then the created asset carries the same tags, license_type, copyright, copyright_source, and date_obtained as the direct-upload path
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

Scenario: Complete rejects a metadata_date_obtained that is not a date
  Given metadata_date_obtained "not-a-date"
  Then the request is rejected with a validation error
# pinned by: tests/Feature/ChunkedUploadTest.php

Scenario: Completing a chunked upload that duplicates an existing asset returns 409
  Given a chunked-upload session whose assembled file's etag matches an existing asset
  When POST .../complete is called
  Then the response is 409 with the same duplicate payload shape as the direct-upload path
# pinned by: tests/Feature/ChunkedUploadTest.php, tests/Feature/DuplicatePayloadTest.php

Scenario: A chunked SVG assembles on a staging key, never the asset's key (REQ-4)
  When POST .../init is called for a 12MB "drawing.svg"
  Then the S3 multipart upload is opened on chunked-staging/{session_token}.svg
  And the session's s3_key is the asset's final key and staging_s3_key the staging key
# pinned by: tests/Feature/ChunkedUploadTest.php

Scenario: Completing a chunked SVG writes the sanitized content to the final key (REQ-4)
  Given an SVG session with every chunk uploaded
  When completeUpload() runs
  Then the multipart is completed on the staging key
  And sanitizeStoredSvg() reads the staging key and writes the asset's key
  And the staging object is deleted and the asset carries the sanitized ETag
# pinned by: tests/Feature/ChunkedUploadTest.php

Scenario: A chunked SVG that fails sanitization is deleted, not stored raw (regression)
  Given a chunked-upload session for an SVG whose assembled content cannot be sanitized
    (e.g. it declares a custom DTD entity, which svg-sanitize >= 1.0 rejects)
  When completeUpload() runs
  Then the staging object is deleted, the asset's key is never written, and the session is marked failed
  And completeUpload() throws, so no Asset row is created or updated
# Regression: sanitizeStoredSvg() returning null used to be ignored — the raw
# upload kept its original ETag and became an asset, breaking s3-storage REQ-4.
# pinned by: tests/Feature/ChunkedUploadTest.php

Scenario: An overwrite-mode SVG that fails sanitization keeps the existing original (REQ-4)
  Given an existing asset at assets/logo.svg
  And a keep_original_filename SVG session for the same key whose content cannot be sanitized
  When completeUpload() runs
  Then only the staging object is deleted — assets/logo.svg is neither deleted nor overwritten
  And the existing Asset row is unchanged
# Was: the multipart assembled straight onto assets/logo.svg, so the only way to avoid
# serving the raw upload was to delete it, leaving the asset without an original.
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
  staging keys hidden from discovery)
- Run: `php artisan config:clear && php artisan test`

## Open questions / future

- `complete` doesn't refuse a session that is already `completed`. A client that
  retries `complete` after a success whose response was lost runs it again: S3
  may accept re-completing the upload, and `completeUpload()` then takes the
  overwrite branch against the asset it just created (derived files are dropped
  and regenerated). Harmless but wasteful; a guard returning the existing asset
  for a `completed` session would close it.
- Two copies of the chunking numbers exist. `ChunkedUploadService` has an
  unused `shouldUseChunkedUpload()` restating the 10MB threshold the uploader
  actually applies, and the uploader slices files with its own `CHUNK_SIZE`
  and counts its own chunks instead of using the `chunk_size`/`total_chunks`
  that `init` returns — so changing the server's chunk size would make every
  browser upload fail at `complete` ("not all chunks uploaded").
