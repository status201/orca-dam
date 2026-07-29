# S3 storage

```yaml
id: s3-storage
status: implemented
version: 1
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
source:
  - app/Services/S3Service.php
  - app/Support/UploadPolicy.php
  - config/filesystems.php
```

## Background / Why

`S3Service` is the sole gateway to AWS S3 (REQ-1 of `architecture.md`) — no
controller or other service talks to the AWS SDK directly. It owns the S3 key
layout, the security hardening applied at upload time (server-detected
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
- **REQ-3** — Content-Type is always server-detected (`$file->getMimeType()`),
  never the client-declared value; non-inline types get
  `Content-Disposition: attachment` (`App\Support\UploadPolicy::isInline()`).
- **REQ-4** — SVGs are sanitized (`enshrined/svg-sanitize`) before storage on
  every upload path; a sanitization failure throws rather than storing
  unsanitized content.
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
  be pointed at any S3-compatible endpoint (the MinIO bucket the E2E suite runs
  against — [`e2e-testing.md`](e2e-testing.md) REQ-2 — or an R2/Wasabi-style
  provider). Both keys already existed in `config/filesystems.php` for the
  Flysystem disk; the service simply stopped ignoring them. When `endpoint` is
  unset the client is constructed exactly as before (region + credentials only),
  so real-AWS behaviour is unchanged.

## Technical design

### Contract / public interface

```yaml
uploadFile(UploadedFile, ?directory, keepOriginalFilename=false): array   # {s3_key, filename, mime_type, size, etag, width, height}
replaceFile(UploadedFile, existingS3Key): array                          # same shape minus s3_key (key unchanged)
putUploadedFile(s3Key, UploadedFile): Aws\Result   # protected — streaming + hardening, shared by upload/replace
sanitizeSvg(string $svg): string
sanitizeStoredSvg(s3Key): ?string           # download → sanitize → re-put; used by chunked-upload completion
generateThumbnail(s3Key): ?string           # 300x300 JPEG; null for EPS/SVG/animated GIF
generateResizedImages(s3Key): array         # {s,m,l} => key; skips non-image ext, animated GIF
uploadThumbnail(s3Key, imageData): ?string  # browser-generated thumb (video/PDF)
deleteResizedImages(Asset): void
deleteAssetFiles(Asset, keepOriginal=false): void   # original + thumbnail + resizes
computeDerivedKeys(s3Key): array            # {thumbnail_s3_key, resize_s_s3_key, resize_m_s3_key, resize_l_s3_key}
moveObject(sourceKey, destinationKey): bool # copy + delete
deleteFile(s3Key): bool
extractImageDimensions(s3Key, mimeType): ?array
listObjects(prefix='', ?maxKeys): array
findUnmappedObjects(?prefix): array         # S3 objects not in the assets table (discovery)
listFolders(?prefix): array
createFolder(folderPath): bool
getObjectMetadata(s3Key): ?array            # {size, mime_type, last_modified, etag}
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

Scenario: findUnmappedObjects excludes mapped, thumbnail, and zero-byte keys
  Given S3 objects that are already-mapped, under thumbnails/, or zero-byte folder markers
  Then findUnmappedObjects() excludes all three categories
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
  Given filesystems.disks.s3.endpoint is http://127.0.0.1:9000 with path-style enabled
  When S3Service is constructed
  Then its S3Client targets that endpoint and uses path-style addressing
# pinned by: tests/Unit/Services/S3ServiceEndpointTest.php

Scenario: No endpoint config leaves AWS addressing untouched
  Given filesystems.disks.s3.endpoint is null
  When S3Service is constructed
  Then its S3Client resolves the regional AWS endpoint and is not path-style
# pinned by: tests/Unit/Services/S3ServiceEndpointTest.php
```

## Tests & verification

- Unit: `tests/Unit/S3ServiceTest.php`, `tests/Unit/Services/S3ServiceTest.php`,
  `tests/Unit/Services/S3ServiceEndpointTest.php`
- Run: `php artisan config:clear && php artisan test`
- E2E: the streaming/hardening core the unit tests only reach indirectly is
  exercised for real against MinIO by `tests/e2e/asset-upload.spec.js`
  (see [`e2e-testing.md`](e2e-testing.md)).

## Open questions / future

- `uploadFile`/`replaceFile`/`putUploadedFile` (the streaming + hardening core —
  server-detected Content-Type, forced `Content-Disposition: attachment` for
  non-inline extensions, SVG sanitize-on-upload) have no dedicated unit test in
  either `S3ServiceTest.php` file; they're only exercised indirectly through
  feature tests that mock `S3Service` (e.g. `tests/Feature/AssetTest.php`'s
  replace scenarios). A direct unit test asserting the `ContentDisposition` /
  `ContentType` params passed to the mocked S3 client would close this gap.
- `generateThumbnail()`, `generateResizedImages()`, `deleteAssetFiles()`, and
  `computeDerivedKeys()` are likewise only covered indirectly (through
  `AssetProcessingServiceTest.php` and the bulk-move feature tests) rather than
  as isolated `S3Service` unit tests.
