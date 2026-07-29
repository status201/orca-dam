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

Add an import alongside the 21 modules registered in `resources/js/app.js`;
order doesn't matter functionally,
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

### 4. Verify — build, then a Playwright spec

```bash
npm run build   # or npm run dev while iterating
```

A clean build only proves the bundle resolves; it does not prove the module
registered or that `x-data="myFeatureManager()"` found it. Pest can't reach
that — but Playwright can, and it is the normal way to verify a module.

**Minimum bar: a boot check.** Put a root `data-testid` on the element
carrying `x-data`, then copy the idiom from
[`tools.spec.js`](../../tests/e2e/tools.spec.js) — register the `pageerror`
listener *before* navigating, assert the root is visible (which waits for
hydration), then assert nothing threw:

```js
test('my feature page boots its Alpine component', async ({ page }) => {
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));

    await page.goto('/my-feature');

    await expect(page.locator(testid('my-feature'))).toBeVisible();
    expect(errors).toEqual([]);
});
```

That one test catches the missing-`import` failure mode below, which is
otherwise silent. Then add behaviour tests for the module's own state and
`fetch()` calls following
[`write-an-e2e-test.md`](write-an-e2e-test.md) — `data-testid` selectors,
reseed per file, and the browser scenario pinned in the feature spec that owns
the behaviour (never in `e2e-testing.md`, which owns only the harness).

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
- Registering a 22nd module makes `npm run spec:lint` fail: the module count is
  hand-written in `CLAUDE.md`, `README.md` and
  `.claude/agents/senior-laravel-specialist.md`, and the lint counts the
  `import './alpine/…'` lines in `app.js` and compares. Bump all three. A new
  *mixin* doesn't count — only a line in `app.js` does.
- Locating by user-visible copy breaks the moment the page renders in `nl`.
  The UI ships in two locales, so a new module's controls need `data-testid`
  hooks before they can be tested at all — add them while you write the view,
  not after.

## Tests & verification

Alpine modules are verified in the browser, by Playwright. No Pest suite
asserts them (client-side, no Blade rendering asserted at this granularity), so
`npm run build` proving the bundle resolves is the floor, not the ceiling.

- `tests/e2e/tools.spec.js` — the boot-check pattern to copy: `pageerror`
  listener, root `data-testid` visible, `expect(errors).toEqual([])`. Its loop
  covers six tool pages.
- `tests/e2e/bulk-operations.spec.js` — the behaviour exemplar: Alpine state
  (selection), a `fetch()` round-trip, and the resulting toast.
- `tests/e2e/csv-import-export.spec.js` — a module driven end to end through
  its own multi-step state machine (`import.js`), plus a form-submit module
  with no success UI (`export.js`).
- `npm run build`, then `npm run test:e2e -- tests/e2e/<area>.spec.js`, then
  `npm run test:e2e` before you're done.
