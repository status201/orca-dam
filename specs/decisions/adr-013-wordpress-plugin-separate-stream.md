# ADR-013 — The WordPress plugin is a separate release stream (`wp-v*` tags)

```yaml
id: adr-013-wordpress-plugin-separate-stream
status: accepted
date: 2026-07-22
deciders: core
related:
  - ../architecture
```

## Context / Forces

ORCA ships a WordPress plugin (`wordpress-plugin/`) that adds a Gutenberg media-
library tab and calls ORCA's `/api/reference-tags` on save. It has its own release
cadence, its own consumers (WordPress site admins), its own auto-update mechanism
(plugin-update-checker), and its own versioning expectations — none of which line up
with the ORCA app's release cycle.

## Decision

Keep the plugin in-repo under `wordpress-plugin/` but release it on a **separate
stream**: its own `wp-v*` git tags and GitHub Release `.zip` artifacts, distinct from
the ORCA app's `v*` releases. The plugin is **consume-only** in v1 (no uploads), uses
a Sanctum token (AES-256-GCM-encrypted in `wp_options`), and proxies all ORCA calls
through the WordPress REST API. The SDD guard exempts `wordpress-plugin/**` from the
spec requirement — it is not part of the Laravel production tree.

## Alternatives considered

- **A separate repository** — rejected: the plugin and the API it calls evolve
  together; one repo keeps them reviewable in the same PR and avoids cross-repo
  version drift.
- **One unified release tag** — rejected: forces a plugin release on every app
  release (and vice versa) and confuses WordPress's update checker, which expects
  plugin-shaped version numbers.
- **Gate the plugin under SDD too** — rejected: it's a distinct product on a distinct
  stack (PHP-for-WordPress), so ORCA's Laravel specs don't govern it; treating it as
  production code would demand ORCA specs for WordPress behaviour.

## Consequences

- **Good:** the plugin releases on its own schedule with WordPress-idiomatic
  versioning and auto-updates, while staying reviewable alongside the API.
- **Good:** clear boundary — the SDD gate ignores it.
- **Trade-off:** two release processes to run and document; a breaking API change
  must consciously consider the plugin's release timeline.
