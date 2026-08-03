# Localization

```yaml
id: localization
status: implemented
version: 1
owner: core
related:
  - architecture
  - decisions/adr-009-project-owns-nl-json
source:
  - app/Http/Middleware/SetLocale.php
  - app/Console/Commands/LangSafeUpdate.php
  - lang/nl.json
  - app/Http/Controllers/ProfileController.php
```

## Background / Why

ORCA is bilingual (`en`, `nl`). Locale is resolved per-request by `SetLocale`, with
a three-tier fallback so a per-user preference wins, a global admin default applies
next, and the app never has no locale at all. Framework strings (validation/auth)
come from the `laravel-lang/common` dev dependency; ORCA's own UI strings live in
project-owned `lang/nl.json`, which the stock `lang:update`/`lang:add nl` would
silently clobber — see [ADR-009](../decisions/adr-009-project-owns-nl-json.md) for
why that command is wrapped and gated.

## Requirements

- **REQ-1** — `SetLocale` resolves the active locale in this priority order: (1) the
  authenticated user's `preferences.locale`, if set and in `['en', 'nl']`; (2) the
  global `Setting::get('locale')`, if set and supported; (3) `config('app.locale')`.
  The middleware never throws — a DB error reading the setting (e.g. during
  migrations, before the `settings` table exists) is caught and falls through to the
  config default.
- **REQ-2** — Only `en` and `nl` are supported (`SetLocale::$supportedLocales`); an
  unsupported value at any tier is ignored and the next tier is tried.
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
SetLocale::getSupportedLocales(): array          # ['en', 'nl']
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

`SetLocale` runs in the web middleware group (registered in `bootstrap/app.php`,
after `AllowEmbedding`) — see [`architecture.md`](../architecture.md#middleware-stack)
for the full stack ordering. It calls `App::setLocale()` before the request reaches
the controller, so every `__()` call and the `<html lang="...">` attribute in
`resources/views/layouts/app.blade.php` reflect the resolved locale for that request.

**JS translation channels** (three, not unified — each scoped to its page family):

- `window.__pageData.translations` — tools views (`resources/views/tools/*.blade.php`),
  the asset edit/show/create/trash/replace pages, profile passkeys/preferences
  partials, dashboard, login, tags index, discover, import — injected via
  `@js(__())` per key in each Blade view.
- `window.assetTranslations` — the asset grid partial
  (`resources/views/assets/partials/grid.blade.php`), shared between the index and
  embed pages.
- `window.appTranslations` — the base layout (`resources/views/layouts/app.blade.php`),
  currently `urlCopied` / `copyFailed` for the global toast system.

The base layout also contributes `window.__pageData.guidedDemo` — a namespaced key on the
first channel, like `tagConfig`, not a fourth channel — carrying the active walkthrough's
translated copy ([`guided-demos.md`](guided-demos.md)). It is written with the
`window.__pageData = window.__pageData || {}` merge idiom and only when a demo is armed.

API responses (`routes/api.php`) are deliberately **not** localized — external
integrations (RTE, WordPress) expect stable English strings.

### Persistence

- No dedicated table; locale resolution reads `users.preferences` (plain JSON
  column) and the `settings` table (`Setting::get('locale')`, 1h cache — see
  [`features/settings.md`](settings.md)).
- `lang/nl.json` is a version-controlled file, not DB state; it is alphabetized on
  every `lang:safe-update` run.

## Scenarios (BDD)

```gherkin
Scenario: No preference or setting configured defaults to English
  Given a user with no locale preference and no global locale setting
  When they load any authenticated page
  Then app()->getLocale() is "en"
# pinned by: tests/Feature/LocaleTest.php

Scenario: Global setting applies when the user has no preference
  Given the global "locale" setting is "nl"
  And the user has no locale preference
  When they load an authenticated page
  Then app()->getLocale() is "nl"
# pinned by: tests/Feature/LocaleTest.php

Scenario: A user preference overrides the global setting
  Given the global "locale" setting is "nl"
  And the user's preference.locale is "en"
  When they load an authenticated page
  Then app()->getLocale() is "en"
# pinned by: tests/Feature/LocaleTest.php

Scenario: The rendered page reflects the resolved locale
  Given the global "locale" setting is "nl"
  When a user loads the dashboard
  Then the <html lang="nl" attribute is present
# pinned by: tests/Feature/LocaleTest.php

Scenario: An unsupported user preference is ignored
  Given the global "locale" setting is "nl"
  And the user's preference.locale is "xx" (unsupported)
  When they load an authenticated page
  Then app()->getLocale() falls through to the global setting, "nl"
# pinned by: tests/Feature/LocaleTest.php

Scenario: An unsupported global setting falls through to config
  Given the global "locale" setting is "xx" (unsupported)
  When a user loads an authenticated page
  Then app()->getLocale() is config('app.locale'), "en"
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

- Feature: `tests/Feature/LocaleTest.php` (resolution priority, fallback, rendered
  `<html lang>`), `tests/Feature/TranslationIntegrityTest.php` (sentinel keys +
  completeness)
- Run: `php artisan config:clear && php artisan test`
- E2E: `tests/e2e/localization.spec.js` — switching the per-user locale and asserting the rendered Dutch chrome + `<html lang>`.

## Open questions / future

- No automated test asserts the *shape* of the three JS translation channels
  (`window.__pageData.translations` / `window.assetTranslations` /
  `window.appTranslations`) beyond `TranslationIntegrityTest`'s blanket `__()`
  extraction — a page-specific JS test could assert a given view injects the keys
  its Alpine module actually reads.
