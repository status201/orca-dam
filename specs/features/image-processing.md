# Image processing

```yaml
id: image-processing
status: implemented
version: 4
owner: core
related:
  - architecture
  - s3-storage
  - asset-upload
source:
  - app/Services/ImageProcessingService.php
  - app/Support/AssetKeyLayout.php
```

## Background / Why

`ImageProcessingService` is the Intervention Image (GD driver) wrapper behind
`S3Service`'s thumbnail and resize generation. It is kept separate from
`S3Service` so the pure image-manipulation logic (thumbnailing, resizing,
dimension extraction, animated-GIF detection) can be unit-tested without any
S3/AWS SDK involvement.

## Requirements

- **REQ-1** — Thumbnails are always 300×300 JPEG (quality 80), scaled to fit —
  except animated GIFs, where thumbnail generation is skipped entirely
  (`createThumbnailContent()` returns `null`) rather than producing a
  single-frame thumbnail that would misrepresent the source.
- **REQ-2** — Resize variants preserve the source format where practical
  (PNG stays PNG, WebP stays WebP) but GIF is always converted to JPEG for
  resizes (`createResizedContent()`); anything else not in
  `{jpg, jpeg, png, webp}` falls back to JPEG.
- **REQ-3** — Animated-GIF detection (`isAnimatedGif()`) parses the actual GIF
  block structure (looking for a second Image Descriptor block, `0x2C`) rather
  than relying on file size or frame-count metadata heuristics, so it's
  correct even for GIFs with unusual block ordering.
- **REQ-4** — Dimension extraction has one implementation,
  `getImageDimensionsFromPath()`, used by every path that stores an image —
  direct upload (`getImageDimensions()` on the upload's temp file), chunked
  upload and discovery (`S3Service::extractImageDimensions()`, which streams the
  object to a temp file first). It skips EPS and SVG (no meaningful raster
  dimensions) and reads the header with `getimagesize()`, never decoding the
  pixels, so a 500MB upload costs no more memory than a small one. EXIF
  orientations 5–8 (rotated 90°/270°) swap width and height, so the stored
  dimensions are the image *as displayed* — matching the auto-oriented
  thumbnails and resizes. The three paths used to disagree: the direct path
  decoded the whole image (EXIF-aware, memory-hungry), the chunked path read
  raw `getimagesize()` (cheap, but a rotated phone photo ≥ 10MB came out
  sideways), and discovery decoded from a string in memory.

## Technical design

### Contract / public interface

```yaml
createThumbnailContent(imageContent: string, filename: string): ?string   # JPEG bytes, or null for animated GIF
createResizedContent(imageContent: string, extension: string, ?width: int, ?height: int): array
  # => {content: string, mime_type: string, extension: string}
  # extension = AssetKeyLayout::resizeOutputExtension($extension) — the same rule the resize
  # key uses, so the stored key and the encoded bytes cannot disagree (s3-storage.md REQ-6)
getImageDimensions(UploadedFile): array          # => {width, height} or [] when not applicable (non-image mime)
getImageDimensionsFromPath(path: string, filename: string): array  # => {width, height} or [] — REQ-4
hasRasterDimensions(filename: string): bool      # false for EPS/SVG — also lets S3Service skip the download
isAnimatedGif(imageData: string): bool
```

### Layer touchpoints & ordering

Called exclusively from `S3Service` (`generateThumbnail`,
`generateResizedImages`, `getImageDimensions` inside `uploadFile`,
`extractImageDimensions`) — this service never touches S3; it transforms
in-memory byte strings, and reads a local path only to size an image
(`getImageDimensionsFromPath()`). `ImageManager` (Intervention
Image 4.x, GD driver) is built once per `ImageProcessingService` instance via
`ImageManager::usingDriver(Driver::class)`.

Decoding is explicit about its input kind: raw byte strings go through
`decodeBinary()` — Intervention 4 dropped the auto-detecting `read()`.
Dimension extraction doesn't decode at all (REQ-4).
Encoding likewise goes through explicit encoder objects
(`Encoders\{JpegEncoder, PngEncoder, WebpEncoder}`) rather than the `toJpeg()` /
`toPng()` / `toWebp()` shortcuts removed in 4.x; each returns an `EncodedImage`
that is cast to a byte string. Intervention's own exceptions all extend
`\Exception`. An unreadable file makes `getimagesize()` return `false`, which
`getImageDimensionsFromPath()` turns into `[]`.

## Scenarios (BDD)

```gherkin
Scenario: Thumbnail generation is skipped for an animated GIF
  Given animated GIF image content
  When createThumbnailContent() is called
  Then it returns null
# pinned by: tests/Unit/ImageProcessingServiceTest.php

Scenario: Thumbnail generation succeeds for a static GIF
  Given single-frame GIF image content
  Then createThumbnailContent() returns a non-null JPEG string
# pinned by: tests/Unit/ImageProcessingServiceTest.php

Scenario: Thumbnail generation works for JPEG and PNG input
  Given JPEG or PNG image content
  Then createThumbnailContent() returns a non-null JPEG string
# pinned by: tests/Unit/ImageProcessingServiceTest.php

Scenario: Resize converts GIF source to JPEG
  Given GIF image content and extension "gif"
  When createResizedContent() runs
  Then the result's mime_type is image/jpeg and extension is "jpg"
# pinned by: tests/Unit/ImageProcessingServiceTest.php

Scenario: Resize preserves PNG and WebP formats
  Given PNG (or WebP) content and matching extension
  Then the result keeps that format
# pinned by: tests/Unit/ImageProcessingServiceTest.php

Scenario: Resize falls back to JPEG for an unrecognized extension
  Given an extension outside {jpg, jpeg, png, webp, gif}
  Then the result is JPEG
# pinned by: tests/Unit/ImageProcessingServiceTest.php

Scenario: getImageDimensions returns empty for non-image, EPS, and SVG files
  Given a non-image mime type, an .eps file, or an .svg file
  Then getImageDimensions() returns []
# pinned by: tests/Unit/ImageProcessingServiceTest.php

Scenario: getImageDimensions returns width/height for a valid JPEG
  Given a valid JPEG upload
  Then getImageDimensions() returns the correct width and height
# pinned by: tests/Unit/ImageProcessingServiceTest.php

Scenario: A JPEG whose EXIF orientation rotates it reports its displayed dimensions (REQ-4)
  Given a 20x10 JPEG carrying EXIF Orientation 6 (rotate 90° clockwise)
  Then getImageDimensionsFromPath() returns width 10 and height 20
  And the same JPEG with Orientation 1 returns width 20 and height 10
# pinned by: tests/Unit/ImageProcessingServiceTest.php

Scenario: S3 dimension extraction streams the object and shares the local implementation (REQ-4)
  Given a stored JPEG
  When S3Service::extractImageDimensions() runs
  Then it returns the JPEG's width and height, and null for a non-image mime type or an SVG
# pinned by: tests/Unit/Services/S3ServiceTest.php

Scenario: isAnimatedGif detects a two-frame GIF as animated
  Given GIF bytes with two Image Descriptor blocks
  Then isAnimatedGif() returns true
# pinned by: tests/Unit/ImageProcessingServiceTest.php

Scenario: isAnimatedGif returns false for data too short to be a GIF
  Given a byte string shorter than 13 bytes
  Then isAnimatedGif() returns false
# pinned by: tests/Unit/ImageProcessingServiceTest.php

Scenario: isAnimatedGif correctly walks a GIF with a Global Color Table and looping extension
  Given a realistic animated GIF with a GCT and NETSCAPE looping extension block
  Then isAnimatedGif() still correctly returns true
# pinned by: tests/Unit/ImageProcessingServiceTest.php
```

## Tests & verification

- Unit: `tests/Unit/ImageProcessingServiceTest.php`, `tests/Unit/Services/S3ServiceTest.php`
- Run: `php artisan config:clear && php artisan test`
