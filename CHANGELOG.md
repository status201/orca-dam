# Changelog

All notable changes to ORCA DAM will be documented in this file.
Dates are in ISO 8601 (YYYY-MM-DD). Entries are grouped by release milestone.

---

## [Unreleased]

### Security
- **Dropped the `users.role` database default of `editor`.** Removing `/register` closed the route that exploited the default, but the default itself — the mechanism that turned an omitted `role` into full asset read/write — was still there for the next creation path to fall into. `users.role` is now `NOT NULL` with no default (`database/migrations/2026_07_30_120000_drop_role_default_from_users_table.php`), so an insert that omits the role fails at the driver (`NOT NULL constraint failed` on SQLite, `Field 'role' doesn't have a default value` on MySQL/MariaDB under `strict`) instead of silently minting an editor. This covers paths the `RegistrationTest` source scan cannot see — `firstOrCreate`, raw inserts, a future SSO or invite flow. No caller changes: `UserController::store` validates `role`, `TokenController::store` and `TokenCreateCommand` hardcode `api`, `UserFactory` sets `editor`. **The migration aborts rather than backfilling if any row holds a `NULL` role** — every role grants strictly more than `NULL` does, so an operator assigns those roles. Pinned by a new scenario in `specs/features/authentication.md` REQ-8; the SQLite path recreates the table from its own stored DDL because `2026_01_26_111545`'s hand-written `email … UNIQUE` left an implicit `sqlite_autoindex_users_1` that Laravel's `->change()` cannot replay.

---

## [v1.5.0] — 2026-07 — Spy Hop
### Added
- **Spec-Driven Development adopted (enforced).** New `specs/` tree is the architectural/behavioural source of truth: a system-wide `architecture.md`, 44 feature specs (Gherkin scenarios each pinned to a real test), 15 ADRs recording the *why* + rejected alternatives, and repeatable recipes. A zero-dep guard (`scripts/sdd-guard.mjs`) enforces spec-before-code on production paths (`app/`, `routes/`, `database/migrations/`, `config/`, `resources/js/`) via Claude Code hooks (PreToolUse/Stop/SubagentStop) and a CI job (`.github/workflows/sdd.yml`); `scripts/spec-lint.mjs` (`npm run spec:lint`) validates spec structure, index completeness, and documented facts (dependency versions and hand-counted totals in the specs and root docs). New `/feature`, `/fix`, `/spec` slash commands drive the flow. Bypass a trivial production tweak with `.sdd-skip` / `[skip-sdd]`. Non-production paths (views, CSS, `lang/`, tests, `public/`, `wordpress-plugin/`, docs) are exempt.
- **Playwright E2E suite for the Laravel app, blocking in CI.** Pest covers the server contract but never executed the 21 Alpine modules that carry the UI. A browser suite now drives the real app — `artisan serve --env=e2e` against a throwaway SQLite file and a local MinIO bucket standing in for S3 — so the grid, bulk bar, tag inputs, uploader and role-gated controls are actually exercised, across 14 spec files serialized at `workers: 1`. Locators are `data-testid` throughout because the same pages render in `en` *and* `nl`, and `localization.spec.js` switches locale mid-suite; every spec file reseeds, which also makes CI retries deterministic. Spec-first: `specs/features/e2e-testing.md` (contract + scenarios), `specs/decisions/adr-014-playwright-e2e-real-stack.md` on why a real stack rather than Dusk/Cypress/mocked S3/real AWS, and `specs/recipes/write-an-e2e-test.md`. One production change came with it: `S3Service::__construct` now honours `filesystems.disks.s3.endpoint` and `use_path_style_endpoint`, which `config/filesystems.php` already defined and the service ignored (`s3-storage.md` REQ-7); an unset endpoint leaves AWS addressing untouched.
- **Comma-separated & paste multi-tag input.** Every tag input (asset edit, upload batch-metadata, grid bulk bar, grid row) now accepts a comma/newline list or a pasted block — typing a comma or pasting `a, b, c` adds all tags at once; single-tag entry, autosuggest, and reference-tag selection are unchanged. Frontend logic is centralized in `resources/js/alpine/tag-input-core.js`; backend splitting in `App\Support\TagInputParser` (reused by CSV import + tag-attach paths). Per-tag max length standardized to 100 (`Tag::MAX_NAME_LENGTH`).
- **Complete Dutch translation coverage.** All remaining English-only UI strings are now translatable: ~32 hardcoded JS toasts (TikZ/GIF/MathML tools, asset grid, copy helper), ~45 controller flash/JSON messages, and framework strings (validation errors, login failures, password resets) via `lang/nl/*.php` published by laravel-lang. API responses intentionally remain English.
- **`php artisan lang:safe-update`** — the only supported way to refresh laravel-lang files. Raw `lang:update` overwrites project translations in `lang/nl.json` (no upstream opt-out); the wrapper restores them afterwards and removes the unused published English lang files. Guarded by a `TranslationIntegrityTest` (sentinel keys + a completeness check that every `__()` string resolves in `nl`) and a Claude Code hook that blocks raw publisher commands.
- **WordPress plugin** (`wordpress-plugin/`, released separately under `wp-v*` tags). Adds an **ORCA DAM** tab to the WordPress media-library modal so editors can pick assets straight from ORCA. On post save, the plugin calls `/api/reference-tags` to stamp the asset with `wp:<site>/post/<id>`, so ORCA shows exactly which posts on which sites use it. Sanctum token stored AES-256-GCM-encrypted in `wp_options`, all calls go through a WP REST proxy (token never leaves the server). Auto-updates from GitHub Releases via plugin-update-checker; ships English + Dutch translations. Settings → ORCA DAM exposes base URL, token, default folder filter, **Test connection**, and a **plugin details** modal with screenshots. v1 is consume-only — uploads still happen in ORCA.

### Changed
- **Alpine modules are now browser-tested, and the recipe says so.** `specs/recipes/add-an-alpine-module.md` claimed Alpine modules were verifiable only by hand; the Playwright suite had since grown to cover most of them. The recipe now prescribes an E2E spec — a boot check as the floor (root `data-testid`, `pageerror` listener) plus behaviour tests — and warns that a new module breaks `spec:lint` until the module count is bumped. The eight modules with no browser coverage got it: CSV import + export (`csv-import-export.spec.js`), the shared batch-metadata form (`upload-metadata.spec.js`), the dashboard feature tour (`dashboard-tour.spec.js`), passkey management (`passkeys.spec.js`), file replace (`asset-replace.spec.js`), S3 discovery (`discover.spec.js`), and the three deprecated tikz pages, which moved from a bare HTTP 200 check into the real boot loop. The suite goes from 74 tests in 14 files to 106 in 20. New `tinker()` harness helper (last-resort state no route can reach — discovery needs an object whose row is gone). New `specs/features/dashboard.md`: the dashboard had no governing spec.
- **Tools-controller uploads deduplicated.** The TikZ / GIF / LaTeX→MathML tool endpoints each carried their own near-identical upload handling; that is now shared through `ToolUploadService`, with tests and docs.
- **Docs reconciled against the specs.** Every E2E scenario is now pinned by the feature spec that owns the behaviour (previously all 28 E2E pins sat in `e2e-testing.md`, restating other features' behaviour). Duplicated documentation surfaces collapsed to one home each — the two ~150-line file trees, five API endpoint lists, six role matrices, four command lists and four `.env` blocks — with each doc's responsibility declared in a docs map. Corrected stale counts (Alpine modules, test totals, spec/ADR totals) and the database contradiction (`README` said MySQL/PostgreSQL; production is MariaDB per ADR-008). New specs for two previously undocumented features: asset cycle navigation and folder management.
- **CI and repo hygiene.** `tests.yml` runs on Node 22 with `actions/cache@v6`, clearing the last Node 20 deprecation annotation (`checkout`, `setup-node` and `upload-artifact` were already current), and disallowed third-party actions were dropped. `.gitattributes` now normalizes line endings across the repo.

### Fixed
- **The export File Type filter silently exported everything.** The dropdown was built from raw mime prefixes (`explode('/', $mime)[0]`), so choosing "Application" sent `application` — a value `Asset::scopeOfType` does not recognise and therefore ignores, exporting the *entire* library while appearing to filter it. The options are now the canonical `Asset::typeCategories()` (`document`/`image`/`video`/`audio`), narrowed to what the library holds, and `export` rejects anything else with a 422 instead of quietly widening the result. New `Asset::typeCategories()` / `Asset::typeCategoryFor()` publish the valid values, since the scope's leniency (a wrong value filters nothing) makes every caller's option list load-bearing.
- **Assets Discover stopped at 1000 S3 objects.** `S3Service` issued a single `ListObjectsV2` call, so discovery silently saw only the first page — 1000 objects — of a larger bucket, and `listFolders` had the same ceiling. Both now page through the full listing via the continuation token, covered by new `tests/Unit/Services/S3ServiceTest.php` cases.
- **E2E flakiness in `bulk-operations` and `asset-trash`.** Bulk actions reload the page ~800ms after their POST resolves, so a spec that acted and then navigated could have its navigation cancelled by that reload (`net::ERR_ABORTED`) — reproducible on `main`, and worse the slower the machine. `gotoStable()` retries a navigation once, and only on that error; `gotoAssets`/`gotoTrash` use it.
- **The passkey rename Cancel button did nothing.** Its `@click` used `@js()` inside a Blade *component* attribute, so the `&quot;` entities were escaped twice and the Alpine expression failed to parse. Found by the new E2E coverage.

### Security
- **Removed self-service registration — it granted `editor` to anyone who found the URL.** The Breeze `register` route shipped mounted but unlinked from the navigation, and `RegisteredUserController::store` passed no `role` to `User::create`, so a self-registered account took the `users.role` column default of `editor` — view, create, update, replace, soft-delete, restore and bulk-download over the **entire** asset library. Because email verification is inert (`User` does not implement `MustVerifyEmail`), that access was live from the moment of signup with no confirmation step. Unlinked is not unreachable: an unknown party had registered on production. The route, `RegisteredUserController` and the register Blade view are deleted, so both verbs now `404`; `tests/Feature/Auth/RegistrationTest.php` inverts to pin their absence and additionally asserts no `User::create` call site under `app/` leans on the column default. Accounts are provisioned by an admin via `/users`, or `token:create` for `api`-role users. **Operators must audit their production `users` table for accounts they did not create.**
- **New user audit trail (`user_audit_logs`) + `php artisan users:audit`.** A `users` row recorded when it was created but nothing about an `UPDATE` that flipped `role`, so the most security-relevant mutation in the system was invisible after the fact — the self-registered `editor` account above was found by a human noticing an unfamiliar name, and a later escalation to `admin` would have left no trace at all. A `UserObserver` now appends who created, re-roled, renamed, re-addressed or deleted an account, and who did it (`actor_id`, plus an `actor_label` that survives the actor's own deletion; `console` for CLI provisioning). Watched attributes are an allowlist — `role`, `email`, `name` — so logins, password changes and 2FA columns file nothing and cannot leak a secret into the trail. Granting `admin` additionally emits a `warning` to the application log. The trail is append-only, and an audit write that fails is logged rather than allowed to break the operation it was auditing. No UI yet: `users:audit --user= --event= --limit=` is how you read it.
- **Password reset no longer confirms whether an address has an account, and is rate-limited.** `POST /forgot-password` reported the broker status verbatim, so `passwords.user` ("We can't find a user with that email address") made the endpoint a login-name oracle for any unauthenticated caller; `passwords.throttled` leaked the same fact more quietly. It now returns one generic confirmation for every syntactically valid address, and logs the non-sent outcomes server-side so operators keep the signal. All four `/forgot-password` + `/reset-password` routes gained `throttle:6,1` — the broker's own 60s `auth.passwords.users.throttle` only debounces repeat links to the *same* address, so it did nothing against a caller walking a list. `POST /reset-password` still reports failures verbatim: it needs a valid signed token, so it tells a caller nothing they don't already know. Trade-off: someone who typos their address now waits for mail that never arrives, which makes the new log line load-bearing for support.
- **API asset replace ignored the `replace` policy ability, and API update silently dropped fields.** `AssetReplaceController` never authorized `replace`, so an `api`-role token could overwrite an asset's bytes even though the role is not granted that ability — it now returns `403`. Separately, `AssetApiController::update` persisted only a subset of the fields it validated, so an API caller's edits could go missing without error; it now mirrors the web controller and persists every validated field.
- **Upload type allowlist + SVG sanitization.** Uploads (direct, chunked, replace) are now restricted to an extension allowlist (`config/uploads.php`, enforced by `App\Rules\AllowedUploadExtension`); previously any file type was accepted. SVGs are sanitized with `enshrined/svg-sanitize` before storage to strip scripts/handlers/remote refs. Non-inline types are stored with `Content-Disposition: attachment`, and the download endpoint sends `nosniff` + RFC-5987-encoded filenames.
- **Baseline security headers.** New `SecurityHeaders` middleware on the web group adds `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN` (relaxed by `AllowEmbedding` when embedding is configured), `Referrer-Policy`, and HSTS over HTTPS. `SESSION_SECURE_COOKIE` now defaults to `true` in production.
- **CSV export formula-injection** neutralized — `CsvExportService` prefixes cells starting with `= + - @` tab/CR with `'`.
- **Rate limits** on expensive/public endpoints (TikZ render, AI tagging, bulk download, public `/api/assets/meta` + `/api/health`, the `/api/assets` group).
- **Reduced info disclosure** — API-role users receive generic error messages (admin/editor still see detail); `embed_allowed_domains` is validated before composing the CSP; `bulkGetTags` now authorizes per asset; the TikZ blocklist rejects all file-inclusion commands.
- **Dependency security patches.** `guzzlehttp/guzzle` 7.11.1 → 7.15.1 over two passes, clearing seven Dependabot advisories: dot-only cookie domains (GHSA-cwxw-98qj-8qjx), silent HTTPS-proxy downgrade (GHSA-wpwq-4j6v-78m3), CRLF injection in `guzzlehttp/psr7` (GHSA-vm85-hxw5-5432), and URI fragments disclosed in redirect `Referer` headers. The constraint floor moved to `^7.15.1` as well — the lock file is what Dependabot reads, but `^7.2` would still have permitted a vulnerable resolve on a fresh install. Also `laravel/framework` 13.15.0 → 13.21.1, `laravel/sanctum` 4.3.3, `aws/aws-sdk-php` 3.388.11, and `intervention/image` 3.11.8 → 4.2.0, which requires PHP `^8.3` and so moved the root constraint off a stale `^8.2` (CI already ran 8.4). On the npm side, `form-data` CRLF injection via unescaped multipart field names and filenames, plus esbuild, websocket-driver and `qs`. `composer audit` is clean.

### Notes for upgrading from v1.4.0 Kelp Crown
- Run `composer install`, `npm ci`, `php artisan migrate` (new `user_audit_logs` table).
- **PHP 8.3 is now the floor** (`intervention/image` 4 requires it). 8.4 is what CI runs.
- **Audit your `users` table for accounts you did not create.** `/register` was reachable
  until this release and granted `editor`; at least one unknown account had been created
  that way in the wild. The audit trail starts empty and records changes from the
  migration onwards, so it says nothing about what happened before it.
- `GET|POST /register` now return `404`. If anything you run links there, repoint it at
  `/users` (admin-provisioned accounts) or `php artisan token:create` for `api` users.
- `POST /forgot-password` no longer tells the requester that an address is unknown.
  Support will need `storage/logs/laravel.log` — the `Password reset link not sent` line —
  to answer "why didn't I get the email?".

---

## [v1.4.0] — 2026-05 — Kelp Crown
### Added
- **Masonry view on the Assets index.** Third view mode alongside Grid and List, optimized for visual browsing: images render at native aspect ratio in CSS columns using the M-size resize (~600px) for crisp previews; non-image types render as fixed-aspect tiles. Bottom-gradient hover overlay shows filename + download / copy / edit. View preference persists in localStorage (`orcaAssetViewMode`).
- **Duplicate upload results panel.** When an upload batch hits etag collisions, each file row shows an outcome pill (Uploaded / Duplicaat / Failed) and a Duplicates panel surfaces below the list with thumbnails, folder, size, "View existing", "Copy URL", multi-select bulk-copy, "Reveal in library", and a Restore-from-trash action when the existing asset is soft-deleted (admin/editor only). Auto-redirect now only fires on a clean batch; duplicate / failure batches keep the user on the upload page until they explicitly continue. Direct and chunked 409 payloads are now identical (`DuplicateAssetException::formatDuplicate`).
- **`?ids[]=` filter on the assets index** — bounded at 200, accepts repeated form or comma-separated, bypasses folder scoping. Surfaced as a chip in the active-filters bar and threaded through cycle navigation so prev/next works across an arbitrary asset set (used by the duplicates panel's "Reveal in library").
- **`local` and `staging` env distinction** using pill and background.

### Changed
- Assets Show: Child assets display as large thumbnails
- Migrate passkeys from laragear/webauthn to laravel/passkeys

### Fixed
- deduplicate app layout (to app.blade.php)

### Notes for upgrading from v1.3.0 Belly Roll
- Run composer install, npm ci, artisan migrate
- Any existing passkeys will be cleared
---

## [v1.3.0] — 2026-05 — Belly Roll

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
  - Built on `laravel/passkeys` ~0.2.1 + `@laravel/passkeys` (first-party). Custom `App\Models\Passkey` encrypts the serialized credential blob at rest. Migrated from `laragear/webauthn` v5 on 2026-05-22 — that package was archived; only the single dev passkey re-registered.
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
