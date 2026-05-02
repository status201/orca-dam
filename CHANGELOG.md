# Changelog

All notable changes to ORCA DAM will be documented in this file.
Dates are in ISO 8601 (YYYY-MM-DD). Entries are grouped by release milestone.

---

## [Unreleased]

### Security
- **API role can no longer delete assets.** `AssetApiController::destroy` now routes through the policy (`$this->authorize('delete', $asset)`) instead of the previous inline `! isAdmin() && user_id !== Auth::id()` check, which had let API tokens delete their own assets despite the documented "api: no delete" rule.
- **`AssetPolicy` hardened.** The `viewAny`, `view`, `create`, `update`, and `bulkDownload` stub abilities (previously `return true`) now enforce explicit role lists, so a future role addition cannot silently inherit access. New `bulkRestore` ability replaces the implicit reuse of `restore` for the bulk-restore route.
- `UserFactory` now defaults `role` to `editor` (matching the migration default) and exposes `admin()` / `editor()` / `apiUser()` states; previously `User::factory()->create()` left `role` NULL in-memory, masking authorization gaps in feature tests.

### Changed
- **`AssetController` split into four cohesive controllers** along route seams: `AssetController` (CRUD + tags, ~650 LOC), `AssetTrashController` (destroy / trash / restore / bulk-trash / bulk-restore / bulk-force-delete), `AssetBulkController` (bulk add/remove/list tags, bulk move, bulk force-delete, bulk download), `AssetReplaceController` (replace, thumbnail upload, AI tag, download). Route URIs and names are unchanged; only the action class moved.
- `Asset` model search-operator parsing extracted to `App\Services\AssetSearchParser`. `Asset::scopeSearch` is now a one-line delegate.

### Added
- **Passkeys (WebAuthn / FIDO2)** — phishing-resistant sign-in alongside the existing password + TOTP flows.
  - Passwordless "Sign in with passkey" on the login page (with conditional UI / autofill where supported)
  - Profile → Security: register, rename, and remove passkeys (max 10 per user, admins + editors only)
  - Successful passkey login bypasses the TOTP challenge (passkey already proves possession + verification)
  - Per-credential `last_used_at` and per-user `last_passkey_used_at` shown in the profile and admin user views
  - Admin recovery: "Clear all passkeys" button on user edit when the user loses all their devices
  - Users index gains a **Passkeys** column (count + last-used tooltip)
  - Console: `passkeys:list [--user] [--role]` and `passkeys:revoke <id>|--user [--force]`
  - Built on `laragear/webauthn` v5 + `@simplewebauthn/browser`; auth provider switched to `eloquent-webauthn` with `password_fallback: true`
- Assets Show cycle navigation now includes the `user` filter in the context summary badge
- **Asset parent/child relations** — assets can now track a source asset via `parent_id`. TikZ Server renders uploaded from a loaded or saved `.tex` template are automatically linked to it; the Asset detail page shows a **Relations** card with Source and Derived assets.

---

## [v1.2.1] — 2026-04 — Tail Slap

### Added
- **Tools: TikZ Server Render** — server-side TikZ/LaTeX compilation via TeX Live
  (SVG, SVG with embedded WOFF2 fonts, SVG as paths, PNG). Includes:
  - 17 font packages, configurable border padding, PNG DPI, extra TikZ libraries
  - Snippet templates (load/save, with `\newcommand` in body)
  - Code editor with color picker (dockable, with search filter)
  - Filename template setting (`diagram-{count}-{variant}.{extension}`)
  - Animated GIF output (handover to Animated GIF tool)
  - Color-package styleguide generation
  - Security hardening: blocks `\write18`, `\openin`, file I/O; `--no-shell-escape`
- **Tools submenu** — Tools section in main nav with TikZ, GIF creator, LaTeX→MathML
- **Tools: Animated GIF creator** (PoC)
- **Cloudflare cache purge** on asset replacement / thumbnail regeneration
  (requires `custom_domain` + `cloudflare_cache_purge` toggle + env config)
- **Asset Show cycle navigation** — prev/next when arriving from a filtered index
- **Async test runner** with cached progress for the web-based Pest runner
- **Upload batch metadata** — user tags, license, copyright, copyright source
  applied to every asset in a batch (web uploads + TikZ tool uploads)
- **Assets index → Assets per user** entry points from Dashboard and Users index
- Asset Show image canvas border outline and hover checkerboard background
- PDF/video thumbnail regeneration on replace; thumbnail-generator JS module refactor
- Search: exact phrase match with double-quote operators (`"phrase"`, `+"phrase"`, `-"phrase"`)
- Assets index list view shows image dimensions
- LaTeX→MathML: font selection

### Changed
- **Upgraded to Laravel 13.3** (from 12.x) — "Riptide" release
  - PHPUnit 11 → 12.5, Pest 3.8 → 4.4, laravel/tinker 2 → 3, google2fa-laravel 2 → 3
  - `serializable_classes` security hardening in `config/cache.php` (set to `false`)
  - No application code changes required; session cookies and cache prefixes unchanged
- ORCA grayscale background/text colors moved to a Tailwind plugin
- Firefox textarea `background-attachment` polyfill
- User delete: assets are reassigned to another user instead of cascade-deleted
- DB & query optimizations

### Fixed
- TikZ: multiple inline SVGs no longer collide on font glyph IDs
- TikZ Server always adds `\usepackage[T1]{fontenc}`
- TikZ: additional packages — correct TikZ vs. LaTeX distinction
- TikZ: load all common libraries when rendering
- Cloudflare cache purge settings toggle
- Cloudflare purge also covers thumbnail store

---

## [v1.2.0] — 2026-04-05 — Riptide

### Upgraded
- Laravel 12 → **Laravel 13.3** (framework, Symfony 8.x components)
- PHPUnit 11 → **PHPUnit 12.5**
- Pest 3.8 → **Pest 4.4**
- laravel/tinker 2.x → **3.0**
- pragmarx/google2fa-laravel 2.x → **3.0**

### Added
- `serializable_classes` security hardening in `config/cache.php`

### Notes
- No application code changes required
- Session cookies and cache prefixes unchanged
- All 643 tests passing with 1755 assertions

---

## [v1.1] — 2026-02 → 2026-03

### Added
- **Bulk operations on Assets index**
  - Bulk add/remove tags; bulk tag list for selection
  - Bulk soft delete (move to trash), bulk restore, bulk permanent delete (admin, maintenance mode)
  - Bulk move assets between folders (admin, maintenance mode)
  - Bulk download as ZIP (max 100 files / 500MB)
- **Maintenance mode** setting, required for bulk move and bulk permanent delete
- **Trash**: List/Grid views, Crop/Fit toggles, bulk restore & permanent delete;
  editors can restore (admins still exclusive for permanent delete)
- **Two-Factor Authentication (2FA)** — setup, challenge, recovery codes,
  CLI disable/status commands, 2FA status in users overview
- **Embed view** (`/assets/embed`) — header/footer-less iframe-ready asset browser
  with `embed_allowed_domains` CSP setting
- **Custom domain / CDN** setting for asset URLs
- **S3 integrity check** — `assets:verify-integrity` command + `VerifyAssetIntegrity`
  job; live status card on System page; `?missing=1` filter on Assets index
- **Health check endpoint** `GET /api/health` (public, 200/503)
- **Duplicate prevention** on upload (by etag); `assets:deduplicate` command;
  `assets:backfill-etags` command
- **CSV import for metadata** with preview and change diffs; tags added via
  `syncWithoutDetaching`
- **Reference tags** — new tag type for tracking asset usage by external systems
  (RTE integrations); API-only creation; batch add/remove by `asset_ids` / `s3_keys`
  / `tag_name` / `tag_names`
- **API upload toggle** — runtime disable of `POST /api/assets` without affecting
  web chunked uploads
- **API: folder filtering** on `/api/assets`; sanitized API responses
- **Chunked uploads** via S3 Multipart API (>=10MB, up to 500MB); `upload_sessions`
  table; idempotent chunks with retry; rate-limited 100/min
- **Configurable image resize presets** (S/M/L width/height) — generated per upload
- **Multilingual support** (English + Dutch), user locale preference,
  `SetLocale` middleware
- **About ORCA page** with user-manual markdown viewer
- **Dashboard** with stats, feature slideshow, extra editor tiles
- **System admin control center** — diagnostics, queue dashboard with manual
  retry, logs, commands, tests, settings
- **Search operators** — `+term` required, `-term` excluded, `"phrase"` exact
- **EPS handling + thumbnail generation** (where Imagick available); thumbs for
  non-animated GIFs
- **Client-side thumbnail generation** for PDFs and videos on upload
- **Assets index**: crop/fit toggle, tag-filter sorting, active filters info bar,
  Shift+Click range select, huge-image warning (>4000px), type filter by tags
- **Tags index**: bulk delete, lazy loading / infinite scroll
- **User preferences** (encrypted JSON): home folder, items per page, locale,
  dark mode
- **Orca Feeding Frenzy** — footer mini-game with global leaderboard, touch controls
- `AllowEmbedding` middleware with configurable `frame-ancestors` CSP
- JWT auth (disabled by default): guard, per-user encrypted secrets, web UI +
  CLI management, `AuthenticateMultiple` middleware
- Tag attribution (pivot `attached_by`): track whether a User or AI attached a tag
- Protected root folder setting
- Manual queue job processing from System → Queue
- Timezone setting; S3 bucket versioning info in diagnostics
- Nice 4xx and 50x error pages
- Rate limiting, validation, and max-length enforcement across API

### Changed
- **Big-time refactor** — leaner controllers, dedicated services
  (`S3Service`, `AssetProcessingService`, `ChunkedUploadService`,
  `RekognitionService`, `SystemService`, `TwoFactorService`, `CsvExportService`,
  `CsvImportService`, `ImageProcessingService`, `QueueService`,
  `TestRunnerService`, `CloudflareService`, `TikzCompilerService`)
- Alpine.js components extracted into `resources/js/alpine/` (15 modules)
- Blade templates refactored; shared asset grid partial for index + embed views
- Rekognition defaults standardized (`MAX_LABELS=3`, `MIN_CONFIDENCE=80`)
- Export CSV: AI tags as separate column
- API accepts plural type values (`images`, `videos`, `documents`)
- PHP version requirement bumped to 8.4+

### Fixed
- API can only soft-delete (not force-delete)
- Chunked upload missing web auth
- Disable-API-Uploads setting no longer blocks web chunked uploads
- Asset replace: extension check against S3 key (not filename)
- Inline partial update no longer removes user tags
- Autosuggest tag click race condition
- Filename validation: `sometimes|required`
- `GET /api/folders` allowed for API users
- Root folder as active filter when top-level S3 folder
- npm/composer security updates (axios DoS, esbuild, vite, rollup)

---

## [v1.0] — 2026-01

### Added
- **AWS Rekognition** AI tagging (max labels, min confidence, language);
  AWS Translate for non-English languages
- **AWS S3 integration** — upload, streamed to S3; JPEG thumbnails;
  folder structure mirrored to `thumbnails/`
- **Assets index** with pagination, search, type/tag filters, sorting
- **Asset CRUD** (upload, edit, show, replace, delete); soft delete + trash
- **Discovery** — find and import unmapped S3 objects (admin)
- **Tags** (user type); many-to-many with assets
- **CSV export** — all asset fields, user info, tag columns, URLs
- **Sanctum API** with token management (web UI + CLI)
- **Role-based access** — `admin`, `editor`, `api`
- **Asset policies** for fine-grained authorization
- **System settings** — key-value store with grouped UI (general/display/aws/api)
- **Dashboard** with stats
- **Asset detail table view** with inline editing
- **Batch upload** with live progress
- **Instant search** for tags; page titles on all pages
- **Asset metadata fields** — alt_text, caption, license, copyright
- **Public metadata endpoint** `GET /api/assets/meta?url=` (no auth)
- In-memory SQLite test suite (Pest) with ~629 tests; web-based test runner

### Notes
- Initial commit 2026-01-04
