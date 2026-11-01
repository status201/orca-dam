# TikZ server render

```yaml
id: tikz-render
status: implemented
version: 6
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

The TikZ Server Render tool (`/tools/tikz-server`) compiles user-submitted TikZ through a real
TeX Live pipeline (LaTeX → DVI → dvisvgm → SVG/PNG) for pixel-accurate diagrams, unlike the
client-side TikZJax tools ([client-side-tools.md](client-side-tools.md)). Because it shells out
to LaTeX — a classic shell-escape and file-read vector — `TikzCompilerService` is deliberately
paranoid: no shell escape, no file inclusion, every render in a throwaway temp directory.
Persisting a result goes through `ToolUploadService`, whose contract
[client-side-tools.md](client-side-tools.md) owns.

## Requirements

- **REQ-1** — LaTeX is invoked with `--no-shell-escape` and `openin_any=p` /
  `openout_any=p` (paranoid mode) so no `\write18` or arbitrary file open can
  reach the filesystem even if input sanitization is bypassed.
- **REQ-2** — `TikzCompilerService::sanitizeInput()` rejects TikZ code (and, separately, a
  custom preamble) matching any of `DANGEROUS_PATTERNS` — shell escape (`\write18`,
  `\immediate\write`) and every file-inclusion primitive (`\input`, `\include`,
  `\InputIfFileExists`, `\lstinputlisting`, `\subfile`, `\subimport`, `\import`, `\openin`,
  `\openout`, `\newwrite`, `\closeout`, `\closein`, `\read`) — as defense-in-depth on REQ-1.
  `\includegraphics` is exempt (word-boundary match). The admin `tikz_color_package` setting
  passes the same check before being written as a `.sty`.
- **REQ-3** — Every render happens in a fresh `sys_get_temp_dir()/orca_tikz_{uniqid}`
  directory that is unconditionally removed in a `finally` block, even on failure.
- **REQ-4** — `compile()` produces up to 4 variants, selectable via `variants` (empty = all):
  `svg_standard` (dvisvgm default), `svg_embedded` (`--font-format=woff2`), `svg_paths`
  (`--no-fonts`), and `png` (from the paths SVG via `rsvg-convert`, `inkscape` fallback). A
  forced canvas (`force_canvas` + width/height 0.5–100cm) injects `\useasboundingbox` (and
  optionally `\clip`) into the first `tikzpicture`, so a batch shares one coordinate frame
  (the animated-GIF workflow).
- **REQ-5** — SVG element IDs are made unique per render (`uniquifySvgIds()`, random 4-hex
  prefix) so multiple inline SVGs on one page don't reuse each other's glyph definitions.
- **REQ-6** — `ToolsController::renderTikzServer` is rate-limited by the named `tikz-render`
  limiter — `config('tikz.render_rate_limit')` per minute per user (`TIKZ_RENDER_RATE_LIMIT`,
  default 60), on its own counter ([upload-policy.md](upload-policy.md) REQ-7) — and returns
  `503` up front when `TikzCompilerService::isAvailable()` (both `latex` and `dvisvgm` on
  `PATH`) is false.
- **REQ-7** — Render results become `Asset` rows only when explicitly uploaded
  (`uploadTikzSvg`/`uploadTikzSvgFonts`/`uploadTikzPng`) via `ToolUploadService::store()`,
  which accepts `parent_asset_id` to link a render to its source `.tex` template.
- **REQ-8** — The page renders a batch as one request per `tikzpicture`. A `429` pauses the
  batch: it reads `Retry-After` (60s if absent), shows a per-second countdown, then retries
  **the same** snippet; three consecutive `429`s end the batch with a rate-limit message. A
  Stop button ends the batch after the current request or countdown, keeping results so far.
  A non-compile failure never surfaces as "Compilation failed": the response's `error`, then
  `message`, is shown.
- **REQ-tex-asset-opens-in-tikz-tool** — A `.tex` asset opens in the tool in one click. Asset
  Show renders **Open in TikZ Tool** first in its Actions card whenever `Asset::isTex()` (shown
  regardless of TeX Live availability), linking `tools/tikz-server?template={id}` in the same
  tab. The page loads a numeric `template` param through `loadFromOrca()` (same as the template
  browser) and then strips it with `history.replaceState`. `loadTexTemplate` accepts an asset
  when `isTex()` **or** its filename ends in `.tex`/`.txt`, so a renamed `.tex` never answers 422.

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

ToolsController (TikZ-relevant):
  tikzServer()                    # GET  tools/tikz-server[?template={id}]
  renderTikzServer(Request)       # POST tools/tikz-server/render     — throttle:tikz-render
  uploadTikzSvg(StoreTikzSvgRequest)             # POST tools/tikz-svg/upload
  uploadTikzSvgFonts(StoreTikzSvgFontsRequest)   # POST tools/tikz-svg-fonts/upload
  uploadTikzPng(StoreTikzPngRequest)             # POST tools/tikz-png/upload  — base64 content
  searchTexTemplates(Request)     # GET  tools/tikz-server/templates          — '%.tex' assets
  loadTexTemplate(Asset)          # GET  tools/tikz-server/templates/{asset}  — 422 unless isTex() or .tex/.txt
  uploadTexTemplate(StoreTexTemplateRequest) # POST tools/tikz-server/templates/upload — process:false
  bakomaFont(string $name)        # GET  tools/bakoma-font/{name}     — proxies+caches (24h) BaKoMa TTFs from tikzjax.com; name [a-z0-9]+
```

### Data shapes

- Render request: validated inline in `ToolsController::renderTikzServer` (code/preamble ≤
  50000 chars, `png_dpi` 72–600, `border_pt` 0–50, canvas 0.5–100cm, per-variant booleans).
  Upload requests: the `app/Http/Requests/Tools/` classes. Rate limit: `config/tikz.php`.
- Fonts, extra packages and default TikZ libraries: `TikzCompilerService` constants
  (`FONT_PACKAGES` etc.); the UI reads `fontPackages()`.

```yaml
# compile() variant entry
type: svg_standard|svg_embedded|svg_paths|png
content: string          # SVG markup, or base64 for png
size: int
mime: image/svg+xml|image/png
width/height: int|null   # png only
```

### Layer touchpoints & ordering

`renderTikzServer` validates → `isAvailable()` (503) → `compile()`: sanitize code, preamble and
colour-package setting before writing anything → `buildTexDocument()` → `latex` (DVI, paranoid
env) → `dvisvgm` per variant → `uniquifySvgIds()` → optional PNG conversion → temp dir removed
in `finally`. Persisting a chosen variant is a separate upload call through
`ToolUploadService::store()` ([client-side-tools.md](client-side-tools.md)).

### Persistence

Compile results are ephemeral. Only an explicit upload creates a row; `assets.parent_id` links a
render to its `.tex` template (the "Relations" card on Asset Show). `bakomaFont` caches TTF bytes
under `bakoma_font_{name}` for 24h — the only cross-request cache here.

## Visual aids

```
POST /tools/tikz-server/render
  -> validate -> isAvailable()? -> TikzCompilerService::compile()
       sanitizeInput(code | preamble | color pkg) — any DANGEROUS_PATTERNS match -> {success:false}
       -> buildTexDocument() -> latex --no-shell-escape (paranoid openin/openout)
       -> no input.dvi? -> {success:false, log}
       -> per variant: dvisvgm [--font-format=woff2 | --no-fonts] -> uniquifySvgIds()
       -> png? rsvg-convert (or inkscape)
       -> cleanup(tmpDir) in finally
  <- {variants: [...], log}

tikzServer.render()  — one POST per tikzpicture, sequential
  429 -> wait Retry-After (countdown) -> retry same snippet   (3 in a row -> stop)
  Stop -> finish current request/countdown -> keep results so far
```

Tools: TeX Live (`latex`, DVI mode), `dvisvgm`, `rsvg-convert`/`inkscape`.

## Scenarios (BDD)

```gherkin
Scenario: Shell-escape and file-inclusion input is rejected, includegraphics is allowed
  When sanitizeInput is called with "\write18{rm -rf /}" or any of \input, \include,
    \InputIfFileExists, \lstinputlisting, \subfile, \subimport, \import, \openin,
    \openout, \newwrite, \closeout, \closein, \read
  Then each returns null
  But sanitizeInput("\includegraphics{image}") is not null
# pinned by: tests/Unit/TikzCompilerServiceTest.php

Scenario: A dangerous preamble or colour package blocks compilation
  When compile() is called with safe code but a preamble containing \openin,
    or with the tikz_color_package setting containing \write18
  Then success is false and the error mentions "dangerous" (and "Color package" for the setting)
# pinned by: tests/Unit/TikzCompilerServiceTest.php

Scenario: buildTexDocument wraps snippets, passes full documents through, honours a preamble
  When buildTexDocument is called with a plain tikzpicture, a string with \documentclass,
    and a snippet plus a non-empty preamble
  Then the first gets \documentclass[tikz]{standalone} and the default \usetikzlibrary block
  And the second is returned verbatim
  And the third contains the preamble but not \usetikzlibrary
# pinned by: tests/Unit/TikzCompilerServiceTest.php

Scenario: Invalid extra library names are dropped, valid ones pass through
  When buildTexDocument is called with extraLibraries "valid,../bad,ok.lib"
  Then the output contains "valid" and "ok.lib" but not "../bad"
# pinned by: tests/Unit/TikzCompilerServiceTest.php

Scenario: injectCanvas adds a bounding box to only the first tikzpicture
  Given TikZ code with two tikzpicture blocks
  When injectCanvas is called with width/height/clip
  Then only one \useasboundingbox is injected, preserving existing options on that block
# pinned by: tests/Unit/TikzCompilerServiceTest.php

Scenario: uniquifySvgIds prevents ID collisions across multiple inline SVGs
  Given SVG markup with id attributes and #id references (double- or single-quoted)
  When uniquifySvgIds is called twice on the same input
  Then ids/references are consistently prefixed and the two calls use different prefixes
# pinned by: tests/Unit/TikzCompilerServiceTest.php

Scenario: The render endpoint validates, compiles, and refuses without TeX Live
  When an editor POSTs png_dpi=9999 and border_pt=100
  Then both fields report validation errors
  And with the compiler mocked to succeed, valid tikz_code returns variants (type/content/size/mime) and a log
  And when isAvailable() is false the response is 503
# pinned by: tests/Feature/ToolsTest.php

Scenario: The render endpoint is rate-limited, 60 per minute by default (REQ-6)
  Given tikz.render_rate_limit is 2
  When an editor POSTs tools/tikz-server/render three times within a minute
  Then the third response is 429 with a Retry-After header
  And with TIKZ_RENDER_RATE_LIMIT unset the config value is 60
# pinned by: tests/Feature/SecurityRemediationTest.php

Scenario: Template loading accepts .tex assets, including renamed ones
  Given an asset "image.jpg", and one with s3_key "assets/renamed.tex" and filename "renamed"
  When loadTexTemplate is requested for each
  Then the first is 422 and the second is 200 with the file content
# pinned by: tests/Feature/ToolsTest.php

Scenario: Asset Show offers "Open in TikZ Tool" only for .tex assets (REQ-tex-asset-opens-in-tikz-tool)
  Given a .tex asset and an image asset
  When each detail page is rendered
  Then the .tex page links to tools/tikz-server?template={its id}; the image page does not
# pinned by: tests/Feature/AssetTest.php

# — browser-level (see e2e-testing.md for the harness) —

Scenario: A rate-limited batch pauses, retries, and can be stopped (REQ-8)
  Given a two-snippet batch whose second request is answered 429 with Retry-After: 1
  When Render is clicked
  Then the countdown shows, the same snippet is resent, and both results render
  And with Retry-After: 30, Stop during the countdown ends the batch with one result and no error
  And three consecutive 429s end the batch with the rate-limit message, not "Compilation failed"
# pinned by: tests/e2e/tools.spec.js

Scenario: A ?template= link loads that template and cleans the URL (REQ-tex-asset-opens-in-tikz-tool)
  Given the template-load endpoint answers for asset 4242
  When /tools/tikz-server?template=4242 is opened
  Then the editor holds that template's content and the URL no longer carries the parameter
# pinned by: tests/e2e/tools.spec.js
```

## Tests & verification

- Unit: `tests/Unit/TikzCompilerServiceTest.php` (no TeX Live needed);
  `tests/Unit/Services/ToolUploadServiceTest.php` (scenarios in
  [client-side-tools.md](client-side-tools.md))
- Feature: `tests/Feature/ToolsTest.php`, `tests/Feature/SecurityRemediationTest.php`,
  `tests/Feature/AssetTest.php` — `php artisan config:clear && php artisan test`
- E2E: `tests/e2e/tools.spec.js` (render and template endpoints mocked; no TeX Live in the
  harness) — `npm run test:e2e -- tests/e2e/tools.spec.js`
- Manual (REQ-6 + REQ-8 with real TeX Live): `TIKZ_RENDER_RATE_LIMIT=3`, `config:clear`, render
  six `tikzpicture` blocks — expect three results, a countdown, then all six.

## Open questions / future

- No test runs a real TeX Live compile (`runLatex`/`runDvisvgm`/`convertSvgToPng`, timeouts, the
  `rsvg-convert`/`inkscape` fallback) — CI ships no TeX Live; verified manually only.
