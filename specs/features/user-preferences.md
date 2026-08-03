# User Preferences

```yaml
id: user-preferences
status: implemented
version: 1
owner: core
related:
  - architecture
  - features/settings
  - features/localization
source:
  - app/Models/User.php
  - app/Http/Controllers/ProfileController.php
  - resources/views/profile/partials/update-preferences-form.blade.php
```

## Background / Why

Individual users need to override a handful of global defaults — which folder
their library opens to, how many items per page, dark mode, UI language — without
those choices leaking to other users or requiring admin action. `users.preferences`
is a single encrypted JSON column rather than one column per preference, so adding
a new preference key is a code change, not a migration.

## Requirements

- **REQ-1** — `users.preferences` is an encrypted JSON column (`'preferences' =>
  'array'` cast; the column itself is `encrypted` at rest via Laravel's model
  encryption — see `User::casts()`). Known keys: `home_folder`, `items_per_page`,
  `locale`, `dark_mode`. It may be `null` (no overrides at all).
- **REQ-2** — `User::getPreference(string $key, mixed $default = null)` /
  `setPreference(string $key, mixed $value)` are the only accessors; callers never
  read/write the `preferences` array directly.
- **REQ-3** — A preference always overrides the equivalent global `Setting` when
  present and valid; an absent or invalid preference falls through to the global
  default. This mirrors the `Setting` cascade for `locale`
  (see [`features/localization.md`](localization.md)) and applies the same pattern
  to `home_folder` (`User::getHomeFolder()` validates against
  `S3Service::getRootFolder()` via `isValidHomeFolder()`) and `items_per_page`
  (`User::getItemsPerPage()`, falls back to `Setting::get('items_per_page', 24)`
  when the preference is unset or `0`).
- **REQ-4** — `home_folder` must be the configured root folder or nested under it
  (`$folder === $root || str_starts_with($folder, $root.'/')`); an out-of-root value
  is rejected by `ProfileController::updatePreferences()` at the request-validation
  level (custom closure rule), not silently coerced.
- **REQ-5** — Submitting an empty/zero value for `home_folder` / `items_per_page` /
  `dark_mode` / `locale` **clears** that key from the preferences array (falls back
  to the corresponding global default) rather than storing an empty value.
- **REQ-6** — `ProfileController::updatePreferences()` responds with a redirect +
  flash `status` for a normal form submit, or JSON (`message` + the fresh
  `preferences` array) when the request `expectsJson()`.

## Technical design

### Contract / public interface

```yaml
User::getPreference(key, default=null): mixed
User::setPreference(key, value): bool                 # persists via save()
User::getHomeFolder(): string
User::isValidHomeFolder(folder): bool
User::getItemsPerPage(): int

PATCH /profile/preferences   (ProfileController::updatePreferences)
  body: { home_folder?, items_per_page?, dark_mode?, locale? }
  -> redirect + flash 'preferences-updated'          (normal form submit)
  -> 200 { message, preferences }                     (AJAX / expectsJson)
  -> 302 with session errors                           (validation failure)
```

### Data shapes

```yaml
users.preferences:                 # encrypted JSON column, nullable
  home_folder: string?              # must equal or nest under S3Service::getRootFolder()
  items_per_page: int?              # one of 0,12,24,36,48,60,72,96; 0/absent = use global
  dark_mode: string?                # disabled | force_dark | force_light; "disabled"/absent = no override
  locale: string?                   # en | nl; absent = use global Setting('locale')
  guided_demos: map?                # <demo-id> => {completed_at, dismissed}; see guided-demos.md
```

### Layer touchpoints & ordering

`ProfileController::edit()` composes the profile page's defaults (global
`items_per_page`, available UI languages, root/child folders) so the form can show
"what happens if you clear this." `updatePreferences()` validates, merges into the
existing `preferences` array (unsetting cleared keys rather than storing empty
strings), and persists via a single `update(['preferences' => ...])` call. Reads
elsewhere in the app (asset index pagination, `SetLocale`, upload folder picker)
go through `User::getPreference()`/the typed accessors above — never the raw
column.

`guided_demos` is the one key `updatePreferences()` does not own: it is written by
`GuidedDemoController::complete` ([`guided-demos.md`](guided-demos.md)). That separation is
deliberate. `updatePreferences()` treats an absent field as "cleared" and unsets it, which is
safe only because the profile form always submits all four of its fields — a partial request
carrying just one key would drop the rest. Any future writer of a single preference key needs
its own action for the same reason.

### Persistence

- **DB**: `users.preferences` (encrypted JSON, nullable).
- **Not persisted**: no separate history of past preference values; overwriting is
  destructive (last write wins), matching the model's "one column, whole-object
  replace" shape.

## Scenarios (BDD)

```gherkin
Scenario: A user preference overrides the global default when present and valid
  Given the global items_per_page setting is 24
  And the user's preference.items_per_page is 48
  When User::getItemsPerPage() is called
  Then it returns 48
# pinned by: tests/Unit/UserPreferencesTest.php

Scenario: Zero/absent preference falls through to the global setting
  Given the user's preference.items_per_page is 0 or unset
  When User::getItemsPerPage() is called
  Then it returns the global setting's value
# pinned by: tests/Unit/UserPreferencesTest.php

Scenario: An out-of-root home_folder preference is ignored by the model accessor
  Given the global root folder is "assets"
  And the user's preference.home_folder is "other/folder"
  When User::getHomeFolder() is called
  Then it returns the global root, not the invalid preference
# pinned by: tests/Unit/UserPreferencesTest.php

Scenario: The preferences form rejects an out-of-root home_folder at the request level
  Given the global root folder is "assets"
  When PATCH /profile/preferences is submitted with home_folder "other/folder"
  Then the response has a validation error on home_folder
# pinned by: tests/Feature/ProfileTest.php

Scenario: Submitting empty values clears the corresponding preferences
  Given a user with home_folder and items_per_page preferences set
  When PATCH /profile/preferences submits empty/zero for those fields
  Then both preferences are cleared (null) and the global defaults apply
# pinned by: tests/Feature/ProfileTest.php

Scenario: An AJAX preferences update returns JSON
  Given an authenticated user
  When PATCH /profile/preferences is submitted with an Accept: application/json header
  Then the response is 200 JSON with a message and the fresh preferences
# pinned by: tests/Feature/ProfileTest.php

Scenario: A user can set and clear their locale preference
  Given an authenticated user
  When they set preference.locale to "nl" then later clear it
  Then the effective locale follows the preference, then falls back to the global setting
# pinned by: tests/Feature/ProfileTest.php

Scenario: An invalid locale preference is rejected at the request level
  Given an authenticated user
  When PATCH /profile/preferences submits an unsupported locale value
  Then the response has a validation error
# pinned by: tests/Feature/ProfileTest.php

# — browser-level (see e2e-testing.md for the harness) —

Scenario: A locale preference saved in the profile takes effect immediately
  Given the profile preferences form
  When the locale is switched to Dutch and saved
  Then the next page render is Dutch, with no logout required
# pinned by: tests/e2e/localization.spec.js
```

## Tests & verification

- Unit: `tests/Unit/UserPreferencesTest.php` (getPreference/setPreference,
  getHomeFolder/isValidHomeFolder, getItemsPerPage, array cast, null handling)
- Feature: `tests/Feature/ProfileTest.php` (form rendering, update/clear via web +
  JSON, validation errors, locale set/clear)
- Run: `php artisan config:clear && php artisan test`
- E2E: `tests/e2e/localization.spec.js` — the locale preference saved through the profile form.

## Open questions / future

- `dark_mode` has no dedicated Unit/Feature test beyond what
  `ProfileTest.php`'s general update/clear coverage implies indirectly — no test
  asserts the three-value enum (`disabled`/`force_dark`/`force_light`) or that an
  invalid value is rejected.
