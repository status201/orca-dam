# Upload Policy

```yaml
id: upload-policy
status: implemented
version: 1
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
  regardless of declared MIME type.
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
- **REQ-6** — Expensive or publicly reachable routes carry rate limiting: bulk
  download, AI tagging (`assets.ai-tag`), the TikZ server-render tool, and the
  public API surface all declare `throttle` middleware.

## Technical design

### Contract / public interface

```yaml
UploadPolicy::allowedExtensions(): array<string>      # config('uploads.allowed_extensions')
UploadPolicy::extension(string $filename): string      # lower-cased, no dot
UploadPolicy::isAllowed(string $filename): bool
UploadPolicy::isInline(string $filename): bool          # config('uploads.inline_extensions')
UploadPolicy::isSvg(string $filename): bool
AllowedUploadExtension implements ValidationRule         # validate(attribute, value, fail)
```

### Data shapes

```yaml
# config/uploads.php
allowed_extensions: [jpg, jpeg, png, gif, webp, svg, pdf, mp4, webm, mov, eps,
                      mp3, wav, ogg, m4a, aac, flac,
                      docx, xlsx, pptx, doc, xls, ppt, odt, ods, odp,
                      tex, mml, txt, md, csv, zip]
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
SVGs on write and sets `Content-Disposition` from `UploadPolicy::isInline()` when
streaming the object via `putUploadedFile()`.

### Persistence

No dedicated table — this is pure config + a validation rule. The allowlist lives
in `config/uploads.php`, not the DB, because it's a deploy-time security control,
not an admin-tunable preference (contrast with [`features/settings.md`](settings.md)).

## Scenarios (BDD)

```gherkin
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
  Given the named routes assets.bulk.download, assets.ai-tag, tools.tikz-server.render
  When their registered middleware is inspected
  Then each declares a throttle middleware
# pinned by: tests/Feature/SecurityRemediationTest.php
```

## Tests & verification

- Feature: `tests/Feature/SecurityRemediationTest.php` (extension allowlist on
  direct + chunked-init, SVG acceptance, rate-limit presence),
  `tests/Feature/AssetTest.php` (replace-path extension matching, incl. case and
  s3_key-vs-filename disagreement)
- Run: `php artisan config:clear && php artisan test`

## Open questions / future

- No dedicated Unit test isolates `App\Support\UploadPolicy` or
  `App\Rules\AllowedUploadExtension` directly — coverage today is entirely through
  the Feature-level HTTP paths above. A Unit test asserting
  `UploadPolicy::isInline()`/`isSvg()` behaviour in isolation would tighten this.
