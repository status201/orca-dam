# ADR-006 — `s3_key` is immutable — cache-bust via Cloudflare purge, never key rewrite

```yaml
id: adr-006-immutable-s3-key
status: accepted
date: 2026-07-22
deciders: core
related:
  - ../architecture
  - ../features/asset-model
  - ../features/asset-replace
  - ../features/cloudflare-purge
```

## Context / Forces

Asset URLs derive from the S3 key and get embedded in external systems — RTE
content, published pages, WordPress posts. If the key changed when a user renamed an
asset or replaced its bytes, every embedded URL would break. But users legitimately
need to rename assets and to replace a file's contents (e.g. a corrected image) while
keeping the same URL, and a CDN in front of S3 will happily serve the stale copy.

## Decision

`assets.s3_key` is **immutable** for the life of the row (`assets/{folder}/{uuid}.{ext}`,
UUID-based so it never collides). The editable display name is `filename`, a separate
column. **Replace** overwrites the bytes at the same key; freshness is handled by a
**Cloudflare cache purge** (`CloudflareService`) of the affected URLs, never by
minting a new key. Bulk **move** is the sole exception — an explicit admin operation
gated on `maintenance_mode` that copies+deletes and rewrites the key knowingly.

## Alternatives considered

- **Key = filename, rewrite on rename** — rejected: renaming would break every
  embedded URL, and filename collisions would need disambiguation in the key.
- **New key (new UUID) on every replace** — rejected: same URL-breakage problem, and
  it defeats etag dedup and leaves orphaned objects.
- **Query-string cache-busting (`?v=2`)** — rejected: doesn't help URLs already
  embedded without the param, and CDNs may cache per full URL anyway; an explicit
  purge is deterministic.

## Consequences

- **Good:** embedded URLs are stable forever; replace keeps the URL and the CDN
  serves fresh bytes after purge.
- **Good:** UUID keys never collide and decouple storage identity from the mutable
  display name.
- **Trade-off:** the storage key isn't human-readable; and correct cache behaviour
  depends on the purge firing (best-effort, non-blocking — a purge failure is logged,
  not fatal, so a stale edge cache is possible until TTL expiry).
