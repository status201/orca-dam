# S3 storage

```yaml
id: s3-storage
status: implemented
version: 3
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
```

## Background / Why

`S3Service` is the sole gateway to AWS S3 (REQ-1 of `architecture.md`) — no
controller or other service talks to the AWS SDK directly (REQ-sole-s3-gateway).
It owns the S3 key
layout, the security hardening applied at upload time (extension-decided
Content-Type, forced download for non-inline types, SVG sanitization), and all
derived-asset (thumbnail/resize) key generation and cleanup.

## Requirements

- **REQ-1** — S3 keys follow `assets/{folder}/{uuid}.{ext}` for originals (or
  the sanitized original filename when `keep_original_filename` is used), and
  `thumbnails/{folder}/{uuid}_thumb.jpg` / `thumbnails/{S,M,L}/{folder}/{name}.{ext}`
  for derived variants. `s3_key` is immutable once written — see
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
- **REQ-sole-s3-gateway** — `S3Service` is the only class in `app/` that uses
  the AWS S3 SDK, so REQ-7's endpoint and REQ-8's timeouts govern every S3
  request ORCA makes. That includes the multipart upload
  [`chunked-upload.md`](chunked-upload.md) drives: `ChunkedUploadService` calls
  `S3Service::createMultipartUpload()` / `uploadPart()` /
  `completeMultipartUpload()` / `abortMultipartUpload()` and holds no client of
  its own. An architecture test enforces it, because the previous arrangement —
  a second, hand-built client in `ChunkedUploadService` — silently skipped both
  requirements and sent every upload ≥ 10MB to AWS on an S3-compatible
  deployment.
- **REQ-staging-prefix-hidden** — `chunked-staging/` at the bucket root
  (`S3Service::CHUNKED_STAGING_PREFIX`) is ORCA's own transient prefix: a
  chunked SVG assembles there before it is sanitized onto its asset key
  ([`chunked-upload.md`](chunked-upload.md) REQ-4). Like `thumbnails/`, it is a
  reserved prefix: `findUnmappedObjects()` never reports a key under one and
  `listFolders()` never lists one, so a staging object a failed delete leaves
  behind can't be imported by discovery or offered as an upload folder — which
  matters most when the root folder is the bucket root and both scan the whole
  bucket.
- **REQ-5** — `getPublicBaseUrl()` resolves to the `custom_domain` setting when
  set, otherwise the configured S3 bucket URL — this is the single point every
  computed asset URL passes through, so a custom-domain change or Cloudflare
  cutover needs no data migration.
- **REQ-6** — Derived-key computation (`computeDerivedKeys()`) mirrors the
  naming used by `generateThumbnail()`/`generateResizedImages()` exactly, so
  moving an asset (bulk move) can predict where its thumbnails/resizes will
  land without regenerating them.
- **REQ-7** — The `S3Client` is built from the `s3` disk config *including*
  `endpoint` and `use_path_style_endpoint` when they are set, so the service can
  be pointed at any S3-compatible endpoint (the RustFS bucket the E2E suite runs
  against — [`e2e-testing.md`](e2e-testing.md) REQ-2 — or an R2/Wasabi-style
  provider). Both keys already existed in `config/filesystems.php` for the
  Flysystem disk; the service simply stopped ignoring them. When `endpoint` is
  unset the client is constructed exactly as before (region + credentials only),
  so real-AWS behaviour is unchanged.
- **REQ-8** — The `S3Client` carries an explicit `connect_timeout` and `timeout`
  (`AWS_CONNECT_TIMEOUT`, default 5s; `AWS_REQUEST_TIMEOUT`, default 120s; both
  read from the `s3` disk config). The SDK's own default is to wait indefinitely
  for a response, so an endpoint that completes the TCP handshake and then never
  answers — a half-open proxy, or an unrelated service listening on the
  configured port — holds the request until PHP's `max_execution_time` ends it,
  occupying a worker for minutes over something that is already broken. A
  bounded wait is what lets the service do what
  [ADR-010](../decisions/adr-010-services-swallow-controllers-map.md) says it
  should: log and return `null`. A deployment that moves genuinely large objects
  can raise `AWS_REQUEST_TIMEOUT`.

## Technical design

### Contract / public interface

```yaml
uploadFile(UploadedFile, ?directory, keepOriginalFilename=false): array   # {s3_key, filename, mime_type, size, etag, width, height}
replaceFile(UploadedFile, existingS3Key): array                          # same shape minus s3_key (key unchanged)
putUploadedFile(s3Key, UploadedFile): Aws\Result   # protected — streaming + hardening, shared by upload/replace
sanitizeSvg(string $svg): string
sanitizeStoredSvg(s3Key, ?destinationKey): ?string  # download s3Key → sanitize → put to destinationKey ?? s3Key; new ETag or null
generateThumbnail(s3Key): ?string           # 300x300 JPEG; null for EPS/SVG/animated GIF
generateResizedImages(s3Key): array         # {s,m,l} => key; skips non-image ext, animated GIF
uploadThumbnail(s3Key, imageData): ?string  # browser-generated thumb (video/PDF)
deleteResizedImages(Asset): void
deleteAssetFiles(Asset, keepOriginal=false): void   # original + thumbnail + resizes
computeDerivedKeys(s3Key): array            # {thumbnail_s3_key, resize_s_s3_key, resize_m_s3_key, resize_l_s3_key}
moveObject(sourceKey, destinationKey): bool # copy + delete
deleteFile(s3Key): bool
extractImageDimensions(s3Key, mimeType): ?array   # null for non-images; streams the object to a temp file
                                            # and defers to ImageProcessingService::getImageDimensionsFromPath()

# Multipart upload — chunked-upload.md's only way to S3 (REQ-sole-s3-gateway). Unlike the rest of
# this service these THROW (ADR-010's exception): the caller orchestrates abort/rollback and has
# to know which step failed.
createMultipartUpload(key): string                            # UploadId; Content-Type + Content-Disposition from the key's extension (REQ-3)
uploadPart(key, uploadId, partNumber, path): string            # part ETag; streams the local file
completeMultipartUpload(key, uploadId, parts): ?string         # parts sorted by PartNumber here; object ETag
abortMultipartUpload(key, uploadId): void
listObjects(prefix='', ?maxKeys): array
findUnmappedObjects(?prefix): array         # S3 objects not in the assets table (discovery); skips thumbnails/ and chunked-staging/
listFolders(?prefix): array
createFolder(folderPath): bool
getObjectMetadata(s3Key): ?array            # {size, mime_type, last_modified, etag}
getObjectHeaders(s3Keys, concurrency=25): array   # key => {content_type, content_disposition} | null (missing/unreadable);
                                            # concurrent HEADs, so a whole library fits in one web request
repairUploadHeaders(s3Key): ?string         # copy onto itself with the headers uploadHeaders() sets today
                                            # (MetadataDirective REPLACE); new ETag, or null on failure
getObjectContent(s3Key): ?string
getPublicBaseUrl(): string                  # static — custom_domain setting > AWS_URL
getUrl(s3Key): string
getRootFolder() / getConfiguredFolders() / getRootPrefix(): static helpers over the Setting model
setS3Client(S3Client): static               # test seam — swap the underlying AWS client
__construct(ImageProcessingService)         # builds S3Client from the `s3` disk config,
                                            # incl. endpoint + use_path_style_endpoint (REQ-7)
sanitizeFilename(filename): string          # static
ensureExtension(filename, ext, fallback): string   # static
```

### Data shapes

```yaml
# uploadFile()/replaceFile() return shape
s3_key: string        # only in uploadFile() — replaceFile keeps the existing key
filename: string       # client original name
mime_type: string
size: int
etag: string           # ETag with surrounding quotes stripped
width / height: int|null
```

### Layer touchpoints & ordering

`generateThumbnail()`/`generateResizedImages()` both derive the destination
folder by stripping the configured root prefix from the source `s3_key`
(`getRootPrefix()`), then re-apply it under `thumbnails/` or `thumbnails/{S,M,L}/`
— this exact folder-stripping logic is duplicated (not shared) across
`generateThumbnail`, `generateResizedImages`, `uploadThumbnail`, and
`computeDerivedKeys`, so a change to one must be mirrored in the others (a
noted internal seam, not a public contract concern). Resize dimensions come
from `Setting::get('resize_{s,m,l}_width|height', ...)`, read live (not cached
beyond the `Setting` model's own 1h cache).

### Persistence

S3 key layout (see also `architecture.md` → "S3 + CDN topology"):

```
assets/{folder}/{uuid}.{ext}                  # original
thumbnails/{folder}/{uuid}_thumb.jpg           # 300x300 JPEG thumbnail
thumbnails/{S|M|L}/{folder}/{basename}.{ext}   # resize variants
chunked-staging/{session_token}.svg            # transient: a chunked SVG before sanitization (chunked-upload REQ-4)
```

Nothing is cached at this layer beyond what `Setting` itself caches; every S3
call is a live AWS API request.

## Scenarios (BDD)

```gherkin
Scenario: getConfiguredFolders falls back to the root folder when s3_folders is unset
  Given no s3_folders setting
  Then getConfiguredFolders() returns [rootFolder]
# pinned by: tests/Unit/S3ServiceTest.php

Scenario: getRootFolder trims whitespace and slashes
  Given s3_root_folder = " /assets/ "
  Then getRootFolder() returns "assets"
# pinned by: tests/Unit/S3ServiceTest.php

Scenario: getPublicBaseUrl prefers a configured custom_domain
  Given custom_domain is set
  Then getPublicBaseUrl() returns that domain, trailing slash stripped
# pinned by: tests/Unit/S3ServiceTest.php

Scenario: getPublicBaseUrl falls back to the S3 bucket URL
  Given custom_domain is empty
  Then getPublicBaseUrl() returns config('filesystems.disks.s3.url')
# pinned by: tests/Unit/S3ServiceTest.php

Scenario: sanitizeSvg strips scripts and event handlers
  Given an SVG containing a <script> tag and an onload handler
  When sanitizeSvg() runs
  Then the output contains neither
# pinned by: tests/Unit/Services/S3ServiceTest.php

Scenario: sanitizeSvg preserves benign vector markup
  Given a clean SVG with only paths/shapes
  Then sanitizeSvg() returns equivalent markup
# pinned by: tests/Unit/Services/S3ServiceTest.php

Scenario: sanitizeSvg throws for an SVG that declares a custom DTD entity
  Given an SVG whose internal DTD declares an entity it then references
    (the GHSA-9rjx-3jch-6vjf vector; also older Illustrator exports' &ns_svg;)
  When sanitizeSvg() runs
  Then it throws instead of returning markup — svg-sanitize >= 1.0 strips the DTD
    and rejects the now-undefined entity, and REQ-4 turns that into a failure
# pinned by: tests/Unit/Services/S3ServiceTest.php

Scenario: findUnmappedObjects excludes mapped, thumbnail, and zero-byte keys
  Given S3 objects that are already-mapped, under thumbnails/, or zero-byte folder markers
  Then findUnmappedObjects() excludes all three categories
# pinned by: tests/Unit/Services/S3ServiceTest.php

Scenario: findUnmappedObjects and listFolders hide the chunked-staging prefix (REQ-staging-prefix-hidden)
  Given an object under chunked-staging/ in the bucket
  Then findUnmappedObjects() does not report it
  And listFolders() does not list chunked-staging as a folder
# pinned by: tests/Unit/Services/S3ServiceTest.php

Scenario: sanitizeStoredSvg can write the sanitized content to a different key
  Given an SVG stored at a source key
  When sanitizeStoredSvg(source, destination) runs
  Then the source is read, the sanitized markup is put to the destination, and its ETag is returned
# pinned by: tests/Unit/Services/S3ServiceTest.php

Scenario: findUnmappedObjects follows pagination across every page
  Given more objects than a single ListObjectsV2 page
  Then all pages are walked via ContinuationToken
# pinned by: tests/Unit/Services/S3ServiceTest.php

Scenario: listObjects with a maxKeys cap issues exactly one bounded request
  Given maxKeys is set
  Then no ContinuationToken pagination occurs, only the first page is returned
# pinned by: tests/Unit/Services/S3ServiceTest.php

Scenario: listFolders paginates common prefixes across pages
  Given folder prefixes spanning multiple ListObjectsV2 pages
  Then listFolders() returns the complete set
# pinned by: tests/Unit/Services/S3ServiceTest.php

Scenario: A configured endpoint points the client at an S3-compatible service
  Given filesystems.disks.s3.endpoint is http://127.0.0.1:9100 with path-style enabled
  When S3Service is constructed
  Then its S3Client targets that endpoint and uses path-style addressing
# pinned by: tests/Unit/Services/S3ServiceEndpointTest.php

Scenario: An endpoint that accepts the connection and never answers fails in seconds
  Given an S3 endpoint that completes the handshake but sends no response
  When S3Service issues a request against it
  Then the client gives up after the configured timeout rather than running until max_execution_time
# pinned by: tests/Unit/Services/S3ServiceEndpointTest.php

Scenario: Chunked-upload requests go to the configured endpoint and are bounded by the timeout (regression)
  Given filesystems.disks.s3.endpoint points at a local socket that accepts and never answers
  When ChunkedUploadService::initiateUpload() runs
  Then the CreateMultipartUpload request arrives at that socket (path-style)
  And the call fails within the configured timeout
# Regression: ChunkedUploadService built its own S3Client from region + credentials only,
# so every upload >= 10MB went to real AWS on an R2/Wasabi/RustFS deployment, and waited
# without bound against AWS.
# pinned by: tests/Unit/Services/S3ServiceEndpointTest.php

Scenario: No class but S3Service uses the AWS S3 SDK (REQ-sole-s3-gateway)
  Then an architecture test finds no use of Aws\S3 anywhere in App outside App\Services\S3Service
# pinned by: tests/Unit/Services/S3ServiceEndpointTest.php

Scenario: No endpoint config leaves AWS addressing untouched
  Given filesystems.disks.s3.endpoint is null
  When S3Service is constructed
  Then its S3Client resolves the regional AWS endpoint and is not path-style
# pinned by: tests/Unit/Services/S3ServiceEndpointTest.php

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
  `tests/Unit/Services/S3ServiceEndpointTest.php`
- Run: `php artisan config:clear && php artisan test`
- E2E: the streaming/hardening core the unit tests only reach indirectly is
  exercised for real against RustFS by `tests/e2e/asset-upload.spec.js`
  (see [`e2e-testing.md`](e2e-testing.md)).

## Open questions / future

- `replaceFile` and SVG sanitize-on-upload in `putUploadedFile` still have no
  dedicated unit test; they're exercised through feature tests that mock
  `S3Service` and by the browser suite. `uploadFile`'s `ContentType` /
  `ContentDisposition` params are now asserted directly (`S3ServiceTest.php`).
- `generateThumbnail()`, `generateResizedImages()`, `deleteAssetFiles()`, and
  `computeDerivedKeys()` are likewise only covered indirectly (through
  `AssetProcessingServiceTest.php` and the bulk-move feature tests) rather than
  as isolated `S3Service` unit tests.
