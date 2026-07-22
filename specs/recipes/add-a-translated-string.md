<!--
  Recipe: add a __() string with its Dutch translation.
-->

# Recipe — Add a translated string

```yaml
id: add-a-translated-string
status: implemented
version: 1
owner: core
related:
  - architecture
  - ../features/localization
  - ../decisions/adr-009-project-owns-nl-json
source:
  - lang/nl.json
```

A repeatable **playbook**, not a feature. Every new `__()` string needs a
Dutch entry because `lang/nl.json` is project-owned and hand-maintained — the
stock `lang:update`/`lang:add nl` would silently overwrite it (see
[ADR-009](../decisions/adr-009-project-owns-nl-json.md)). This is a
two-file-minimum discipline (the `__()` call site + the `nl.json` entry), plus
a JS injection step if the string needs to reach client-side code.

## Background / Why

`TranslationIntegrityTest` mechanically checks that every `__()`/`@lang()`
literal extracted from `app/` and `resources/views/` resolves to an `nl`
translation — a missing entry is a **test failure**, not a silent English
fallback in production. Keeping `nl.json` alphabetically sorted is what makes
the file diffable/reviewable at its current size; an insertion at the wrong
position is a cosmetic nit but still worth getting right on the way in.

## Steps

### 1. Use the string in Blade/PHP

```blade
{{ __('Archive this asset') }}
```

```php
$message = __(':count asset(s) archived', ['count' => $count]);
```

### 2. Add the Dutch entry — `lang/nl.json`, alphabetical order

```json
"Archive this asset": "Dit asset archiveren",
```

Insert it at the correct alphabetical position among the existing keys —
don't append to the end of the file. Pluralization strings use the
`singular|plural` Laravel format with `:count`:

```json
":count asset(s) archived": ":count asset gearchiveerd|:count assets gearchiveerd",
```

### 3. If the string needs to reach client-side JS, inject it via the right channel

Pick the channel matching the page family (see
[`localization.md`](../features/localization.md) — there are three, not one
global object):

```blade
{{-- most pages: tools views, asset edit/show/create/trash/replace, profile, dashboard, login, tags, discover, import --}}
<script>window.__pageData = { translations: { archiveConfirm: @js(__('Are you sure you want to archive this asset?')) } };</script>
```

```blade
{{-- the asset grid partial only (index + embed) --}}
window.assetTranslations = { ... @js(__('...')) ... };
```

```blade
{{-- the base layout only — currently just urlCopied/copyFailed toasts --}}
window.appTranslations = { ... };
```

API responses (`routes/api.php`) are **never** localized — leave those
strings as plain English literals, not `__()`.

### 4. Verify

```bash
php artisan config:clear && php artisan test tests/Feature/TranslationIntegrityTest.php
./vendor/bin/pint
```

## Gotchas

- **Never** run raw `php artisan lang:update` or `lang:add nl` — a PreToolUse
  hook blocks it, and it would overwrite every project translation in
  `nl.json` with laravel-lang's generic defaults. To refresh *framework*
  strings (validation/auth), use `php artisan lang:safe-update` instead — it
  diffs and restores `nl.json`'s project-owned keys around the underlying
  `lang:update` call.
- Don't invent a fourth JS translation channel or reuse the wrong one — a
  string injected into `window.assetTranslations` on a page that isn't the
  asset grid partial will just be `undefined` at read time in the consuming
  Alpine module.
- API responses stay English on purpose (external integrations like the RTE
  and WordPress plugin expect stable strings) — don't wrap an API controller's
  JSON message in `__()`.
- Keep the alphabetical ordering — `lang:safe-update` re-sorts the whole file
  on every run, so a manually-appended-at-the-end key will just get moved on
  the next refresh anyway; inserting it correctly the first time avoids a
  needless diff.

## Scenarios (BDD)

```gherkin
Scenario: Every translatable string has a Dutch entry
  Given every __()/@lang() literal extracted from app/ and resources/views/
  When checked against the nl locale
  Then each one resolves to a translation
# pinned by: tests/Feature/TranslationIntegrityTest.php
```

## Tests & verification

- `tests/Feature/TranslationIntegrityTest.php` — completeness check (every
  extracted `__()` literal resolves in `nl`) and sentinel-key guard (project
  wording hasn't been clobbered by a framework refresh).
- `php artisan config:clear && php artisan test tests/Feature/TranslationIntegrityTest.php`.
