# Asset replace

```yaml
id: asset-replace
status: implemented
version: 1
owner: core
related:
  - architecture
  - asset-model
  - s3-storage
  - image-processing
  - cloudflare-purge
  - ai-tagging
source:
  - app/Http/Controllers/AssetReplaceController.php
  - app/Policies/AssetPolicy.php
```

## Background / Why

Replacing a file swaps the binary content of an asset while preserving its
metadata (tags, license, alt text, caption, id) and — critically — its `s3_key`
and any URLs already embedded elsewhere (RTE documents, WordPress posts). This
is why replace overwrites the existing key in place rather than creating a new
asset, and purges the CDN cache for the changed URLs instead of ever renaming
the key (see [ADR-006](../decisions/adr-006-immutable-s3-key.md)).

## Requirements

- **REQ-1** — Replace requires the same file extension as the existing asset
  (case-insensitive) — a caller cannot change an asset's file type via replace.
- **REQ-2** — Replace regenerates the thumbnail and resize variants from
  scratch (old ones are deleted first) — stale derived images must never
  survive a replace.
- **REQ-3** — Replace is authorized via `AssetPolicy::update` (a looser check
  than the dedicated `AssetPolicy::replace` ability, which currently exists on
  the policy but is not the one enforced by this controller — see Open
  questions).
- **REQ-4** — CDN URLs for the asset are collected *before* the file is
  swapped and purged *after*, via `CloudflareService`, so a stale Cloudflare
  cache doesn't serve the old binary under the unchanged URL.
- **REQ-5** — `storeThumbnail()` lets the browser upload a client-generated
  thumbnail (used for video/PDF assets where server-side thumbnailing isn't
  practical) — this also purges the CDN cache for the thumbnail URL.

## Technical design

### Contract / public interface

Routes (`routes/web.php`, web-authenticated):

```yaml
GET  assets/{asset}/download:          AssetReplaceController::download
GET  assets/{asset}/replace:           AssetReplaceController::showReplace  # assets.replace
POST assets/{asset}/replace:           AssetReplaceController::replace      # assets.replace.store
POST assets/{asset}/thumbnail:         AssetReplaceController::storeThumbnail  # assets.thumbnail.store
POST assets/{asset}/ai-tag:            AssetReplaceController::generateAiTags   # rate-limited (see CLAUDE.md)
```

`replace(Request, Asset)` validation: `file` (required, max 500000KB,
`AllowedUploadExtension`, plus an inline closure rejecting a different
extension than the current `s3_key`'s). On success: deletes old thumbnail +
resizes, updates `filename`/`mime_type`/`size`/`etag`/`width`/`height` and
nulls all four derived-key columns, then runs
`AssetProcessingService::processImageAsset()` to regenerate them — tags and
other metadata are untouched.

`download(Asset)` — streams the raw S3 object content with an RFC 5987-encoded
`Content-Disposition: attachment` (ASCII fallback derived via `Str::ascii()`),
guarding against header injection from a crafted filename.

`generateAiTags(Asset)` — manual re-run of Rekognition tagging for a single
image asset; requires `RekognitionService::isEnabled()` and `asset->isImage()`,
otherwise redirects back with an error flash.

### Layer touchpoints & ordering

```
replace(): authorize(update)
  → validate (same extension as current s3_key)
  → CloudflareService::collectAssetUrls($asset)      [before the swap]
  → S3Service::replaceFile()                          [overwrites s3_key in place]
  → delete old thumbnail_s3_key + resize keys
  → Asset::update() (clears thumbnail/resize columns)
  → AssetProcessingService::processImageAsset($asset) [regenerates thumbnail + resizes]
  → CloudflareService::purgeUrls($urlsToPurge)         [after the swap]
```

## Scenarios (BDD)

```gherkin
Scenario: Replace rejects a file with a different extension
  Given an asset with s3_key ending in .jpg
  When replace is submitted with a .png file
  Then the request is rejected with a validation error
# pinned by: tests/Feature/AssetTest.php

Scenario: Replace accepts a file with the same extension
  Given an asset with s3_key ending in .jpg
  When replace is submitted with another .jpg file
  Then the asset's content is updated and the response succeeds
# pinned by: tests/Feature/AssetTest.php

Scenario: Replace matches by s3_key extension, not filename extension
  Given an asset whose filename and s3_key extensions differ
  When replace is submitted
  Then the s3_key's extension is the one enforced
# pinned by: tests/Feature/AssetTest.php

Scenario: Replace accepts a file with a different-case extension
  Given an asset with s3_key ending in .jpg
  When replace is submitted with a .JPG file
  Then the case-insensitive extension match succeeds
# pinned by: tests/Feature/AssetTest.php

Scenario: Replace preserves tags after the file is swapped
  Given an asset with existing tags
  When the file is replaced
  Then the same tags remain attached afterward
# pinned by: tests/Feature/AssetTest.php

Scenario: Guests cannot access or perform replace
  Given an unauthenticated request
  When it hits the replace form or replace action
  Then it is redirected to login
# pinned by: tests/Feature/AssetTest.php

Scenario: storeThumbnail uploads a base64 thumbnail for a video asset
  Given a video asset and a base64-encoded thumbnail image
  When storeThumbnail is called
  Then the asset's thumbnail_s3_key is set and thumbnail_url reflects it
# pinned by: tests/Feature/AssetTest.php

Scenario: storeThumbnail deletes the old thumbnail before uploading the new one
  Given an asset with an existing thumbnail_s3_key
  When storeThumbnail is called again
  Then the old S3 thumbnail object is deleted before the new one is stored
# pinned by: tests/Feature/AssetTest.php

Scenario: storeThumbnail requires authentication and a thumbnail field
  Given a guest request, or a request missing the thumbnail field
  Then the request is rejected (redirect to login, or a validation error)
# pinned by: tests/Feature/AssetTest.php
```

## Tests & verification

- Feature: `tests/Feature/AssetTest.php` (replace + storeThumbnail scenarios)
- Run: `php artisan config:clear && php artisan test`

## Open questions / future

- `AssetPolicy` defines a dedicated `replace(User): bool` ability
  (admin/editor only), but `AssetReplaceController::showReplace`/`replace` call
  `$this->authorize('update', $asset)` instead of `$this->authorize('replace', ...)`
  — since `update` is granted to all three roles (including `api`), an
  API-role token can currently hit the replace endpoints even though the
  policy's `replace` ability says it shouldn't be able to. Worth confirming
  whether this is intentional (replace treated as a metadata-adjacent update)
  or a policy-wiring gap; no test currently asserts API-role behavior on
  `assets.replace.store` either way.
- `download()`'s RFC 5987 filename-encoding/header-injection-guard logic has no
  dedicated test asserting the `Content-Disposition` header shape for a
  crafted filename.
