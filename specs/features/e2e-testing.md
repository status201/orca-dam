# End-to-end testing (Playwright)

```yaml
id: e2e-testing
status: implemented
version: 1
owner: core
related:
  - architecture
  - authentication
  - authorization-policies
  - asset-upload
  - asset-search
  - asset-trash
  - bulk-operations
  - localization
  - s3-storage
  - system-admin
  - user-management
  - iframe-embedding
  - ../recipes/write-an-e2e-test
  - ../decisions/adr-014-playwright-e2e-real-stack
source:
  - playwright.config.js
  - tests/e2e/
  - database/seeders/E2eSeeder.php
  - docker-compose.e2e.yml
  - .env.e2e
  - .github/workflows/tests.yml
```

## Background / Why

Pest covers the server contract (controllers, services, policies, jobs) but never
executes a line of the 21 Alpine modules that carry the actual UI — the asset
grid's filter/sort/selection state, the bulk bar, the tag inputs, the chunked
uploader. A regression there is invisible to `php artisan test` and only shows up
in a browser. The WordPress plugin already had a Playwright suite
([ADR-013](../decisions/adr-013-wordpress-plugin-separate-stream.md) keeps it a
separate stream); this spec brings the same coverage to the Laravel app itself.

The suite drives a **real** stack — `php artisan serve` against a throwaway
SQLite file and a MinIO bucket standing in for S3 — rather than mocking the
backend, so an upload genuinely round-trips bytes to object storage and back
through `S3Service`. The reasoning and the rejected alternatives are in
[ADR-014](../decisions/adr-014-playwright-e2e-real-stack.md).

## Requirements

- **REQ-1** — The suite runs against a booted app (`php artisan serve --env=e2e`)
  started and torn down by Playwright's `webServer`, never against the developer's
  dev database or the production S3 bucket. `.env.e2e` is the only environment the
  suite reads, and it points `DB_DATABASE` at `database/e2e.sqlite` and `AWS_*` at
  the local MinIO endpoint.
- **REQ-2** — Object storage is a local MinIO bucket reached through
  `AWS_ENDPOINT` + `AWS_USE_PATH_STYLE_ENDPOINT`. This is why `S3Service` honours
  those two config keys (see [`s3-storage.md`](s3-storage.md) REQ-7) — without
  them the suite could only talk to real AWS.
- **REQ-3** — Every browser context is authenticated from a saved `storageState`,
  one per role (`admin`, `editor`, `api`), produced by the `setup` project logging
  in through the real login form. The default project uses the `admin` state;
  specs that assert role behaviour opt into another with `test.use({ storageState })`.
- **REQ-4** — Test data comes from `database/seeders/E2eSeeder.php`, which seeds
  the three role users, the runtime `Setting` rows the UI reads (folders, root
  folder, per-page), tags, and a set of **namespaced** fixture assets
  (`e2e-<area>-*`) so no two spec files compete for the same row. A spec that
  mutates data reseeds in `test.beforeAll` (`reseed()` from
  `tests/e2e/support/db.js`), which also makes CI retries deterministic.
- **REQ-5** — Sessions use the **file** driver in `.env.e2e`, not `database`: a
  mid-run reseed truncates the `sessions` table and would invalidate every saved
  `storageState`. Cache is the `array` driver, so a settings change takes effect
  on the next request and `throttle:` counters never leak between tests.
- **REQ-6** — Queues run `sync`, so a thumbnail/resize/AI-tag job dispatched by an
  upload has completed by the time the HTTP response returns and the browser can
  assert on the derived image.
- **REQ-7** — Selectors are `data-testid` attributes, added to the Blade views the
  suite drives. Locators must not depend on user-visible copy, because the same
  pages render in `en` and `nl` ([`localization.md`](localization.md)) and
  `localization.spec.js` deliberately switches locale mid-suite. The naming
  convention is `data-testid="<area>-<thing>[-<qualifier>]"` (e.g.
  `asset-card`, `grid-search`, `bulk-bar-trash`), documented in
  [`../recipes/write-an-e2e-test.md`](../recipes/write-an-e2e-test.md).
- **REQ-8** — Specs that need real bytes in object storage (upload, replace,
  download, thumbnail generation) are guarded by `requiresS3()` and **skip** when
  no MinIO endpoint answers, so a developer without a container runtime can still
  run the other ~95% of the suite. Under `CI` the absence of an endpoint is a hard
  error instead: a MinIO that failed to start must fail the job, not quietly skip
  the storage coverage.
- **REQ-9** — Workers are serialized (`workers: 1`). The whole suite shares one
  SQLite file and one bucket; parallel workers would interleave reseeds.
- **REQ-10** — CI runs the suite as a **blocking** job on every PR that touches
  anything outside `wordpress-plugin/**` (`.github/workflows/tests.yml` → `e2e`),
  with `retries: 2` and the HTML report + traces uploaded as an artifact on
  failure.
- **REQ-11** — The browser is **network-isolated**: the `page` fixture aborts every
  request whose host is neither the app nor the bucket. The layouts load Font
  Awesome from cdnjs and the Figtree webfont from fonts.bunny.net, which otherwise
  makes each page load depend on egress (locally it turned a 7-second run into an
  hour). Because Font Awesome then can't size the icon-only controls — a
  `<button><i class="fas fa-trash">` collapses to 0×0 and is unclickable — the
  fixture injects a minimal `.fas{width:1em;height:1em}` rule. It is injected, not
  served via `route.fulfill`, because the `<link>` carries an SRI `integrity` hash
  that rejects a substituted body.
- **REQ-12** — Interactions wait for **Alpine hydration**, not just for the
  server-rendered element. Alpine strips every `x-cloak` attribute as it
  initialises, so "no `[x-cloak]` left in the DOM" is the hydration signal
  (`waitForAlpine`, used by `gotoAssets`/`gotoTrash`). Without it a click can land
  on markup Alpine has not un-hidden yet and fails as "element is not visible".
- **REQ-13** — A spec must not end a **shared role session**. All contexts for a
  role replay the same saved cookie, so a logout (or password change) invalidates
  that session server-side for every later spec. `auth.spec.js`'s logout test runs
  from a blank `storageState` against the spare account.

## Technical design

### Contract / public interface

```yaml
# npm scripts (package.json)
test:e2e:         playwright test                       # the suite
test:e2e:ui:      playwright test --ui                  # interactive runner
test:e2e:headed:  playwright test --headed
test:e2e:install: playwright install --with-deps chromium
e2e:up:           docker compose -f docker-compose.e2e.yml up -d --wait minio
                  && docker compose -f docker-compose.e2e.yml run --rm minio-init
e2e:down:         docker compose -f docker-compose.e2e.yml down -v
e2e:reset:        node tests/e2e/support/reseed.mjs      # migrate:fresh + E2eSeeder

# playwright.config.js
testDir:   ./tests/e2e
webServer: php artisan config:clear && php artisan serve --env=e2e --host=127.0.0.1 --port=8100
baseURL:   E2E_BASE_URL ?? http://127.0.0.1:8100        # E2E_PORT overrides the port
projects:  setup (global.setup.js) -> chromium (storageState = .auth/admin.json)

# tests/e2e/support/fixtures.js — what a spec imports
test / expect                     # re-exported; `test` is extended (see below)
users / PASSWORD                  # the seeded accounts and their shared password
asAdmin / asEditor / asApi        # storageState paths for test.use({ storageState })
testid(id): string                # '[data-testid="id"]' — the only locator convention
gotoAssets(page, params?)         # navigate + wait for the hydrated grid
gotoTrash(page)                   # same for /assets/trash/index
useViewMode(page, grid|masonry|list)          # switch + wait for that view
useTrashViewMode(page, grid|list)
assetCard(page, filename) / assetRow(page, filename)   # locate one fixture
waitForAlpine(page)               # resolves once no [x-cloak] remains (REQ-12)
expectToast(page, /re/)           # assert a `.toast` message
acceptConfirm(page)               # accept the next window.confirm()
requiresS3(test?)                 # skip the enclosing file/describe without MinIO
# fixtures the extended `test` provides
page                              # network-isolated + icon-CSS stub (REQ-11)
api(role): APIRequestContext      # Bearer-authenticated request context per role

# tests/e2e/support/db.js
reseed(): Promise<void>           # config:clear + migrate:fresh --seed --seeder=E2eSeeder
tokens(): object                  # {admin, editor, api} plaintext Sanctum tokens
settingValue(key): Promise<string># read one settings row (assert persistence)
ensureRuntimeDirs(): void         # create the gitignored storage/* dirs (REQ-5)

# tests/e2e/support/s3.js
probeS3(): Promise<boolean>       # called once by the config; result in E2E_S3
hasS3(): boolean                  # what requiresS3() consults
endpoint(): ?string               # AWS_ENDPOINT parsed out of .env.e2e

# tests/e2e/support/files.js
pngFixture(name, {color?}): {name, mimeType, buffer}   # valid PNG, no fixture files
pngBuffer({width?, height?, color?}): Buffer
uniqueName(prefix, ext?): string  # collision-free upload name (the bucket persists)
uniqueColor(): [r, g, b]          # distinct bytes → distinct etag
```

### Data shapes

```yaml
# .env.e2e — only the values that differ from .env.example matter
APP_ENV: e2e
APP_URL: http://127.0.0.1:8100
DB_CONNECTION: sqlite
DB_DATABASE: database/e2e.sqlite      # absolute path resolved by Laravel
SESSION_DRIVER: file                  # REQ-5 — never `database`
CACHE_STORE: array                    # REQ-5
QUEUE_CONNECTION: sync                # REQ-6
FILESYSTEM_DISK: s3
AWS_ACCESS_KEY_ID: orca-e2e
AWS_SECRET_ACCESS_KEY: orca-e2e-secret
AWS_DEFAULT_REGION: us-east-1
AWS_BUCKET: orca-e2e
AWS_ENDPOINT: http://127.0.0.1:9000
AWS_USE_PATH_STYLE_ENDPOINT: true
AWS_URL: http://127.0.0.1:9000/orca-e2e
AWS_REKOGNITION_ENABLED: false        # no AWS calls from a test run
CLOUDFLARE_ENABLED: false

# storage/e2e/tokens.json — written by E2eSeeder, read by tests/e2e/support/db.js
admin: string    # plaintext Sanctum token "id|secret"
editor: string
api: string

# seeded users (password "password" for all four)
admin@e2e.test:  role admin
editor@e2e.test: role editor
api@e2e.test:    role api
spare@e2e.test:  role editor   # for the logout + user-management specs (REQ-13)

# seeded fixture assets — namespaced per spec area (REQ-4)
e2e-grid-{01..14}.png        # grid, search, sort, pagination
e2e-detail-alpha.png         # detail + edit
e2e-trash-{01..04}.png       # trash lifecycle (04 starts soft-deleted)
e2e-bulk-{01..06}.png        # bulk bar
e2e-embed-01.png             # iframe embed
e2e-doc-01.pdf               # type=document filter
e2e-video-01.mp4             # type=video filter
e2e-api-owned-01.png         # owned by the api user (its only writable asset)

# tags
e2e-shared / e2e-alpha       # attached to e2e-grid-01 / -02 (tag filter)
e2e-rename-me / e2e-delete-me  # consumed by tags.spec.js
e2e-ai-tag / e2e-reference-tag # type badges + "AI tags can't be renamed"
```

### Layer touchpoints & ordering

```
npm run e2e:up                      MinIO up, bucket created + anonymous read
        │
playwright.config.js
        ├── refuse to run without .env.e2e
        ├── probeS3() -> process.env.E2E_S3   (workers inherit it — REQ-8)
        └── webServer ──> php artisan config:clear && php artisan serve --env=e2e (:8100)
        │
setup project (global.setup.js)
        ├── reseed()                migrate:fresh --seed --seeder=E2eSeeder
        └── login × 3 (real form) ─> tests/e2e/.auth/{admin,editor,api}.json
        │
chromium project                    one spec file at a time (workers: 1)
        ├── mutating spec: beforeAll -> reseed()
        └── request-context specs read storage/e2e/tokens.json for Bearer auth
```

Ordering constraints that bite if reversed: the reseed must precede the logins
(`migrate:fresh` drops the users table, orphaning any session saved before it);
the S3 probe must precede test *collection*, because `requiresS3()` is evaluated
synchronously while files load — hence the top-level `await` in the config rather
than a step in the `setup` project; and `config:clear` must precede
`artisan serve`, because a cached `bootstrap/cache/config.php` outranks `.env.e2e`
and would point `migrate:fresh` at the development database.

### Persistence

Everything the suite writes is disposable and gitignored:

```
database/e2e.sqlite            # rebuilt by every reseed
storage/e2e/tokens.json        # API tokens for request-context specs
tests/e2e/.auth/*.json         # one saved storageState per role
test-results/, playwright-report/
```

The MinIO bucket (`orca-e2e`) is *not* cleaned between runs — uploads use
`uniqueName()` so an orphaned object never collides, and `npm run e2e:down -v`
drops the volume.

## Visual aids

Tools and versions:

- `@playwright/test` `^1.62.0` (Chromium only). The WordPress plugin pins its own
  copy in `wordpress-plugin/package.json` and is versioned independently
  ([ADR-013](../decisions/adr-013-wordpress-plugin-separate-stream.md)), so the
  two suites each download their own browser build.
- MinIO (`minio/minio`, `RELEASE.2025-04-22T22-12-26Z`) + `minio/mc` for bucket
  setup, wired in `docker-compose.e2e.yml`.
- Node 22 in CI (matching the `phpunit` and `sdd` jobs).

## Scenarios (BDD)

```gherkin
Scenario: A seeded editor logs in through the real form and lands on the asset library
  Given the login page
  When they submit editor@e2e.test with the seeded password
  Then the asset grid is visible
  And the navigation shows their name
# pinned by: tests/e2e/auth.spec.js

Scenario: A wrong password keeps the user on the login page with an error
  Given the login page
  When they submit a bad password
  Then an email validation error is shown and no session is created
# pinned by: tests/e2e/auth.spec.js

Scenario: An unauthenticated visitor is redirected to login
  Given no session
  When they open /assets
  Then they land on /login
# pinned by: tests/e2e/auth.spec.js

Scenario: Logging out really ends the session
  Given a session created by this test (never a shared role session — REQ-13)
  When they log out from the user menu
  Then /assets redirects to /login
# pinned by: tests/e2e/auth.spec.js

Scenario: Searching narrows the grid to matching filenames
  Given 14 seeded grid assets
  When "e2e-grid-01" is typed into the grid search and applied
  Then only that asset's card remains and a search filter pill is shown
# pinned by: tests/e2e/asset-grid.spec.js

Scenario: The type filter, sort order and view modes survive a page load
  Given the asset grid
  When type=document is chosen
  Then only the seeded PDF is listed and the URL carries type=document
# pinned by: tests/e2e/asset-grid.spec.js

Scenario: Clear all filters returns the full library
  Given a filtered grid
  When "clear all filters" is clicked
  Then the grid shows the unfiltered total again
# pinned by: tests/e2e/asset-grid.spec.js

Scenario: Editing an asset persists the filename and alt text
  Given the edit page for e2e-detail-alpha.png
  When the filename and alt text are changed and saved
  Then the detail page shows the new values
# pinned by: tests/e2e/asset-detail.spec.js

Scenario: A tag added on the edit page appears on the asset and is removable
  Given the edit page for a seeded asset
  When a tag is typed and added, then saved
  Then the tag badge is listed on the detail page
# pinned by: tests/e2e/asset-detail.spec.js

Scenario: Uploading an image stores it in S3 and it renders in the grid
  Given the upload page and a MinIO bucket
  When a PNG is selected and uploaded
  Then the row reports "Uploaded"
  And the asset appears in the grid with a generated thumbnail that loads
# pinned by: tests/e2e/asset-upload.spec.js

Scenario: Re-uploading identical bytes is reported as a duplicate, not stored twice
  Given a PNG that was already uploaded
  When the same bytes are uploaded again
  Then the row is flagged as a duplicate and the duplicates panel offers the existing asset
# pinned by: tests/e2e/asset-upload.spec.js

Scenario: Deleting an asset moves it to trash and restoring brings it back
  Given the list view of the grid
  When an asset is deleted from its row
  Then it disappears from the grid and appears in /assets/trash/index
  And restoring it returns it to the grid
# pinned by: tests/e2e/asset-trash.spec.js

Scenario: An admin permanently deletes a trashed asset
  Given a soft-deleted asset in trash
  When the admin confirms permanent deletion
  Then the asset is gone from trash entirely
# pinned by: tests/e2e/asset-trash.spec.js

Scenario: Bulk-adding a tag applies it to every selected asset
  Given several selected assets in the grid
  When a tag is added from the bulk bar
  Then the bar reports success and each asset carries the tag
# pinned by: tests/e2e/bulk-operations.spec.js

Scenario: Bulk move to trash empties the selection and the assets leave the grid
  Given a selection of assets
  When "move to trash" is used from the bulk bar
  Then the selection clears and the assets are in trash
# pinned by: tests/e2e/bulk-operations.spec.js

Scenario: Bulk restore reports the filenames it restored before reloading
  Given a selected asset in trash
  When bulk restore runs
  Then a summary lists the restored filename, and dismissing it returns the asset
# pinned by: tests/e2e/asset-trash.spec.js

Scenario: An editor sees no admin-only navigation and is refused /system
  Given a session for editor@e2e.test
  Then the Users, System and API navigation entries are absent
  And GET /system responds 403
# pinned by: tests/e2e/role-matrix.spec.js

Scenario: An api-role user cannot trash assets from the UI or the API
  Given a session for api@e2e.test
  Then the bulk bar offers no "move to trash" control
  And DELETE /api/assets/{id} responds 403
# pinned by: tests/e2e/role-matrix.spec.js

Scenario: An api-role user may read any asset but only update its own
  Given a session for api@e2e.test
  When it PATCHes an asset owned by the editor
  Then the response is 403, while PATCHing its own asset succeeds
# pinned by: tests/e2e/role-matrix.spec.js

Scenario: Switching the interface language translates the chrome
  Given an English UI
  When Dutch is selected in profile preferences
  Then the navigation renders the Dutch strings and the html lang attribute is nl
# pinned by: tests/e2e/localization.spec.js

Scenario: An admin creates, re-roles and deletes a user
  Given the users page
  When a new editor is created, promoted to admin and deleted
  Then each step is reflected in the users table
# pinned by: tests/e2e/user-management.spec.js

Scenario: A runtime setting changed on /system survives a reload
  Given the system settings page
  When items_per_page is changed
  Then the value is persisted and the asset grid honours it
# pinned by: tests/e2e/system-settings.spec.js

Scenario: An admin issues and revokes an API token from /api-docs
  Given the API tokens page
  When a token is created and then revoked
  Then it is listed once and then gone
# pinned by: tests/e2e/api-docs.spec.js

Scenario: A token issued in the browser authenticates a REST call
  Given a token just created on /api-docs
  When it is sent as a Bearer token to GET /api/assets
  Then the response is 200 with a paginated payload
# pinned by: tests/e2e/api-docs.spec.js

Scenario: An admin generates a JWT secret for a user
  Given the JWT tab
  When a secret is generated for api@e2e.test
  Then the secret is shown once and the user appears in the secret list
# pinned by: tests/e2e/api-docs.spec.js

Scenario: Tags can be renamed and deleted from the tags page
  Given a seeded tag
  When it is renamed and then deleted
  Then the tags table reflects both changes
# pinned by: tests/e2e/tags.spec.js

Scenario: Every tools page boots its Alpine component
  Given the tools overview
  When each tool card is followed
  Then the tool's own root element renders and no page error is raised
  # (Alpine's benign "Transition was skipped" rejection is filtered)
# pinned by: tests/e2e/tools.spec.js

Scenario: The embed view renders the grid without the app chrome
  Given embedding is enabled with an allowed domain
  When /assets/embed is opened
  Then the grid renders and no application navigation is present
# pinned by: tests/e2e/embed.spec.js
```

## Tests & verification

```bash
npm ci && npm run build            # the @vite manifest must exist
npm run test:e2e:install           # once — Chromium + OS deps
npm run e2e:up                     # MinIO on :9000 (skippable, see REQ-8)
npm run test:e2e                   # boots artisan serve itself
npm run test:e2e -- tests/e2e/asset-grid.spec.js   # one file
npm run e2e:down
```

78 tests across 14 spec files, ~3 minutes serialized (the 4 upload tests skip
without MinIO):

```
auth · asset-grid · asset-detail · asset-upload(S3) · asset-trash ·
bulk-operations · tags · user-management · system-settings · api-docs ·
tools · embed · localization · role-matrix
```

- CI: `.github/workflows/tests.yml` → job `e2e` (blocking, `retries: 2`,
  `playwright-report` + `test-results` artifact and the Laravel log tail on
  failure).
- The suite never touches `.env`; a missing `.env.e2e` fails fast in
  `playwright.config.js`.

## Open questions / future

- **Not covered yet**: the chunked (≥10MB) upload path
  ([`chunked-upload.md`](chunked-upload.md)) — a 10MB fixture would dominate the
  suite's runtime; the passkey/WebAuthn and TOTP flows
  ([`passkeys.md`](passkeys.md), [`two-factor-auth.md`](two-factor-auth.md)),
  which need a virtual authenticator (`CDP WebAuthn` domain) and a TOTP generator
  respectively; CSV import/export round-trips
  ([`csv-export-import.md`](csv-export-import.md)); and S3 discovery
  ([`discovery-import.md`](discovery-import.md)), which needs objects planted in
  the bucket behind the app's back.
- Only Chromium runs. Firefox/WebKit projects are one config entry away but
  triple the CI minutes for a Blade + Alpine app with no browser-specific code.
- `data-testid` coverage is limited to the elements the current specs drive;
  extending the suite generally means adding more hooks (see the recipe).
- **Found while writing this suite, not fixed here**: `layouts/app.blade.php`
  defines its own `window.showToast` plus a `#toast-container` div, both dead —
  `resources/js/app.js` overrides the function and appends `.toast` elements
  straight to `<body>`, so the container is never filled. Harmless but misleading;
  removing the inline copy is a separate change.
- Assertions on toasts are avoided for actions that reload the page ~1s later
  (row delete, every bulk action): those wait on the response and assert the
  persisted outcome instead. Only non-reloading actions (tag edit, preferences
  save, tag rename/delete) assert the toast itself.
