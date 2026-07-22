<!--
  Recipe: add a new Alpine.js module and register it.
-->

# Recipe — Add an Alpine.js module

```yaml
id: add-an-alpine-module
status: implemented
version: 1
owner: core
related:
  - architecture
  - ../decisions/adr-007-blade-alpine-over-spa
  - ../features/localization
source:
  - resources/js/alpine/
  - resources/js/app.js
```

A repeatable **playbook**, not a feature. ORCA has no SPA framework — each
interactive concern (tag manager, uploader, trash bulk bar, …) is an isolated
Alpine.js module registered globally, not a component tree (see
[ADR-007](../decisions/adr-007-blade-alpine-over-spa.md)). The concrete
worked instance is `resources/js/alpine/tags.js` (`tagManager()`), used by
`resources/views/tags/index.blade.php`.

## Background / Why

Because there's no bundler-level component system, a module is a plain
factory function that returns an Alpine `x-data` object, attached to
`window` so a Blade view can reference it by name in `x-data="tagManager()"`.
Keeping modules one-per-concern (rather than one giant shared object) is what
lets each be tested/reasoned about independently and keeps Blade views slim —
the interactivity lives in `resources/js/alpine/`, not inline `<script>` tags.

## Steps

### 1. Create the module — `resources/js/alpine/<name>.js`

Read page data (config + translations) from the page's `window.__pageData`
(or the grid-specific `window.assetTranslations` — see
[`localization.md`](../features/localization.md) for which channel a given
page family uses), return the Alpine data object, and attach it to `window`:

```js
export function myFeatureManager() {
    const t = window.__pageData?.translations || {};
    const config = window.__pageData?.myFeatureConfig || {};

    return {
        someState: config.initial || null,

        init() {
            // runs on x-init / element mount
        },

        async doThing() {
            const response = await fetch('/my-endpoint', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ /* ... */ }),
            });
            const data = await response.json();
            if (response.ok) {
                window.showToast(data.message || t.thingDone || 'Done');
            } else {
                window.showToast(data.message || t.thingFailed || 'Failed', 'error');
            }
        },
    };
}

window.myFeatureManager = myFeatureManager;
```

Reuse a shared mixin instead of duplicating logic already factored out:
`tag-input-core` (comma/paste-splitting tag inputs), `upload-metadata` (batch
metadata form), `thumbnail-generator` (client-side PDF/video thumbs) — these
are mixins, not top-level registered modules, so import their exported
helpers rather than copying their logic.

### 2. Register it — `resources/js/app.js`

Add an import alongside the existing ~25; order doesn't matter functionally,
but the file groups related modules together (asset grid/detail/editor, then
trash/discover/import/export, then tags/uploader/replacer, then admin, then
tools):

```js
import './alpine/my-feature';
```

### 3. Wire it into the Blade view

```blade
<div x-data="myFeatureManager()">
    ...
</div>
```

Inject any server-side config/translations the module reads via
`@js(__('...'))` into `window.__pageData` (see any existing view for the
established shape) — never fetch translations from the API; API responses
stay English.

### 4. Verify

```bash
npm run build   # or npm run dev while iterating
```

There is no Pest coverage for Alpine modules (browser-only behavior) — verify
manually or via the `claude-in-chrome`/`run` tooling if driving the actual
page.

## Gotchas

- Forgetting the `import './alpine/<name>';` line in `app.js` is the single
  most common failure mode — the module works in isolation but
  `x-data="myFeatureManager()"` silently does nothing in the browser (Alpine
  just can't find the function), with no build error.
- Don't invent a fourth JS-translation channel — pick the one matching your
  page family (`window.__pageData.translations` for most pages,
  `window.assetTranslations` for the grid partial, `window.appTranslations`
  only for the base layout's global toasts). See
  [`localization.md`](../features/localization.md).
- CSRF header + `Accept: application/json` on every `fetch()` — copy the
  pattern from an existing module (`tags.js`) rather than reconstructing it;
  a missing CSRF header produces a silent 419 on POST/PATCH/DELETE.
- Genuinely shared logic (used by more than one module) belongs in a mixin
  file, not copy-pasted — see `tag-input-core.js`'s `parseTagNames`/
  `tagInputCore`, reused by all four tag inputs in the app.

## Tests & verification

- No Pest suite covers Alpine modules directly (client-side, no Blade
  rendering asserted at this granularity). Verify via `npm run build` (no
  bundler errors) and a manual/browser-driven check that the module registers
  and its `fetch()` calls hit the expected route.
