# Settings

```yaml
id: settings
status: implemented
version: 4
owner: core
related:
  - architecture
  - decisions/adr-011-settings-in-db
source:
  - app/Models/Setting.php
  - app/Http/Controllers/SystemController.php
  - app/Support/SettingRules.php
  - app/Services/FolderRegistry.php
  - database/migrations/2026_02_22_000000_seed_missing_settings.php
```

## Background / Why

Admin-tunable knobs (pagination, locale, S3 folders, Rekognition thresholds, embed domains, feature
toggles such as `maintenance_mode`, `api_upload_enabled`, `cloudflare_cache_purge`, resize
dimensions) live in the `settings` table via `Setting`, not `.env`, so an edit on the System
settings page applies to the next request ([ADR-011](../decisions/adr-011-settings-in-db.md)).

## Requirements

- **REQ-1** — `Setting::get(string $key, mixed $default = null)` reads a typed value,
  cached for 1 hour (`CACHE_TTL = 3600`) under `setting:{key}`.
- **REQ-2** — `Setting::set(string $key, mixed $value, ?string $type, ?string $group)`
  creates-or-updates the row and **invalidates** the per-key cache plus the
  `settings:all` / `settings:grouped` aggregate caches.
- **REQ-3** — Value types are `string` (default) / `integer` / `boolean` / `json`;
  casting happens in `Setting::castValue()` on read, never on write (the DB column is
  always a string; arrays are `json_encode`d before storage).
- **REQ-4** — Settings are grouped (`general`/`display`/`aws`/`api`, though the group
  is free-form) for the admin UI's section layout (`Setting::allGrouped()`).
- **REQ-5** — The admin System settings page validates known keys server-side
  (`SystemController::updateSetting`, rules in `App\Support\SettingRules`) before persisting — e.g. `items_per_page`
  10–100, `rekognition_min_confidence` 65–99, `custom_domain` must be empty or a
  valid `http(s)://` URL, `embed_allowed_domains` must decode to a JSON array of
  URL-shaped strings. Unknown keys skip validation and are stored as-is.

## Technical design

### Contract / public interface

```yaml
Setting::get(key, default=null): mixed        # typed, cached
Setting::set(key, value, type=null, group=null): bool   # upsert + cache-bust
Setting::allSettings(): array                  # cached flat key => value map
Setting::allGrouped(): array                   # cached group => [key => {value,type,description}]
Setting::clearCache(): void                     # forgets every per-key cache + the two aggregates
Setting::forget(key): void                      # deletes the row, its cache and the two aggregates
POST /system/settings  (system.update-setting → SystemController::updateSetting, admin-only, AJAX)
  body: { key: string, value: string }
  -> 422 { success: false, error } on failed validation for a known key
  -> 200 { success: bool, message }
```

### Data shapes

```yaml
Setting:                # settings table
  key: string            # unique
  value: string           # always stored as string; json-encoded for arrays
  type: string|integer|boolean|json
  group: string           # general | display | aws | api (free-form)
  description: string?    # shown in the admin UI
```

### Layer touchpoints & ordering

Request → `SystemController::updateSetting` (validates known keys through `SettingRules::passes()`)
→ `Setting::set()` (upsert + cache-bust). Changing `s3_root_folder` also drops the `s3_folders`
setting via `FolderRegistry::forget()` → `Setting::forget()` (which clears the aggregates too), so
the folder list is recomputed. Reads go directly through `Setting::get()`; no repository layer.

### Persistence

- **DB**: `settings` table, one row per key.
- **Cache**: `setting:{key}` per-key (1h TTL), plus `settings:all` and
  `settings:grouped` aggregates — all three are forgotten on every `Setting::set()`
  call and by `Setting::clearCache()`.
- A raw DB edit (bypassing `Setting::set()`) is invisible until the cache entry expires or is
  forgotten.

## Scenarios (BDD)

```gherkin
Scenario: A missing key returns the caller's default
  Given no "nonexistent_key" row exists
  When Setting::get('nonexistent_key', 'default_value') is called
  Then the result is "default_value"
# pinned by: tests/Unit/SettingTest.php

Scenario: Typed values are cast on read
  Given a setting stored with type "integer" / "boolean" / "json"
  When Setting::get() reads it
  Then the value is an int / bool / decoded array respectively
# pinned by: tests/Unit/SettingTest.php

Scenario: Setting::set busts the cache
  Given a setting was already cached by a prior get()
  When Setting::set() updates it directly
  Then Setting::get() immediately returns the new value, not the stale cached one
# pinned by: tests/Unit/SettingTest.php

Scenario: The admin settings endpoint rejects an out-of-range known key
  Given an authenticated admin
  When they POST an items_per_page value outside the allowed range to the settings update endpoint
  Then the response is 422 with an error message
# pinned by: tests/Feature/SystemTest.php

Scenario: Editors are blocked from the settings update endpoint
  Given an authenticated editor
  When they POST to the settings update endpoint
  Then the request is denied
# pinned by: tests/Feature/SystemTest.php

Scenario: Changing the embed allow-list takes effect without a deploy
  Given an admin updates embed_allowed_domains via Setting::set
  When a subsequent request passes through AllowEmbedding
  Then the new domain list is reflected in the CSP frame-ancestors directive
# pinned by: tests/Feature/Middleware/AllowEmbeddingTest.php

# — browser-level (see e2e-testing.md for the harness) —

Scenario: A runtime setting changed on /system survives a reload and changes behaviour
  Given the system settings page
  When items_per_page is changed
  Then the value is persisted and the asset grid honours it
# pinned by: tests/e2e/system-settings.spec.js
```

## Tests & verification

- Unit: `tests/Unit/SettingTest.php`
- Feature: `tests/Feature/SystemTest.php` (per-key validation, editor gate),
  `tests/Feature/Middleware/AllowEmbeddingTest.php`
- Run: `php artisan config:clear && php artisan test`
- E2E: `tests/e2e/system-settings.spec.js` — `npm run test:e2e`
