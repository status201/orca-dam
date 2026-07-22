# ADR-003 — Soft delete keeps S3 objects; only hard delete clears storage

```yaml
id: adr-003-soft-delete-keeps-s3
status: accepted
date: 2026-07-22
deciders: core
related:
  - ../architecture
  - ../features/asset-trash
  - ../features/discovery-import
```

## Context / Forces

Deleting a digital asset is high-consequence: the S3 object may be referenced by
published pages, RTE content, or WordPress posts, and an accidental delete is
expensive to reverse. But storage isn't free either, so there must be a way to truly
reclaim it. Discovery also re-scans S3, so a "deleted" asset must not silently
reappear on the next import.

## Decision

**Soft delete** (`deleted_at`) is the default: it hides the asset and frees the
name in the UI but **keeps the S3 objects** (original + thumbnail + resizes), so
Restore is instant and lossless. **Hard delete** (force delete, admin-only) clears
both DB and S3 (all variants). Discovery flags soft-deleted assets with a "Deleted"
badge so they are not re-imported.

## Alternatives considered

- **Hard delete always** — rejected: no undo for a destructive, externally-visible
  action; one misclick loses a published asset.
- **S3 lifecycle/versioning as the only safety net** — rejected: it protects the
  bytes but not the DB row, metadata, tags, or the discovery "don't re-import"
  signal; and it's bucket config, invisible in-app.
- **A separate trash bucket / move-on-delete** — rejected: doubles the S3 key
  bookkeeping and breaks the immutable-`s3_key` invariant ([ADR-006](adr-006-immutable-s3-key.md))
  for a benefit soft delete already provides.

## Consequences

- **Good:** Restore is a single `deleted_at = null`; no S3 round-trip, no data loss.
- **Good:** force delete is a distinct, admin-gated, irreversible operation — the
  danger is explicit.
- **Trade-off:** soft-deleted assets keep consuming S3 storage until someone force-
  deletes them; the two-step model is more concepts for a user to understand.
