# Specs — Spec-Driven Development for ORCA DAM

This folder is the **architectural source of truth** for ORCA DAM. Specs are
written for both humans and AI agents to read, so an agent can act from a current,
authoritative description of *behaviour* instead of re-deriving intent from stale
code snapshots.

We use **Spec-Driven Development (SDD)**: the spec comes first and the code is
treated as a (re-generatable) consequence of it. In practice that means two things:

1. **Document** the load-bearing architecture that already exists, so it stops
   living only in people's heads (and only in `CLAUDE.md`'s conventions list).
2. **Spec-first for new work** — write/review the spec for a new feature *before*
   generating the code that implements it.

## What a good spec contains

Every feature spec aims to cover, in roughly this order:

- **Background / the "why"** — the context behind the "what", so a reader (or
  agent) can reason ahead about the steps they'll need.
- **Requirements** — the design broken into discrete, numbered pieces (`REQ-1`,
  `REQ-2`, …), not a vague one-liner.
- **Technical design** — the public **contract/interface** (routes, controller
  actions, service methods, model scopes, policy abilities, JSON shapes), the
  **data shapes** (DB columns, payloads, responses), the **layer touchpoints**
  (request → middleware → controller → service → S3/queue), and **persistence**
  (tables, S3 keys, cache keys, and what is *deliberately not* persisted).
- **Visual aids** — a diagram where it helps, plus tools/libraries with **explicit
  version numbers**.
- **Scenarios** — what good looks like, what failure looks like, and the edge
  cases, written as **BDD/Gherkin** (see below).
- **Tests & verification** — which suites pin the behaviour and how to run them.

> This is the **feature** shape (the *what*). **Recipes** (`recipes/`) follow a
> leaner *playbook* shape — Background → Steps → Gotchas → Scenarios (BDD) → Tests,
> dropping Requirements / Technical-design / Visual-aids — so copy
> `recipes/_recipe-template.md`, not the feature template. **ADRs** (`decisions/`)
> use their own Context → Decision → Alternatives → Consequences shape
> (`decisions/_adr-template.md`).

## ORCA is the shape SDD assumes — concept mapping

The general SDD literature assumes a backend with a database and a REST API. ORCA
DAM **is exactly that shape**, so the usual spec ingredients map 1:1 onto what the
repo actually has — no translation needed:

| General SDD concept   | ORCA DAM equivalent                                                                      |
| --------------------- | ---------------------------------------------------------------------------------------- |
| Database schema       | `database/migrations/` + the Eloquent models (`Asset`, `Tag`, `User`, …) + the `settings` table |
| API contract          | `routes/api.php` + `routes/web.php` + Policy abilities + the controllers' request/response shapes |
| Persistence / model   | MariaDB tables, the S3 key layout (`assets/{folder}/{uuid}.{ext}`), `users.preferences` |
| System diagram        | request → middleware → controller → service → S3 / queue                                 |

See [`architecture.md`](architecture.md) for the system-wide version of all four.

## Specs vs. the existing docs

ORCA already has excellent documentation. Specs do **not** replace it — they add
the one layer that was missing (per-feature *behavioural contracts*). Keep the
lanes separate; specs **link**, they never restate:

- **`specs/`** — the architectural / behavioural contract (this folder). The
  source of truth for *how the system must behave*.
- **`README.md` / `USER_MANUAL.md` / `GEBRUIKERSHANDLEIDING.md`** — user-facing.
- **`SETUP_GUIDE.md` / `DEPLOYMENT.md` / `RTE_INTEGRATION.md`** — ops / integration-facing.
- **`CLAUDE.md`** — the exhaustive *conventions* reference (naming, commands, file
  layout). A spec points at it for conventions rather than duplicating them.

**Cross-cutting facility rule** — a reusable facility that many features lean on
gets its **own** spec, and feature specs reference it instead of re-describing it:
e.g. [`features/settings.md`](features/settings.md),
[`features/tag-input.md`](features/tag-input.md),
[`features/upload-policy.md`](features/upload-policy.md),
[`features/s3-storage.md`](features/s3-storage.md).

## Format rules

LLMs process tokenized text, so format affects accuracy *and* cost. Keep `specs/`
a **lean compiled instruction set, not a dumping ground** — every needless newline
and indent costs tokens and latency.

- **Markdown headers** for narrative / instructions.
- **Flat YAML** (in a fenced ```` ```yaml ```` block) for structured config and for
  any schema nested **more than ~3 levels deep** — prefer flat key paths over deep
  trees.
- Reference code **by symbol name** (`S3Service::putUploadedFile`,
  `Asset::scopeSearch`, `AssetPolicy::forceDelete`) and file path, **not** by line
  number — line numbers rot.
- Don't restate things that belong in `CLAUDE.md` or code comments verbatim; link
  to them. Specs are standalone enough to read on their own, but they should not
  duplicate the entire conventions list — point at it.

## BDD — scenarios in Gherkin

Behaviour is specified as `Scenario / Given / When / Then`. This forces
**State → Action → Outcome** thinking and removes guesswork about what "done"
means. Every scenario should name the **test that pins it**, so the spec stays
backed by a green check:

```gherkin
Scenario: An API-role token cannot delete an asset
  Given an authenticated user with role "api"
  When they send DELETE /api/assets/{id}
  Then the response status is 403
  And the asset is not soft-deleted
# pinned by: tests/Feature/ApiTest.php
```

The `# pinned by:` path must point at a **real** test file. `scripts/spec-lint.mjs`
fails on a path that does not exist — never invent one, and note that the same rule
applies to paths named in `## Tests & verification`, not just to pin lines. A behaviour
with no test is a **finding**: write the scenario and list the missing coverage under the
spec's "Open questions / future"; do not fabricate a pin or write the test just to
satisfy the lint.

**Browser-level scenarios belong to the feature, not to the suite.** A Playwright spec
is pinned by the feature spec that owns the behaviour, in a block at the end of that
spec's scenarios introduced by a `# — browser-level —` marker comment:

```gherkin
# — browser-level (see e2e-testing.md for the harness) —

Scenario: Deleting an asset moves it to trash and restoring brings it back
  Given the list view of the grid
  When an asset is deleted from its row
  Then it disappears from the grid and appears in /assets/trash/index
# pinned by: tests/e2e/asset-trash.spec.js
```

[`features/e2e-testing.md`](features/e2e-testing.md) specifies only the **harness** —
reseeding, saved role sessions, the MinIO skip, the disposable artefacts. It does not
restate application behaviour, so nothing is specified twice. See
[`recipes/write-an-e2e-test.md`](recipes/write-an-e2e-test.md).

## Spec lifecycle

Each feature spec carries a `status` in its metadata block:

- `draft` — being written / under review; not yet built.
- `active` — agreed and being implemented.
- `implemented` — shipped; the spec now documents what exists and is the source of
  truth for future changes.

For **new** features: write the spec as `draft`, get a human to catch logic flaws
*before* an agent generates code, then move it to `active`/`implemented`.

## Decisions (ADRs) — the "why" layer

Feature specs capture the **what** and recipes the **how**, but the *why* behind a
cross-cutting architectural choice — and crucially, **which alternatives were
rejected and the trade-off accepted** — otherwise lives only implicitly in prose.
That's what makes a deliberate constraint indistinguishable from an accident, and
invites someone to "fix" a thing that was chosen on purpose.

`decisions/` holds **Architecture Decision Records**: one short, immutable record
per decision, structured Context → Decision → Alternatives → Consequences. Copy
[`decisions/_adr-template.md`](decisions/_adr-template.md) to start one
(`adr-NNN-<slug>.md`, numbered contiguously); [`decisions/README.md`](decisions/README.md)
is the index. An ADR's `status` is its own decision lifecycle —
`proposed | accepted | superseded by adr-XXX | deprecated` — **not** the
`draft | active | implemented` of a feature spec. ADRs are append-only: to change a
decision, write a new ADR that supersedes the old one rather than rewriting it. Each
ADR's `related:` links to the specs it governs, and [`architecture.md`](architecture.md)
→ "Key decisions" links back to the ADRs.

## Procedure by change type

**Spec-before-code**: a change that edits production code (`app/**`, `routes/**`,
`database/migrations/**`, `config/**`, `resources/js/**`) must create/update a spec
in the *same* change — the SDD hooks + CI enforce this (see "Enforcement &
exemptions"). By kind of change:

**Feature / any behaviour change**
1. **Spec first** — create/update `specs/features/<name>.md` from
   `features/_feature-template.md` (`status: draft`): background, requirements
   (`REQ-n`), contract, data shapes, BDD scenarios.
2. **Review** — a human reads the spec (plan-approval / PR). `status: active`.
3. **Implement** the code to satisfy the spec.
4. **Test** — add tests mapping to each Gherkin scenario.
5. **Reconcile** — `status: implemented`; the spec matches what shipped.

**Bug fix** — find the governing spec. If it's wrong/missing, fix the spec first;
if the code merely diverged from a correct spec, add a **regression scenario**
capturing the bug. Then implement the fix + a test mapping to that scenario.

**Refactor (no behaviour change)** — the spec is the invariant; keep its contract +
scenarios green. Only edit the spec if a public **contract** changes.

**Trivial** — typos, comments, formatting, pure renames, dep bumps, and
test/style/doc edits need no spec (see exemptions below).

**Bump the spec's `version:` when its contract changes** — that is, when
`## Requirements` or `## Technical design` changes. It is a plain integer counting
contract revisions, and it is enforced (see "Enforcement & exemptions"), because for a
long time it was a comment in the template that nothing checked: 45 of 47 specs sat at
`1` while their requirements were rewritten underneath them.

Editing anything **else** must *not* bump it — `## Background / Why`,
`## Scenarios (BDD)`, `## Tests & verification`, `## Open questions / future`, or a
`status:` transition. This is deliberate and load-bearing for the bug-fix flow above:
that flow prescribes adding a **regression scenario**, so counting scenarios as contract
changes would force a bump on nearly every fix and the number would stop meaning
anything. The corollary is that fixing a *wrong requirement* does bump, because the
documented contract really did change even when behaviour did not.

The `/feature`, `/fix`, and `/spec` slash commands run these flows.

## Enforcement & exemptions

SDD is enforced by `scripts/sdd-guard.mjs`, wired as Claude Code hooks
(`.claude/settings.json`) and a CI check (`.github/workflows/sdd.yml`):

- **PreToolUse** blocks an `Edit`/`Write` to production code when no spec changed.
- **Stop** blocks finishing a turn whose diff changed production code without specs,
  **or** changed a feature spec's contract without incrementing its `version:`.
- **CI** fails a PR with the same two rules (also catches non-Claude agents + direct
  commits to `main`).
- **`npm run spec:version`** runs the version rule on its own against the base ref.

The version rule diffs the `## Requirements` and `## Technical design` sections of each
changed `specs/features/*.md` against the base ref, so it is blind to line-number shifts
and to edits anywhere else in the file. A **new** spec is skipped (there is no previous
contract to have changed), and so is a rename. Both rules share the bypass below — a typo
inside a `REQ-` line is the known false positive and that is its answer. Two scope limits
worth knowing: `recipes/` carry a `version:` but follow the leaner playbook shape with no
contract section for the rule to read, and ADRs carry no `version:` at all (they are
superseded, not revised). `spec-lint` requires the key on every feature spec, so the gate
cannot be disabled by deleting it.

**Gated (needs a spec):** `app/**`, `routes/**`, `database/migrations/**`,
`config/**` (except Laravel-published framework configs), `resources/js/**`
(except `resources/js/vendor/**`).

**Exempt automatically** (no spec needed): everything else — `resources/views/**`,
`resources/css/**`, `lang/**`, `database/factories/**`, `database/seeders/**`,
`tests/**`, `public/**`, `wordpress-plugin/**`, all `*.md`, and the framework
config files Laravel publishes (`config/app.php`, `auth`, `cache`, `database`,
`filesystems`, `logging`, `mail`, `queue`, `services`, `session`).

**Explicit bypass** for a rare trivial *production* change: `touch .sdd-skip`
(a gitignored sentinel) locally, or put `[skip-sdd]` in a commit message / add the
`skip-sdd` PR label for CI.

> The guard checks that a spec *changed*, not that it is *good* — spec quality is
> the human review gate. A companion check, `scripts/spec-lint.mjs`
> (`npm run spec:lint`), validates spec *structure*: a metadata block, `id`
> matching the filename, a valid `status`, a `## Tests & verification` section on
> every feature/recipe, and that every spec/ADR is listed in this folder map **and**
> the `decisions/` index.
>
> **Test paths.** Every `tests/…` path a spec names must resolve — both on a
> `# pinned by:` line and in a backticked `## Tests & verification` bullet. The
> bullets went unchecked for a while, which let a spec keep advertising coverage
> after the test file was gone. Paths under `## Open questions / future` are
> exempt: that section is where the method says to record coverage that does *not*
> exist yet.
>
> It also checks documented *facts* don't rot, over `specs/**` **and** the root docs
> (`README.md`, `CLAUDE.md`, `QUICK_REFERENCE.md`, `SETUP_GUIDE.md`,
> `DEPLOYMENT.md`, `CHANGELOG.md`, `.claude/agents/*.md`) — the same wrong number
> used to sit in three files at once:
> - **Dependency versions** must match the **constraints** in `composer.json` /
>   `package.json` (never `composer.lock` — a routine `composer update` inside a
>   range must not fail the build, only a real constraint change). A version may be
>   coarser than the constraint (`^13` for `^13.0`) but never contradict it.
> - **Hand-counted totals**: feature specs, ADRs, Alpine modules, services, console
>   commands, `*Test.php` files, and the Playwright test + spec-file counts. The E2E
>   total counts loop-generated cases, not just literal `test(` calls.
> - **The file tree** in `QUICK_REFERENCE.md` — the repo's only one — must name every
>   `app/Services/` and `app/Console/Commands/` entry and every tracked top-level
>   directory, and nothing that has been deleted.
> - **Manual parity**: `GEBRUIKERSHANDLEIDING.md` must mirror `USER_MANUAL.md`'s
>   heading-level sequence, so the Dutch manual cannot silently fall behind.
>
> `CHANGELOG.md` is checked only inside `[Unreleased]`; released entries are a
> historical record of what shipped then and must not be rewritten.
>
> It runs in CI (PRs **and** pushes to `main`) **and** unconditionally as a local
> `Stop` hook. Hooks can be disabled by editing `.claude/settings.json`, so CI is
> the real cross-tool backstop.

## Folder map

```
specs/
  README.md            ← you are here (the method)
  architecture.md      ← system-wide source of truth (read this first)
  features/            ← one spec per feature
    _feature-template.md ·  copy this to start a new feature spec
    # — assets & storage —
    asset-model.md         ·  Asset entity, scopes, computed attrs, license fields
    asset-upload.md        ·  direct upload pipeline (validate → dedup → S3 → process)
    chunked-upload.md      ·  S3 multipart >=10MB, upload_sessions, retries, abort
    duplicate-detection.md ·  etag dedup, DuplicateAssetException, 409 payload
    s3-storage.md          ·  S3Service: keys, streaming, ContentType, CDN URL
    image-processing.md    ·  thumbnails + S/M/L resizes, animated-GIF handling
    asset-replace.md       ·  replace bytes, thumbnail regen, CDN purge
    asset-trash.md         ·  soft delete / restore / force delete, S3 lifecycle
    bulk-operations.md     ·  bulk tags/trash/restore/move/download/force-delete
    asset-search.md        ·  search operators, URL-prefix stripping, sort values
    asset-cycle-navigation.md · show-page prev/next over the index result set
    folder-management.md   ·  s3_folders list: read / scan / create endpoints
    s3-integrity.md        ·  verify-integrity command + job, s3_missing_at
    discovery-import.md    ·  S3 discovery → import → ProcessDiscoveredAsset
    csv-export-import.md   ·  33-column export; import diff → validate → apply
    # — tags —
    tags.md                ·  Tag types, "last attacher wins" attribution
    tag-input.md           ·  TagInputParser + shared tag-input-core.js
    ai-tagging.md          ·  Rekognition + Translate, GenerateAiTags job
    # — auth & access —
    authentication.md      ·  session login + auth.multi resolution
    authorization-policies.md · the role × ability matrix (no return true)
    api-tokens-sanctum.md  ·  long-lived Sanctum tokens + console commands
    jwt-auth.md            ·  JwtGuard, claims, per-user encrypted secret
    passkeys.md            ·  WebAuthn/FIDO2, 10/user cap, TOTP bypass
    two-factor-auth.md     ·  TOTP setup / verify / recovery codes
    user-management.md     ·  user CRUD + role assignment (admin-only)
    user-audit-log.md      ·  append-only trail of user create/re-role/delete
    # — API —
    rest-api.md            ·  REST endpoint/pagination/filter/sort/error contract
    reference-tags-api.md  ·  reference-tag add/remove endpoints (API-only create)
    api-docs-admin.md      ·  /api-docs admin dashboard, tokens, JWT secrets
    # — platform / cross-cutting —
    settings.md            ·  Setting key-value model, 1h cache, groups
    input-validation.md    ·  ColumnLimits as the source of truth; the rule↔column audit
    error-handling.md      ·  QueryException → keyed 422, error reference id, no bare 500s
    localization.md        ·  SetLocale, nl.json ownership, JS translation channels
    security-headers.md    ·  SecurityHeaders middleware (nosniff, XFO, HSTS)
    security-invariants.md ·  self-auditing route/policy/source invariants + exploit probes
    iframe-embedding.md    ·  AllowEmbedding CSP frame-ancestors + /assets/embed
    upload-policy.md       ·  allowlist + AllowedUploadExtension + SVG sanitize
    cloudflare-purge.md    ·  non-blocking CDN purge on replace / thumbnail regen
    user-preferences.md    ·  users.preferences JSON + profile settings
    dashboard.md           ·  post-login stats + the role-built feature tour
    guided-demos.md        ·  interactive onboarding walkthroughs (spotlight, cross-page)
    queue-jobs.md          ·  the 5 queued jobs and their dispatch
    # — system & tools —
    e2e-testing.md         ·  Playwright browser suite: roles, seeding, MinIO, CI
    static-analysis.md     ·  arch bans, Semgrep rules, CodeQL — and what each cannot see
    system-admin.md        ·  /system dashboard, queue, logs, web test runner
    maintenance-commands.md ·  the 17 console commands + contracts
    tikz-render.md         ·  TikzCompilerService pipeline + security posture
    client-side-tools.md   ·  GIF maker, LaTeX→MathML, client TikZ; ToolUploadService
    easter-egg-game.md     ·  GameScore, leaderboard, lazy-loaded bundle
  recipes/             ← repeatable how-tos / playbooks
    _recipe-template.md      ·  copy this to start a new recipe
    add-a-service.md         ·  a new app/Services/ service (DI, swallow+log)
    add-an-api-endpoint.md   ·  route → AssetApiController action → policy → JSON
    add-a-policy-ability.md  ·  a policy ability with explicit role lists
    add-a-migration.md       ·  the ripple: migration → model → factory → CSV → search → API → tests
    add-an-alpine-module.md  ·  a resources/js/alpine/ module + app.js registration
    add-a-guided-demo.md     ·  a new app/Demos/ walkthrough (storyboard → class → registry)
    add-a-console-command.md ·  an artisan command + Feature/Console test
    add-a-queued-job.md      ·  an app/Jobs/ job (dispatch, tries, sync-in-tests)
    add-a-setting.md         ·  a runtime Setting (default, get/set, cache)
    add-a-translated-string.md · a __() string + nl.json entry (lang:safe-update)
    write-a-test.md          ·  Pest conventions, config:clear, factory states
    write-an-e2e-test.md     ·  Playwright spec: data-testid, role state, reseed
  decisions/           ← Architecture Decision Records (the "why")
    README.md            ·  the ADR index (number / title / status)
    _adr-template.md     ·  copy this to start a new ADR
    adr-000-spec-driven-development.md          ·  SDD leads code (enforced)
    adr-001-service-layer.md                    ·  services over fat controllers
    adr-002-explicit-policy-roles.md            ·  no `return true` policy stubs
    adr-003-soft-delete-keeps-s3.md             ·  soft delete keeps S3; hard clears it
    adr-004-auth-multi.md                       ·  four auth mechanisms behind auth.multi
    adr-005-chunked-above-10mb.md               ·  chunked >=10MB, direct below
    adr-006-immutable-s3-key.md                 ·  s3_key immutable; purge not rewrite
    adr-007-blade-alpine-over-spa.md            ·  Blade + Alpine over an SPA
    adr-008-sqlite-tests.md                     ·  in-memory SQLite tests vs MariaDB prod
    adr-009-project-owns-nl-json.md             ·  project owns lang/nl.json
    adr-010-services-swallow-controllers-map.md ·  services swallow+log; controllers map codes
    adr-011-settings-in-db.md                   ·  runtime settings in the DB
    adr-012-reference-tags-api-only.md          ·  reference tags API-created only
    adr-013-wordpress-plugin-separate-stream.md ·  WP plugin is a separate release stream
    adr-014-playwright-e2e-real-stack.md        ·  Playwright E2E on a real stack (MinIO)
    adr-015-guided-demos-server-declared.md     ·  demos declared in PHP; spotlight hand-rolled
    adr-016-database-errors-are-user-errors.md  ·  driver rejections → keyed 422 (amends adr-010)
```

> This map grows as the backfill lands (see `_backfill-status.md` while it exists).
> New features get their own spec (copy `features/_feature-template.md`) as they're
> built — keep the folder lean, not exhaustive-for-its-own-sake. Every `.md` under
> `specs/` must be listed here, and every ADR in `decisions/README.md` — the
> structure lint enforces both.

## Writing a new spec

1. For a feature, copy [`features/_feature-template.md`](features/_feature-template.md)
   to `features/<feature>.md`; for a repeatable how-to, copy
   [`recipes/_recipe-template.md`](recipes/_recipe-template.md) to `recipes/<recipe>.md`.
2. Fill the metadata block, then the sections top-to-bottom. Delete sections that
   genuinely don't apply rather than leaving empty headings.
3. Write scenarios in Gherkin and name the **real** tests that pin them.
4. Add the new file to the **Folder map** above (and, for an ADR, to
   `decisions/README.md`). Keep it lean.
