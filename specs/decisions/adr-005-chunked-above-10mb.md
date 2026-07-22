# ADR-005 — Chunked S3 multipart above 10 MB, direct upload below

```yaml
id: adr-005-chunked-above-10mb
status: accepted
date: 2026-07-22
deciders: core
related:
  - ../architecture
  - ../features/asset-upload
  - ../features/chunked-upload
```

## Context / Forces

Assets range from tiny SVGs to ~500 MB source files. A single PHP request upload for
a large file demands large `upload_max_filesize`/`post_max_size`, holds a worker for
the whole transfer, and loses all progress on a dropped connection. But forcing every
small upload through multipart machinery is wasteful and slower for the common case.

## Decision

**Auto-select by size**: uploads `<10 MB` go **direct** (`POST /assets`); uploads
`≥10 MB` (up to 500 MB) go **chunked** via S3 Multipart (`ChunkedUploadService`,
10 MB chunks) tracked in `upload_sessions`, with idempotent retries and an abort
path. Both paths converge on `AssetProcessingService` for post-upload work and share
the etag dedup check.

## Alternatives considered

- **Direct upload for everything** — rejected: requires large PHP limits, ties up a
  worker for the whole transfer, and has no resume — a dropped connection at 490 MB
  starts over.
- **Chunked for everything** — rejected: unnecessary session/state overhead and
  extra round-trips for the overwhelmingly common small-file case.
- **A pre-signed direct-to-S3 browser upload** — rejected for v1: it bypasses the
  server, so the allowlist validation, SVG sanitization, and etag dedup would have to
  be re-implemented post-hoc; the server-mediated path keeps those guarantees in one
  place.

## Consequences

- **Good:** small uploads stay simple and fast; large uploads resume and don't need
  giant PHP limits (chunked mode needs only ~15 MB `upload_max_filesize`).
- **Good:** idempotent chunk retries survive flaky networks.
- **Trade-off:** two upload code paths to maintain and test, plus the
  `upload_sessions` table and a cleanup command for abandoned sessions.
