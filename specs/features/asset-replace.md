# Asset replace

```yaml
id: asset-replace
status: implemented
version: 2
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
POST assets/{asset}/ai-tag:            AssetReplaceController::generateAiTags   # ai-tagging.md
```

`replace(Request, Asset)` validates `file` with `AllowedUploadExtension` plus a closure rejecting
an extension different from the current `s3_key`'s. On success it updates
`filename`/`mime_type`/`size`/`etag`/`width`/`height`, nulls the four derived-key columns and runs
`AssetProcessingService::processImageAsset()` to regenerate them; tags and other metadata are
untouched.

`download(Asset)` — streams the raw S3 object with an RFC 5987-encoded
`Content-Disposition: attachment` (ASCII fallback via `Str::ascii()`), guarding against header
injection from a crafted filename.

### Layer touchpoints & ordering

```
replace(): authorize(replace) → validate (same extension as s3_key)
  → CloudflareService::collectAssetUrls($asset)      [before the swap]
  → S3Service::replaceFile()                          [overwrites s3_key in place]
  → delete old derived keys → Asset::update() (derived columns null)
  → processImageAsset($asset)                         [regenerates thumbnail + resizes]
  → CloudflareService::purgeUrls($urlsToPurge)         [after the swap]
```

## Scenarios (BDD)

```gherkin
Scenario Outline: Replace enforces the s3_key's extension, case-insensitively (REQ-1)
  Given an asset with s3_key ending in .jpg
  When replace is submitted with <file>
  Then <outcome>
  Examples:
    | file                                               | outcome                         |
    | a .png file                                        | a validation error              |
    | another .jpg file                                  | the content is updated          |
    | a .JPG file                                        | accepted                        |
    | a file matching the filename but not the s3_key ext | the s3_key's extension is enforced |
# pinned by: tests/Feature/AssetTest.php

Scenario: Replace preserves tags after the file is swapped
  Given an asset with existing tags
  When the file is replaced
  Then the same tags remain attached afterward
# pinned by: tests/Feature/AssetTest.php

Scenario: Guests and api-role users cannot replace (REQ-3)
  Given an unauthenticated request to the replace form or action
  Then it is redirected to login
  And an api-role user submitting replace gets 403 and the file is not swapped
# pinned by: tests/Feature/AssetTest.php

Scenario: storeThumbnail stores a client-generated thumbnail (REQ-5)
  Given a video asset and a base64-encoded thumbnail image
  When storeThumbnail is called
  Then thumbnail_s3_key is set and thumbnail_url reflects it
  And an existing thumbnail object is deleted before the new one is stored
  And a guest request, or one missing the thumbnail field, is rejected
# pinned by: tests/Feature/AssetTest.php

# — browser-level (see e2e-testing.md for the harness) —

Scenario: A replacement with the wrong extension never reaches the server
  Given the replace page for a .png asset
  When a file named .jpg is chosen
  Then an error is shown in the page and no upload is offered
# pinned by: tests/e2e/asset-replace.spec.js

Scenario: A staged replacement can be cleared, and cancelling replaces nothing
  Given a staged replacement file
  When Clear is clicked
  Then the drop zone returns to its empty state
  And cancelling the Replace File confirmation keeps the file staged and uploads nothing
# pinned by: tests/e2e/asset-replace.spec.js

Scenario: Confirming replaces the file and returns to the edit page
  Given a staged replacement file and a reachable bucket
  When the replacement is confirmed
  Then the edit page is shown with the replaced marker in the URL (the module redirects after ~2s)
# pinned by: tests/e2e/asset-replace.spec.js
```

## Tests & verification

- Feature: `tests/Feature/AssetTest.php`
- E2E: `tests/e2e/asset-replace.spec.js` — the successful replace needs a bucket (`requiresS3()`).
- Run: `php artisan config:clear && php artisan test`; `npm run test:e2e`

## Open questions / future

- Coverage gap: no test asserts `download()`'s `Content-Disposition` shape for a crafted filename.
