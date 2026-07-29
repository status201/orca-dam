<!--
  Recipe: Playwright browser-test conventions for the Laravel app.
-->

# Recipe — Write an E2E (Playwright) test

```yaml
id: write-an-e2e-test
status: implemented
version: 1
owner: core
related:
  - architecture
  - ../features/e2e-testing
  - ../decisions/adr-014-playwright-e2e-real-stack
source:
  - tests/e2e/
  - playwright.config.js
  - database/seeders/E2eSeeder.php
```

A repeatable **playbook**, not a feature. Every browser spec drives the real app
booted by Playwright's `webServer` against the throwaway `.env.e2e` stack, is
authenticated from a saved `storageState`, and locates elements by
`data-testid` — the contract is [`../features/e2e-testing.md`](../features/e2e-testing.md).
The worked instance to copy is
[`tests/e2e/asset-grid.spec.js`](../../tests/e2e/asset-grid.spec.js).

## Background / Why

Two rules make these tests boring instead of flaky, and both are non-obvious.
**Locate by `data-testid`**: the same page renders in `en` and `nl`, and
`localization.spec.js` switches the locale mid-suite, so any locator keyed to
visible copy is a time bomb. **Reseed per file, not per test**: the suite shares
one SQLite file and one bucket with `workers: 1`, so isolation comes from a
`beforeAll` reseed plus asset names namespaced per spec area — not from
transactions, which don't exist across an HTTP boundary.

## Steps

### 1. Add the `data-testid` hooks to the view — `resources/views/...`

```blade
<div data-testid="asset-card" data-asset-id="{{ $asset->id }}">
    <p data-testid="asset-card-filename">{{ $asset->filename }}</p>
</div>
```

Naming is `<area>-<thing>[-<qualifier>]`, kebab-case: `grid-search`,
`asset-card`, `bulk-bar-trash`, `trash-restore`, `system-setting-items-per-page`.
Put the id on the element you *act on* (the button, the input), and add
`data-asset-id` / `data-user-id` where a test needs to address one row of many.
Views are exempt from the SDD production-code gate, so this edit needs no spec of
its own — but a **new** hook that a spec relies on belongs in that spec's
scenario.

### 2. Seed the fixtures you need — `database/seeders/E2eSeeder.php`

```php
$this->assets('e2e-widget', 3, $editor);   // e2e-widget-01.png … -03.png
```

Namespace by spec area so no two files fight over a row, and never assert on a
global count (another spec's leftovers would break it) — assert on your own
namespace.

### 3. Write the spec — `tests/e2e/<area>.spec.js`

```js
import { test, expect, gotoAssets, reseed, testid } from './support/fixtures.js';

test.describe('widgets', () => {
    test.beforeAll(reseed);          // only when the file mutates data

    test('a widget can be filtered out of the grid', async ({ page }) => {
        await gotoAssets(page);
        await page.fill(testid('grid-search'), 'e2e-widget-01');
        await page.press(testid('grid-search'), 'Enter');

        await expect(page.locator(testid('asset-card'))).toHaveCount(1);
        await expect(page.locator(testid('asset-card-filename'))).toHaveText('e2e-widget-01.png');
    });
});
```

For a non-admin role, opt into another saved session at the top of the file (or
inside a `describe`):

```js
import { asEditor, asApi } from './support/fixtures.js';
test.use({ storageState: asEditor });
```

For behaviour that needs real bytes in object storage, guard the block — it then
skips cleanly on a machine with no MinIO:

```js
requiresS3(test);        // first line inside the describe
```

### 4. Verify

```bash
npm run e2e:up                                       # MinIO (once per session)
npm run test:e2e -- tests/e2e/<area>.spec.js         # the new file
npm run test:e2e                                     # the whole suite before you're done
```

Then add the Gherkin scenario + `# pinned by: tests/e2e/<area>.spec.js` to the
feature spec that owns the behaviour — at the end of its `## Scenarios (BDD)` block,
after the `# — browser-level —` marker comment — and add an `- E2E:` bullet to that
spec's `## Tests & verification`. Run `npm run spec:lint`: every path named in a spec
must resolve, whether it is on a pin line or in a bullet.

**Do not add it to [`e2e-testing.md`](../features/e2e-testing.md).** That spec owns the
*harness* — reseeding, saved role sessions, the MinIO skip, the disposable artefacts —
and nothing else. Application behaviour is specified once, by its owning feature spec.
A behaviour with no owning spec means the spec is missing; write it first (`/feature`).

## Gotchas

- **`x-cloak` / `x-show` elements exist before Alpine hydrates.** Assert
  visibility (`toBeVisible()`), never presence, for anything inside the grid, the
  bulk bar, or a modal — and prefer `gotoAssets()`, which waits for hydration,
  over a bare `page.goto()`.
- **The grid's filter/sort/view-mode state is a full page navigation**
  (`applyFilters()` rewrites the URL). Await the navigation or a post-condition;
  don't assert immediately after the keypress.
- **`toast-container` messages auto-dismiss after 5s.** Use `expectToast()` right
  after the action that triggers it.
- **Never seed via the UI what a seeder can seed.** An upload test uploads; a grid
  test uses seeded rows. Uploading fixtures in `beforeEach` is the main way this
  suite gets slow.
- **A soft-deleted fixture stays soft-deleted for the rest of the file.** If a
  test consumes a fixture destructively, either give the file its own reseed or
  address a numbered fixture that no other test in the file touches — CI retries
  re-run `beforeAll`, but not the tests that already passed.
- **Never log out (or change a password) from a shared role session.** Every spec's
  `storageState` points at the *same* server-side session per role, so a logout in
  one file logs out every later file. Do it from a blank `storageState` with the
  spare account — see `tests/e2e/auth.spec.js`.
- **Don't point the suite at `.env`.** `playwright.config.js` loads `.env.e2e`
  and fails fast if it is missing; running against the dev database would let
  `reseed()` (`migrate:fresh`) drop it.
- **`role`-based queries are fine for genuinely semantic, untranslated markup**
  (an `<input type=file>`, a URL) — the `data-testid` rule is about anything
  carrying `__()` copy.

## Tests & verification

- `tests/e2e/support/fixtures.js` — the extended `test`/`expect`, role
  `storageState` paths, `gotoAssets`, `expectToast`, `testid`, `requiresS3`.
- `tests/e2e/global.setup.js` — reseed → S3 probe → three real logins.
- `npm run test:e2e` locally; `.github/workflows/tests.yml` → `e2e` in CI
  (blocking, report artifact on failure).
