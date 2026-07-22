# ADR-011 — Runtime settings live in the DB (`Setting`, 1 h cache), not `.env`

```yaml
id: adr-011-settings-in-db
status: accepted
date: 2026-07-22
deciders: core
related:
  - ../architecture
  - ../features/settings
  - ../recipes/add-a-setting
```

## Context / Forces

Many knobs need to change at runtime by an admin without a redeploy: items-per-page,
locale, S3 root folders, Rekognition label/confidence limits, embed domains, feature
toggles (`api_upload_enabled`, `maintenance_mode`, `cloudflare_cache_purge`), resize
dimensions. `.env` values are deploy-time, require a restart + config cache rebuild,
and aren't editable from the admin UI.

## Decision

Runtime-tunable config lives in the **`settings` table** via the `Setting` model
(`Setting::get('key', $default)` / `Setting::set(...)`), typed
(string/integer/boolean/json), grouped (general/display/aws/api), and **cached for
1 hour**. `.env` keeps only secrets and deploy-fixed wiring (AWS keys, DB, feature
*capability* flags like `JWT_ENABLED`). Services read settings **live** from the DB
so an admin change takes effect without a deploy.

## Alternatives considered

- **Everything in `.env` / config files** — rejected: not editable at runtime,
  needs a redeploy + `config:cache` rebuild, and can't be exposed safely in the admin
  UI.
- **A config package (e.g. spatie/laravel-settings) with typed classes** — reasonable,
  but the key/value `Setting` model with a cache already covers the need; a package
  adds migration/casting machinery for little gain here.
- **No cache (read every request)** — rejected: hot paths read settings often; a
  1-hour cache with explicit invalidation on write is the right balance.

## Consequences

- **Good:** admins tune behaviour from the UI with immediate effect; secrets stay in
  `.env` where they belong.
- **Good:** typed + grouped settings are self-describing and testable.
- **Trade-off:** two places to look for "configuration" (DB vs `.env`); the cache
  means a write must invalidate it, and a raw DB edit won't take effect until the TTL
  expires.
