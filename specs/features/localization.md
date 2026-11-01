# Localization

```yaml
id: localization
status: implemented
version: 5
owner: core
related:
  - architecture
  - decisions/adr-009-project-owns-nl-json
source:
  - app/Http/Middleware/SetLocale.php
  - app/Console/Commands/LangSafeUpdate.php
  - lang/nl.json
  - app/Http/Controllers/ProfileController.php
  - config/orca.php
```

## Background / Why

ORCA is bilingual (`en`, `nl`). `SetLocale` resolves the locale per request with a three-tier
fallback (user preference → global setting → config). Framework strings come from the
`laravel-lang/common` dev dependency; ORCA's own strings live in project-owned `lang/nl.json`, which
stock `lang:update`/`lang:add nl` would clobber ([ADR-009](../decisions/adr-009-project-owns-nl-json.md)).
This spec owns the locale conventions.

## Requirements

- **REQ-1** — `SetLocale` resolves the active locale in this priority order: (1) the
  authenticated user's `preferences.locale`, if set and in `['en', 'nl']`; (2) the
  global `Setting::get('locale')`, if set and supported; (3) `config('app.locale')`.
  The middleware never throws — a DB error reading the setting (e.g. before the `settings` table
  exists) falls through to the config default.
- **REQ-2** — Only `en` and `nl` are supported. The list lives once, in
  `config('orca.ui_locales')` (code → display name), and everything that accepts or
  offers a locale reads it: `SetLocale`, the `POST /locale` switcher, the System settings
  dropdown and its validation, and the profile page. An unsupported value at any tier is
  ignored and the next tier is tried.
- **REQ-3** — `lang/nl.json` is project-owned and hand-maintained. Every new `__()`
  string added to `app/` or `resources/views/` gets a Dutch entry, inserted in
  alphabetical order. Refreshing framework translations goes through
  `php artisan lang:safe-update`, never raw `lang:update`/`lang:add nl` (blocked by a
  PreToolUse hook; guarded further by `TranslationIntegrityTest`'s sentinel keys +
  completeness check).
- **REQ-4** — JS-side toasts/UI strings reach the client via **three** distinct
  injection channels depending on the page family — there is no single global
  translations object. API JSON responses are never translated; they stay English.

## Technical design

### Contract / public interface

```yaml
SetLocale::handle(Request, Closure): Response
SetLocale::getSupportedLocales(): array          # array_keys(config('orca.ui_locales')) — ['en', 'nl']
php artisan lang:safe-update                      # wraps lang:update; protects nl.json
```

### Data shapes

```yaml
# users.preferences (plain JSON) — see features/user-preferences.md
preferences:
  locale: "en" | "nl"                              # optional; absent = fall through

# settings row
key: "locale"
type: "string"
value: "en" | "nl"
```

### Layer touchpoints & ordering

`SetLocale` runs in the web middleware group, first of the three appended web middleware (before
`AllowEmbedding`; [`architecture.md`](../architecture.md#middleware-stack)). It calls
`App::setLocale()` before the controller, so every `__()` and `<html lang>` in
`resources/views/layouts/app.blade.php` reflect the resolved locale.

**JS translation channels** (three, not unified — each scoped to its page family):

- `window.__pageData.translations` — tools views, asset edit/show/create/trash/replace, profile
  partials, dashboard, login, tags index, discover, import — `@js(__())` per key in each view.
  The layout also adds the namespaced `window.__pageData.guidedDemo` key (merge idiom, only when a
  demo is armed; [`guided-demos.md`](guided-demos.md)) — not a fourth channel.
- `window.assetTranslations` — the asset grid partial (`resources/views/assets/partials/grid.blade.php`),
  shared by index and embed.
- `window.appTranslations` — the base layout: `urlCopied` / `copyFailed` for toasts, plus
  `sessionExpired` / `payloadTooLarge` / `requestFailed` for `resources/js/http-errors.js`
  ([`error-handling.md`](error-handling.md) REQ-11). `layouts/embed.blade.php` defines the three
  error keys too; the helper guards for a missing object.

API responses (`routes/api.php`) are deliberately **not** localized — external
integrations (RTE, WordPress) expect stable English strings.

### Persistence

- No dedicated table; locale resolution reads `users.preferences` (plain JSON
  column) and the `settings` table (`Setting::get('locale')`, 1h cache — see
  [`features/settings.md`](settings.md)).
- `lang/nl.json` is version-controlled and alphabetized by every `lang:safe-update` run.

## Scenarios (BDD)

```gherkin
Scenario Outline: Locale resolution order and fallback
  Given the user preference.locale is <pref> and the global "locale" setting is <setting>
  When they load an authenticated page
  Then app()->getLocale() is <locale>
  Examples:
    | pref         | setting      | locale |
    | unset        | unset        | en     |
    | unset        | nl           | nl     |
    | en           | nl           | en     |
    | xx (invalid) | nl           | nl     |
    | unset        | xx (invalid) | en     |
# pinned by: tests/Feature/LocaleTest.php

Scenario: The rendered page reflects the resolved locale
  Given the global "locale" setting is "nl"
  When a user loads the dashboard
  Then the <html lang="nl" attribute is present
# pinned by: tests/Feature/LocaleTest.php

Scenario: The profile form and the switcher accept exactly the configured locales
  Given config('orca.ui_locales') lists en, nl and de
  When a user saves locale "de" on the profile page
  Then the preference is stored, and an unlisted locale is rejected with a validation error
# pinned by: tests/Feature/LocaleTest.php

Scenario: A raw lang:update run is caught before it ships
  Given lang/nl.json carries ORCA's project wording for known sentinel keys
  When the translation integrity check runs
  Then every sentinel key still matches ORCA's wording, not laravel-lang's official value
# pinned by: tests/Feature/TranslationIntegrityTest.php

Scenario: Every translatable string has a Dutch entry
  Given every __()/@lang() literal extracted from app/ and resources/views/
  When checked against the nl locale
  Then each one resolves to a translation
# pinned by: tests/Feature/TranslationIntegrityTest.php

# — browser-level (see e2e-testing.md for the harness) —

Scenario: Switching the interface language translates the chrome
  Given an English UI
  When Dutch is selected in profile preferences
  Then the navigation renders the Dutch strings and the html lang attribute is nl
  And switching back restores the English chrome
# pinned by: tests/e2e/localization.spec.js

Scenario: The grid keeps working in Dutch and data-testid locators are unaffected
  Given a Dutch UI
  When the asset grid is driven by its data-testid hooks
  Then every interaction behaves as it does in English
# pinned by: tests/e2e/localization.spec.js
```

## Tests & verification

- Feature: `tests/Feature/LocaleTest.php`, `tests/Feature/TranslationIntegrityTest.php`
- Run: `php artisan config:clear && php artisan test`
- E2E: `tests/e2e/localization.spec.js` — `npm run test:e2e`

## Open questions / future

- No test asserts that a view injects the JS translation keys its Alpine module reads (beyond the
  blanket `__()` extraction).
