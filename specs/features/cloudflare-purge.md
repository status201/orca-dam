# Cloudflare Cache Purge

```yaml
id: cloudflare-purge
status: implemented
version: 1
owner: core
related:
  - architecture
  - decisions/adr-006-immutable-s3-key
source:
  - app/Services/CloudflareService.php
  - config/cloudflare.php
```

## Background / Why

`assets.s3_key` is immutable ([ADR-006](../decisions/adr-006-immutable-s3-key.md)) —
replacing a file's bytes or regenerating its thumbnail keeps the same URL, which
means a CDN sitting in front of S3 will happily keep serving the stale copy unless
explicitly told otherwise. `CloudflareService` purges the affected URLs
non-blockingly after a replace or thumbnail/resize regen, so the fix is "purge the
edge cache," never "mint a new key" (query-string cache-busting was explicitly
rejected too — see the ADR's alternatives).

## Requirements

- **REQ-1** — Purging requires **all four** of: `CLOUDFLARE_ENABLED=true` (env),
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
CloudflareService::collectAssetUrls(Asset $asset): array<string>
CloudflareService::purgeUrls(array $urls): bool
```

### Data shapes

```yaml
# config/cloudflare.php
enabled: env('CLOUDFLARE_ENABLED', false)
api_token: env('CLOUDFLARE_API_TOKEN', '')
zone_id: env('CLOUDFLARE_ZONE_ID', '')

# settings rows read live (no local caching beyond Setting::get's 1h TTL)
custom_domain: string            # group aws
cloudflare_cache_purge: boolean  # group aws
```

### Layer touchpoints & ordering

Called from `AssetReplaceController` only — both `replace()` (file replacement)
and `storeThumbnail()` (custom thumbnail upload):

```
1. collectAssetUrls($asset)   # BEFORE S3 keys are nulled/replaced on the model
2. ... replace bytes / regenerate thumbnail on S3 ...
3. purgeUrls($urls)            # best-effort, non-blocking, never throws
```

Note: `RegenerateResizedImage` (the bulk resize-regeneration job, see
[`features/queue-jobs.md`](queue-jobs.md)) does **not** call `CloudflareService` —
purge is wired only into the interactive replace/thumbnail paths.

`S3Service::getPublicBaseUrl()` supplies the base URL (honors `custom_domain`) that
`collectAssetUrls()` prefixes onto each S3 key.

### Persistence

No dedicated table or cache of its own; reads `custom_domain` /
`cloudflare_cache_purge` live from `Setting::get()` (see
[`features/settings.md`](settings.md)) so an admin toggle takes effect on the next
replace without a deploy.

## Scenarios (BDD)

```gherkin
Scenario: Purging is disabled unless every gate is satisfied
  Given any one of: CLOUDFLARE_ENABLED false, empty api_token, empty zone_id, empty custom_domain, or cloudflare_cache_purge off
  When CloudflareService::isEnabled() is checked
  Then it returns false
# pinned by: tests/Unit/CloudflareServiceTest.php

Scenario: Fully configured, purging is enabled
  Given CLOUDFLARE_ENABLED is true, api_token and zone_id are set, custom_domain is set, and cloudflare_cache_purge is on
  When CloudflareService::isEnabled() is checked
  Then it returns true
# pinned by: tests/Unit/CloudflareServiceTest.php

Scenario: collectAssetUrls returns nothing when disabled
  Given Cloudflare purging is disabled
  When collectAssetUrls() is called for an asset
  Then it returns an empty array without touching S3Service
# pinned by: tests/Unit/CloudflareServiceTest.php

Scenario: collectAssetUrls includes only the variants that exist
  Given an asset with no thumbnail or resize keys
  When collectAssetUrls() is called
  Then only the original asset URL is returned
# pinned by: tests/Unit/CloudflareServiceTest.php

Scenario: collectAssetUrls includes every variant when all exist
  Given an asset with thumbnail_s3_key and all three resize_*_s3_key set
  When collectAssetUrls() is called
  Then all five URLs (original + thumbnail + S/M/L) are returned in order
# pinned by: tests/Unit/CloudflareServiceTest.php

Scenario: purgeUrls sends the expected request
  Given Cloudflare purging is enabled
  When purgeUrls() is called with a list of URLs
  Then a POST to the zone's purge_cache endpoint is sent with a Bearer token and the URL list as "files"
# pinned by: tests/Unit/CloudflareServiceTest.php

Scenario: purgeUrls never throws on API failure
  Given the Cloudflare API responds with success:false / a non-2xx status
  When purgeUrls() is called
  Then it returns false and logs an error, without throwing
# pinned by: tests/Unit/CloudflareServiceTest.php

Scenario: purgeUrls never throws on a network exception
  Given the HTTP client throws during the request
  When purgeUrls() is called
  Then it returns false and logs the exception message, without throwing
# pinned by: tests/Unit/CloudflareServiceTest.php

Scenario: purgeUrls filters empty/null entries and short-circuits an empty list
  Given a URL list containing nulls and empty strings, or an entirely empty list
  When purgeUrls() is called
  Then null/empty entries are dropped before sending, and an all-empty list returns true without an HTTP call
# pinned by: tests/Unit/CloudflareServiceTest.php
```

## Tests & verification

- Unit: `tests/Unit/CloudflareServiceTest.php` (gate combinations, URL collection,
  purge request shape, failure/exception handling, filtering) — uses `Http::fake()`,
  no real Cloudflare calls
- Run: `php artisan config:clear && php artisan test`

## Open questions / future

- No Feature-level test exercises the actual call sites (asset replace /
  `RegenerateResizedImage`) invoking `collectAssetUrls()` + `purgeUrls()` together
  end-to-end — coverage today is at the `CloudflareService` unit level plus the
  ordering contract documented in code comments (call `collectAssetUrls()` before
  nulling S3 keys). A Feature test asserting the purge is attempted (via
  `Http::fake()`) during a real replace request would close this gap.
