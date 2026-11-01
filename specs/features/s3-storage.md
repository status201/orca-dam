# S3 storage

```yaml
id: s3-storage
status: implemented
version: 8
owner: core
related:
  - architecture
  - e2e-testing
  - asset-model
  - image-processing
  - asset-upload
  - chunked-upload
  - s3-integrity
  - discovery-import
  - bulk-operations
  - cloudflare-purge
  - folder-management
source:
  - app/Services/S3Service.php
  - app/Support/UploadPolicy.php
  - config/filesystems.php
  - app/Support/AssetKeyLayout.php
```

## Background / Why

`S3Service` is the sole gateway to AWS S3 (REQ-1 of `architecture.md`, REQ-sole-s3-gateway). It
owns the S3 key layout, the security hardening applied at upload time (extension-decided
Content-Type, forced download for non-inline types, SVG sanitization), and all derived-asset
(thumbnail/resize) key generation and cleanup.

## Requirements

- **REQ-1** — S3 keys follow `assets/{folder}/{uuid}.{ext}` for originals (or
  the sanitized original filename when `keep_original_filename` is used), and
  `thumbnails/{folder}/{name}_thumb.{ext}` / `thumbnails/{S,M,L}/{folder}/{name}.{ext}`
  for derived variants. A generated thumbnail keeps the original's extension even
  though its bytes are always JPEG (`abc.png` → `abc_thumb.png`, served as
  `image/jpeg`); a browser-generated one for a video/PDF is always `{name}_thumb.jpg`.
  A stored key's *name* never changes after it is written (REQ-6); `s3_key` is immutable —
  [ADR-006](../decisions/adr-006-immutable-s3-key.md).
- **REQ-2** — Every upload is streamed to S3 (not buffered fully in memory)
  except SVGs, which are small enough to sanitize in-memory before storage.
- **REQ-3** — Content-Type is decided by the object's allowlisted extension
  (`UploadPolicy::contentType()` — [`upload-policy.md`](upload-policy.md)
  REQ-content-type-from-extension), on `putUploadedFile()` and
  `createMultipartUpload()` alike: never the client-declared value, and never
  sniffed from the bytes, which the uploader also controls. Non-inline types get
  `Content-Disposition: attachment` (`App\Support\UploadPolicy::isInline()`).
- **REQ-4** — SVGs are sanitized (`enshrined/svg-sanitize`) before storage on
  every upload path; a sanitization failure throws rather than storing
  unsanitized content.
- **REQ-sole-s3-gateway** — `S3Service` is the only class in `app/` that uses the AWS S3 SDK, so
  REQ-7's endpoint and REQ-8's timeouts govern every S3 request — including the multipart upload
  [`chunked-upload.md`](chunked-upload.md) drives through `createMultipartUpload()` /
  `uploadPart()` / `completeMultipartUpload()` / `abortMultipartUpload()`. An architecture test
  enforces it. Why: a second client silently skips both and sends uploads to real AWS on an
  S3-compatible deployment.
- **REQ-staging-prefix-hidden** — `chunked-staging/` at the bucket root
  (`S3Service::CHUNKED_STAGING_PREFIX`) is ORCA's own transient prefix, where a chunked SVG
  assembles before it is sanitized onto its asset key ([`chunked-upload.md`](chunked-upload.md)
  REQ-4). Like `thumbnails/` it is reserved: `findUnmappedObjects()` never reports a key under it
  and `listFolders()` never lists it, so a leftover staging object can't be imported or offered
  as a folder.
- **REQ-5** — `getPublicBaseUrl()` resolves to the `custom_domain` setting when
  set, otherwise the configured S3 bucket URL — this is the single point every
  computed asset URL passes through, so a custom-domain change or Cloudflare
  cutover needs no data migration.
- **REQ-6** — Derived keys are named in one place, `App\Support\AssetKeyLayout`, used by every
  writer (`generateThumbnail()`, `generateResizedImages()`, `uploadThumbnail()`). Moving an asset
  (bulk move) relocates each derived key that exists by swapping only its folder segment
  (`relocatedDerivedKeys()`), keeping its stored name — never recomputing it (a PNG's thumbnail
  stays `abc_thumb.png`). A derived key not under the folder its original implies (legacy
  layout) is left where it is.
- **REQ-7** — The `S3Client` is built from the `s3` disk config *including*
  `endpoint` and `use_path_style_endpoint` when set, so the service can target any S3-compatible
  endpoint (the E2E RustFS bucket — [`e2e-testing.md`](e2e-testing.md) REQ-2 — or R2/Wasabi).
  When `endpoint` is unset the client uses region + credentials only (plain AWS).
- **REQ-8** — The `S3Client` carries an explicit `connect_timeout` and `timeout`
  (`AWS_CONNECT_TIMEOUT`, default 5s; `AWS_REQUEST_TIMEOUT`, default 120s; both from the `s3` disk
  config). Why: the SDK default waits indefinitely, so a half-open endpoint would hold a worker
  until `max_execution_time` instead of letting the service log and return `null`
  ([ADR-010](../decisions/adr-010-services-swallow-controllers-map.md)).

## Technical design

### Contract / public interface

```yaml
uploadFile(UploadedFile, ?directory, keepOriginalFilename=false): array   # {s3_key, filename, mime_type, size, etag, width, height}
replaceFile(UploadedFile, existingS3Key): array                          # same shape minus s3_key (key unchanged)
sanitizeSvg(string $svg): string
sanitizeStoredSvg(s3Key, ?destinationKey): ?string  # download → sanitize → put to destinationKey ?? s3Key; new ETag or null
generateThumbnail(s3Key): ?string           # 300x300 JPEG; null for EPS/SVG/animated GIF
generateResizedImages(s3Key): array         # {s,m,l} => key; skips non-image ext, animated GIF
uploadThumbnail(s3Key, imageData): ?string  # browser-generated thumb (video/PDF)
deleteResizedImages(Asset): void
deleteAssetFiles(Asset, keepOriginal=false): void   # original + thumbnail + resizes
relocatedDerivedKeys(Asset, newS3Key): array # column => new key, per derived key the asset holds (REQ-6)
moveObject(sourceKey, destinationKey): bool # copy + delete
connectionError(): ?string                  # null when the bucket lists, else the error — does not swallow (system-admin.md)
deleteFile(s3Key): bool
extractImageDimensions(s3Key, mimeType): ?array   # null for non-images; via ImageProcessingService::getImageDimensionsFromPath()

# Multipart — chunked-upload.md's only way to S3. These THROW (ADR-010's exception):
# the caller orchestrates abort/rollback and must know which step failed.
createMultipartUpload(key): string                       # UploadId; headers from the key's extension (REQ-3)
uploadPart(key, uploadId, partNumber, path): string       # part ETag; streams the local file
completeMultipartUpload(key, uploadId, parts): ?string    # parts sorted by PartNumber here; object ETag
abortMultipartUpload(key, uploadId): void

listObjects(prefix='', ?maxKeys): array      # maxKeys → one bounded request, no pagination
findUnmappedObjects(?prefix): array         # objects not in assets (discovery); skips thumbnails/, chunked-staging/
listFolders(?prefix): array
createFolder(folderPath): bool
getObjectMetadata(s3Key): ?array            # {size, mime_type, last_modified, etag}
getObjectHeaders(s3Keys, concurrency=25): array   # key => {content_type, content_disposition} | null; concurrent HEADs
repairUploadHeaders(s3Key): ?string         # copy onto itself with today's upload headers (REPLACE); new ETag or null
getObjectContent(s3Key): ?string
getPublicBaseUrl(): string                  # static — custom_domain setting > AWS_URL (REQ-5)
getUrl(s3Key): string
getRootFolder() / getRootPrefix(): static helpers over Setting (folder list: folder-management.md)
sanitizeFilename(filename): string          # static
ensureExtension(filename, ext, fallback): string   # static
```

### Data shapes

```yaml
# uploadFile()/replaceFile() return shape
s3_key: string        # only in uploadFile()
filename: string       # client original name
mime_type: string      # UploadPolicy::contentType(key), never sniffed
size: int
etag: string           # surrounding quotes stripped
width / height: int|null
```

### Layer touchpoints & ordering

`App\Support\AssetKeyLayout` (pure; constructed with `S3Service::getRootPrefix()`) strips the
root prefix from `s3_key` to get the relative folder, then re-applies it under `thumbnails/` or
`thumbnails/{S,M,L}/`:

```yaml
App\Support\AssetKeyLayout:
  relativeFolder(s3Key): string                 # '' or 'sub/folder/'
  thumbnailKey(s3Key): string                   # thumbnails/{rel}{name}_thumb.{ext}  (generated)
  uploadedThumbnailKey(s3Key): string           # thumbnails/{rel}{name}_thumb.jpg    (browser-generated)
  resizeKey(s3Key, size, extension): string     # thumbnails/{SIZE}/{rel}{filename}.{extension}
  resizeOutputExtension(sourceExtension): string # static — png/webp/jpg/jpeg keep theirs; else 'jpg'
  relocate(derivedKey, ?size, oldS3Key, newS3Key): ?string  # size null = thumbnail; null when not in the layout
```

Resize dimensions come from `Setting::get('resize_{s,m,l}_width|height', ...)`, read live.

### Persistence

S3 key layout (see also `architecture.md` → "S3 + CDN topology"):

```
assets/{folder}/{uuid}.{ext}                  # original
thumbnails/{folder}/{uuid}_thumb.{ext}         # 300x300 JPEG bytes; .jpg for a browser-generated thumb
thumbnails/{S|M|L}/{folder}/{basename}.{ext}   # resize variants
chunked-staging/{session_token}.svg            # transient: chunked SVG before sanitization
```

Nothing is cached at this layer beyond `Setting`'s own cache; every S3 call is live.

## Scenarios (BDD)

```gherkin
Scenario: getRootFolder trims whitespace and slashes
  Given s3_root_folder = " /assets/ "
  Then getRootFolder() returns "assets"
# pinned by: tests/Unit/S3ServiceTest.php

Scenario: getPublicBaseUrl prefers custom_domain, else the bucket URL
  Given custom_domain is set
  Then getPublicBaseUrl() returns that domain, trailing slash stripped
  And with custom_domain empty it returns config('filesystems.disks.s3.url')
# pinned by: tests/Unit/S3ServiceTest.php

Scenario: sanitizeSvg strips scripts and handlers and keeps benign markup
  Given an SVG containing a <script> tag and an onload handler
  Then sanitizeSvg() output contains neither
  And a clean SVG with only paths/shapes comes back equivalent
# pinned by: tests/Unit/Services/S3ServiceTest.php

Scenario: sanitizeSvg throws for an SVG that declares a custom DTD entity
  Given an SVG whose internal DTD declares an entity it then references
  When sanitizeSvg() runs
  Then it throws instead of returning markup (REQ-4)
# pinned by: tests/Unit/Services/S3ServiceTest.php

Scenario: sanitizeStoredSvg can write the sanitized content to a different key
  Given an SVG stored at a source key
  When sanitizeStoredSvg(source, destination) runs
  Then the sanitized markup is put to the destination and its ETag is returned
# pinned by: tests/Unit/Services/S3ServiceTest.php

Scenario: findUnmappedObjects and listFolders skip reserved and mapped keys
  Given objects that are already mapped, under thumbnails/ or chunked-staging/, or zero-byte markers
  Then findUnmappedObjects() reports none of them
  And listFolders() does not list chunked-staging as a folder
# pinned by: tests/Unit/Services/S3ServiceTest.php

Scenario: Listings paginate unless capped
  Given more objects or folder prefixes than one ListObjectsV2 page
  Then findUnmappedObjects() and listFolders() walk every page via ContinuationToken
  And listObjects() with maxKeys issues exactly one bounded request
# pinned by: tests/Unit/Services/S3ServiceTest.php

Scenario: The client honours a configured endpoint and timeouts (REQ-7, REQ-8)
  Given filesystems.disks.s3.endpoint is http://127.0.0.1:9100 with path-style enabled
  Then S3Service's client targets that endpoint path-style
  And with no endpoint it resolves the regional AWS endpoint, not path-style
  And against an endpoint that accepts and never answers it fails within the configured timeout
  And ChunkedUploadService::initiateUpload() reaches that same endpoint under the same timeout
# pinned by: tests/Unit/Services/S3ServiceEndpointTest.php

Scenario: No class but S3Service uses the AWS S3 SDK (REQ-sole-s3-gateway)
  Then an architecture test finds no use of Aws\S3 anywhere in App outside App\Services\S3Service
# pinned by: tests/Unit/Services/S3ServiceEndpointTest.php

Scenario: Each writer's derived key is pinned to the layout existing objects use
  Given the root folder "assets"
  Then generateThumbnail("assets/photos/abc.png") writes thumbnails/photos/abc_thumb.png as image/jpeg
  And uploadThumbnail("assets/videos/clip.mp4") writes thumbnails/videos/clip_thumb.jpg
  And generateResizedImages("assets/photos/abc.png") writes thumbnails/{S,M,L}/photos/abc.png
  And a key directly under the root, or a bucket-root setup, gets no folder segment
# pinned by: tests/Unit/Services/S3ServiceTest.php, tests/Unit/Support/AssetKeyLayoutTest.php

Scenario: A bulk move keeps each derived object's stored name (REQ-6)
  Given a PNG asset whose generated thumbnail is thumbnails/photos/abc_thumb.png
  When it is moved to assets/archive
  Then the thumbnail moves to thumbnails/archive/abc_thumb.png, not abc_thumb.jpg
  And only the derived keys the asset holds are moved
# pinned by: tests/Feature/BulkMoveTest.php

# — browser-level (see e2e-testing.md for the harness; skips without a bucket) —

Scenario: Bytes round-trip through a real S3-compatible bucket
  Given a RustFS bucket standing in for S3
  When a PNG is uploaded through the browser
  Then the object is stored under assets/{folder}/ and is publicly fetchable
  And its generated thumbnail is fetchable under thumbnails/
# pinned by: tests/e2e/asset-upload.spec.js
```

## Tests & verification

- Unit: `tests/Unit/S3ServiceTest.php`, `tests/Unit/Services/S3ServiceTest.php`,
  `tests/Unit/Services/S3ServiceEndpointTest.php` — `php artisan config:clear && php artisan test`
- E2E: `tests/e2e/asset-upload.spec.js` against RustFS ([`e2e-testing.md`](e2e-testing.md)).

## Open questions / future

- `replaceFile`, SVG sanitize-on-upload in `putUploadedFile` and `deleteAssetFiles()` have no
  dedicated unit test — covered only through feature tests that mock `S3Service` and the browser
  suite.
