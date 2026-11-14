# End-to-end testing (Playwright)

```yaml
id: e2e-testing
status: implemented
version: 6
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
  - ../decisions/adr-017-rustfs-replaces-minio
source:
  - playwright.config.js
  - tests/e2e/
  - scripts/e2e-storage.mjs
  - database/seeders/E2eSeeder.php
  - docker-compose.e2e.yml
  - .env.e2e
  - .github/workflows/tests.yml
```

## Background / Why

Pest covers the server contract but never executes the 22 Alpine modules that carry the UI
(grid filter/sort/selection, bulk bar, tag inputs, chunked uploader). This suite drives a
**real** stack — `php artisan serve` against a throwaway SQLite file and a RustFS bucket
standing in for S3 — so an upload genuinely round-trips bytes through `S3Service`
([ADR-014](../decisions/adr-014-playwright-e2e-real-stack.md),
[ADR-017](../decisions/adr-017-rustfs-replaces-minio.md)). This spec owns only the
**harness**; application behaviour is pinned by each owning feature spec's browser-level
scenarios. The WordPress plugin's suite is separate
([ADR-013](../decisions/adr-013-wordpress-plugin-separate-stream.md)).

## Requirements

- **REQ-1** — The suite runs against a booted app (`php artisan serve --env=e2e`) started and
  torn down by Playwright's `webServer`, never against the dev database or a production bucket.
  `.env.e2e` is the only environment it reads (`DB_DATABASE` → `database/e2e.sqlite`, `AWS_*` →
  local RustFS). `AppServiceProvider::boot()` adds `TMP`, `TEMP` and `TMPDIR` to
  `ServeCommand::$passthroughVariables`. Why: `serve --env=` unsets everything not on that
  list, so on Windows every multipart upload fails to create its temp file.
- **REQ-2** — Object storage is a local **RustFS** bucket reached through `AWS_ENDPOINT` +
  `AWS_USE_PATH_STYLE_ENDPOINT` ([`s3-storage.md`](s3-storage.md) REQ-7), on port **9100**
  (9000 is commonly taken), stated once in `.env.e2e`. `npm run e2e:up`
  (`scripts/e2e-storage.mjs`) starts it via `docker-compose.e2e.yml`, or — with no usable
  container runtime (no `docker`, no Compose v2, or a daemon that does not answer) — from a
  RustFS binary downloaded to `storage/e2e/` and verified against a pinned SHA-256. Either way
  `tests/e2e/support/bucket.js` creates the bucket, opens it to anonymous `s3:GetObject` (the
  browser loads asset URLs straight from `AWS_URL`), and asserts the anonymous read took.
- **REQ-3** — Every browser context is authenticated from a saved `storageState`, one per role
  (`admin`, `editor`, `api`), produced by the `setup` project logging in through the real login
  form. The default project uses `admin`; specs opt into another with `test.use({ storageState })`.
- **REQ-4** — Test data comes from `database/seeders/E2eSeeder.php`: the role users, the runtime
  `Setting` rows the UI reads, tags, and **namespaced** fixture assets (`e2e-<area>-*`) so no two
  spec files compete for a row. A mutating spec reseeds in `test.beforeAll` (`reseed()` from
  `tests/e2e/support/db.js`), which also makes CI retries deterministic.
- **REQ-5** — Sessions use the **file** driver (a reseed truncates `sessions` and would
  invalidate every saved `storageState`); cache is `array`, so settings take effect on the next
  request and `throttle:` counters never leak between tests.
- **REQ-6** — Queues run `sync`, so jobs dispatched by an upload have completed when the HTTP
  response returns.
- **REQ-7** — Selectors are `data-testid` attributes named `<area>-<thing>[-<qualifier>]`
  ([`../recipes/write-an-e2e-test.md`](../recipes/write-an-e2e-test.md)). Locators must not
  depend on copy: pages render in `en` and `nl`, and `localization.spec.js` switches locale
  mid-suite.
- **REQ-8** — Specs needing real bytes in storage are guarded by `requiresS3()` and **skip**
  when no endpoint reports ready; under `CI` a missing endpoint is a hard error. The
  `AWS_ENDPOINT` read from `.env.e2e` is validated first (`http`/`https`, host `127.0.0.1` or
  `localhost`, probe origin rebuilt from those literals); anything else counts as "no endpoint".
  `E2E_S3_ENDPOINT` is the deliberate escape hatch and is used as given.
- **REQ-9** — Workers are serialized (`workers: 1`): one SQLite file and one bucket are shared,
  and parallel workers would interleave reseeds.
- **REQ-10** — CI runs the suite as a **blocking** job on **every** PR
  (`.github/workflows/tests.yml` → `e2e`), `retries: 2`, report + traces uploaded on failure.
  The `pull_request` trigger has no path filter. Why: a required check from a workflow that does
  not run never reports, so a plugin-only PR would block forever.
- **REQ-11** — The browser is **network-isolated**: the `page` fixture aborts every request whose
  host is neither the app nor the bucket (the Figtree webfont from fonts.bunny.net would otherwise
  make each page load depend on egress). Font Awesome ships in the Vite bundle
  ([ADR-019](../decisions/adr-019-tailwind-4-css-first.md)), so icon-only controls need no stub.
- **REQ-12** — Interactions wait for **Alpine hydration**: "no `[x-cloak]` left in the DOM" is
  the signal (`waitForAlpine`, used by `gotoAssets`/`gotoTrash`).
- **REQ-13** — A spec must not end a **shared role session** (logout or password change would
  invalidate the saved cookie for every later spec). `auth.spec.js`'s logout test runs from a
  blank `storageState` against the spare account.
- **REQ-14** — Navigation tolerates the **app navigating on its own** (bulk actions and summary
  panels reload the page). `gotoStable()` retries a navigation once, only on `net::ERR_ABORTED`;
  `gotoAssets`/`gotoTrash` route through it.

## Technical design

### Contract / public interface

```yaml
# npm scripts (package.json)
test:e2e:         playwright test                       # the suite
test:e2e:ui:      playwright test --ui                  # interactive runner
test:e2e:headed:  playwright test --headed
test:e2e:install: playwright install --with-deps chromium
e2e:up:           node scripts/e2e-storage.mjs up      # start RustFS + provision the bucket
e2e:down:         node scripts/e2e-storage.mjs down
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
gotoStable(page, url)             # page.goto that retries once on ERR_ABORTED (REQ-14)
gotoAssets(page, params?)         # navigate + wait for the hydrated grid
gotoTrash(page)                   # same for /assets/trash/index
useViewMode(page, grid|masonry|list)          # switch + wait for that view
useTrashViewMode(page, grid|list)
assetCard(page, filename) / assetRow(page, filename)   # locate one fixture
waitForAlpine(page)               # resolves once no [x-cloak] remains (REQ-12)
expectToast(page, /re/)           # assert a `.toast` message
acceptConfirm(page)               # accept the next window.confirm()
requiresS3(test?)                 # skip the enclosing file/describe without a bucket
# fixtures the extended `test` provides
page                              # network-isolated (REQ-11)
api(role): APIRequestContext      # Bearer-authenticated request context per role

# tests/e2e/support/db.js
reseed(): Promise<void>           # config:clear + migrate:fresh --seed --seeder=E2eSeeder
tokens(): object                  # {admin, editor, api} plaintext Sanctum tokens
settingValue(key): Promise<string># read one settings row (assert persistence)
ensureRuntimeDirs(): void         # create the gitignored storage/* dirs (REQ-5)
tinker(php): Promise<string>      # last resort: one PHP statement under --env=e2e

# tests/e2e/support/s3.js
probeS3(): Promise<boolean>       # called once by the config; result in E2E_S3
hasS3(): boolean                  # what requiresS3() consults
endpoint(): ?string               # AWS_ENDPOINT parsed out of .env.e2e

# tests/e2e/support/bucket.js — SigV4 by hand; no client library, no second image
bucketConfig(): ?object           # {origin, bucket, region, accessKey, secretKey} from .env.e2e
createBucket(config): Promise<'created'|'exists'>
putPublicReadPolicy(config): Promise<void>   # anonymous s3:GetObject on the bucket
assertAnonymousRead(config): Promise<void>   # throws if the policy did not take

# scripts/e2e-storage.mjs — `npm run e2e:up` / `e2e:down`
up      # Docker Compose when the daemon answers `docker info`, else the downloaded binary; then the bucket
down    # compose down -v (only if the daemon answers) and kill any detached process, dropping its data
bucket  # provision only, against a backend that is already up

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
AWS_ENDPOINT: http://127.0.0.1:9100
AWS_USE_PATH_STYLE_ENDPOINT: true
AWS_URL: http://127.0.0.1:9100/orca-e2e
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
npm run e2e:up                      RustFS up (Docker or binary), bucket created + anonymous read
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

Ordering that must hold: reseed before the logins (`migrate:fresh` drops `users`); the S3
probe before test *collection* (`requiresS3()` is evaluated as files load — hence top-level
`await` in the config); `config:clear` before `artisan serve` (a cached config outranks
`.env.e2e`).

### Persistence

Everything the suite writes is disposable and gitignored: `database/e2e.sqlite`,
`storage/e2e/tokens.json`, the per-role sessions in `tests/e2e/.auth/`, `test-results/`, `playwright-report/`.
The `orca-e2e` bucket is *not* cleaned between runs — uploads use `uniqueName()`;
`npm run e2e:down` drops the backing store.

## Visual aids

- `@playwright/test` `^1.62.0` (Chromium only). The WordPress plugin pins its own copy.
- RustFS `1.0.1` — `rustfs/rustfs:1.0.1` in `docker-compose.e2e.yml`, or the same version's
  release binary pinned (version + per-platform SHA-256) in `scripts/e2e-storage.mjs`, cached
  under `storage/e2e/bin/{version}/`. Bumped by hand only.
- Node 22 in CI.
- PHP in CI runs with `upload_max_filesize=15M` / `post_max_size=16M` (the chunked-mode limits);
  a local PHP needs the same for `asset-upload.spec.js`'s chunked test.

## Scenarios (BDD)

```gherkin
Scenario: The suite reseeds before it saves any session (REQ-4)
  Given a possibly stale database/e2e.sqlite
  When the setup project runs
  Then migrate:fresh --seeder=E2eSeeder rebuilds it before any login
# pinned by: tests/e2e/global.setup.js, tests/e2e/support/db.js

Scenario: One storageState is saved per role and reused by every spec
  Given the reseeded fixtures
  When setup logs in as admin, editor and api through the real login form
  Then each session is written to tests/e2e/.auth/<role>.json
  And specs adopt one by declaring asAdmin / asEditor / asApi
# pinned by: tests/e2e/global.setup.js, tests/e2e/support/fixtures.js

Scenario: The S3-dependent specs skip cleanly when no bucket is there
  Given nothing answering on the endpoint .env.e2e names
  When playwright.config.js probes /health/ready before test collection
  Then E2E_S3 is unset and requiresS3() skips those specs
  But in CI the missing bucket is a hard failure instead
# pinned by: tests/e2e/support/s3.js

Scenario: A multipart upload survives the dev server's environment filtering
  Given the app served by artisan serve with an explicit --env
  When a spec uploads a file
  Then PHP can still create its upload temp file and the request reaches validation
# pinned by: tests/Feature/ServeCommandEnvironmentTest.php, tests/e2e/asset-upload.spec.js

Scenario: The storage stand-in starts without a usable container runtime
  Given no docker on PATH, or docker installed with a daemon that is not answering
  When npm run e2e:up runs
  Then the pinned RustFS binary is downloaded, checksum-verified and started detached
  And the bucket is provisioned by the same code CI uses
  And npm run e2e:down stops that binary rather than failing in compose
# pinned by: scripts/e2e-storage.mjs, tests/e2e/support/bucket.js

Scenario: A bucket that is not anonymously readable fails at provisioning time
  Given a storage backend that refuses the public-read policy
  When npm run e2e:up provisions the bucket
  Then it fails there, rather than later as a thumbnail that never loads
# pinned by: tests/e2e/support/bucket.js

Scenario: A navigation cancelled by the app's own reload is retried, not failed
  Given a spec that has just run a bulk action, which reloads the page shortly after
  When it navigates before that reload lands
  Then the aborted navigation is retried once and the spec continues
  And a navigation that fails for any other reason still fails
# pinned by: tests/e2e/support/fixtures.js

Scenario: A missing .env.e2e fails fast rather than touching the dev database
  Given no .env.e2e file
  When the Playwright config loads
  Then it throws before any artisan command runs
# pinned by: tests/e2e/support/db.js

Scenario: Fixtures are generated, never committed
  Given a spec needing an image
  When it calls pngFixture() / uniqueName() / uniqueColor()
  Then the PNG is synthesised in-process
  And identical bytes yield an identical etag (how duplicate-detection.md forces a collision)
# pinned by: tests/e2e/support/files.js

Scenario: A reseed can be triggered on its own
  Given a dirty e2e database
  When npm run e2e:reset is run
  Then reseed() rebuilds database/e2e.sqlite without starting the suite
# pinned by: tests/e2e/support/reseed.mjs, tests/e2e/support/db.js

Scenario: A spec can reach a state no HTTP route exposes, as a last resort
  Given a state the app never produces (an S3 object whose row is gone)
  When a spec calls tinker() with a single PHP statement
  Then it runs against --env=e2e and returns the output
  And fixtures still belong in E2eSeeder and state changes in the UI
# pinned by: tests/e2e/support/db.js, tests/e2e/discover.spec.js
```

## Tests & verification

```bash
npm ci && npm run build            # the @vite manifest must exist
npm run test:e2e:install           # once, and again after a Playwright bump — Chromium + OS deps
npm run e2e:up                     # RustFS on :9100 (skippable, see REQ-8)
npm run test:e2e                   # boots artisan serve itself
npm run test:e2e -- tests/e2e/asset-grid.spec.js   # one file
npm run e2e:down
```

146 tests across 21 spec files (loop-generated cases in `tools.spec.js` included), plus the
4 in `global.setup.js`. `npm run spec:lint` counts loop-generated tests and errors on a loop it
cannot size, so generated tests must iterate an array literal or a named `const`. 7 tests skip
without a bucket.

- CI: `.github/workflows/tests.yml` → job `e2e`.
- Which behaviour each spec file pins is recorded by the owning feature spec:
  `git grep -n "pinned by:.*asset-grid.spec.js"`. Every spec file is pinned by at least one
  feature spec.

## Open questions / future

- Not covered: the passkey *ceremonies* ([`passkeys.md`](passkeys.md)) and the TOTP flow
  ([`two-factor-auth.md`](two-factor-auth.md)) — need a CDP virtual authenticator and a TOTP
  generator.
- A discovery scan finding *nothing* is not assertable: the bucket outlives the database.
- The client-side TikZ render path is never rendered (external hosts are blocked);
  `tools.spec.js` asserts hydration only.
- Firefox/WebKit projects? One config entry each, but triple the CI minutes.
