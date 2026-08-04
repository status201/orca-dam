# Security invariants

```yaml
id: security-invariants
status: implemented
version: 1
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
```

## Background / Why

An unknown party registered an account on production. Breeze's `register` route shipped mounted
but unlinked from the navigation, and `RegisteredUserController::store` passed no `role` to
`User::create`, so the account took the `users.role` column default of `editor` — read and write
over the whole asset library, live immediately because email verification is inert
([authentication.md](authentication.md) REQ-7). Both halves are closed and pinned by
`tests/Feature/Auth/RegistrationTest.php` under [authentication.md](authentication.md) REQ-8.

This spec exists because of the *second* question: several security scans and a green test suite
had run over that code for months. Every security test in the repo asserts something a person
already thought to ask — `AssetPolicyTest` checks the thirteen abilities its dataset names,
`SecurityRemediationTest` checks the three route names it names, `role-matrix.spec.js` walks a
fixed path list, and roughly forty hand-written `'guests cannot …'` tests each cover the one route
their author remembered. Nothing enumerated the application itself, so a route nobody wrote a test
for was indistinguishable from a route that was safe.

The requirements below are therefore all of one shape: **derive the coverage from the app, and
write down only the exceptions.** Each audit reads the router, the policy classes, or the source
tree, and compares what it finds against a small allowlist whose every entry carries a reason. New
attack surface fails the suite until a human edits that allowlist, which turns "someone should
have noticed" into a required review step.

## Requirements

- **REQ-1** — **Route exposure is enumerated, not listed.** Every route in
  `Route::getRoutes()` must require authentication, be a guest-only form, or appear in a public
  allowlist with a stated reason. Every unauthenticated route must be rate limited, either by
  route middleware or by an allowlist entry naming where the limiting happens. No route may expose
  a registration surface under any name (`/regist`, `/signup`, `/sign-up`, or a route so named).
  `auth.multi` may only name the guards ADR-004 documents (`web`, `sanctum`, `jwt`), and no route
  may pin a guard through Laravel's own `auth:<guard>` middleware instead. Beyond introspection, an
  actual unauthenticated request to every parameter-less guarded `GET` must never return `2xx` —
  declared middleware proves what is configured, not what the stack does.

- **REQ-2** — **Every policy ability has a stated verdict for every role.** Abilities are
  discovered by reflection over `app/Policies`, and each must appear in an explicit role × ability
  matrix; an ability with no matrix entry fails. The matrix is then checked against the real `Gate`
  verdict for each role. No ability may be a bare `return true` (ADR-002), no policy may declare
  `before()`, and no `Gate::before()` may be registered — a blanket pre-authorisation also covers
  every ability added afterwards, which nobody reviews. The matrix must cover exactly the roles the
  `users.role` constraint permits, so a fourth role cannot inherit a verdict by accident.

- **REQ-3** — **Every authenticated endpoint is gated somewhere, and the somewhere is named.**
  `auth` proves only that the caller is somebody. For each controller behind an authenticated
  route, authorization must come from a `can:` gate on all of its routes, from an authorization
  call inside the class, or from an allowlist entry declaring the controller open to all
  authenticated roles. Closure-backed authenticated routes are held to the same rule. Role reads
  that exist for presentation (`isAdmin()` in a view model) do not count as gating;
  `canEnableTwoFactor()` and `canEnablePasskeys()` do, because they exist only to authorize.

- **REQ-4** — **No creation path leaves a user's role implicit.** Every user-creation idiom
  (`User::create`, `User::factory`, `firstOrCreate`, `updateOrCreate`, a raw `users` insert) under
  `app/`, `routes/` and `database/seeders/` must name the role, or use an explicit factory state
  (`->admin()`, `->editor()`, `->apiUser()`). `database/seeders` is in scope because `db:seed` runs
  against whatever database is configured; `database/factories` is out of scope, because a factory
  runs only from a test and its `role` default *is* its declaration. This is the source-level half
  of [authentication.md](authentication.md) REQ-8 — the column constraint is the other half and
  stays pinned there.

- **REQ-5** — **No self-service surface can change a role, and role boundaries hold over HTTP.**
  `role` is mass-assignable on `User` because the admin user form assigns it, so the guarantee has
  to be asserted at the endpoints: an `editor` or `api` caller posting `role=admin` to
  `/profile` or `/profile/preferences` keeps their own role; non-admins are refused every
  `/users` endpoint, every admin-only page, and every credential-minting endpoint; an admin cannot
  delete their own account; an `api` caller cannot delete, trash, restore or replace an asset, nor
  enrol a second factor.

- **REQ-6** — **The model layer cannot be talked into granting something.** No model write in
  `app/` may be handed an unfiltered request payload (`$request->all()`, `$request->except(...)`)
  — validated input or an explicit field list only. `ProfileUpdateRequest::rules()` must permit
  exactly `name` and `email`. No model may set `$guarded = []`. Every attribute with an
  `encrypted*` cast on `User` must also appear in `$hidden`, alongside `password` and
  `remember_token`, and a serialised `User` must contain no credential material.

- **REQ-7** — **Runtime settings that open API surface are tested on both sides of the branch.**
  `api_meta_endpoint_enabled` and `api_upload_enabled` live in the database and are flipped from
  `/api-docs` at runtime, so the exposure of an unauthenticated endpoint is operator state rather
  than code — nothing in a diff reveals it. Each toggle must be asserted enabled *and* disabled;
  the metadata kill switch must withhold the data rather than only changing the status line, and
  must close the endpoint for authenticated callers too, since the check precedes any auth
  consideration. The set of toggles is read from `ApiDocsController::updateSettings`'s own
  validation rule, so a new one fails until it is covered. Only an admin may flip them. Endpoint
  behaviour itself remains owned by [rest-api.md](rest-api.md) REQ-5.

- **REQ-8** — **The suite is its own CI gate, and each audit proves it can fail.** The tests live
  in a `Security` PHPUnit suite and run as a dedicated CI job, so a security regression does not
  read as "some test failed". Dependency advisories are checked in the same job (`composer audit
  --locked`, `npm audit`). Every audit that could pass vacuously carries a canary: a scanner that
  has silently stopped matching produces the same empty result as a clean codebase, which is the
  exact failure mode this spec exists to prevent.

- **REQ-9** — **The bootstrap seeder cannot mint a published-credential admin.**
  `AdminUserSeeder` creates the first admin on a fresh installation and is a documented production
  step ([DEPLOYMENT.md](../../DEPLOYMENT.md), immediately after `migrate --force`). It used to
  hardcode `admin@orca.dam` / `password` with no guard at all. **This repository is public**, so
  those were not weak credentials but published ones: any installation seeded from them shipped
  with an admin account whose login anybody could read, and nothing ever forced it to change.

  Under `APP_ENV=production` the seeder now requires `ORCA_ADMIN_EMAIL` and `ORCA_ADMIN_PASSWORD`
  (read through `config/orca.php`, not `env()` — `config:cache` runs later in the same runbook and
  would make `env()` return null). It **throws** when they are absent, when the email is malformed,
  when the password fails `Rules\Password::defaults()` — the same rule the interactive password
  flows use — or when the password is one of a handful of well-known values, the published default
  first among them. Supplying the default explicitly must not be a way around the guard.

  The refusal is an exception, not a message, and that is the one place this deliberately differs
  from `E2eSeeder`: `E2eSeeder`'s guard writes to stderr and `return`s, leaving the exit code at 0.
  For a fixture seeder being skipped is the intended outcome. Here it would mean a deployment
  script reporting success while no admin account exists, so it has to be loud.

  Outside production the previous development defaults still apply, unchanged. The operator's
  password is never echoed, so it stays out of deployment logs. Creation uses `firstOrCreate`, so a
  second run reports that the account exists rather than dying on the unique-email index — the
  previous `User::create` made a re-run look like a failed deployment.

## Technical design

### Contract / public interface

```yaml
suite: Security                       # phpunit.xml, alongside Unit and Feature
harness: tests/Pest.php               # RefreshDatabase applied via ->in('Feature', 'Unit', 'Security')
ci_job: .github/workflows/tests.yml   # job "security", a required status check

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

Middleware is always compared **resolved**. `Route::gatherMiddleware()` returns what was declared
(`'web'`, `'auth'`), so string-matching it would miss a route that names a middleware class
directly and would never see inside the `web` group; `Router::resolveMiddleware()` expands both.

`RouteInventory::controllerFor()` handles the invokable form (a bare `Class` with no `@method`)
as well as `Class@method`. Missing that form silently reclassifies single-action controllers as
closures and drops them out of REQ-3 — which is how `VerifyEmailController` and
`EmailVerificationPromptController` initially escaped the audit.

### Allowlists

Every audit's exceptions live as a named function in the test file that asserts them, not in a
shared table — a failure should put the list in front of whoever is changing the thing it covers.
Each is paired with a staleness test, because an allowlist entry that no longer matches anything
silently pre-approves whatever occupies that name next.

```yaml
tests/Security/RouteExposureTest.php:
  securityPublicRoutes: 8 entries          # GET /, /csrf-token, /up, /sanctum/csrf-cookie,
                                           # /api/health, /api/assets/meta, GET|PUT /storage/{path}
  securityRateLimitExemptRoutes: 12 entries
tests/Security/ControllerAuthorizationTest.php:
  controllersOpenToAllRoles: 13 entries    # self-scoped or all-roles-by-matrix
  closureRoutesOpenToAllRoles: 1 entry     # POST /locale
tests/Security/PolicyMatrixTest.php:
  policyMatrix: 21 abilities × 3 roles
  policyTargets: 3 policies
tests/Security/RuntimeExposureTogglesTest.php:
  runtimeExposureToggleCoverage: 3 toggles
```

### Layer touchpoints & ordering

The audits read, and never mutate, the router, the container's policy bindings and the source
tree. `PolicyMatrixTest` enables `maintenance_mode` in `beforeEach` so its matrix isolates the
role dimension; the `maintenance_mode` double gate on `AssetPolicy::move` and `bulkForceDelete`
stays owned by `tests/Unit/Policies/AssetPolicyTest.php`
([authorization-policies.md](authorization-policies.md) REQ-2).

Overlap with existing tests is deliberate and one-directional: the per-policy unit tests and the
per-route feature tests remain the readable record of individual behaviours, and this suite adds
only the completeness dimension they cannot express.

### Persistence

None. The suite adds no tables, columns or settings. The one production change it forced is an
explicit role on `DatabaseSeeder`'s user (REQ-4).

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

Scenario: An ability that grants unconditionally is rejected
  Given a policy ability whose whole body is "return true"
  When the security suite runs
  Then the blanket-grant audit fails, citing ADR-002
# pinned by: tests/Security/PolicyMatrixTest.php

Scenario: A blanket pre-authorisation is rejected
  Given a policy declares before(), or Gate::before() is registered
  When the security suite runs
  Then the audit fails, because a pre-authorisation also covers abilities added later
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

Scenario: A non-admin is refused every user-management endpoint
  Given an authenticated user with role "editor" or "api"
  When they request any /users endpoint
  Then the response status is 403
  And no account is created, re-roled or deleted
# pinned by: tests/Security/PrivilegeEscalationTest.php

Scenario: An api-role caller cannot destroy or replace an asset
  Given an authenticated user with role "api"
  When they delete, trash, restore or replace an asset by any route
  Then the response status is 403
  And the asset is unchanged
# pinned by: tests/Security/PrivilegeEscalationTest.php

Scenario: An api-role caller cannot enrol a second factor
  Given an authenticated user with role "api"
  When they open the 2FA setup or request passkey registration options
  Then no response is a 200
  And the account holds no TOTP secret and no passkey
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
  Then the audit fails, because the attribute is serialised into API responses
# pinned by: tests/Security/ModelInvariantsTest.php

Scenario: Disabling the public metadata endpoint closes it
  Given api_meta_endpoint_enabled is false
  When an unauthenticated caller requests GET /api/assets/meta for a known asset
  Then the response status is 403
  And the response body contains none of the asset's metadata
# pinned by: tests/Security/RuntimeExposureTogglesTest.php

Scenario: The metadata kill switch applies to authenticated callers too
  Given api_meta_endpoint_enabled is false
  When an admin, editor or api caller requests GET /api/assets/meta
  Then the response status is 403
# pinned by: tests/Security/RuntimeExposureTogglesTest.php

Scenario: Disabling API uploads stores nothing
  Given api_upload_enabled is false
  When an api-role caller posts a file to POST /api/assets
  Then the response status is 403
  And no asset row is created
# pinned by: tests/Security/RuntimeExposureTogglesTest.php

Scenario: A new runtime exposure toggle must be covered before it ships
  Given ApiDocsController::updateSettings validates a key against a list
  And that list contains a key this suite does not cover
  When the security suite runs
  Then the coverage audit fails and names the uncovered toggle
# pinned by: tests/Security/RuntimeExposureTogglesTest.php

Scenario: A non-admin cannot flip a runtime exposure toggle
  Given an authenticated user with role "editor" or "api"
  When they post to /api-docs/settings to re-enable the metadata endpoint
  Then the response status is 403
  And the setting is unchanged
# pinned by: tests/Security/RuntimeExposureTogglesTest.php

Scenario: Seeding an admin in production without credentials is refused
  Given APP_ENV is production
  And neither ORCA_ADMIN_EMAIL nor ORCA_ADMIN_PASSWORD is set
  When AdminUserSeeder runs
  Then it throws, the command exits non-zero, and no user is created
# pinned by: tests/Security/AdminBootstrapTest.php

Scenario: The published development password is refused even when supplied explicitly
  Given APP_ENV is production
  And ORCA_ADMIN_PASSWORD is set to the committed development default
  When AdminUserSeeder runs
  Then it throws and no user is created
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

- Suite: `tests/Security/` — 97 tests. `php artisan config:clear && php artisan test --testsuite=Security`.
- REQ-1: `tests/Security/RouteExposureTest.php` — route-table enumeration, the live guest sweep,
  throttle coverage, the registration-shape ban, and the guard allowlist. Includes the canary that
  mounts an unguarded route and requires the audit to name it.
- REQ-2: `tests/Security/PolicyMatrixTest.php` — reflection-driven ability discovery, the 21 × 3
  matrix against real `Gate` verdicts, the `return true` and `before()` bans, and the role-set
  cross-check against the `users.role` constraint. The blanket-grant detector is proved against an
  inline stub.
- REQ-3: `tests/Security/ControllerAuthorizationTest.php` — gating per controller behind an
  authenticated route, plus closure routes. Its canary mounts
  `tests/Security/Support/UngatedCanaryController.php`, which exists only to be caught.
- REQ-4: `tests/Security/UserProvisioningTest.php` — the widened creation-path scan. This is what
  found `DatabaseSeeder`'s unroled `User::factory()->create()`, which
  `tests/Feature/Auth/RegistrationTest.php` could not see (it scans `app/` only, and matches only
  the literal `User::create(`).
- REQ-5: `tests/Security/PrivilegeEscalationTest.php` — 21 probes driven as editor, api, admin and
  guest against the real endpoints.
- REQ-6: `tests/Security/ModelInvariantsTest.php` — mass-assignment scan, the
  `ProfileUpdateRequest` field contract, `$guarded = []` ban, and encrypted-versus-hidden parity
  including a real serialisation.
- REQ-7: `tests/Security/RuntimeExposureTogglesTest.php` — both states of both toggles, the
  admin-only gate on flipping them, and the toggle-set completeness check. Closes the gap
  [rest-api.md](rest-api.md) previously recorded as untested.
- REQ-9: `tests/Security/AdminBootstrapTest.php` — 22 tests, and the first in this repository to
  execute a seeder at all. That absence is part of why the problem survived: the REQ-4 scan reads
  the file and sees an explicit `'role' => 'admin'`, which is all it claims to check, and nothing
  exercised the credentials. Also verified from a real shell, not only through
  `app()->detectEnvironment()`: `APP_ENV=production php artisan db:seed --class=AdminUserSeeder
  --force -n` exits 1 with the refusal message, and does so again when
  `ORCA_ADMIN_PASSWORD=password` is supplied explicitly.
- Shared: `tests/Security/Support/RouteInventory.php`, `tests/Security/Support/SourceScanner.php`.
  `tests/Feature/Auth/RegistrationTest.php` was refactored onto `SourceScanner` rather than keeping
  its own copy of the paren-balancing scanner.
- Run everything: `php artisan config:clear && php artisan test`
- Advisories: `composer audit --locked` and `npm audit --audit-level=high` — both clean at the time
  of writing, so the CI steps are blocking with no ignore list.
- Style: `./vendor/bin/pint --test`
- Spec structure: `npm run spec:lint`

### Mutation results

Each audit was run against a deliberate regression. The four canaries below are permanent tests
rather than a manual checklist, because an audit that can no longer fail is the failure mode this
spec exists to catch:

| Mutation | Result |
|---|---|
| Mount `GET /security-canary-unguarded` with no middleware | REQ-1 audit names it — asserted permanently |
| A policy method whose body is `return true` | REQ-2 detector flags it, a role-checked method does not — asserted permanently |
| Mount `UngatedCanaryController` behind `auth` | REQ-3 audit names it — asserted permanently |
| `User::factory()->create([...])` with no role or state | REQ-4 detector flags it; `'role' =>` and `->admin()` forms pass — asserted permanently |
| Rename `GET /api/health` in the public allowlist | REQ-1 exposure audit names the now-unlisted route, *and* the staleness test names the dangling entry |
| Add a 4th key to `ApiDocsController`'s `in:` rule | REQ-7 coverage audit names the uncovered toggle |
| Add `'role'` to `ProfileUpdateRequest::rules()` | REQ-6 contract test fails, and both REQ-5 profile probes fail — the editor really is promoted, so the probes catch the escalation itself and not merely the config change |
| Remove `two_factor_secret` from `User::$hidden` | three REQ-6 tests fail: encrypted/hidden parity, the credential list, and the real serialisation |
| Drop `->editor()` from `DatabaseSeeder` | REQ-4 audit fails, quoting the statement |

## Open questions / future

- **Sanctum tokens carry no abilities.** `TokenController::store` and `TokenCreateCommand` both
  call `createToken($name)` with no ability list, so a token's power is entirely its user's role
  and `tokenCan()` is never consulted. That is a deliberate simplification
  ([api-tokens-sanctum.md](api-tokens-sanctum.md)), but it means a leaked token held by an admin is
  a full admin credential. No test asserts abilities because there are none to assert.
- **`config/sanctum.php` is unpublished**, so `sanctum.guard` takes the vendor default `['web']`
  and `Auth::guard('sanctum')->check()` consults the session first. Harmless today because the
  `api` middleware group has no `StartSession`, but it becomes a real confusion if session
  middleware is ever added there. Not asserted.
- **No CSRF coverage anywhere in the suite.** Laravel disables the middleware in tests, so this
  needs deliberate `withMiddleware()` handling; worth its own change rather than a partial version
  here.
- **The source-level halves of REQ-4 and REQ-6 are now guarded twice, and the duplication is
  deliberate.** [static-analysis.md](static-analysis.md) REQ-5 restates four of the requirements
  above as Semgrep parse-tree rules over `app/`, `routes/`, `database/seeders` and
  `database/migrations` — so those invariants no longer rest solely on the text scans in
  `tests/Security/Support/SourceScanner.php`, with the weakness that file's own docblock records. The
  text half stays until the rules have a track record; the mutation table there asserts both catch
  the same defect. Not yet equivalent, though: `SourceScanner` covers six creation idioms including
  `DB::table('users')->insert(`, which has no AST counterpart.

  Neither layer can follow a variable, so four `$asset->update($updates)`-shaped writes in `app/` are
  outside both nets. That boundary is recorded there rather than here.

  The other layers: Pest arch bans (language-level, not dataflow), CodeQL (**no PHP support at all**,
  so `resources/js/` and the workflow files only), and PHPStan/Larastan — which was declined in an
  earlier version of that spec on an unmeasured estimate of 150–1,200 findings, then measured at 41
  and **adopted at level 2 with no baseline**. It found two real defects. This bullet previously
  repeated the decline; it was wrong twice over, and the correction lives there.
- **The live guest sweep in REQ-1 covers parameter-less `GET` routes only**, so `GET
  /api/assets/search` still has no direct "requires authentication" assertion — a gap
  [rest-api.md](rest-api.md) records. Parameterised routes would need per-route fixtures; the
  middleware introspection half of REQ-1 covers them in the meantime.
- **REQ-3 accepts a controller-level authorization call, not a per-action one.** A controller that
  gates six of its seven actions passes. Tightening that to per-action would need call-graph
  analysis rather than a source scan.
