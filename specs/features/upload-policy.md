# Upload Policy

```yaml
id: upload-policy
status: implemented
version: 5
owner: core
related:
  - architecture
  - decisions/adr-005-chunked-above-10mb
source:
  - config/uploads.php
  - app/Rules/AllowedUploadExtension.php
  - app/Support/UploadPolicy.php
  - app/Services/S3Service.php
```

## Background / Why

Every upload path (direct `POST /assets`, chunked `/api/chunked-upload/*`, replace, and the
client-side tools) must reject dangerous file types before bytes reach S3, and must store what it
accepts with a Content-Type that can't be turned into stored XSS on the asset domain.
`App\Support\UploadPolicy` is the single source of truth so validation (`AllowedUploadExtension`)
and storage decisions (`S3Service` headers) never drift apart. This spec also owns the rate
limits on the heavy routes.

## Requirements

- **REQ-1** — `config/uploads.php` `allowed_extensions` is the sole allowlist for
  what any uploader accepts; anything not on it is rejected at validation time,
  regardless of declared MIME type. It maps each extension to the Content-Type
  that extension is stored with (REQ-content-type-from-extension), so an
  extension can't be allowed without deciding how it is served.
- **REQ-2** — `App\Rules\AllowedUploadExtension` enforces the allowlist on **every**
  upload path: it accepts either an `UploadedFile` (direct/replace) or a plain
  filename string (chunked `init`, where the file bytes aren't present yet) and
  fails with `"This file type is not allowed."` if the extension isn't allowlisted.
- **REQ-3** — `UploadPolicy::isAllowed()` compares a **lower-cased** extension
  against the config list — case (`.JPG` vs `.jpg`) doesn't matter.
- **REQ-4** — SVGs are allowlisted but always sanitized before storage
  (`S3Service::sanitizeSvg()`, via `enshrined/svg-sanitize`) — they're the one
  vector format accepted, and only because sanitization strips active content.
- **REQ-5** — `config/uploads.php` `inline_extensions` decides
  `Content-Disposition`: types on that list are served inline; everything else
  (even if allowlisted for upload, e.g. `.docx`, `.zip`, `.tex`) is stored with
  `Content-Disposition: attachment` so it downloads instead of rendering —
  defense-in-depth against active markup or MIME-sniffing even for allowlisted
  types.
- **REQ-content-type-from-extension** — The Content-Type an uploaded object is stored (and so
  served) with is the one its allowlisted extension maps to (`UploadPolicy::contentType()`), on
  every upload path: direct, replace, chunked, and the client-side tools. Never the client's
  declaration and never sniffed from the bytes — the uploader controls both, and either would let
  `text/html` be served inline under an image extension. The asset row's `mime_type` records that
  same type on every path, so what the UI/API classify an asset as is what the bucket serves.
- **REQ-6** — Expensive or publicly reachable routes carry rate limiting: bulk
  download, AI tagging (`assets.ai-tag`), the TikZ server-render tool, chunked
  upload, and the public API surface all declare `throttle` middleware.
- **REQ-7** — The four heavy web routes throttle through **named** limiters registered in
  `AppServiceProvider::boot()`, so each keeps its own counter (a bare `throttle:N,1` keys on the
  user alone and would share one budget across routes). Each named limiter is keyed per user (IP
  when unauthenticated), and answers `429` with `Retry-After`.

## Technical design

### Contract / public interface

```yaml
UploadPolicy::allowedExtensions(): array<string>      # keys of config('uploads.allowed_extensions')
UploadPolicy::contentType(string $filename): string    # mapped type; application/octet-stream off the list
UploadPolicy::extension(string $filename): string      # lower-cased, no dot
UploadPolicy::isAllowed(string $filename): bool
UploadPolicy::isInline(string $filename): bool          # config('uploads.inline_extensions')
UploadPolicy::isSvg(string $filename): bool
UploadPolicy::servedHeaderMismatch(filename, ?contentType, ?contentDisposition): ?array
  # how stored headers differ from what an upload sets today; dangerous = inline + active type
  # (HTML, XHTML, XML, JavaScript, or SVG behind a non-SVG name) — maintenance-commands REQ-audit-content-types
AllowedUploadExtension implements ValidationRule         # validate(attribute, value, fail)

# Named rate limiters (AppServiceProvider::boot) — per minute, by user id ?: ip
bulk-download:  20                                   # assets.bulk.download
ai-tag:         30                                   # assets.ai-tag
tikz-render:    config('tikz.render_rate_limit')     # tools.tikz-server.render — default 60
chunked-upload: 100                                  # chunked-upload.init|chunk|complete|abort
```

### Data shapes

The extension → Content-Type map (`allowed_extensions`) and the `inline_extensions` list live in
`config/uploads.php` — the file is the contract.

### Layer touchpoints & ordering

`AllowedUploadExtension` runs **before** any controller/service work: `StoreAssetRequest` on
`POST /assets` (an `UploadedFile`), inline `$request->validate()` in
`ChunkedUploadController::initiate` (the filename string) and `AssetReplaceController::replace`
(an `UploadedFile`).

At storage time one private `S3Service::uploadHeaders()` sets `ContentType` from
`UploadPolicy::contentType()` and `Content-Disposition` from `UploadPolicy::isInline()` for both
`putUploadedFile()` (direct, replace, tools) and `createMultipartUpload()` (chunked).
`uploadFile()`/`replaceFile()` return that same type as `mime_type`, which controllers write to
`assets.mime_type`; chunked completion and `ToolUploadService` record it too.

### Persistence

No table — config + a validation rule. The allowlist lives in `config/uploads.php`, not the DB,
because it's a deploy-time security control, not an admin preference (contrast
[`settings.md`](settings.md)).

## Scenarios (BDD)

```gherkin
Scenario: An object's Content-Type comes from its extension, not the client or the bytes
  Given a chunked upload of "photo.jpg" whose init declares mime_type "text/html"
  And a direct upload of "pixel.png" whose bytes are an HTML page
  When each is stored
  Then the chunked object is created with Content-Type image/jpeg, and the asset records image/jpeg
  And the direct object is put with Content-Type image/png, and records image/png
  And a .tex replaced with LaTeX bytes records application/x-tex, not the sniffed text/x-tex
# pinned by: tests/Feature/ChunkedUploadTest.php, tests/Unit/Services/S3ServiceTest.php

Scenario: Stored headers are judged against what an upload would set today
  Then servedHeaderMismatch() is null for matching headers, ignoring a charset parameter and a sanitized .svg
  And flags text/html (or SVG behind a non-SVG name) served inline as dangerous
  And reports a mismatch served as a download, or of an inert type, as not dangerous
# pinned by: tests/Feature/SecurityRemediationTest.php

Scenario: Every allowlisted extension has a Content-Type, and anything else is octet-stream
  Then UploadPolicy::contentType() returns a type for each allowed extension, case-insensitively
  And returns application/octet-stream for an extension off the list
# pinned by: tests/Feature/SecurityRemediationTest.php

Scenario Outline: The allowlist is enforced on direct upload and chunked init
  When <file> is sent to <endpoint>
  Then the response is <status>
  Examples:
    | file          | endpoint                      | status                                  |
    | "malware.exe" | POST /assets                  | 422, error on the file field            |
    | "shell.php"   | POST /assets                  | 422                                     |
    | "vector.svg"  | POST /assets                  | 200, asset created (sanitized first)    |
    | "archive.exe" | POST /api/chunked-upload/init | 422, error on the filename field        |
# pinned by: tests/Feature/SecurityRemediationTest.php

Scenario: Replace only accepts a file whose extension matches the asset's s3_key
  Given an existing asset with s3_key extension "jpg"
  When it is replaced with a file of a different extension
  Then the request is rejected (case and filename variants: asset-replace.md)
# pinned by: tests/Feature/AssetTest.php

Scenario: Heavy routes are rate-limited with separate counters (REQ-6, REQ-7)
  Given the routes assets.bulk.download, assets.ai-tag, tools.tikz-server.render, chunked-upload.init
  Then each declares its own named throttle limiter
  And an editor who has sent 20 TikZ renders and 20 AI-tag requests this minute is not 429 on bulk download
# pinned by: tests/Feature/SecurityRemediationTest.php
```

## Tests & verification

- Feature: `tests/Feature/SecurityRemediationTest.php`, `tests/Feature/AssetTest.php` —
  `php artisan config:clear && php artisan test`

## Open questions / future

- No Unit test isolates `UploadPolicy` or `AllowedUploadExtension`; coverage is through the
  Feature-level HTTP paths only.
