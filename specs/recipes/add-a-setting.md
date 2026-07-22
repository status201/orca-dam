<!--
  Recipe: add a new runtime Setting.
-->

# Recipe — Add a runtime setting

```yaml
id: add-a-setting
status: implemented
version: 1
owner: core
related:
  - architecture
  - ../features/settings
  - ../decisions/adr-011-settings-in-db
source:
  - app/Models/Setting.php
  - app/Http/Controllers/SystemController.php
  - resources/views/system/index.blade.php
  - resources/js/alpine/system-admin.js
```

A repeatable **playbook**, not a feature. Runtime-tunable config lives in the
`settings` table so an admin edit takes effect without a redeploy (see
[ADR-011](../decisions/adr-011-settings-in-db.md)). A new setting has a fixed
four-part shape: seed a default, read it via `Setting::get()`, expose it on
the System settings page bound with `x-model`/`@change`, and (if it's a known
key) add server-side validation. The concrete worked instance is
`api_meta_endpoint_enabled`
(`database/migrations/2026_02_22_000000_seed_missing_settings.php`).

## Background / Why

`Setting::get()`/`set()` cache for 1 hour and invalidate on every write, so
reads are cheap and writes are immediately visible — but only if every write
path goes through `Setting::set()` (a raw DB update bypassing it leaves the
stale cached value in place until the TTL expires). Seeding a default via
`firstOrCreate` in a migration (not `create`) means the migration is safe to
run twice and won't blow up a fresh install that already has the row from a
later migration.

## Steps

### 1. Seed a default — a migration using `firstOrCreate`

```php
public function up(): void
{
    Setting::firstOrCreate(
        ['key' => 'my_new_setting'],
        [
            'value' => 'false',
            'type' => 'boolean',
            'group' => 'general',
            'description' => 'What this toggle controls',
        ]
    );
}

public function down(): void
{
    Setting::where('key', 'my_new_setting')->delete();
}
```

### 2. Read it at the call site — live, not cached in a constructor

```php
if (! Setting::get('my_new_setting', false)) {
    return response()->json(['message' => 'Feature is disabled.'], 403);
}
```

### 3. Add server-side validation for the admin endpoint (only for a known key with real constraints) — `SystemController::updateSetting`

```php
$validationRules = [
    // ... existing entries
    'my_new_setting' => function ($v) {
        return in_array($v, ['0', '1', 'true', 'false'], true);
    },
];
```

An unknown key without a validation entry is stored as-is — only add a rule
here if the setting has a real constraint (a numeric range, an enum, a URL
shape) worth rejecting server-side.

### 4. Expose it on the System settings page — `resources/views/system/index.blade.php`

```blade
<input type="checkbox"
       x-model="settings.my_new_setting"
       @change="updateSetting('my_new_setting', settings.my_new_setting ? '1' : '0')">
```

`updateSetting(key, value)` (already defined once in
`resources/js/alpine/system-admin.js`) POSTs to the existing
`/system/settings/update` endpoint — no new JS needed unless the setting
change should trigger a side effect (see `s3_root_folder`'s existing
`refreshFolderHierarchy()` follow-up call for the pattern).

### 5. Verify

```bash
./vendor/bin/pint
php artisan config:clear && php artisan test tests/Feature/SystemTest.php tests/Unit/SettingTest.php
```

## Gotchas

- Use `Setting::firstOrCreate`, never `Setting::create`, in a seeding
  migration — a straight `create()` throws a duplicate-key error if the
  migration is ever re-run or the key was already seeded by another
  migration.
- Every `Setting::set()` call busts three cache keys (`setting:{key}`,
  `settings:all`, `settings:grouped`) — a raw `Setting::where(...)->update()`
  bypasses all three and the change won't be visible until the 1-hour TTL
  expires. Always write through `Setting::set()`.
- A boolean setting is stored as the **string** `'true'`/`'false'` (or
  `'1'`/`'0'` from a checkbox), cast on read via `Setting::castValue()` using
  `filter_var($value, FILTER_VALIDATE_BOOLEAN)` — don't compare the raw
  string yourself; always go through `Setting::get()`.
- Only admins can reach `SystemController::updateSetting`
  (`SystemPolicy::access`) — a new setting doesn't need its own authorization
  check, it inherits the endpoint's gate.
- If the setting is read in a hot path (middleware, a scope applied to every
  list query), reading via `Setting::get()` is already cached — don't add a
  second application-level cache on top of it.

## Scenarios (BDD)

```gherkin
Scenario: Setting::set busts the cache
  Given a setting was already cached by a prior get()
  When Setting::set() updates it directly
  Then Setting::get() immediately returns the new value, not the stale cached one
# pinned by: tests/Unit/SettingTest.php
```

## Tests & verification

- `tests/Unit/SettingTest.php` — get/set/cast/cache-busting/allSettings/
  allGrouped, the pattern to follow for a new setting's read/write round-trip.
- `tests/Feature/SystemTest.php` — per-key admin-endpoint validation and the
  editor-cannot-update-settings role gate.
- `php artisan config:clear && php artisan test`.
