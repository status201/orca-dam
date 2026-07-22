# Image processing

```yaml
id: image-processing
status: implemented
version: 2
owner: core
related:
  - architecture
  - s3-storage
  - asset-upload
source:
  - app/Services/ImageProcessingService.php
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
- **REQ-4** — Dimension extraction (`getImageDimensions()`) skips EPS and SVG
  (no meaningful raster dimensions) and uses `getimagesize()` rather than
  loading the full image for GIFs, to avoid memory blowups on large animated
  GIFs.

## Technical design

### Contract / public interface

```yaml
createThumbnailContent(imageContent: string, filename: string): ?string   # JPEG bytes, or null for animated GIF
createResizedContent(imageContent: string, extension: string, ?width: int, ?height: int): array
  # => {content: string, mime_type: string, extension: string}
getImageDimensions(UploadedFile): array          # => {width, height} or [] when not applicable
getImageDimensionsFromData(imageData: string): array  # => {width, height}
isAnimatedGif(imageData: string): bool
```

### Layer touchpoints & ordering

Called exclusively from `S3Service` (`generateThumbnail`,
`generateResizedImages`, `getImageDimensions` inside `uploadFile`,
`extractImageDimensions`) — this service never touches S3 or the filesystem
itself, it only transforms in-memory byte strings. `ImageManager` (Intervention
Image 4.x, GD driver) is built once per `ImageProcessingService` instance via
`ImageManager::usingDriver(Driver::class)`.

Decoding is explicit about its input kind: raw byte strings go through
`decodeBinary()`, and the single filesystem path (`getImageDimensions()`) goes
through `decodePath()` — Intervention 4 dropped the auto-detecting `read()`.
Encoding likewise goes through explicit encoder objects
(`Encoders\{JpegEncoder, PngEncoder, WebpEncoder}`) rather than the `toJpeg()` /
`toPng()` / `toWebp()` shortcuts removed in 4.x; each returns an `EncodedImage`
that is cast to a byte string. Intervention's own exceptions all extend
`\Exception`, so the `getImageDimensions()` catch blocks still degrade to `[]`
on an undecodable file.

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

- Unit: `tests/Unit/ImageProcessingServiceTest.php`
- Run: `php artisan config:clear && php artisan test`
