# Client-side tools

```yaml
id: client-side-tools
status: implemented
version: 8
owner: core
related:
  - architecture
  - tikz-render
  - upload-policy
  - tag-input
source:
  - app/Http/Controllers/ToolsController.php
  - app/Services/ToolUploadService.php
  - app/Http/Requests/Tools/StoreGifRequest.php
  - app/Http/Requests/Tools/StoreMathmlRequest.php
  - resources/js/alpine/tools-gif-maker.js
  - resources/js/alpine/tools-latex-mathml.js
  - resources/js/alpine/tools-tikz-svg.js
  - resources/js/alpine/tools-tikz-svg-fonts.js
  - resources/js/alpine/tools-tikz-png.js
  - resources/js/alpine/tikz-results.js
```

## Background / Why

`/tools` bundles several small authoring utilities that render entirely in the
browser — no server-side TeX Live needed — then hand the finished output to
`ToolUploadService` to land as an Asset. This is the low-friction counterpart to
[tikz-render.md](tikz-render.md)'s real TeX Live pipeline: client-side TikZ uses
the third-party TikZJax WASM/JS engine (`tikzjax.com`), LaTeX→MathML uses Temml,
and the GIF maker composites frames with `gif.js` — none of it requires a
backend render step, only a backend *persist* step.

## Requirements

- **REQ-1** — Every tool follows the same persist contract: render/compose fully
  client-side, then POST the finished content (raw text for SVG/MathML/.tex,
  base64 for PNG/GIF) to a per-tool upload endpoint that calls
  `ToolUploadService::store()`.
- **REQ-2** — All tool upload endpoints extend `ToolUploadRequest`
  (`authorize()`: `$user->can('create', Asset::class)`) and share `filename`
  (required, max:255), `folder` (nullable, max:255), and `caption` (nullable,
  max:1000) rules; each subclass adds only its `content` size cap and any
  tool-specific fields via `extraRules()`.
- **REQ-3** — SVG/GIF/PNG uploads accept the shared batch-metadata fields via
  `HasUploadMetadataRules`, applied identically to the main upload flow
  ([`asset-upload.md`](asset-upload.md)). The field list is the trait's, never a copy (the tool
  views include the same `partials/upload-metadata.blade.php`). MathML and `.tex` template
  uploads do not accept batch metadata.
- **REQ-4** — SVG/PNG/GIF uploads accept an optional `parent_asset_id` (must
  `exists:assets,id`) so a tool-generated asset can link back to a source `.tex`
  template — the same `Asset.parent_id` self-FK used by the TikZ server tool.
- **REQ-5** — Base64 payloads (GIF, PNG) are decoded via a shared helper that
  strips an optional `data:...;base64,` prefix and rejects invalid base64 with a
  `422` before any S3 call is attempted.
- **REQ-6** — The `.tex` template upload (`uploadTexTemplate`) passes
  `process: false` to `ToolUploadService::store()` — no thumbnail, resize, or AI
  tagging for a plain-text source file.

- **REQ-7** — **The three TikZJax `message` handlers accept messages only from their own
  render iframe.** `tools-tikz-svg`, `tools-tikz-svg-fonts` and `tools-tikz-png` inject render
  output into the page (`x-html` / `<img src>`), so each handler rejects the event unless
  **both** hold: (1) `event.source === document.getElementById(<its iframe id>).contentWindow`
  — the load-bearing check; (2) `event.origin` is `window.location.origin` or `"null"` (the
  `tikz-svg` iframe is sandboxed without same-origin, the other two inherit the app's origin).
  The guard is inline in each handler, not a helper. Why: any window holding a reference (an
  opener, a framing page per [iframe-embedding.md](iframe-embedding.md)) can `postMessage`, and
  unchecked `event.data` is a DOM-XSS sink.

- **REQ-tool-upload-error-audience** — A failed tool upload answers
  `500 {"error": "Upload failed. <detail>"}`, built by `Controller::clientError()` like every
  other role-aware error ([error-handling.md](error-handling.md) REQ-9): an api-role caller
  gets `"Upload failed."` with no exception text, and a database error shows the driver's
  sentence, never the SQL with its bindings.

## Technical design

### Contract / public interface

```yaml
# GIF maker — client composites frames with gif.js + sortablejs (drag-reorder)
GET  tools/gif-maker              -> tools.gif-maker
POST tools/gif-maker/upload       -> ToolsController::uploadGif (StoreGifRequest)
  content: required|string|max:15000000     # base64, up to ~15MB decoded
  width/height: nullable|integer|min:1
  parent_asset_id: nullable|integer|exists:assets,id
  + HasUploadMetadataRules (metadata_tags, metadata_reference_tag_ids, metadata_license_type,
                            metadata_copyright, metadata_copyright_source, metadata_date_obtained)

# LaTeX -> MathML (Temml, client-side; no image render — just markup transform)
GET  tools/latex-mathml           -> tools.latex-mathml
POST tools/latex-mathml/upload    -> ToolsController::uploadMathml (StoreMathmlRequest)
  content: required|string|max:1000000       # the MathML markup
  latex: nullable|string|max:10000            # stored as alt_text (source LaTeX)
  # no metadata rules, no parent_asset_id — mathml is a leaf artifact

# Client-side TikZ (TikZJax, tikzjax.com WASM/JS engine — no TeX Live needed)
GET  tools/tikz-svg               -> tools.tikz-svg           (SVG only, dvisvgm-style fonts)
POST tools/tikz-svg/upload        -> ToolsController::uploadTikzSvg   (StoreTikzSvgRequest)
GET  tools/tikz-svg-fonts         -> tools.tikz-svg-fonts     (SVG with embedded fonts)
POST tools/tikz-svg-fonts/upload  -> ToolsController::uploadTikzSvgFonts (StoreTikzSvgFontsRequest, same rules as tikz-svg)
GET  tools/tikz-png               -> tools.tikz-png           (client rasterizes the TikZJax SVG to PNG via <canvas>)
POST tools/tikz-png/upload        -> ToolsController::uploadTikzPng  (StoreTikzPngRequest)
GET  tools/bakoma-font/{name}     -> ToolsController::bakomaFont     (proxies+caches BaKoMa TTFs used by the svg-fonts variant)

ToolUploadService::store(content, filename, folder, attributes=[], process=true, metadata=null): Asset
  # shared by every tool upload, incl. tikz-render.md's. tempnam() -> UploadedFile ->
  # S3Service::uploadFile() -> Asset::create() with the extension's type
  # (upload-policy REQ-content-type-from-extension)
  # -> (if $process) AssetProcessingService::processImageAsset(dispatchAiTagging: true)
  # -> (if $metadata) AssetProcessingService::applyUploadMetadata(...)
  # temp file always unlinked, even on exception

resources/js/alpine/tikz-results.js:   # shared by tikz-svg, tikz-svg-fonts and tikz-png
  TIKZ_EXAMPLES                          # the example snippets
  tikzResults({ uploadPayload }): object # spread into the tool's x-data: init, parseSnippets,
                                         # loadExample, clearCode, selectAll, deselectAll,
                                         # uploadSelected; uploadPayload(result) returns the
                                         # tool-specific body fields (content, width/height)
```

### Data shapes

```yaml
# uploadGif / uploadTikzPng: base64 content, optionally prefixed "data:<mime>;base64,"
# decodeBase64Content() strips the prefix, then base64_decode(..., strict: true);
# a decode failure returns 422 {"error": "Invalid base64 data"} before any S3 call.

# MathML upload persists:
Asset:
  mime_type: application/mathml+xml
  alt_text: <the source LaTeX, if provided>
  caption: <optional>
  # NOT processed (process defaults true in store(), but MathML is not an image —
  # processImageAsset() no-ops for non-image/PDF mime types)
```

### Layer touchpoints & ordering

Every tool page hydrates from `window.__pageData` (folders, root folder, translations) and an
Alpine module registered in `resources/js/app.js` (TikZ Server's `tools-tikz-server.js` is in
[tikz-render.md](tikz-render.md)). All six upload actions (`uploadGif`, `uploadMathml`,
`uploadTikzSvg`, `uploadTikzSvgFonts`, `uploadTikzPng`, `uploadTexTemplate`) converge on
`ToolUploadService::store()` and differ only in mime type, decoding (base64 vs raw) and which
`attributes`/`metadata` they forward; a failure maps through `Controller::clientError()`.

GIF maker **handoff**: another tool (TikZ Server) stashes rendered frames + metadata in
`sessionStorage['orca:gif-handoff']` before navigating; `gifMaker.init()` reads and clears it.

### Persistence

No tool-specific DB tables — all output lands as an `Asset` row through the
standard S3 + Asset pipeline. `parent_asset_id` (SVG/PNG/GIF only) is the only
tool-specific persisted linkage, reusing `Asset.parent_id`.

## Visual aids

Tools/libraries pulled in client-side: `gif.js` 0.2 (GIF encoding, GIF maker +
TikZ Server's animated-GIF variant), `sortablejs` 1.15 (drag-reorder frames),
Temml (LaTeX→MathML, bundled with the latex-mathml view), TikZJax
(`https://tikzjax.com/v1/tikzjax.js` + `fonts.css`, third-party WASM/JS TeX
engine for the three client-side TikZ tools — distinct from the server-side TeX
Live pipeline in [tikz-render.md](tikz-render.md)).

## Scenarios (BDD)

```gherkin
Scenario: Uploading selected diagrams posts each one with a live CSRF token
  Given a TikZ tool page (svg, svg-fonts or png) holding two rendered results
  When the selected results are uploaded
  Then each is posted once with its content, name and folder, and the page's current CSRF token
  And one translated success toast reports the count
# pinned by: tests/e2e/tools.spec.js

Scenario: A failed tool upload hides the exception from an api-role caller
  Given ToolUploadService throws "secret bucket detail"
  When an api-role user uploads through a tool endpoint
  Then the response is 500 with error "Upload failed." and no exception text
  But an editor gets "Upload failed. secret bucket detail"
# pinned by: tests/Feature/ToolsTest.php

Scenario: An unauthenticated request cannot upload a tex template
  When a guest POSTs tools/tikz-server/templates/upload
  Then the response is 401
# pinned by: tests/Feature/ToolsTest.php

Scenario: Uploading a tex template requires content and filename
  When an editor POSTs tools/tikz-server/templates/upload with no body
  Then content and filename are reported as required
# pinned by: tests/Feature/ToolsTest.php

Scenario: Uploading a tex template creates an asset without image processing
  When an editor POSTs a valid tex template
  Then an asset row is created with mime_type application/x-tex
# pinned by: tests/Feature/ToolsTest.php

Scenario: SVG uploads create svg assets and apply batch metadata
  When an editor POSTs tools/tikz-svg/upload, and tools/tikz-svg-fonts/upload, with metadata fields
  Then each asset has mime_type image/svg+xml
  And carries those tags (lowercased, user-attributed) and license fields
# pinned by: tests/Feature/ToolsTest.php

Scenario: PNG upload rejects invalid base64 before any S3 call
  When an editor POSTs tools/tikz-png/upload with non-base64 content
  Then the response is 422 with error "Invalid base64 data"
# pinned by: tests/Feature/ToolsTest.php

Scenario: PNG upload persists width/height and batch metadata
  When an editor POSTs valid base64 PNG content with width, height, and metadata fields
  Then the asset row stores the dimensions and the metadata is applied
# pinned by: tests/Feature/ToolsTest.php

Scenario: An invalid metadata_license_type is rejected on any tool upload
  When an editor POSTs metadata_license_type "totally-fake-license" to tools/tikz-svg/upload
  Then the response is 422 with a validation error on that field
# pinned by: tests/Feature/ToolsTest.php

Scenario: ToolUploadService always removes its temp file
  When store() completes (success or failure)
  Then the temporary file created for the upload no longer exists
# pinned by: tests/Unit/Services/ToolUploadServiceTest.php

Scenario: ToolUploadService forwards metadata verbatim, and skips both steps when process is false
  When store() is called with a metadata array
  Then AssetProcessingService::applyUploadMetadata is called with exactly those values
  And with process: false and no metadata, neither processImageAsset nor applyUploadMetadata is called
# pinned by: tests/Unit/Services/ToolUploadServiceTest.php

Scenario: An API-role user can still reach the tools pages (auth-only, no role gate)
  Given a user with role "api"
  When they GET tools
  Then the response is 200 — tools routes only require authentication, not a specific role
# pinned by: tests/Feature/ToolsTest.php

# — browser-level (see e2e-testing.md for the harness) —

Scenario: The tools overview lists every tool and every tool page boots
  Given the tools overview
  Then a card is present for each tool, including the deprecated tikz-svg/tikz-png entries
  And following each card renders the tool's root element with no page error
    (Alpine's benign "Transition was skipped" rejection is filtered; rendering is not driven)
# pinned by: tests/e2e/tools.spec.js

Scenario: A forged render message from outside the iframe is ignored (REQ-7)
  Given a TikZJax tool page
  When the page itself posts a tikz-svgs / tikz-pngs message carrying markup with an
    onerror handler
  Then no result is rendered
  And the injected handler never executes
# pinned by: tests/e2e/tools.spec.js
```

## Tests & verification

- Feature: `tests/Feature/ToolsTest.php` (SVG/SVG-fonts/PNG/tex-template upload
  paths, metadata application, validation) — `php artisan config:clear && php artisan test`
- Unit: `tests/Unit/Services/ToolUploadServiceTest.php`
- E2E: `tests/e2e/tools.spec.js` — all six tool pages (three current, three
  deprecated) load and boot their JS without a page error.

## Open questions / future

- `tools/gif-maker/upload` and `tools/latex-mathml/upload` have no Feature test (base64
  failure, MathML `alt_text`, GIF `parent_asset_id`).
- Client-side rendering itself (TikZJax, Temml, gif.js, canvas rasterization) has no Pest
  coverage by construction.
