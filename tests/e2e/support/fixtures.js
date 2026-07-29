// The API every spec imports: the extended `test`/`expect`, role sessions, and
// the handful of helpers that keep locators stable and waits explicit.
// Contract: specs/features/e2e-testing.md · How-to: specs/recipes/write-an-e2e-test.md
import { expect, test as base } from '@playwright/test';
import { hasS3 } from './s3.js';
import { reseed, tinker, tokens } from './db.js';

/** Saved sessions produced by global.setup.js — pass to `test.use({ storageState })`. */
export const asAdmin = 'tests/e2e/.auth/admin.json';
export const asEditor = 'tests/e2e/.auth/editor.json';
export const asApi = 'tests/e2e/.auth/api.json';

export const users = {
    admin: { email: 'admin@e2e.test', name: 'Admin E2E' },
    editor: { email: 'editor@e2e.test', name: 'Editor E2E' },
    api: { email: 'api@e2e.test', name: 'Api E2E' },
    spare: { email: 'spare@e2e.test', name: 'Spare Editor' },
};

export const PASSWORD = 'password';

/** `data-testid` selector. Never locate by user-visible copy — the UI renders en *and* nl. */
export function testid(id) {
    return `[data-testid="${id}"]`;
}

export const test = base.extend({
    /**
     * Keep the suite hermetic: the layouts pull Font Awesome from cdnjs and the
     * Figtree webfont from fonts.bunny.net, which makes every page load depend on
     * the network (and stall for its timeout when CI has no egress). Anything not
     * served by the app or the local bucket is aborted.
     */
    page: async ({ page, baseURL }, use) => {
        const allowed = new Set([new URL(baseURL).host, '127.0.0.1:9000', 'localhost:9000']);

        await page.route('**/*', (route) => (
            allowed.has(new URL(route.request().url()).host) ? route.continue() : route.abort()
        ));

        // Several controls are icon-only (`<button><i class="fas fa-trash">`), and
        // with Font Awesome blocked those <i> elements collapse to 0x0, so the
        // button has no hit box and Playwright rightly refuses to click it. The
        // stylesheet can't be stubbed via route.fulfill (the <link> carries an SRI
        // integrity hash, so a substituted body is rejected), hence an injected
        // style rule instead.
        await page.addInitScript(() => {
            const css = '.fa,.fas,.far,.fab,.fa-solid,.fa-regular{display:inline-block;width:1em;height:1em;line-height:1}';
            const inject = () => {
                const style = document.createElement('style');
                style.dataset.e2eIconStub = '1';
                style.textContent = css;
                document.head.appendChild(style);
            };

            if (document.head) inject();
            else document.addEventListener('DOMContentLoaded', inject, { once: true });
        });

        await use(page);
    },

    /** A request context authenticated as a role's Sanctum token. */
    api: async ({ playwright, baseURL }, use) => {
        const token = tokens();
        const contexts = {};

        const contextFor = async (role) => {
            contexts[role] ??= await playwright.request.newContext({
                baseURL,
                extraHTTPHeaders: {
                    Authorization: `Bearer ${token[role]}`,
                    Accept: 'application/json',
                },
            });

            return contexts[role];
        };

        await use(contextFor);

        await Promise.all(Object.values(contexts).map((c) => c.dispose()));
    },
});

export { expect, reseed, tinker, tokens, hasS3 };

/**
 * Skip the enclosing file/describe when no MinIO endpoint answered at startup.
 * Call as the first statement inside the describe (or at file scope).
 */
export function requiresS3(t = test) {
    t.skip(!hasS3(), 'needs the MinIO bucket — run `npm run e2e:up`');
}

/**
 * Wait until Alpine has started. Alpine removes every `x-cloak` attribute as it
 * initialises, so an empty `[x-cloak]` set is the one reliable "hydrated" signal —
 * and without it a click can land on server-rendered markup that Alpine has not
 * un-hidden yet, which fails as "element is not visible".
 */
export async function waitForAlpine(page) {
    await page.waitForFunction(() => document.querySelectorAll('[x-cloak]').length === 0);
}

/**
 * page.goto() that survives the app navigating out from under it.
 *
 * Every bulk action reloads the page ~800ms after its POST resolves
 * (`setTimeout(() => window.location.reload(), 800)` in asset-grid.js and
 * trash.js), and the summary panels reload the moment they are dismissed. A spec
 * that acts and then navigates therefore has a window in which the app's reload
 * commits first and Chromium cancels the test's navigation with ERR_ABORTED —
 * which surfaced as bulk-operations and asset-trash failing on a plain page.goto,
 * more often the slower the machine.
 *
 * The abort is benign: nothing is wrong except that two navigations raced. The
 * reload fires once, so by the time we retry it has already landed and the second
 * goto wins. Retrying only on ERR_ABORTED keeps real navigation failures loud.
 */
export async function gotoStable(page, url) {
    try {
        await page.goto(url);
    } catch (error) {
        if (!/ERR_ABORTED/.test(String(error?.message))) throw error;

        await page.goto(url);
    }

    return page;
}

/** Open the asset library (optionally filtered) and wait for the hydrated grid. */
export async function gotoAssets(page, params = {}) {
    const query = new URLSearchParams(params).toString();
    await gotoStable(page, `/assets${query ? `?${query}` : ''}`);
    await expect(page.locator(testid('asset-grid'))).toBeVisible();
    await waitForAlpine(page);

    return page;
}

/** Open the trash page and wait for it to hydrate. */
export async function gotoTrash(page) {
    await gotoStable(page, '/assets/trash/index');
    await expect(page.locator(testid('trash-page'))).toBeVisible();
    await waitForAlpine(page);

    return page;
}

/** Switch the grid to `grid` | `masonry` | `list` and wait for that view to show. */
export async function useViewMode(page, mode) {
    await page.click(testid(`grid-view-${mode}`));
    await expect(page.locator(testid(`asset-${mode}-view`))).toBeVisible();
}

/** Switch the trash page to `grid` | `list`. */
export async function useTrashViewMode(page, mode) {
    await page.click(testid(`trash-view-${mode}`));
    await expect(page.locator(testid(mode === 'list' ? 'trash-row' : 'trash-card')).first()).toBeVisible();
}

/** The card (grid view) or row (list view) for one seeded asset, by filename. */
export function assetCard(page, filename) {
    return page.locator(testid('asset-card')).filter({ has: page.getByText(filename, { exact: true }) });
}

export function assetRow(page, filename) {
    return page.locator(testid('asset-row')).filter({ has: page.getByText(filename, { exact: true }) });
}

/**
 * Assert a toast message. Toasts are `.toast` divs appended straight to <body> by
 * `window.showToast` (resources/js/app.js) — the `#toast-container` in
 * layouts/app.blade.php belongs to an older inline implementation that the bundle
 * overrides, so it is always empty. They auto-dismiss after 3s (5s for warnings),
 * and several actions reload the page ~1s later, so assert immediately after the
 * triggering action, or assert the outcome instead.
 */
export async function expectToast(page, pattern) {
    await expect(page.locator('.toast').filter({ hasText: pattern }).first()).toBeVisible({ timeout: 5_000 });
}

/** Dismiss the next window.confirm() — several destructive UI actions use one. */
export function acceptConfirm(page) {
    page.once('dialog', (dialog) => dialog.accept());
}
