# Security invariants

```yaml
id: security-invariants
status: implemented
version: 5
owner: core
related:
  - architecture
  - authorization-policies
  - authentication
  - user-management
  - rest-api
source:
  - tests/Security/RouteExposureTest.php
  - tests/Security/PolicyMatrixTest.php
  - tests/Security/ControllerAuthorizationTest.php
  - tests/Security/UserProvisioningTest.php
  - tests/Security/PrivilegeEscalationTest.php
  - tests/Security/ModelInvariantsTest.php
  - tests/Security/RuntimeExposureTogglesTest.php
  - tests/Security/AdminBootstrapTest.php
  - tests/Security/Support/RouteInventory.php
  - tests/Security/Support/SourceScanner.php
  - database/seeders/AdminUserSeeder.php
  - config/orca.php
  - scripts/npm-audit.mjs
```

## Background / Why

Hand-written security tests only assert what someone thought to ask, so an unlisted route or
ability was indistinguishable from a safe one — which is how an unlinked registration route went
unnoticed ([authentication.md](authentication.md) REQ-8). Every requirement here has one shape:
**derive the coverage from the app (router, policy classes, source tree) and write down only the
exceptions**, in an allowlist whose every entry carries a reason. New attack surface fails the suite
until a human edits that allowlist.

## Requirements

- **REQ-1** — **Route exposure is enumerated, not listed.** Every route in `Route::getRoutes()`
  must require authentication, be guest-only, or appear in a public allowlist with a reason. Every
  unauthenticated route must be rate limited (route middleware, or an allowlist entry naming where).
  No route may expose a registration surface under any name (`/regist`, `/signup`, `/sign-up`, or
  so named). `auth.multi` may only name ADR-004's guards (`web`, `sanctum`, `jwt`); no route may use
  `auth:<guard>`. An actual unauthenticated request to every parameter-less guarded `GET` must
  never return `2xx`.

- **REQ-2** — **Every policy ability has a stated verdict for every role.** Abilities are
  discovered by reflection over `app/Policies` and each must appear in an explicit role × ability
  matrix, checked against the real `Gate` verdict. No bare `return true` (ADR-002), no policy
  `before()`, no `Gate::before()`. The matrix covers exactly the roles the `users.role` constraint
  permits. Why: a blanket pre-authorisation also covers abilities added later, which nobody reviews.

- **REQ-3** — **Every authenticated endpoint is gated somewhere, and the somewhere is named.** Each
  controller (and closure) behind an authenticated route must be authorized by a `can:` gate on all
  its routes, an authorization call inside the class, or an allowlist entry declaring it open to all
  authenticated roles. Presentation role reads (`isAdmin()` in a view model) do not count;
  `canEnableTwoFactor()` and `canEnablePasskeys()` do.

- **REQ-4** — **No creation path leaves a user's role implicit.** Every user-creation idiom
  (`User::create`, `User::factory`, `firstOrCreate`, `updateOrCreate`, a raw `users` insert) under
  `app/`, `routes/` and `database/seeders/` must name the role or use an explicit factory state
  (`->admin()`, `->editor()`, `->apiUser()`). `database/factories` is out of scope (test-only; its
  `role` default is its declaration). Source-level half of [authentication.md](authentication.md)
  REQ-8; the column constraint is pinned there.

- **REQ-5** — **No self-service surface can change a role, and role boundaries hold over HTTP.**
  `role` is mass-assignable on `User` (the admin form assigns it), so it is asserted at the
  endpoints: an `editor`/`api` posting `role=admin` to `/profile` or `/profile/preferences` keeps
  their role; non-admins are refused every `/users` endpoint, admin-only page and credential-minting
  endpoint; an admin cannot delete themselves; an `api` caller cannot delete, trash, restore or
  replace an asset, nor enrol a second factor.

- **REQ-6** — **The model layer cannot be talked into granting something.** No model write in
  `app/` may take an unfiltered payload (`$request->all()`, `$request->except(...)`).
  `ProfileUpdateRequest::rules()` permits exactly `name` and `email`. No `$guarded = []`. Every
  `encrypted*`-cast attribute on `User` is in `$hidden` alongside `password` and `remember_token`;
  a serialised `User` contains no credential material.

- **REQ-7** — **Runtime settings that open API surface are tested on both sides of the branch.**
  `api_meta_endpoint_enabled` and `api_upload_enabled` are DB settings flipped from `/api-docs`, so
  exposure is operator state invisible in a diff. Each toggle is asserted enabled *and* disabled;
  the metadata kill switch withholds the data (not just the status) and applies to authenticated
  callers too. The toggle set is read from `ApiDocsController::updateSettings`'s validation rule, so
  a new toggle fails until covered. Only an admin may flip them. Endpoint behaviour is owned by
  [rest-api.md](rest-api.md) REQ-5.

- **REQ-8** — **The suite is its own CI gate, and each audit proves it can fail.** The tests form a
  `Security` PHPUnit suite run as a dedicated CI job, together with `composer audit --locked` and
  `npm audit`. Every audit that could pass vacuously carries a canary (a scanner that stopped
  matching looks like a clean codebase).

  npm: an advisory with **no patched release** may be ignored, one GHSA id at a time, in
  `scripts/npm-audit.mjs`'s `ADVISORY_ALLOWLIST`, each entry recording why it is unreachable and
  what removes it; never a whole package. A finding passes only when *every* high/critical advisory
  beneath it is listed. An entry that matches no current advisory **fails the step**. The wrapper
  checks its own filter against fixtures on every run.

  composer: an **abandoned** package (exit 2) may be exempted by name in `composer.json`'s
  `config.audit.ignore-abandoned` only when it is transitive, no replacement is possible because a
  dependent still requires it, and the dependent's latest release still does. Advisories against it
  still fail. Current entries: `dragon-code/support` and `dragon-code/contracts` (dev-only, via
  `laravel-lang/common`); they go when laravel-lang drops them or `lang:safe-update` no longer needs
  `laravel-lang/common`.

- **REQ-9** — **The bootstrap seeder cannot mint a published-credential admin.** `AdminUserSeeder`
  is a documented production step ([DEPLOYMENT.md](../../DEPLOYMENT.md)). Under
  `APP_ENV=production` it requires `ORCA_ADMIN_EMAIL` and `ORCA_ADMIN_PASSWORD` (via
  `config/orca.php`, not `env()` — `config:cache` runs later) and **throws** when they are absent,
  the email is malformed, the password fails `Rules\Password::defaults()`, or the password is a
  well-known value (the committed default included, even when supplied explicitly). Why it throws
  rather than returning like `E2eSeeder`: a skipped admin must not look like a successful deploy.
  Outside production the development defaults apply. The password is never echoed. Creation uses
  `firstOrCreate`, so a re-run reports the account exists instead of failing.

## Technical design

### Contract / public interface

```yaml
suite: Security                       # phpunit.xml, alongside Unit and Feature
harness: tests/Pest.php               # RefreshDatabase applied via ->in('Feature', 'Unit', 'Security')
ci_job: .github/workflows/tests.yml   # job "security", a required status check
npm_audit: scripts/npm-audit.mjs      # `npm audit --json` minus ADVISORY_ALLOWLIST; high/critical fail

support:
  Tests\Security\Support\RouteInventory:   # read-only view of the route table
    all: array<string, Route>              #   keyed "METHOD /uri", HEAD/OPTIONS dropped
    middlewareFor: list<string>            #   resolved to class names, as route:list does
    requiresAuth / isGuestOnly / hasThrottle / hasAuthorizeGate: bool
    controllerFor: class-string|null       #   handles Class@method AND invokable Class
  Tests\Security\Support\SourceScanner:
    sourceOf: string                       #   comment-stripped via token_get_all
    phpFilesUnder / callArgumentsFor / callSitesUnder / statementsContaining / statementSitesUnder
```

Middleware is always compared **resolved** (`Router::resolveMiddleware()`), never as declared, so
a directly-named class or the contents of the `web` group are seen.

### Allowlists

Each audit's exceptions live as a named function in the test file that asserts them, each paired
with a staleness test (a dangling entry pre-approves whatever takes that name next).

```yaml
tests/Security/RouteExposureTest.php:
  securityPublicRoutes:                    # GET /, /csrf-token, /up, /sanctum/csrf-cookie,
                                           # /api/health, /api/assets/meta, GET|PUT /storage/{path}
  securityRateLimitExemptRoutes:
tests/Security/ControllerAuthorizationTest.php:
  controllersOpenToAllRoles:               # self-scoped or all-roles-by-matrix
  closureRoutesOpenToAllRoles:             # POST /locale
tests/Security/PolicyMatrixTest.php:
  policyMatrix:                            # ability × role
  policyTargets:
tests/Security/RuntimeExposureTogglesTest.php:
  runtimeExposureToggleCoverage:
```

### Layer touchpoints & ordering

The audits only read the router, policy bindings and source tree. `PolicyMatrixTest` enables
`maintenance_mode` in `beforeEach` to isolate the role dimension; the `maintenance_mode` double
gate stays owned by `tests/Unit/Policies/AssetPolicyTest.php`
([authorization-policies.md](authorization-policies.md) REQ-2). Per-policy and per-route tests
remain the record of individual behaviours; this suite adds only completeness.

### Persistence

None.

## Scenarios (BDD)

```gherkin
Scenario: A route mounted without authentication fails the audit
  Given a route is mounted outside any auth or guest middleware group
  And it is not listed in the public-route allowlist
  When the security suite runs
  Then the route exposure audit fails and names the route and its action
# pinned by: tests/Security/RouteExposureTest.php

Scenario: The exposure audit proves it can fail
  Given the audit reports no unguarded routes
  When an unguarded route is mounted at runtime
  Then the audit reports exactly that route
# pinned by: tests/Security/RouteExposureTest.php

Scenario: A guarded page never renders for a caller with no session
  Given every parameter-less guarded GET route
  When an unauthenticated request is made to each
  Then no response has a 2xx status
# pinned by: tests/Security/RouteExposureTest.php

Scenario: A registration surface cannot be re-mounted under another name
  Given a route whose URI or name matches regist, signup or sign-up
  When the security suite runs
  Then the audit fails and points at authentication.md REQ-8
# pinned by: tests/Security/RouteExposureTest.php

Scenario: A new policy ability must state its roles
  Given a public method is added to a class in app/Policies
  And it has no entry in the role matrix
  When the security suite runs
  Then the completeness audit fails and names the ability
# pinned by: tests/Security/PolicyMatrixTest.php

Scenario: Blanket grants and pre-authorisation are rejected
  Given a policy ability whose whole body is "return true", or a policy before(), or a Gate::before()
  When the security suite runs
  Then the audit fails, citing ADR-002
# pinned by: tests/Security/PolicyMatrixTest.php

Scenario: A controller behind auth with no authorization decision fails the audit
  Given a controller is reachable from an authenticated route
  And neither its routes nor its body make an authorization decision
  And it is not allowlisted as open to all authenticated roles
  When the security suite runs
  Then the controller audit fails and names the class and its routes
# pinned by: tests/Security/ControllerAuthorizationTest.php

Scenario: A user-creation path that omits the role fails the audit
  Given a call site under app/, routes/ or database/seeders/ creates a user
  And it names no role and uses no explicit factory state
  When the security suite runs
  Then the provisioning audit fails and quotes the statement
# pinned by: tests/Security/UserProvisioningTest.php

Scenario: An editor cannot promote themselves through the profile form
  Given an authenticated user with role "editor"
  When they PATCH /profile with role=admin
  Then the response carries no validation errors
  And their role is still "editor"
# pinned by: tests/Security/PrivilegeEscalationTest.php

Scenario Outline: Role boundaries hold over HTTP
  Given an authenticated user with role "<role>"
  When they <action>
  Then no response succeeds
  And <state> is unchanged
  Examples:
    | role          | action                                                    | state                               |
    | editor or api | request any /users endpoint                               | every account                       |
    | api           | delete, trash, restore or replace an asset by any route   | the asset                           |
    | api           | open 2FA setup or request passkey registration options    | the account's TOTP secret / passkeys |
# pinned by: tests/Security/PrivilegeEscalationTest.php

Scenario: A model write handed an unfiltered request payload fails the audit
  Given a call to fill(), update(), create() or forceFill() in app/
  And its argument is $request->all() or $request->except(...)
  When the security suite runs
  Then the mass-assignment audit fails and quotes the call
# pinned by: tests/Security/ModelInvariantsTest.php

Scenario: An encrypted attribute that is not hidden fails the audit
  Given a User attribute carries an encrypted cast
  And it is absent from $hidden
  When the security suite runs
  Then the audit fails
# pinned by: tests/Security/ModelInvariantsTest.php

Scenario: Disabling the public metadata endpoint closes it for everyone
  Given api_meta_endpoint_enabled is false
  When a guest, admin, editor or api caller requests GET /api/assets/meta for a known asset
  Then the response status is 403
  And the response body contains none of the asset's metadata
# pinned by: tests/Security/RuntimeExposureTogglesTest.php

Scenario: Disabling API uploads stores nothing
  Given api_upload_enabled is false
  When an api-role caller posts a file to POST /api/assets
  Then the response status is 403
  And no asset row is created
# pinned by: tests/Security/RuntimeExposureTogglesTest.php

Scenario: A new runtime exposure toggle must be covered before it ships
  Given ApiDocsController::updateSettings validates a key this suite does not cover
  When the security suite runs
  Then the coverage audit fails and names the uncovered toggle
# pinned by: tests/Security/RuntimeExposureTogglesTest.php

Scenario: A non-admin cannot flip a runtime exposure toggle
  Given an authenticated user with role "editor" or "api"
  When they post to /api-docs/settings to re-enable the metadata endpoint
  Then the response status is 403
  And the setting is unchanged
# pinned by: tests/Security/RuntimeExposureTogglesTest.php

Scenario: Seeding a production admin with missing or published credentials is refused
  Given APP_ENV is production
  And ORCA_ADMIN_EMAIL / ORCA_ADMIN_PASSWORD are unset, or the password is the committed development default
  When AdminUserSeeder runs
  Then it throws, the command exits non-zero, and no user is created
# pinned by: tests/Security/AdminBootstrapTest.php

Scenario: A production admin is created without the password reaching the logs
  Given APP_ENV is production
  And ORCA_ADMIN_EMAIL and a policy-compliant ORCA_ADMIN_PASSWORD are set
  When AdminUserSeeder runs
  Then an admin account is created with that email
  And the password does not appear in the command output
# pinned by: tests/Security/AdminBootstrapTest.php

Scenario: The development default still works outside production
  Given APP_ENV is local, testing or e2e
  And no admin credentials are configured
  When AdminUserSeeder runs
  Then admin@orca.dam is created with role "admin"
# pinned by: tests/Security/AdminBootstrapTest.php

Scenario: Seeding twice leaves one admin
  Given AdminUserSeeder has already run
  When it runs again
  Then it reports that the account exists, changes nothing, and does not throw
# pinned by: tests/Security/AdminBootstrapTest.php
```

## Tests & verification

- Suite: `tests/Security/` — `php artisan config:clear && php artisan test --testsuite=Security`.
- REQ-1 `tests/Security/RouteExposureTest.php`; REQ-2 `tests/Security/PolicyMatrixTest.php`;
  REQ-3 `tests/Security/ControllerAuthorizationTest.php` (canary
  `tests/Security/Support/UngatedCanaryController.php`); REQ-4
  `tests/Security/UserProvisioningTest.php`; REQ-5 `tests/Security/PrivilegeEscalationTest.php`;
  REQ-6 `tests/Security/ModelInvariantsTest.php`; REQ-7
  `tests/Security/RuntimeExposureTogglesTest.php`; REQ-9 `tests/Security/AdminBootstrapTest.php`.
- Shared: `tests/Security/Support/RouteInventory.php`, `tests/Security/Support/SourceScanner.php`
  (also used by `tests/Feature/Auth/RegistrationTest.php`).
- Advisories (REQ-8): `composer audit --locked` and `node scripts/npm-audit.mjs`; both block.
  [ADR-019](../decisions/adr-019-tailwind-4-css-first.md) emptied the npm allowlist.
- Run everything: `php artisan config:clear && php artisan test`; style `./vendor/bin/pint --test`;
  `npm run spec:lint`.

## Open questions / future

- **Sanctum tokens carry no abilities** (`createToken($name)`), so a token's power is its user's
  role; a leaked admin token is a full admin credential ([api-tokens-sanctum.md](api-tokens-sanctum.md)).
- **`config/sanctum.php` is unpublished**, so `sanctum.guard` defaults to `['web']` and consults the
  session first — harmless while the `api` group has no `StartSession`. Not asserted.
- **No CSRF coverage** (middleware is disabled in tests; needs deliberate `withMiddleware()`).
- **REQ-4/REQ-6 source scans are duplicated** by Semgrep rules in
  [static-analysis.md](static-analysis.md) REQ-5 (deliberate until the rules have a track record);
  `DB::table('users')->insert(` has no AST counterpart, and neither layer follows variables.
- **The REQ-1 live guest sweep covers parameter-less `GET` only**, so `GET /api/assets/search` has
  no direct "requires authentication" assertion ([rest-api.md](rest-api.md)).
- **REQ-3 accepts a controller-level authorization call, not per-action**; tightening needs
  call-graph analysis.
