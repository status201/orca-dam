# ADR-017 — RustFS replaces the archived MinIO as the E2E S3 stand-in

```yaml
id: adr-017-rustfs-replaces-minio
status: accepted
date: 2026-09-14
deciders: core
related:
  - ../features/e2e-testing
  - ../features/s3-storage
  - adr-014-playwright-e2e-real-stack
```

> This amends [ADR-014](adr-014-playwright-e2e-real-stack.md) on one point — which
> S3-compatible server the browser suite stores into. Everything else ADR-014
> decided (Playwright over Dusk/Cypress, a real stack over a mocked one, a local
> bucket over real AWS) is unchanged and still holds, so ADR-014 stays `accepted`
> rather than superseded.

## Context / Forces

[ADR-014](adr-014-playwright-e2e-real-stack.md) chose a local MinIO bucket as the
S3 stand-in because it was API-compatible for everything the app does and cost
nothing to run in CI. MinIO has since stopped being a maintained artifact: the
project stopped publishing community Docker images and pre-built binaries in
October 2025, declared maintenance mode that December, and the repository was
archived in 2026 — the community edition is source-only now. A pinned image tag
that already exists keeps working until it doesn't; what is gone is any future
image, any security patch, and any expectation that `docker pull` in CI will keep
resolving. Two places depended on it: `docker-compose.e2e.yml` (`minio/minio` plus
a `minio/mc` container purely to create the bucket) and the `e2e` job in
`.github/workflows/tests.yml`.

A second force showed up while replacing it. `docker` is not present on every
machine that runs this suite, and MinIO shipped no binary to fall back to, so
`requiresS3()` meant CI was in practice the only place the upload, replace and
discovery specs ever ran.

## Decision

The E2E S3 stand-in is **[RustFS](https://github.com/rustfs/rustfs), pinned to
`1.0.0-rc.6`**, started by `scripts/e2e-storage.mjs` behind the unchanged
`npm run e2e:up` / `e2e:down` contract. The script uses `docker-compose.e2e.yml`
when Docker is available and otherwise downloads the RustFS release binary into
`storage/e2e/`, verifies it against a SHA-256 pinned alongside the version, and
runs it detached — so the storage specs can run on a machine with no container
runtime, which was never possible with MinIO.

The bucket is created and opened to anonymous `s3:GetObject` by
`tests/e2e/support/bucket.js`, which signs the two S3 requests (`PUT /{bucket}`,
`PUT /{bucket}?policy`) with AWS SigV4 over `node:crypto`. That replaces both the
`minio/mc` container and CI's use of the runner's `aws` CLI, so the bucket is now
provisioned by one code path everywhere instead of two that only ever diverged
silently. Provisioning asserts the anonymous read took, because a bucket that is
not publicly readable otherwise fails much later as a thumbnail that never loads.

The endpoint moved to port **9100**. Nothing about RustFS requires that; 9000 is
simply contended on developer machines (PhpStorm binds it), and the failure mode
was bad — see the trade-off below.

## Alternatives considered

- **Stay on the last published MinIO image** — rejected: it works today and
  decays from here. No patches, no new tags, and a CI job whose storage backend
  is an unmaintained pull is a slow-motion outage, not a stable pin.
- **Garage, SeaweedFS, Ceph/RGW** — rejected mainly on setup cost for what this
  is: Garage needs a layout/cluster init step before a bucket exists, SeaweedFS
  wants a multi-process topology, and RGW is far more machinery than one bucket
  in a test job. RustFS starts as a single process with a data directory, which
  is what MinIO's role here actually was.
- **LocalStack** — rejected: it emulates all of AWS to get one bucket, starts far
  slower, and its S3 is a reimplementation aimed at API surface rather than a
  server anyone stores real bytes in.
- **Real AWS S3 with a dedicated CI bucket** — rejected again, for the reasons
  ADR-014 already gave: long-lived credentials in CI for a test suite, cost per
  run, and concurrent PR runs sharing mutable state.
- **Keep `minio/mc` (or use `amazon/aws-cli`) for bucket setup** — rejected: `mc`
  is archived with the rest of MinIO, and either choice means pulling a second
  image to make two HTTP calls — an image that is unavailable on exactly the
  Docker-less machine the binary fallback exists to serve. Hand-signing SigV4 is
  ~150 lines and is the same trade this harness already makes in
  `tests/e2e/support/files.js`, which hand-rolls a PNG writer rather than take a
  dependency.

## Consequences

- **Good:** the storage backend is maintained again, and the suite's storage
  specs can now run locally without Docker — the coverage ADR-014 wanted most
  (etag-based duplicate detection, streamed uploads, thumbnail generation) stops
  being CI-only.
- **Good:** one provisioning path for local and CI. The MinIO setup used `mc`
  locally and the runner's `aws` CLI in the workflow, so the local path was the
  only one anyone exercised and the CI path was the only one that mattered.
- **Trade-off:** RustFS is pre-1.0 (`1.0.0-rc.*`). It is pinned exactly, so it
  cannot move under a PR, but nothing bumps it automatically either — Dependabot
  has no manifest to watch for a docker tag in a compose file or a version
  constant in a script, so it moves when someone edits
  `scripts/e2e-storage.mjs` and `docker-compose.e2e.yml` together and re-records
  the digests from the release's `SHA256SUMS`.
- **Trade-off:** the harness now downloads and executes a binary from a GitHub
  release. The SHA-256 of each platform's archive is pinned next to the version
  and verified before anything is unpacked, because a pinned version with an
  unpinned payload is only pretending to be pinned.

  CodeQL reports this as
  [`js/http-to-file-access`](https://github.com/status201/orca-dam/security/code-scanning/18)
  ("network data written to file") against the `writeFileSync` in `ensureBinary()`,
  and it is right about the dataflow: bytes arrive over HTTP and land on disk.
  The alert is **dismissed as `used_in_tests`** rather than fixed, which is the
  opposite of how the mirror-image finding (`js/file-access-to-http`, on the S3
  probe) was handled, so the reasoning belongs here rather than in a dashboard:

  - The digest check is the mitigation and it runs *before* the write, so nothing
    unverified is ever unpacked or executed. CodeQL has no sanitizer concept for
    a checksum comparison, so no arrangement of this code clears the rule.
  - The write itself is unavoidable: `tar` needs a real file, and the archives
    are zips, which Node cannot unpack in-memory without a dependency.
  - `scripts/e2e-storage.mjs` is E2E tooling. It is never shipped, never loaded
    by the app, and runs only when a developer or CI asks for a bucket — which is
    what `used_in_tests` means, exactly.

  What would *not* be an improvement: shelling the download out to `curl` so the
  network-to-file flow leaves JavaScript. That hides the finding from the
  analyzer while making the situation genuinely worse, because the file would
  then reach disk before anything verified it.
- **Trade-off:** we own a SigV4 implementation. It signs two requests against a
  local server and is exercised on every `e2e:up`, so it cannot rot unnoticed —
  but it is ours to fix.
- **Trade-off:** the endpoint port is no longer the conventional 9000. That is a
  one-line surprise for anyone who knows the convention, bought in exchange for
  not colliding with PhpStorm — a collision that was not merely inconvenient: the
  IDE accepted the TCP connection and never answered, which hung the
  single-process `artisan serve` for PHP's whole `max_execution_time` and failed
  four unrelated specs with timeouts that named nothing relevant. The S3 client
  now bounds its own waits regardless ([`s3-storage.md`](../features/s3-storage.md)
  REQ-8), so the same collision would be loud rather than baffling.
