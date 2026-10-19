# Upload Policy

```yaml
id: upload-policy
status: implemented
version: 4
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

Every upload path (direct `POST /assets`, chunked `/api/chunked-upload/*`, and
replace) must reject dangerous file types before bytes ever reach S3 — a `.php`
disguised with a media extension, or an outright disallowed type, is a server-side
risk once served back from a CDN. `App\Support\UploadPolicy` is the single source
of truth so validation (`AllowedUploadExtension`) and storage decisions
(`S3Service`, inline vs. attachment `Content-Disposition`) never drift apart.

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
- **REQ-content-type-from-extension** — The Content-Type an uploaded object is
  stored — and so served — with is the one its allowlisted extension maps to
  (`UploadPolicy::contentType()`), on every upload path: direct, replace,
  chunked, and the client-side tools. It is never the client's declaration and
  never sniffed from the bytes, because the uploader controls both. Either one
  let an uploader — an api token included — store `text/html` under an inline
  extension: chunked `init` took `mime_type: text/html` for `photo.jpg` at its
  word, and the direct path sniffed HTML bytes in a `.png` as `text/html`. The
  bucket or custom domain then served that object inline as a page, which is
  stored XSS on the asset domain. With the type pinned to the extension, the
  worst a mislabelled file can do is fail to render as the image it claims to be.
  The asset row's `mime_type` records that same type on every path, so what the
  UI and API classify an asset as is what the bucket serves it as: the direct
  path used to record the sniffed type, so a `.tex` was `text/x-tex` in the
  database and `application/x-tex` in S3, and a `.mml` sniffed as `text/xml` was
  not recognised as MathML.
- **REQ-6** — Expensive or publicly reachable routes carry rate limiting: bulk
  download, AI tagging (`assets.ai-tag`), the TikZ server-render tool, chunked
  upload, and the public API surface all declare `throttle` middleware.
- **REQ-7** — The four heavy web routes throttle through **named** limiters
  registered in `AppServiceProvider::boot()`, so each keeps its own counter. A bare
  `throttle:N,1` keys the counter on the user id alone — no route in the key — so
  every such route spends one shared per-user budget: twenty TikZ renders would
  lock a user out of bulk download for the rest of the minute. Each named limiter is
  keyed per user (IP when unauthenticated), and still answers `429` with
  `Retry-After`.

## Technical design

### Contract / public interface

```yaml
UploadPolicy::allowedExtensions(): array<string>      # keys of config('uploads.allowed_extensions')
UploadPolicy::contentType(string $filename): string    # the extension's mapped type — REQ-content-type-from-extension;
                                                       # application/octet-stream for anything off the list
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

```yaml
# config/uploads.php
allowed_extensions:   # extension => the Content-Type it is stored with
  jpg/jpeg: image/jpeg   png: image/png   gif: image/gif   webp: image/webp
  svg: image/svg+xml     pdf: application/pdf
  mp4: video/mp4         webm: video/webm  mov: video/quicktime
  eps: application/postscript
  mp3: audio/mpeg  wav: audio/wav  ogg: audio/ogg  m4a: audio/mp4  aac: audio/aac  flac: audio/flac
  docx/xlsx/pptx: application/vnd.openxmlformats-officedocument.*   doc/xls/ppt: application/ms*
  odt/ods/odp: application/vnd.oasis.opendocument.*
  tex: application/x-tex  mml: application/mathml+xml
  txt: text/plain  md: text/markdown  csv: text/csv  zip: application/zip
inline_extensions:  [jpg, jpeg, png, gif, webp, svg, pdf,
                      mp4, webm, mov, mp3, wav, ogg, m4a, aac, flac]
```

### Layer touchpoints & ordering

FormRequest validation (`AllowedUploadExtension` rule) runs **before** any
controller/service work on all three paths:

```
POST /assets            -> validates $file  (UploadedFile instance)
POST /chunked-upload/init -> validates $filename (string; bytes not yet present)
POST /assets/{id}/replace -> validates $file  (UploadedFile instance)
```

Storage-time decisions read the same `UploadPolicy` helpers: `S3Service` sanitizes
SVGs on write, and one private `uploadHeaders()` sets `ContentType` from
`UploadPolicy::contentType()` and `Content-Disposition` from `UploadPolicy::isInline()`
for both `putUploadedFile()` (direct, replace, tools) and `createMultipartUpload()`
(chunked). `S3Service::uploadFile()` and `replaceFile()` return that same
`UploadPolicy::contentType()` as `mime_type`, which the controllers write to
`assets.mime_type`; chunked completion records it too, and `ToolUploadService`
takes it from `uploadFile()` rather than from its caller.

### Persistence

No dedicated table — this is pure config + a validation rule. The allowlist lives
in `config/uploads.php`, not the DB, because it's a deploy-time security control,
not an admin-tunable preference (contrast with [`features/settings.md`](settings.md)).

## Scenarios (BDD)

```gherkin
Scenario: An object's Content-Type comes from its extension, not the client or the bytes (regression)
  Given a chunked upload of "photo.jpg" whose init declares mime_type "text/html"
  And a direct upload of "pixel.png" whose bytes are an HTML page
  When each is stored
  Then the chunked object is created with Content-Type image/jpeg, and the asset records image/jpeg
  And the direct object is put with Content-Type image/png, and records image/png
  And a .tex replaced with LaTeX bytes records application/x-tex, not the sniffed text/x-tex
# Regression: chunked init stored the client's mime_type and the direct path stored the
# sniffed type, so both served text/html inline from the asset domain (stored XSS). The
# direct path also kept recording the sniffed type in assets.mime_type after the header fix.
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

Scenario: A disallowed extension is rejected on direct upload
  Given a file named "malware.exe"
  When it is uploaded via POST /assets
  Then the response is 422 with a validation error on the file field
# pinned by: tests/Feature/SecurityRemediationTest.php

Scenario: A PHP file disguised with an allowlisted-looking name is still rejected
  Given a file named "shell.php"
  When it is uploaded via POST /assets
  Then the response is 422
# pinned by: tests/Feature/SecurityRemediationTest.php

Scenario: An allowlisted SVG is accepted (and sanitized before storage)
  Given a file named "vector.svg"
  When it is uploaded via POST /assets
  Then the response is 200 and the asset is created
# pinned by: tests/Feature/SecurityRemediationTest.php

Scenario: The chunked-upload init step enforces the same allowlist
  Given a chunked upload initiated for "archive.exe"
  When POST /api/chunked-upload/init is called
  Then the response is 422 with a validation error on the filename field
# pinned by: tests/Feature/SecurityRemediationTest.php

Scenario: Replace only accepts a file with a matching extension
  Given an existing asset with s3_key extension "jpg"
  When it is replaced with a file of a different extension
  Then the replace request is rejected
# pinned by: tests/Feature/AssetTest.php

Scenario: Replace extension matching is case-insensitive and keyed off s3_key, not filename
  Given an existing asset
  When it is replaced with a file whose extension differs only in case, or whose filename disagrees with the s3_key extension
  Then the appropriate accept/reject behaviour follows the s3_key's extension
# pinned by: tests/Feature/AssetTest.php

Scenario: Heavy and public routes are rate-limited
  Given the named routes assets.bulk.download, assets.ai-tag, tools.tikz-server.render, chunked-upload.init
  When their registered middleware is inspected
  Then each declares its own named throttle limiter
# pinned by: tests/Feature/SecurityRemediationTest.php

Scenario: Throttled heavy routes keep separate counters (REQ-7)
  Given an editor who has sent 20 TikZ render and 20 AI-tag requests this minute
  When they POST to assets.bulk.download (limit 20)
  Then the response is not 429
# pinned by: tests/Feature/SecurityRemediationTest.php
```

## Tests & verification

- Feature: `tests/Feature/SecurityRemediationTest.php` (extension allowlist on
  direct + chunked-init, SVG acceptance, rate-limit presence and per-route counters),
  `tests/Feature/AssetTest.php` (replace-path extension matching, incl. case and
  s3_key-vs-filename disagreement)
- Run: `php artisan config:clear && php artisan test`

## Open questions / future

- No dedicated Unit test isolates `App\Support\UploadPolicy` or
  `App\Rules\AllowedUploadExtension` directly — coverage today is entirely through
  the Feature-level HTTP paths above. A Unit test asserting
  `UploadPolicy::isInline()`/`isSvg()` behaviour in isolation would tighten this.
