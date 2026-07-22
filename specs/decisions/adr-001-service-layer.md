# ADR-001 — Service layer in `app/Services/` over fat controllers

```yaml
id: adr-001-service-layer
status: accepted
date: 2026-07-22
deciders: core
related:
  - ../architecture
  - ../features/asset-upload
  - ../features/s3-storage
```

## Context / Forces

Asset work is heavy and shared: upload streams to S3, detects dimensions, builds a
thumbnail and S/M/L resizes, dispatches AI tagging, and applies batch metadata. That
same sequence is reached from several entry points — `AssetController`,
`AssetApiController`, `ChunkedUploadController`, and the `ProcessDiscoveredAsset`
job. If each controller owned the logic, the four paths would drift and S3/GD code
would be untestable without an HTTP request.

## Decision

Put all non-trivial work in **services** under `app/Services/` (16 of them) and keep
controllers thin: validate, authorize (Policy), delegate, map the result to a
response. `AssetProcessingService` centralizes post-upload work so every upload path
calls the same code; `S3Service` owns every S3 operation.

## Alternatives considered

- **Fat controllers** — rejected: duplicates the pipeline across four entry points
  and couples S3/image logic to the HTTP layer (hard to unit-test, easy to drift).
- **Eloquent model methods / observers for everything** — rejected: S3 streaming,
  Rekognition dispatch and CDN purge are orchestration, not persistence; burying
  them in model events makes control flow implicit and hard to follow.
- **Actions / single-invokable command classes per operation** — reasonable, but a
  cohesive service (e.g. `S3Service`) groups related operations that share helpers
  and configuration; the team already reads the codebase in service terms.

## Consequences

- **Good:** one code path per concern, unit-testable in isolation (see
  `tests/Unit/Services/`), reused across web + API + job entry points.
- **Good:** controllers stay readable and focused on HTTP concerns + authorization.
- **Trade-off:** more indirection — a reader follows controller → service → S3;
  and "where does this belong" needs judgement when a service grows large.
