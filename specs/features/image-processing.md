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
- **REQ-4** — Dimension extraction has one implementation, `getImageDimensionsFromPath()`, used
  by every path that stores an image — direct upload (`getImageDimensions()` on the temp file),
  chunked upload and discovery (`S3Service::extractImageDimensions()`, which streams the object
  to a temp file first). It skips EPS and SVG and reads the header with `getimagesize()`, never
  decoding pixels, so memory doesn't scale with file size. EXIF orientations 5–8 swap width and
  height, so stored dimensions are the image *as displayed*, matching the auto-oriented
  thumbnails.

## Technical design

### Contract / public interface

```yaml
createThumbnailContent(imageContent: string, filename: string): ?string   # JPEG bytes, or null for animated GIF
createResizedContent(imageContent: string, extension: string, ?width: int, ?height: int): array
  # => {content: string, mime_type: string, extension: string}
  # extension = AssetKeyLayout::resizeOutputExtension($extension) — same rule as the resize key (s3-storage.md REQ-6)
getImageDimensions(UploadedFile): array          # => {width, height} or [] (non-image mime)
getImageDimensionsFromPath(path: string, filename: string): array  # => {width, height} or [] (also when unreadable) — REQ-4
hasRasterDimensions(filename: string): bool      # false for EPS/SVG — lets S3Service skip the download
isAnimatedGif(imageData: string): bool
```

### Layer touchpoints & ordering

Called only from `S3Service` (`generateThumbnail`, `generateResizedImages`, `uploadFile`,
`extractImageDimensions`). This service never touches S3: it transforms in-memory byte strings,
and reads a local path only to size an image.

## Visual aids

```yaml
intervention/image: ^4.2   # GD driver
```

## Scenarios (BDD)

```gherkin
Scenario: Thumbnails are JPEG, except for animated GIFs (REQ-1)
  Given JPEG, PNG or single-frame GIF content
  Then createThumbnailContent() returns a non-null JPEG string
  And for animated GIF content it returns null
# pinned by: tests/Unit/ImageProcessingServiceTest.php

Scenario Outline: Resize output format (REQ-2)
  Given <source> content
  When createResizedContent() runs
  Then the result is <output>
  Examples:
    | source                                    | output                          |
    | GIF                                       | image/jpeg, extension "jpg"     |
    | PNG or WebP                               | the same format                 |
    | an extension outside jpg/jpeg/png/webp/gif | JPEG                           |
# pinned by: tests/Unit/ImageProcessingServiceTest.php

Scenario: Dimensions for raster images only, as displayed (REQ-4)
  Given a non-image mime type, an .eps or an .svg
  Then getImageDimensions() returns []
  And a valid JPEG upload returns its width and height
  And a 20x10 JPEG with EXIF Orientation 6 returns 10x20 from getImageDimensionsFromPath(), with Orientation 1 20x10
# pinned by: tests/Unit/ImageProcessingServiceTest.php

Scenario: S3 dimension extraction streams the object and shares the local implementation (REQ-4)
  Given a stored JPEG
  When S3Service::extractImageDimensions() runs
  Then it returns the JPEG's width and height, and null for a non-image mime type or an SVG
# pinned by: tests/Unit/Services/S3ServiceTest.php

Scenario: isAnimatedGif walks the block structure (REQ-3)
  Then GIF bytes with two Image Descriptor blocks are animated
  And a realistic animated GIF with a GCT and NETSCAPE looping extension is still animated
  And a byte string shorter than 13 bytes is not
# pinned by: tests/Unit/ImageProcessingServiceTest.php
```

## Tests & verification

- Unit: `tests/Unit/ImageProcessingServiceTest.php`, `tests/Unit/Services/S3ServiceTest.php`
- Run: `php artisan config:clear && php artisan test`
