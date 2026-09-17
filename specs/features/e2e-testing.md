# End-to-end testing (Playwright)

```yaml
id: e2e-testing
status: implemented
version: 3
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

Pest covers the server contract (controllers, services, policies, jobs) but never
executes a line of the 22 Alpine modules that carry the actual UI — the asset
grid's filter/sort/selection state, the bulk bar, the tag inputs, the chunked
uploader. A regression there is invisible to `php artisan test` and only shows up
in a browser. The WordPress plugin already had a Playwright suite
([ADR-013](../decisions/adr-013-wordpress-plugin-separate-stream.md) keeps it a
separate stream); this spec brings the same coverage to the Laravel app itself.

The suite drives a **real** stack — `php artisan serve` against a throwaway
SQLite file and a RustFS bucket standing in for S3 — rather than mocking the
backend, so an upload genuinely round-trips bytes to object storage and back
through `S3Service`. The reasoning and the rejected alternatives are in
[ADR-014](../decisions/adr-014-playwright-e2e-real-stack.md); the storage engine
itself was re-picked in [ADR-017](../decisions/adr-017-rustfs-replaces-minio.md)
when MinIO was archived upstream.

## Requirements

- **REQ-1** — The suite runs against a booted app (`php artisan serve --env=e2e`)
  started and torn down by Playwright's `webServer`, never against the developer's
  dev database or the production S3 bucket. `.env.e2e` is the only environment the
  suite reads, and it points `DB_DATABASE` at `database/e2e.sqlite` and `AWS_*` at
  the local RustFS endpoint.

  `TMP`, `TEMP` and `TMPDIR` are added to
  `Illuminate\Foundation\Console\ServeCommand::$passthroughVariables` in
  `AppServiceProvider::boot()`. Passing `--env=` — which this suite must —
  switches `serve` from inheriting the environment to passing through only a
  fixed allowlist, explicitly *unsetting* everything else in the server process.
  None of the temp-directory variables are on that list, so on Windows
  `GetTempPath()` falls through to the Windows directory, which is not writable,
  and **every** multipart request dies at request startup with
  `PHP Request Startup: File upload error - unable to create a temporary file`,
  reaching the caller as a 422 from validation with no exception and nothing in
  the log. That is a plain `artisan serve` bug rather than a test one — it breaks
  uploads for any Windows developer who serves with `--env` — but it is invisible
  on Linux, where PHP falls back to `/tmp`, so the browser suite is where it
  shows up.
- **REQ-2** — Object storage is a local **RustFS** bucket reached through
  `AWS_ENDPOINT` + `AWS_USE_PATH_STYLE_ENDPOINT`. This is why `S3Service` honours
  those two config keys (see [`s3-storage.md`](s3-storage.md) REQ-7) — without
  them the suite could only talk to real AWS.

  `npm run e2e:up` (`scripts/e2e-storage.mjs`) brings it up **either** through
  `docker-compose.e2e.yml` **or**, on a machine with no container runtime, from a
  RustFS binary it downloads into `storage/e2e/` and verifies against a pinned
  SHA-256 before running. The version is pinned in one place and moves only by
  hand. Either way the bucket is created and opened to anonymous `s3:GetObject`
  by `tests/e2e/support/bucket.js`, which signs the two requests itself — so
  local and CI provision the bucket by the same code path, and the provisioning
  step asserts the anonymous read actually took rather than discovering it later
  as a missing thumbnail. Anonymous read is not optional: the browser loads asset
  and thumbnail URLs straight from `AWS_URL`, exactly as it would from S3 behind
  the CDN.

  The endpoint is on port **9100**, not the conventional 9000. 9000 is routinely
  already bound on a developer machine (PhpStorm), and — before
  [`s3-storage.md`](s3-storage.md) REQ-8 bounded the S3 client's waits — an
  endpoint that accepted the connection and never answered hung the single-process
  dev server for PHP's entire `max_execution_time`, which surfaced as unrelated
  specs timing out. The port is stated in `.env.e2e` and read from there by the
  harness (`fixtures.js` derives the browser's network allowlist from it), so it
  exists in one place plus the compose port mapping.
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
  no storage endpoint reports itself ready, so a developer who cannot start one at
  all can still run the other ~95% of the suite. Under `CI` the absence of an
  endpoint is a hard error instead: a backend that failed to start must fail the
  job, not quietly skip the storage coverage.

  The `AWS_ENDPOINT` read out of `.env.e2e` is validated before it is probed: the
  protocol must be `http`/`https` and the host must be `127.0.0.1` or `localhost`,
  and the origin the probe fetches is rebuilt from those allowlisted literals rather
  than from the parsed text. A malformed or non-loopback value counts as "no
  endpoint", so it skips locally and fails the job in CI. `E2E_S3_ENDPOINT` is the
  deliberate exception and is used as given — it is the escape hatch for a remote
  bucket or a tunnel, and an environment variable is not file data. This came from
  CodeQL's `js/file-access-to-http` (see
  [static-analysis.md](static-analysis.md) REQ-2), which was reporting a real
  dataflow from `readFileSync` into `fetch`; the committed `.env.e2e` made it
  harmless, but a probe that fetches whatever host a config file names was worth
  tightening regardless.
- **REQ-9** — Workers are serialized (`workers: 1`). The whole suite shares one
  SQLite file and one bucket; parallel workers would interleave reseeds.
- **REQ-10** — CI runs the suite as a **blocking** job on **every** PR
  (`.github/workflows/tests.yml` → `e2e`), with `retries: 2` and the HTML report +
  traces uploaded as an artifact on failure. The `pull_request` trigger carries no
  path filter, deliberately: branch protection requires this job by name, and a
  required check from a workflow that does not run never reports, so a
  `wordpress-plugin/**`-only PR would block forever waiting for it. The filter is
  kept on the `push` trigger, where nothing is gated. The cost is a few wasted
  minutes on the rare plugin-only PR.
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
- **REQ-14** — Navigation tolerates the **app navigating on its own**. Bulk actions
  reload the page ~800ms after their POST resolves, and the summary panels reload
  when dismissed, so a spec that acts and then navigates can have its navigation
  cancelled by that reload — `net::ERR_ABORTED`, more likely the slower the
  machine. `gotoStable()` retries a navigation once, and only on that error, so a
  genuine navigation failure still fails. `gotoAssets`/`gotoTrash` route through
  it. This was a real source of flakes in `bulk-operations` and `asset-trash`.

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

# tests/e2e/support/bucket.js — SigV4 by hand; no client library, no second image
bucketConfig(): ?object           # {origin, bucket, region, accessKey, secretKey} from .env.e2e
createBucket(config): Promise<'created'|'exists'>
putPublicReadPolicy(config): Promise<void>   # anonymous s3:GetObject on the bucket
assertAnonymousRead(config): Promise<void>   # throws if the policy did not take

# scripts/e2e-storage.mjs — `npm run e2e:up` / `e2e:down`
up      # Docker Compose when docker resolves, else the downloaded binary; then the bucket
down    # compose down -v and/or kill the detached process, dropping its data
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

The bucket (`orca-e2e`) is *not* cleaned between runs — uploads use
`uniqueName()` so an orphaned object never collides, and `npm run e2e:down` drops
the backing store (the compose volume, or `storage/e2e/rustfs-data/`).

## Visual aids

Tools and versions:

- `@playwright/test` `^1.62.0` (Chromium only). The WordPress plugin pins its own
  copy in `wordpress-plugin/package.json` and is versioned independently
  ([ADR-013](../decisions/adr-013-wordpress-plugin-separate-stream.md)), so the
  two suites each download their own browser build.
- RustFS `1.0.0-rc.6` — the image `rustfs/rustfs:1.0.0-rc.6` in
  `docker-compose.e2e.yml`, or the release binary of the same version fetched by
  `scripts/e2e-storage.mjs`, which pins that version and the SHA-256 of each
  platform's archive. Nothing bumps either automatically. It replaced MinIO,
  which was archived upstream ([ADR-017](../decisions/adr-017-rustfs-replaces-minio.md));
  bucket setup no longer needs a second image at all.
- Node 22 in CI (matching the `phpunit` and `sdd` jobs).

## Scenarios (BDD)

The suite's *own* contract lives here — seeding, saved role sessions, the S3 skip and
the disposable artefacts. Every scenario about application behaviour is pinned by the
feature spec that owns it (a browser-level block at the end of that spec's scenarios),
so no behaviour is specified twice. Feature specs currently carrying browser-level
scenarios: [authentication](authentication.md), [asset-search](asset-search.md),
[asset-model](asset-model.md), [asset-upload](asset-upload.md),
[s3-storage](s3-storage.md), [duplicate-detection](duplicate-detection.md),
[asset-trash](asset-trash.md), [bulk-operations](bulk-operations.md),
[tags](tags.md), [tag-input](tag-input.md),
[authorization-policies](authorization-policies.md),
[user-management](user-management.md), [settings](settings.md),
[system-admin](system-admin.md), [api-docs-admin](api-docs-admin.md),
[localization](localization.md), [user-preferences](user-preferences.md),
[client-side-tools](client-side-tools.md), [iframe-embedding](iframe-embedding.md).

```gherkin
Scenario: The suite reseeds before it saves any session (REQ-13)
  Given a possibly stale database/e2e.sqlite
  When the setup project runs
  Then migrate:fresh --seeder=E2eSeeder rebuilds it
  And this happens before any login, because migrate:fresh drops the users table
    and would orphan a session saved earlier
# pinned by: tests/e2e/global.setup.js, tests/e2e/support/db.js

Scenario: One storageState is saved per role and reused by every spec
  Given the reseeded fixtures
  When setup logs in as admin@e2e.test, editor@e2e.test and api@e2e.test
    through the real login form
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

Scenario: The storage stand-in starts without a container runtime
  Given a machine with no docker on PATH
  When npm run e2e:up runs
  Then the pinned RustFS binary is downloaded, checksum-verified and started detached
  And the bucket is created and opened to anonymous reads by the same code CI uses
# pinned by: scripts/e2e-storage.mjs, tests/e2e/support/bucket.js

Scenario: A bucket that is not anonymously readable fails at provisioning time
  Given a storage backend that refuses the public-read policy
  When npm run e2e:up provisions the bucket
  Then it fails there, rather than later as a thumbnail that never loads
# pinned by: tests/e2e/support/bucket.js

Scenario: A navigation cancelled by the app's own reload is retried, not failed
  Given a spec that has just run a bulk action, which reloads the page ~800ms later
  When it navigates before that reload lands
  Then the aborted navigation is retried once and the spec continues
  And a navigation that fails for any other reason still fails
# pinned by: tests/e2e/support/fixtures.js

Scenario: A missing .env.e2e fails fast rather than touching the dev database
  Given no .env.e2e file
  When the Playwright config loads
  Then it throws before any artisan command runs
  And config:clear always precedes artisan serve, so a cached config cannot
    outrank .env.e2e and point migrate:fresh at the development database
# pinned by: tests/e2e/support/db.js

Scenario: Fixtures are generated, never committed
  Given a spec needing an image
  When it calls pngFixture() / uniqueName() / uniqueColor()
  Then the PNG is synthesised in-process by a hand-rolled CRC32 chunk writer
  And identical bytes yield an identical etag, which is how the duplicate
    scenario in duplicate-detection.md forces a collision
# pinned by: tests/e2e/support/files.js

Scenario: A reseed can be triggered on its own
  Given a dirty e2e database
  When npm run e2e:reset is run
  Then reseed() rebuilds database/e2e.sqlite without starting the suite
# pinned by: tests/e2e/support/reseed.mjs, tests/e2e/support/db.js

Scenario: A spec can reach a state no HTTP route exposes
  Given a state the app itself will never produce — an S3 object whose database
    row is gone, which every delete path in the app refuses to leave behind
  When a spec calls tinker() with a single PHP statement
  Then it runs against --env=e2e and returns the output
  And this is the last resort, not a convenience: fixtures belong in E2eSeeder and
    state changes belong in the UI, so that what the suite asserts is what a user
    can actually cause
# pinned by: tests/e2e/support/db.js, tests/e2e/discover.spec.js
```

## Tests & verification

```bash
npm ci && npm run build            # the @vite manifest must exist
npm run test:e2e:install           # once — Chromium + OS deps
npm run e2e:up                     # RustFS on :9100 (skippable, see REQ-8)
npm run test:e2e                   # boots artisan serve itself
npm run test:e2e -- tests/e2e/asset-grid.spec.js   # one file
npm run e2e:down
```

139 tests across 21 spec files (132 literal `test()` calls plus 7 generated by the two
`tools.spec.js` loops), on top of the 4 in `global.setup.js` — so
`npx playwright test --list` reports **143 tests in 22 files**. Counting only literal
`test(` calls understates it; `npm run spec:lint` counts the loop-generated cases too.
It resolves a loop's array by splitting on top-level commas — bracket-, string- and
comment-aware — so a nested object or arrow literal in an entry no longer inflates the
total. Two guarantees come with that: the counter has fixtures checked on every run
(`checkE2eCounter`, so a broken counter fails as a self-test rather than as a stale
number), and a loop it cannot size is an **error**, not a silent 1. Generated tests must
therefore iterate an array literal or a named `const` — `for (const x of xs.filter(…))`
will be rejected.
~4 minutes serialized; 7 tests skip without a bucket (4 upload, 1 replace, 2 discovery):

```
auth · asset-grid · asset-detail · asset-upload(S3) · asset-trash ·
bulk-operations · tags · user-management · system-settings · api-docs ·
tools · embed · localization · role-matrix · csv-import-export ·
upload-metadata · dashboard-tour · guided-demo · passkeys · asset-replace ·
discover(S3)
```

- CI: `.github/workflows/tests.yml` → job `e2e` (blocking, `retries: 2`,
  `playwright-report` + `test-results` artifact and the Laravel log tail on
  failure).
- The suite never touches `.env`; a missing `.env.e2e` fails fast in
  `playwright.config.js`.
- Which behaviour each spec file pins is recorded by the owning feature spec, not
  here — grep for the spec file name: `git grep -n "pinned by:.*asset-grid.spec.js"`.
  Every one of the 20 files is pinned by at least one feature spec, and
  `npm run spec:lint` fails if a pinned path stops existing.

## Open questions / future

- **Not covered yet**: the chunked (≥10MB) upload path
  ([`chunked-upload.md`](chunked-upload.md)) — a 10MB fixture would dominate the
  suite's runtime; the passkey *ceremonies* — registration and passkey sign-in
  ([`passkeys.md`](passkeys.md)) — and the TOTP flow
  ([`two-factor-auth.md`](two-factor-auth.md)), which need a virtual authenticator
  (`CDP WebAuthn` domain) and a TOTP generator respectively. Passkey *management*
  (list, rename, remove) is covered by `passkeys.spec.js`, which never clicks
  "Add Passkey".
- Discovery is covered (`discover.spec.js`) but only for an object the spec
  orphans itself — upload through the UI, then drop the row with `tinker()`. A scan
  finding *nothing* is not assertable: the bucket outlives the database, so every
  reseed orphans whatever earlier spec files uploaded.
- The client-side TikZ render path is booted but never rendered: with external
  hosts blocked, `render()` waits out its own 90s deadline. `tools.spec.js` asserts
  hydration only.
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
