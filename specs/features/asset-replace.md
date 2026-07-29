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
- **REQ-3** — The mutating replace operations (`showReplace`, `replace`,
  `storeThumbnail`) are authorized via the dedicated `AssetPolicy::replace`
  ability (**admin/editor only**) — an `api`-role principal is refused, matching
  the role matrix in [`authorization-policies.md`](authorization-policies.md).
  (`download` streams content with no policy gate; `generateAiTags` keeps its
  `update` check.)
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
replace(): authorize(replace)                       [admin/editor only]
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

Scenario: An api-role user cannot replace an asset
  Given an authenticated user with role "api"
  When they submit the replace action for an asset
  Then the response status is 403 and the asset's file is not swapped
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

# — browser-level (see e2e-testing.md for the harness) —

Scenario: A replacement with the wrong extension never reaches the server
  Given the replace page for a .png asset
  When a file named .jpg is chosen
  Then an error is shown in the page and no upload is offered
# pinned by: tests/e2e/asset-replace.spec.js

Scenario: A staged replacement can be cleared
  Given a staged replacement file
  When Clear is clicked
  Then the drop zone returns to its empty state
# pinned by: tests/e2e/asset-replace.spec.js

Scenario: Cancelling the confirmation does not replace anything
  Given a staged replacement file
  When Replace File is clicked and the confirmation cancelled
  Then the modal closes, the file stays staged, and nothing was uploaded
# pinned by: tests/e2e/asset-replace.spec.js

Scenario: Confirming replaces the file and returns to the edit page
  Given a staged replacement file and a reachable bucket
  When the replacement is confirmed
  Then the edit page is shown with the replaced marker in the URL
  And the transient success panel is not what proves it — the module redirects
    after ~2 seconds
# pinned by: tests/e2e/asset-replace.spec.js
```

## Tests & verification

- Feature: `tests/Feature/AssetTest.php` (replace + storeThumbnail scenarios)
- E2E: `tests/e2e/asset-replace.spec.js` — the client-side extension guard,
  staging/clearing and the confirmation modal run without a bucket; the successful
  replace is guarded by `requiresS3()`.
- Run: `php artisan config:clear && php artisan test`; `npm run test:e2e`

## Open questions / future

- `download()`'s RFC 5987 filename-encoding/header-injection-guard logic has no
  dedicated test asserting the `Content-Disposition` header shape for a
  crafted filename.
