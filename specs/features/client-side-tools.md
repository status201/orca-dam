# Client-side tools

```yaml
id: client-side-tools
status: implemented
version: 1
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
- **REQ-3** — SVG/GIF/PNG uploads accept the shared batch-metadata fields
  (`metadata_tags`, `metadata_license_type`, `metadata_copyright`,
  `metadata_copyright_source`) via `HasUploadMetadataRules`, applied identically
  to the main upload flow (see `CLAUDE.md` → Key Workflows → Upload). The MathML
  and `.tex` template uploads do not accept batch metadata.
- **REQ-4** — SVG/PNG/GIF uploads accept an optional `parent_asset_id` (must
  `exists:assets,id`) so a tool-generated asset can link back to a source `.tex`
  template — the same `Asset.parent_id` self-FK used by the TikZ server tool.
- **REQ-5** — Base64 payloads (GIF, PNG) are decoded via a shared helper that
  strips an optional `data:...;base64,` prefix and rejects invalid base64 with a
  `422` before any S3 call is attempted.
- **REQ-6** — The `.tex` template upload (`uploadTexTemplate`) passes
  `process: false` to `ToolUploadService::store()` — no thumbnail, resize, or AI
  tagging for a plain-text source file.

## Technical design

### Contract / public interface

```yaml
# GIF maker — client composites frames with gif.js + sortablejs (drag-reorder)
GET  tools/gif-maker              -> tools.gif-maker
POST tools/gif-maker/upload       -> ToolsController::uploadGif (StoreGifRequest)
  content: required|string|max:15000000     # base64, up to ~15MB decoded
  width/height: nullable|integer|min:1
  parent_asset_id: nullable|integer|exists:assets,id
  + HasUploadMetadataRules (metadata_tags, metadata_license_type, metadata_copyright, metadata_copyright_source)

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

ToolUploadService::store(content, filename, mimeType, folder, attributes, process, metadata): Asset
  # shared by every tool above — see tikz-render.md for the full contract.
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

Every tool page hydrates page-scoped state from `window.__pageData` (folders,
root folder, translations) and an Alpine module registered in
`resources/js/app.js` (`tools-gif-maker.js`, `tools-latex-mathml.js`,
`tools-tikz-svg.js`, `tools-tikz-svg-fonts.js`, `tools-tikz-png.js`; TikZ Server
itself is `tools-tikz-server.js`, covered in
[tikz-render.md](tikz-render.md)). All five upload flows converge on
`ToolUploadService::store()` — the controller methods
(`uploadGif`/`uploadMathml`/`uploadTikzSvg`/`uploadTikzSvgFonts`/`uploadTikzPng`)
differ only in mime type, content decoding (base64 vs raw string), and which
`attributes`/`metadata` they forward.

The GIF maker additionally supports a **handoff**: another tool (TikZ Server) can
stash a batch of rendered frames + metadata into
`sessionStorage['orca:gif-handoff']` before navigating to the GIF maker page;
`gifMaker.init()` reads and clears that key on load so a refresh doesn't
re-hydrate stale data.

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

Scenario: Uploading an SVG creates an asset with the svg mime type
  When an editor POSTs tools/tikz-svg/upload with SVG content
  Then an asset is created with mime_type image/svg+xml
# pinned by: tests/Feature/ToolsTest.php

Scenario: SVG upload applies batch metadata (tags/license/copyright)
  When an editor POSTs tools/tikz-svg/upload with metadata_tags and metadata_license_type
  Then the created asset carries those tags (lowercased, user-attributed) and license fields
# pinned by: tests/Feature/ToolsTest.php

Scenario: SVG-with-embedded-fonts upload applies the same batch metadata rules
  When an editor POSTs tools/tikz-svg-fonts/upload with metadata fields
  Then the created asset reflects them identically to the plain SVG upload
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

Scenario: ToolUploadService forwards the metadata payload verbatim to applyUploadMetadata
  When store() is called with a metadata array (tags/license/copyright/reference_tag_ids)
  Then AssetProcessingService::applyUploadMetadata is called with exactly those values
# pinned by: tests/Unit/Services/ToolUploadServiceTest.php

Scenario: An API-role user can still reach the tools pages (auth-only, no role gate)
  Given a user with role "api"
  When they GET tools
  Then the response is 200 — tools routes only require authentication, not a specific role
# pinned by: tests/Feature/ToolsTest.php

# — browser-level (see e2e-testing.md for the harness) —

Scenario: The tools overview lists every tool card
  Given the tools page
  Then a card is present for each tool, including the deprecated tikz-svg/tikz-png entries
# pinned by: tests/e2e/tools.spec.js

Scenario: Every tools page boots its Alpine component
  Given the tools overview
  When each tool card is followed
  Then the tool's own root element renders and no page error is raised
  # (Alpine's benign "Transition was skipped" rejection is filtered)
# pinned by: tests/e2e/tools.spec.js

Scenario: Deprecated tool routes still render
  Given /tools/tikz-svg, /tools/tikz-svg-fonts and /tools/tikz-png
  Then each responds 200
# pinned by: tests/e2e/tools.spec.js
```

## Tests & verification

- Feature: `tests/Feature/ToolsTest.php` (SVG/SVG-fonts/PNG/tex-template upload
  paths, metadata application, validation) — `php artisan config:clear && php artisan test`
- Unit: `tests/Unit/Services/ToolUploadServiceTest.php`
- E2E: `tests/e2e/tools.spec.js` — each tool page loads and boots its JS without a page error.

## Open questions / future

- The GIF maker (`uploadGif`) and LaTeX→MathML (`uploadMathml`) controller
  actions have no Feature-test coverage — `ToolsTest.php` exercises the tex
  template, SVG, SVG-fonts, and PNG upload endpoints but not
  `tools/gif-maker/upload` or `tools/latex-mathml/upload`. Both share the same
  `ToolUploadService::store()` path already covered at the unit level, so risk is
  low, but a request-level test (base64 decode failure, `alt_text` = latex source
  for MathML, `parent_asset_id` linkage for GIF) would close the gap.
- The client-side rendering itself (TikZJax compilation, Temml LaTeX→MathML
  conversion, gif.js frame encoding, canvas-based SVG→PNG rasterization in
  `tools-tikz-png.js`) runs entirely in the browser and has no Pest coverage by
  construction — only the resulting upload payload is server-tested.
