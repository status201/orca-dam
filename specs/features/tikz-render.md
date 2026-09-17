# TikZ server render

```yaml
id: tikz-render
status: implemented
version: 4
owner: core
related:
  - architecture
  - client-side-tools
  - upload-policy
source:
  - app/Services/TikzCompilerService.php
  - app/Services/ToolUploadService.php
  - app/Http/Controllers/ToolsController.php
  - app/Http/Requests/Tools/StoreTikzSvgRequest.php
  - app/Http/Requests/Tools/StoreTikzPngRequest.php
  - app/Http/Requests/Tools/StoreTikzSvgFontsRequest.php
  - app/Http/Requests/Tools/StoreTexTemplateRequest.php
  - config/tikz.php
  - resources/js/alpine/tools-tikz-server.js
  - resources/views/assets/show.blade.php
```

## Background / Why

The TikZ Server Render tool (`/tools/tikz-server`) compiles user-submitted TikZ
snippets through a real TeX Live pipeline (LaTeX → DVI → dvisvgm → SVG/PNG) so
editors get pixel-accurate diagrams instead of the JS-approximated client-side
TikZ renderer (see [client-side-tools.md](client-side-tools.md)). Because this
shells out to LaTeX — historically a shell-escape and arbitrary-file-read vector —
`TikzCompilerService` is deliberately paranoid: no shell escape, no file
inclusion of any kind, and every render runs in a throwaway temp directory.

## Requirements

- **REQ-1** — LaTeX is invoked with `--no-shell-escape` and `openin_any=p` /
  `openout_any=p` (paranoid mode) so no `\write18` or arbitrary file open can
  reach the filesystem even if input sanitization is bypassed.
- **REQ-2** — `TikzCompilerService::sanitizeInput()` rejects TikZ code (and,
  separately, a custom preamble) containing any of `DANGEROUS_PATTERNS` — shell
  escape (`\write18`, `\immediate\write`) and every file-inclusion primitive
  (`\input`, `\include`, `\InputIfFileExists`, `\lstinputlisting`, `\subfile`,
  `\subimport`, `\import`, `\openin`, `\openout`, `\newwrite`, `\closeout`,
  `\closein`, `\read`) — as defense-in-depth on top of REQ-1, since renders run in
  an isolated temp dir with no sibling files to include anyway.
  `\includegraphics` is intentionally exempt (word-boundary regex, not a `\include`
  substring match).
  Ordinary users only reach `TikzCompilerService::compile()` through
  `ToolsController::renderTikzServer`; the admin-configurable
  `tikz_color_package` setting is sanitized through the same check before being
  written as a `.sty` file.
- **REQ-3** — Every render happens in a fresh `sys_get_temp_dir()/orca_tikz_{uniqid}`
  directory that is unconditionally removed in a `finally` block, even on failure.
- **REQ-4** — `compile()` can produce up to 4 variants per render — `svg_standard`
  (dvisvgm default, fonts as path data), `svg_embedded` (`--font-format=woff2`),
  `svg_paths` (`--no-fonts`, no font dependency at all), and `png` (converted from
  the paths SVG via `rsvg-convert` or `inkscape` fallback) — selectable via the
  `variants` request field; an empty selection means "all".
  A forced canvas (`force_canvas` + width/height 0.5–100cm) injects
  `\useasboundingbox` (and optionally `\clip`) into the first `tikzpicture` so a
  batch of renders shares one fixed coordinate frame — used by the animated-GIF
  workflow in the tikz-server Alpine module.
- **REQ-5** — SVG element IDs are made unique per render (`uniquifySvgIds()`,
  random 4-hex prefix) so multiple inline SVGs on the same results page don't
  collide on shared glyph-definition IDs and silently reuse the wrong glyph.
- **REQ-6** — `ToolsController::renderTikzServer` is rate-limited through the
  named `tikz-render` limiter — `config('tikz.render_rate_limit')` requests per
  minute per user (`TIKZ_RENDER_RATE_LIMIT`, default 60), on its own counter (see
  [upload-policy.md](upload-policy.md) REQ-7) — and returns `503` up front via
  `TikzCompilerService::isAvailable()` (checks both `latex` and `dvisvgm` binaries
  on `PATH`) when TeX Live isn't installed, rather than attempting a compile that
  can only fail.
- **REQ-7** — Render results are only persisted as `Asset` rows when explicitly
  uploaded (`uploadTikzSvg`/`uploadTikzSvgFonts`/`uploadTikzPng`), via
  `ToolUploadService::store()`, which also accepts `parent_asset_id` to link a
  rendered asset back to the source `.tex` template it was rendered from.
- **REQ-8** — The tikz-server page renders a batch as one request per
  `tikzpicture`, so a large batch can outrun REQ-6's limit. A `429` pauses the
  batch instead of ending it: the page reads `Retry-After` (60s if absent), shows a
  per-second "resuming in Ns" countdown, and then retries **the same** snippet.
  Three consecutive `429`s end the batch with a rate-limit message. A Stop button,
  shown while rendering, ends the batch after the current request or countdown and
  keeps the results so far. No non-compile failure may surface as
  "Compilation failed": the response's `error`, then `message`, is shown first.
- **REQ-9** — A `.tex` asset opens in the tool in one click. Asset Show renders an
  **Open in TikZ Tool** link as the first entry of its Actions card whenever
  `Asset::isTex()` is true (shown regardless of TeX Live availability — the tool
  page carries its own "compiler unavailable" notice), pointing at
  `tools/tikz-server?template={asset id}` in the same tab. On init, the tikz-server
  page loads a numeric `template` query parameter through the same
  `loadFromOrca()` path as the template browser — so the parent link (REQ-7), the
  modified-snapshot and the success/error toast all behave identically — and then
  strips the parameter with `history.replaceState`, so it is a one-shot action
  rather than state a later reload replays over the user's edits.
  `loadTexTemplate` accepts an asset when `isTex()` is true **or** its filename
  ends in `.tex`/`.txt`: the button is gated on `isTex()` (mime type or `s3_key`
  extension), and a `.tex` asset whose display filename was edited must not show a
  button that then answers 422.

## Technical design

### Contract / public interface

```yaml
TikzCompilerService:
  isAvailable(): bool
  compile(tikzCode, pngDpi=300, borderPt=5, fontPackage='arev', preamble='', enabledVariants=[], extraLibraries='', forceCanvas=false, canvasWidthCm=0, canvasHeightCm=0, clipToCanvas=false): array{success, variants?, log?, error?}
  sanitizeInput(string $code): ?string             # null = rejected
  injectCanvas(string $code, float $w, float $h, bool $clip): string
  buildTexDocument(tikzSnippet, borderPt=5, fontPackage='arev', preamble='', extraLibraries=''): string
  fontPackages(): array                            # static — key => label, for the UI dropdown
  fontLatex(string $key): string                    # static — LaTeX preamble line(s) for a font key

ToolUploadService:
  store(content, filename, mimeType, folder, attributes=[], process=true, metadata=null): Asset
    # tempnam() -> UploadedFile -> S3Service::uploadFile() -> Asset::create()
    # -> (if $process) AssetProcessingService::processImageAsset(dispatchAiTagging: true)
    # -> (if $metadata) AssetProcessingService::applyUploadMetadata(...)
    # temp file always unlinked, even on exception

ToolsController (TikZ-relevant):
  tikzServer()                    # GET  tools/tikz-server[?template={id}] — view: folders, rootFolder, compilerAvailable, fontPackages, colorPackage(Name); `template` is read client-side (REQ-9)
  renderTikzServer(Request)       # POST tools/tikz-server/render     — throttle:tikz-render (60/min default)
  uploadTikzSvg(StoreTikzSvgRequest)             # POST tools/tikz-svg/upload
  uploadTikzSvgFonts(StoreTikzSvgFontsRequest)   # POST tools/tikz-svg-fonts/upload
  uploadTikzPng(StoreTikzPngRequest)             # POST tools/tikz-png/upload  — content is base64 (data: URL prefix stripped)
  searchTexTemplates(Request)     # GET  tools/tikz-server/templates          — Asset::search() over filename/s3_key LIKE '%.tex'
  loadTexTemplate(Asset)          # GET  tools/tikz-server/templates/{asset}  — 422 unless isTex() or filename ends .tex/.txt
  uploadTexTemplate(StoreTexTemplateRequest) # POST tools/tikz-server/templates/upload — process:false (no thumbnail/resize/AI)
  bakomaFont(string $name)        # GET  tools/bakoma-font/{name}     — proxies+caches (24h) BaKoMa TTFs from tikzjax.com, name whitelisted to [a-z0-9]+
```

### Data shapes

```yaml
# renderTikzServer request validation
tikz_code: required|string|max:50000
png_dpi: nullable|integer|min:72|max:600
border_pt: nullable|integer|min:0|max:50
font_package: nullable|string|max:30
preamble: nullable|string|max:50000
variants: {svg_standard, svg_embedded, svg_paths, png}: nullable|boolean
extra_libraries: nullable|string|max:500
force_canvas: nullable|boolean
canvas_width_cm / canvas_height_cm: nullable|numeric|min:0.5|max:100
clip_to_canvas: nullable|boolean

# config/tikz.php
render_rate_limit: int   # TIKZ_RENDER_RATE_LIMIT, default 60 — requests/minute/user for tikz-render

# compile() variant entry
type: svg_standard|svg_embedded|svg_paths|png
content: string          # SVG markup, or base64 for png
size: int
mime: image/svg+xml|image/png
width/height: int|null   # png only, from getimagesize()

# Font packages (TikzCompilerService::FONT_PACKAGES) — 17 total
sans_serif: [arev, cmbright, helvet, avant, opensans, firasans, sourcesanspro, roboto, cabin, iwona, kurier, raleway]
serif: [lmodern, palatino, charter, bookman]
default: [default]  # Computer Modern, empty preamble line

# Extra packages (need \usepackage{} not \usetikzlibrary{})
[pgfplots, circuitikz, tikz-cd, tikz-3dplot, pgf-pie, forest, chemfig, tikz-timing]

# Default TikZ libraries always included
[calc, arrows.meta, positioning, decorations.pathreplacing, decorations.markings,
 patterns, shapes.geometric, angles, quotes, intersections, fit, backgrounds, matrix, trees]
```

### Layer touchpoints & ordering

`ToolsController::renderTikzServer` validates → checks `isAvailable()` (503 early
exit) → calls `TikzCompilerService::compile()`, which sanitizes input+preamble
(and, if configured, the admin `tikz_color_package` setting) before writing
anything to disk, builds the `.tex` document, runs `latex` (DVI output,
paranoid env), then `dvisvgm` per requested variant, then optionally converts to
PNG — all inside one temp dir cleaned up in a `finally`. A *separate* upload step
(`uploadTikzSvg`/`Fonts`/`Png`) is what actually persists a chosen variant as an
`Asset`, via `ToolUploadService::store()` → `S3Service::uploadFile()` →
`AssetProcessingService::processImageAsset()` (thumbnail/resize/AI dispatch,
skipped for `.tex` templates via `process: false`) →
`AssetProcessingService::applyUploadMetadata()` when batch metadata
(`metadata_tags`, `metadata_reference_tag_ids`, `metadata_license_type`,
`metadata_copyright`, `metadata_copyright_source`, `metadata_date_obtained`) is
present, using the shared `HasUploadMetadataRules` trait for validation — the field
list is the trait's, so it tracks [`asset-upload.md`](asset-upload.md) REQ-5 rather
than being maintained here.

### Persistence

Compile results are ephemeral (temp dir, deleted after the response). Only an
explicit upload creates a DB row: `assets.parent_id` links a rendered SVG/PNG back
to the `.tex` template asset it was rendered from, surfaced as a "Relations" card
on Asset Show (see `CLAUDE.md` → Asset model). The `bakomaFont` proxy caches raw
TTF bytes (base64) under `bakoma_font_{name}` for 24h — the only cross-request
cache this feature uses.

## Visual aids

```
POST /tools/tikz-server/render
  -> validate -> isAvailable()? -> TikzCompilerService::compile()
       sanitizeInput(code) ─┐
       sanitizeInput(preamble)? ┤ any match on DANGEROUS_PATTERNS -> {success:false}
       sanitizeInput(color pkg setting)? ┘
       -> buildTexDocument() -> latex --no-shell-escape --interaction=nonstopmode (paranoid openin/openout)
       -> input.dvi produced? no -> {success:false, log}
       -> per requested variant: dvisvgm --bbox=... [--font-format=woff2 | --no-fonts]
       -> uniquifySvgIds() per SVG
       -> png requested? convert paths-svg via rsvg-convert (or inkscape fallback)
       -> cleanup(tmpDir) in finally
  <- {variants: [...], log}

tikzServer.render()  — one POST per tikzpicture, sequential
  429 -> wait Retry-After (countdown) -> retry same snippet   (3 in a row -> stop, rate-limit message)
  Stop -> finish current request/countdown -> keep results so far

POST /tools/tikz-svg/upload (separate call, user picks a variant to keep)
  -> ToolUploadService::store() -> S3 -> Asset (parent_id = source .tex, if any)
```

Tools: TeX Live (`latex`, DVI mode), `dvisvgm` (DVI→SVG, WOFF2 embedding,
`--no-fonts` path mode), `rsvg-convert`/`inkscape` (SVG→PNG fallback chain).

## Scenarios (BDD)

```gherkin
Scenario: Dangerous shell-escape input is rejected before any process runs
  When sanitizeInput is called with "\write18{rm -rf /}"
  Then it returns null
# pinned by: tests/Unit/TikzCompilerServiceTest.php

Scenario: File-inclusion primitives are all rejected, but includegraphics is allowed
  When sanitizeInput is called with \input, \include, \InputIfFileExists,
    \lstinputlisting, \subfile, \subimport, \import, \openin, \openout, \newwrite,
    \closeout, \closein, \read
  Then each returns null
  But sanitizeInput("\includegraphics{image}") is not null
# pinned by: tests/Unit/TikzCompilerServiceTest.php

Scenario: A dangerous preamble is rejected even when the TikZ code is safe
  When compile() is called with safe code but a preamble containing \openin
  Then success is false and the error mentions "dangerous"
# pinned by: tests/Unit/TikzCompilerServiceTest.php

Scenario: A dangerous admin-configured color package blocks compilation
  Given the tikz_color_package setting contains \write18
  When compile() runs with otherwise-safe input
  Then success is false and the error mentions both "Color package" and "dangerous"
# pinned by: tests/Unit/TikzCompilerServiceTest.php

Scenario: buildTexDocument wraps a snippet in a standalone document with default libraries and font
  When buildTexDocument is called with a plain tikzpicture snippet
  Then the output contains \documentclass[tikz]{standalone}, \usetikzlibrary{...calc, arrows.meta...}
# pinned by: tests/Unit/TikzCompilerServiceTest.php

Scenario: A full document (already containing \documentclass) passes through unchanged
  When buildTexDocument is called on a string containing \documentclass
  Then the result equals the input verbatim
# pinned by: tests/Unit/TikzCompilerServiceTest.php

Scenario: A custom preamble suppresses the default font/library block
  When buildTexDocument is called with a non-empty preamble
  Then the output contains the preamble but not \usetikzlibrary
# pinned by: tests/Unit/TikzCompilerServiceTest.php

Scenario: Invalid extra library names are dropped, valid ones pass through
  When buildTexDocument is called with extraLibraries "valid,../bad,ok.lib"
  Then the output contains "valid" and "ok.lib" but not "../bad"
# pinned by: tests/Unit/TikzCompilerServiceTest.php

Scenario: injectCanvas adds a fixed bounding box (and optional clip) to only the first tikzpicture
  Given TikZ code with two tikzpicture blocks
  When injectCanvas is called with width/height/clip
  Then only one \useasboundingbox is injected, preserving any existing options on that block
# pinned by: tests/Unit/TikzCompilerServiceTest.php

Scenario: uniquifySvgIds prevents ID collisions across multiple inline SVGs
  Given SVG markup with id attributes and #id references (double- or single-quoted)
  When uniquifySvgIds is called twice on the same input
  Then all ids/references are consistently prefixed and the two calls produce different prefixes
# pinned by: tests/Unit/TikzCompilerServiceTest.php

Scenario: The render endpoint returns 503 when TeX Live is unavailable
  Given TikzCompilerService::isAvailable() returns false
  When an editor POSTs tools/tikz-server/render
  Then the response is 503
# pinned by: tests/Feature/ToolsTest.php

Scenario: The render endpoint answers 429 with Retry-After once the configured limit is spent (REQ-6)
  Given tikz.render_rate_limit is 2
  When an editor POSTs tools/tikz-server/render three times within a minute
  Then the third response is 429 and carries a Retry-After header
# pinned by: tests/Feature/SecurityRemediationTest.php

Scenario: The render limit defaults to 60 per minute (REQ-6)
  Given TIKZ_RENDER_RATE_LIMIT is unset
  Then config('tikz.render_rate_limit') is 60
# pinned by: tests/Feature/SecurityRemediationTest.php

Scenario: A rate-limited batch pauses, then retries the same snippet (REQ-8)
  Given a two-snippet batch whose second render request is answered 429 with Retry-After: 1
  When the Render button is clicked
  Then the rate-limit countdown shows, the second snippet is sent again, and both results render with no error
# pinned by: tests/e2e/tools.spec.js

Scenario: Stop during a rate-limit pause keeps the earlier results (REQ-8)
  Given the first snippet renders and the next request is answered 429 with Retry-After: 30
  When Stop is clicked during the countdown
  Then the batch ends within seconds with one result and no error
# pinned by: tests/e2e/tools.spec.js

Scenario: Three consecutive 429s end the batch with the rate-limit message (REQ-8)
  Given every render request is answered 429
  When the Render button is clicked
  Then after the third request the error shows the rate-limit message, not "Compilation failed"
# pinned by: tests/e2e/tools.spec.js

Scenario: The render endpoint validates dpi/border ranges
  When an editor POSTs png_dpi=9999 and border_pt=100
  Then both fields report validation errors
# pinned by: tests/Feature/ToolsTest.php

Scenario: A successful compile returns the requested variants and log
  Given the compiler is mocked to succeed
  When an editor POSTs valid tikz_code
  Then the response contains a variants array (type/content/size/mime) and a log
# pinned by: tests/Feature/ToolsTest.php

Scenario: Uploading a rendered SVG applies batch metadata (tags/license/copyright)
  When an editor POSTs tools/tikz-svg/upload with metadata_tags/metadata_license_type/etc.
  Then the created asset has those attributes and lowercased user-attributed tags
# pinned by: tests/Feature/ToolsTest.php

Scenario: Uploading a rendered PNG persists provided width/height
  When an editor POSTs tools/tikz-png/upload with width and height
  Then the asset row stores those dimensions
# pinned by: tests/Feature/ToolsTest.php

Scenario: An invalid metadata_license_type is rejected on tool upload
  When an editor POSTs metadata_license_type "totally-fake-license"
  Then the response is 422 with a validation error on that field
# pinned by: tests/Feature/ToolsTest.php

Scenario: Loading a .tex template rejects non-.tex/.txt assets
  Given an asset named "image.jpg"
  When loadTexTemplate is requested for it
  Then the response is 422
# pinned by: tests/Feature/ToolsTest.php

Scenario: A .tex asset whose filename lost its extension still loads (REQ-9)
  Given an asset with s3_key "assets/renamed.tex" and filename "renamed"
  When loadTexTemplate is requested for it
  Then the response is 200 with the file content
# pinned by: tests/Feature/ToolsTest.php

Scenario: Asset Show offers "Open in TikZ Tool" only for .tex assets (REQ-9)
  Given a .tex asset and an image asset
  When each detail page is rendered
  Then the .tex page links to tools/tikz-server?template={its id}
  And the image page has no such link
# pinned by: tests/Feature/AssetTest.php

Scenario: A ?template= link loads that template and cleans the URL (REQ-9)
  Given the template-load endpoint answers for asset 4242
  When /tools/tikz-server?template=4242 is opened
  Then the editor holds that template's content
  And the address bar no longer carries the template parameter
# pinned by: tests/e2e/tools.spec.js

Scenario: ToolUploadService always deletes its temp file, even on success
  When store() is called and completes normally
  Then the underlying temp file no longer exists on disk
# pinned by: tests/Unit/Services/ToolUploadServiceTest.php

Scenario: ToolUploadService skips processing and metadata when process is false
  When store() is called with process: false and no metadata
  Then AssetProcessingService::processImageAsset and applyUploadMetadata are never called
# pinned by: tests/Unit/Services/ToolUploadServiceTest.php
```

## Tests & verification

- Unit: `tests/Unit/TikzCompilerServiceTest.php` (sanitization, document building,
  canvas injection, SVG ID uniquification, font packages — all without requiring
  TeX Live to be installed), `tests/Unit/Services/ToolUploadServiceTest.php`
- Feature: `tests/Feature/ToolsTest.php` (render endpoint, template search/load/
  upload, SVG/PNG upload with metadata), `tests/Feature/SecurityRemediationTest.php`
  (render limit, `429` + `Retry-After`) — `php artisan config:clear && php artisan test`
- Feature: `tests/Feature/AssetTest.php` (REQ-9 — the detail-page button appears
  for `.tex` assets only)
- E2E: `tests/e2e/tools.spec.js` (REQ-8 pause/resume/Stop against a mocked render
  endpoint — the harness ships no TeX Live; REQ-9 `?template=` deep link against a
  mocked template-load endpoint) — `npm run test:e2e -- tests/e2e/tools.spec.js`.
  No `.tex` asset is seeded for the button itself: `application/x-tex` is a
  `document`, and the grid/embed/export specs pin exact document counts.
- Manual (REQ-6 + REQ-8 against real TeX Live): set `TIKZ_RENDER_RATE_LIMIT=3`,
  `php artisan config:clear`, render six `tikzpicture` blocks on `/tools/tikz-server`
  — expect three results, a countdown, then all six.

## Open questions / future

- No test exercises an actual TeX Live compile end-to-end (`runLatex`/
  `runDvisvgm`/`convertSvgToPng` against real binaries) — all Feature tests mock
  `TikzCompilerService`, and Unit tests stop at `compile()`'s early
  sanitization-rejection path. This is a deliberate trade-off (CI doesn't ship
  TeX Live) but means the actual subprocess plumbing, timeout handling, and
  `rsvg-convert`/`inkscape` fallback chain are only verified manually / in
  environments with TeX Live installed.
