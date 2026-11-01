# Cloudflare Cache Purge

```yaml
id: cloudflare-purge
status: implemented
version: 2
owner: core
related:
  - architecture
  - decisions/adr-006-immutable-s3-key
source:
  - app/Services/CloudflareService.php
  - config/cloudflare.php
```

## Background / Why

`assets.s3_key` is immutable ([ADR-006](../decisions/adr-006-immutable-s3-key.md)), so replacing
a file's bytes or thumbnail keeps the same URL and a CDN in front of S3 keeps serving the stale
copy. `CloudflareService` purges the affected URLs, best-effort, after a replace, a
custom-thumbnail upload, or a content-type repair — the fix is "purge the edge cache", never
"mint a new key" (query-string cache-busting was rejected too — see the ADR).

## Requirements

- **REQ-1** — Purging requires **all five** of: `CLOUDFLARE_ENABLED=true` (env),
  a non-empty `CLOUDFLARE_API_TOKEN` and `CLOUDFLARE_ZONE_ID` (env), a non-empty
  `custom_domain` setting, and the `cloudflare_cache_purge` setting toggled on.
  Missing any one means `isEnabled()` is false and no HTTP call is ever attempted.
- **REQ-2** — `collectAssetUrls(Asset $asset)` must be called **before** the
  asset's S3 keys are reset to null during a replace, so the thumbnail/resize URLs
  are still readable off the model; it returns the original + thumbnail + S/M/L
  resize URLs that currently exist (skips any variant key that's null).
- **REQ-3** — `purgeUrls(array $urls)` never throws — a failed HTTP call, a
  non-2xx/`success:false` response, or a thrown exception during the request are
  all logged (`Log::error`) and the method returns `false`. Callers must not depend
  on purge succeeding for the primary operation (replace/regen) to be considered
  successful.
- **REQ-4** — `purgeUrls()` filters out empty/null URLs before sending and returns
  `true` immediately (without an HTTP call) for an empty URL list.
- **REQ-5** — The Cloudflare API request is `POST
  https://api.cloudflare.com/client/v4/zones/{zone_id}/purge_cache` with
  `Authorization: Bearer {api_token}`, body `{"files": [...]}`, 10s timeout.

## Technical design

### Contract / public interface

```yaml
CloudflareService::isEnabled(): bool
CloudflareService::collectAssetUrls(Asset $asset): array<string>   # base URL via S3Service::getPublicBaseUrl()
CloudflareService::purgeUrls(array $urls): bool
```

Env keys are read through `config/cloudflare.php`; `custom_domain` and `cloudflare_cache_purge`
(group `aws`) are read live via `Setting::get()` ([`settings.md`](settings.md)), so a toggle takes
effect on the next replace without a deploy.

### Layer touchpoints & ordering

Callers: `AssetReplaceController::replace()` and `storeThumbnail()`, and
`AuditContentTypesCommand --fix` ([`maintenance-commands.md`](maintenance-commands.md)):

```
1. collectAssetUrls($asset)   # BEFORE S3 keys are nulled/replaced on the model
2. ... replace bytes / regenerate thumbnail on S3 ...
3. purgeUrls($urls)            # best-effort, never throws
```

`RegenerateResizedImage` ([`queue-jobs.md`](queue-jobs.md)) deliberately does **not** purge.

## Scenarios (BDD)

```gherkin
Scenario: Purging is enabled only when every gate is satisfied (REQ-1)
  Given any one of: CLOUDFLARE_ENABLED false, empty api_token, empty zone_id, empty custom_domain, or cloudflare_cache_purge off
  Then CloudflareService::isEnabled() returns false
  And with all five satisfied it returns true
# pinned by: tests/Unit/CloudflareServiceTest.php

Scenario: collectAssetUrls returns the variants that exist (REQ-2)
  Given purging is disabled
  Then collectAssetUrls() returns [] without touching S3Service
  And when enabled, an asset without derived keys yields only the original URL
  And an asset with thumbnail and all three resizes yields five URLs in order
# pinned by: tests/Unit/CloudflareServiceTest.php

Scenario: purgeUrls sends the expected request (REQ-5)
  Given Cloudflare purging is enabled
  When purgeUrls() is called with a list of URLs
  Then a POST to the zone's purge_cache endpoint is sent with a Bearer token and the URL list as "files"
# pinned by: tests/Unit/CloudflareServiceTest.php

Scenario: purgeUrls never throws (REQ-3)
  Given the API responds success:false or non-2xx, or the HTTP client throws
  When purgeUrls() is called
  Then it returns false and logs an error, without throwing
# pinned by: tests/Unit/CloudflareServiceTest.php

Scenario: purgeUrls filters empty/null entries and short-circuits an empty list (REQ-4)
  Given a URL list containing nulls and empty strings, or an entirely empty list
  When purgeUrls() is called
  Then null/empty entries are dropped before sending, and an all-empty list returns true without an HTTP call
# pinned by: tests/Unit/CloudflareServiceTest.php
```

## Tests & verification

- Unit: `tests/Unit/CloudflareServiceTest.php` (`Http::fake()`, no real calls)
- Run: `php artisan config:clear && php artisan test`

## Open questions / future

- Coverage gap: no Feature test asserts a real replace request collects and purges URLs in order.
