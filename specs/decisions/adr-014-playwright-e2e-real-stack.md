# ADR-014 — Browser E2E with Playwright against a real stack (MinIO for S3)

```yaml
id: adr-014-playwright-e2e-real-stack
status: accepted
date: 2026-07-29
deciders: core
related:
  - ../architecture
  - ../features/e2e-testing
  - ../features/s3-storage
  - adr-007-blade-alpine-over-spa
  - adr-008-sqlite-tests
  - adr-013-wordpress-plugin-separate-stream
```

## Context / Forces

[ADR-007](adr-007-blade-alpine-over-spa.md) put the interaction logic in 21 Alpine
modules rendered by Blade. Pest exercises everything *behind* the response body:
no Pest test evaluates the grid's selection store, the bulk bar, the tag inputs, or
the uploader's progress/duplicate handling. Those are exactly the parts that break
silently. The WordPress plugin already proved Playwright works for this team
([ADR-013](adr-013-wordpress-plugin-separate-stream.md)), but that suite runs
against a *mock* ORCA backend, which is only appropriate because the plugin is a
client of ORCA, not ORCA. For the app itself the interesting failures — an upload
that stores the wrong Content-Type, a thumbnail that never appears, a duplicate
that isn't detected — live in the seam between the app and object storage, so a
suite that mocks storage would test the least risky half. Against that: real AWS
in CI means credentials, cost and cross-run interference, and
[ADR-008](adr-008-sqlite-tests.md) already accepts a test/prod database divergence
for speed.

## Decision

E2E tests are **Playwright specs in `tests/e2e/`** driving a real booted app
(`php artisan serve --env=e2e`) against a throwaway SQLite file and a **local
MinIO bucket** in place of S3, wired as a blocking CI job. To make MinIO reachable,
`S3Service::__construct` now honours `filesystems.disks.s3.endpoint` and
`use_path_style_endpoint` (already present in `config/filesystems.php`, previously
ignored) — the single production change this decision requires. Selectors are
`data-testid` attributes so locators survive the `en`/`nl` locale switch. The
contract, seeding strategy and the list of what is deliberately *not* covered are
in [`../features/e2e-testing.md`](../features/e2e-testing.md).

## Alternatives considered

- **Laravel Dusk** — rejected because it is a PHP wrapper around ChromeDriver with
  no trace viewer, no auto-waiting locators, and no reuse of the Playwright
  knowledge, tooling and CI recipe the WordPress plugin already established. Two
  browser-automation stacks in one repo is a tax with no upside.
- **Cypress** — rejected for the same "second stack" reason, plus its
  same-origin/iframe model fights the `/assets/embed` and cross-origin S3 URL
  cases this suite is meant to cover.
- **Mock S3 in the browser (route interception) or a `Storage::fake`-style local
  disk** — rejected because it deletes the coverage that motivated the suite:
  etag-based duplicate detection, streamed uploads, server-detected Content-Type
  and thumbnail generation are all storage-boundary behaviour. A local-disk shim
  would also have meant a permanent production branch in `S3Service` (a fake
  driver), which is a bigger and riskier change than honouring two config keys
  that already exist.
- **Real AWS S3 with a dedicated CI bucket** — rejected: it puts long-lived
  credentials in CI for a test suite, costs money per run, and makes concurrent PR
  runs share mutable state. MinIO is API-compatible for everything the app uses.
- **`RefreshDatabase`-style per-test transactions** — not available across an HTTP
  boundary; the suite reseeds per spec file instead (`workers: 1`).

## Consequences

- **Good:** the Alpine layer, the Blade templates, the middleware stack and the
  storage boundary are all covered by one suite that fails the way a user would
  experience the bug. Traces/videos on failure make CI failures diagnosable
  without reproducing locally.
- **Good:** honouring `AWS_ENDPOINT` also unblocks any future non-AWS
  S3-compatible provider (R2, Wasabi) without further code change.
- **Trade-off:** CI now needs a container runtime step (MinIO) and a Chromium
  download, adding a few minutes to every PR — and a developer without Docker can
  only run the non-storage specs (`requiresS3()` skips the rest).
- **Trade-off:** `data-testid` attributes are test-only markup living in
  production views, and the suite is serialized (`workers: 1`), so it grows
  linearly in wall-clock time. Both are accepted in exchange for determinism.
- **Trade-off:** the browser is cut off from every host except the app and the
  bucket, so the CDN-hosted Font Awesome and webfont the layouts load are *not*
  under test and have to be stubbed for icon-only controls to be clickable
  (`e2e-testing.md` REQ-11). A CDN outage or a broken `integrity` hash is
  therefore invisible to this suite — the cost of not depending on egress.
- **Trade-off:** e2e specs are exempt from the SDD production-code gate (they live
  under `tests/**`), so the spec ↔ suite link is maintained by the `# pinned by:`
  convention and `scripts/spec-lint.mjs`, not by the guard.
