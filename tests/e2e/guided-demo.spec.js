// The guided-demo engine — pins the browser-level scenarios of
// specs/features/guided-demos.md.
//
// Nothing here hardcodes a step number or a step total. Steps are data (a demo may gain
// or lose one without any behaviour changing), so every assertion is keyed to
// `data-target` — the testid the overlay says it is currently spotlighting — or to the
// total the page itself reports. That is the same discipline dashboard-tour.spec.js
// applies to slide counts, for the same reason.
//
// Demos are started by URL rather than by clicking the carousel slide: the carousel
// autoplays every 7s, so a spec that had to catch a particular slide would be racing it.
// One test asserts the slide's href instead, which is the part that could actually break.
import {
    asEditor,
    expect,
    gotoStable,
    reseed,
    test,
    testid,
    waitForAlpine,
} from './support/fixtures.js';

const overlay = (page) => page.locator(testid('demo-overlay'));
const spotlitTarget = (page) => overlay(page).getAttribute('data-target');

/**
 * Collect page errors, minus one the app produces on any script-driven navigation.
 *
 * `app.css` opts into cross-document view transitions (`@view-transition { navigation:
 * auto }`). A navigation that comes from script rather than from a user activating a link
 * gets its transition skipped, and Chromium surfaces the rejected promise as an unhandled
 * "Transition was skipped". Verified against a plain nav-link click (no error) versus both
 * `location.assign` and a scripted anchor click (error), with no demo involved — and the
 * app already navigates this way in eight places, `assetGrid.applyFilters` among them, so
 * every grid filter change does it today. Nothing to do with the demo, so it is filtered
 * rather than asserted; anything else still fails the test.
 */
function collectPageErrors(page) {
    const errors = [];
    page.on('pageerror', (error) => {
        if (!/Transition was skipped/.test(error.message)) errors.push(error.message);
    });

    return errors;
}

/**
 * Wait until the engine has finished positioning the current step.
 *
 * Target resolution is asynchronous (a reveal, then a poll for hydration), so
 * `data-target` still holds the *previous* step's value for a moment after a click.
 * `data-settled` is the signal that it is safe to read.
 */
async function settle(page) {
    await expect(overlay(page)).toHaveAttribute('data-settled', 'true');
}

/** Open a demo straight at a step and wait for the overlay to take hold. */
async function openDemo(page, path = '/dashboard', step = 0) {
    const url = new URL(path, 'http://x');
    url.searchParams.set('demo', 'welcome');
    url.searchParams.set('demoStep', String(step));

    await gotoStable(page, `${url.pathname}${url.search}`);
    await waitForAlpine(page);
    await expect(overlay(page)).toHaveAttribute('data-active', 'true');
    await settle(page);

    return page;
}

/**
 * Click Next until `target` is the spotlit element.
 *
 * Deliberately not "click Next N times": which step a target lives at is data, and a
 * step whose target is absent for this user is skipped by design (REQ-9), so counting
 * clicks would be wrong even if the demo never changed.
 */
async function advanceUntil(page, target, max = 25) {
    for (let i = 0; i < max; i++) {
        await settle(page);

        if ((await spotlitTarget(page)) === target) return;

        await page.click(testid('demo-next'));
        // A step may cross a page boundary, so let the next document hydrate. Cheap when
        // nothing navigated: x-cloak is already clear.
        await waitForAlpine(page);
        await expect(overlay(page)).toHaveAttribute('data-active', 'true');
    }

    throw new Error(`the demo never spotlit ${target} (stopped at ${await spotlitTarget(page)})`);
}

/**
 * Assert the highlight ring is centred on an element.
 *
 * Polled rather than measured once: the ring morphs between steps through a 180ms CSS
 * transition (app.css), so a single boundingBox() read can land mid-flight.
 */
async function expectRingOn(page, target) {
    await expect
        .poll(async () => {
            const ring = await page.locator(testid('demo-spotlight')).boundingBox();
            const box = await page.locator(testid(target)).boundingBox();

            if (!ring || !box) return Number.MAX_SAFE_INTEGER;

            return Math.max(
                Math.abs((ring.x + ring.width / 2) - (box.x + box.width / 2)),
                Math.abs((ring.y + ring.height / 2) - (box.y + box.height / 2)),
            );
        }, { timeout: 5_000 })
        // The ring is padded around its target, so centres match even though edges do not.
        .toBeLessThan(8);
}

test.describe('guided demos', () => {
    // Finishing or skipping a demo writes to the user's preferences, so this file is not
    // read-only and must start from a known database.
    test.beforeAll(reseed);

    // The engine keeps a same-tab breadcrumb so an app-driven navigation cannot lose the
    // demo. Clear it before the page's own scripts run, or one test's abandoned demo
    // re-arms itself inside the next.
    test.beforeEach(async ({ page }) => {
        await page.addInitScript(() => window.sessionStorage.removeItem('orca.demo'));
    });

    test('the overlay is inert when no demo is running', async ({ page }) => {
        // This is the test that protects every other spec in the suite: the overlay ships
        // from the base layout, so an engine that armed itself unbidden would put a scrim
        // over the whole app.
        const errors = collectPageErrors(page);

        await gotoStable(page, '/dashboard');
        await waitForAlpine(page);

        await expect(overlay(page)).toHaveCount(0);
        await expect(page.locator(testid('nav-assets'))).toBeVisible();
        expect(errors).toEqual([]);
    });

    test('the launcher starts the demo on its first step', async ({ page }) => {
        await gotoStable(page, '/dashboard');
        await waitForAlpine(page);

        await page.click(testid('demo-start'));
        await waitForAlpine(page);

        await expect(overlay(page)).toHaveAttribute('data-active', 'true');
        await expect(overlay(page)).toHaveAttribute('data-demo', 'welcome');
        await expect(page.locator(testid('demo-step'))).toHaveText('1');
        await expect(page.locator(testid('demo-title'))).not.toBeEmpty();
    });

    test('next and back walk the steps and the counter tracks them', async ({ page }) => {
        await openDemo(page);

        const total = Number(await page.locator(testid('demo-steps')).textContent());
        expect(total).toBeGreaterThan(1);

        await expect(page.locator(testid('demo-step'))).toHaveText('1');
        // Back is unavailable on the first step rather than wrapping — a demo is a
        // sequence, not a carousel.
        await expect(page.locator(testid('demo-prev'))).toBeDisabled();

        await page.click(testid('demo-next'));
        await expect(page.locator(testid('demo-step'))).toHaveText('2');

        await page.click(testid('demo-prev'));
        await expect(page.locator(testid('demo-step'))).toHaveText('1');
    });

    test('the spotlight is centred on the real element', async ({ page }) => {
        await openDemo(page);
        await advanceUntil(page, 'stat-total-assets');

        await expectRingOn(page, 'stat-total-assets');
    });

    test('a nav step stays visible even though the nav auto-hides on scroll', async ({ page }) => {
        await openDemo(page);

        // layouts/navigation.blade.php adds -translate-y-full once scrollY passes 100.
        await page.mouse.wheel(0, 600);
        await expect(page.locator(testid('app-nav'))).toHaveClass(/-translate-y-full/);

        await advanceUntil(page, 'nav-assets');

        // Pinned back by body.orca-demo-active in app.css, not by touching the nav's own
        // Alpine state.
        const nav = await page.locator(testid('app-nav')).boundingBox();
        expect(nav.y).toBeGreaterThanOrEqual(0);

        await expectRingOn(page, 'nav-assets');
    });

    test('the demo crosses from the dashboard to the library and resumes there', async ({ page }) => {
        await openDemo(page);

        // Walk to the last dashboard step — the Assets nav item, which is what the hand-off
        // is anchored on — then one more Next crosses the page boundary.
        await advanceUntil(page, 'nav-assets');

        await Promise.all([
            page.waitForURL(/\/assets\?/),
            page.click(testid('demo-next')),
        ]);

        await expect(page.locator(testid('asset-grid'))).toBeVisible();
        await waitForAlpine(page);

        await expect(overlay(page)).toHaveAttribute('data-active', 'true');
        await expect(overlay(page)).toHaveAttribute('data-demo', 'welcome');
        await expect(overlay(page)).toHaveAttribute('data-target', 'grid-total');
    });

    test('a shared link opens the demo part-way through', async ({ page }) => {
        // Straight to a library step without replaying the dashboard ones — which is also
        // what makes the rest of this file quick.
        await openDemo(page, '/assets', 6);

        await expect(page.locator(testid('asset-grid'))).toBeVisible();
        expect(await spotlitTarget(page)).toBe('grid-search');
    });

    test('a link that lands on the wrong page offers to go to the right one', async ({ page }) => {
        // Step 6 belongs to the assets index; open it on the tags page instead.
        await openDemo(page, '/tags', 6);

        await expect(overlay(page)).toHaveAttribute('data-placement', 'center');
        await expect(page.locator(testid('demo-goto'))).toBeVisible();
        await expect(page.locator(testid('demo-spotlight'))).toBeHidden();

        await Promise.all([
            page.waitForURL(/\/assets\?/),
            page.click(testid('demo-goto')),
        ]);

        await waitForAlpine(page);
        await settle(page);
        expect(await spotlitTarget(page)).toBe('grid-search');
    });

    test('a mid-demo reload resumes on the same step', async ({ page }) => {
        await openDemo(page, '/assets', 6);

        await page.click(testid('demo-next'));
        await settle(page);
        const target = await spotlitTarget(page);

        await page.reload();
        await waitForAlpine(page);

        // The step lives in the URL, so a reload is not a restart.
        await expect(overlay(page)).toHaveAttribute('data-target', target);
    });

    test('an interactive step advances when the user types in the real search box', async ({ page }) => {
        await openDemo(page, '/assets', 6);

        expect(await spotlitTarget(page)).toBe('grid-search');
        await expect(overlay(page)).toHaveAttribute('data-awaiting', 'true');

        // No demo control is touched — the app's own input is the only thing driven.
        await page.fill(testid('grid-search'), 'e2e-grid');

        await expect(overlay(page)).toHaveAttribute('data-awaiting', 'false');
        await expect(overlay(page)).toHaveAttribute('data-target', 'grid-filter-folder');
    });

    test('next stays available on an interactive step so nobody is trapped', async ({ page }) => {
        await openDemo(page, '/assets', 6);

        await expect(overlay(page)).toHaveAttribute('data-awaiting', 'true');
        await expect(page.locator(testid('demo-next'))).toBeEnabled();

        await page.click(testid('demo-next'));
        await expect(overlay(page)).toHaveAttribute('data-target', 'grid-filter-folder');
    });

    test('clicking the tag filter advances and the panel step reveals it', async ({ page }) => {
        await openDemo(page, '/assets', 6);
        await advanceUntil(page, 'grid-filter-tags');

        await expect(overlay(page)).toHaveAttribute('data-awaiting', 'true');
        await page.click(testid('grid-filter-tags'));

        await expect(overlay(page)).toHaveAttribute('data-target', 'grid-tag-filter-panel');
        // The reveal primitive must not re-click the toggle and close what this click
        // opened — the control is a toggle, not an opener.
        await expect(page.locator(testid('grid-tag-filter-panel'))).toBeVisible();
    });

    test('the view-mode steps switch the library into each view in turn', async ({ page }) => {
        await openDemo(page, '/assets', 6);

        // Each step's reveal presses its own toggle, so the assets visibly rearrange as
        // the step is read rather than being described in the abstract.
        await advanceUntil(page, 'grid-view-grid');
        await expect(page.locator(testid('asset-grid-view'))).toBeVisible();

        await advanceUntil(page, 'grid-view-masonry');
        await expect(page.locator(testid('asset-masonry-view'))).toBeVisible();
        await expect(page.locator(testid('asset-grid-view'))).toBeHidden();

        await advanceUntil(page, 'grid-view-list');
        await expect(page.locator(testid('asset-list-view'))).toBeVisible();
        await expect(page.locator(testid('asset-masonry-view'))).toBeHidden();

        // The list step claims it is the only view you can edit from; prove the inline
        // tag and licence controls are actually on screen while it says so.
        await expect(page.locator(testid('asset-row-tag-add')).first()).toBeVisible();
        await expect(page.locator(testid('asset-row-license')).first()).toBeVisible();
    });

    test('the upload steps run on the upload screen and the demo returns to the library', async ({ page }) => {
        await openDemo(page, '/assets', 6);
        await advanceUntil(page, 'grid-upload');

        await Promise.all([
            page.waitForURL(/\/assets\/create/),
            page.click(testid('demo-next')),
        ]);
        await waitForAlpine(page);

        await expect(page.locator(testid('upload-page'))).toBeVisible();
        await expect(overlay(page)).toHaveAttribute('data-target', 'upload-folder');

        // The two choices that are awkward to undo, then the batch metadata panel, which
        // the step opens for itself.
        await advanceUntil(page, 'upload-keep-filename');
        await advanceUntil(page, 'batch-metadata-panel');
        await expect(page.locator(testid('batch-metadata-panel'))).toBeVisible();

        await advanceUntil(page, 'upload-dropzone');

        // The closing step lives back on the library, so Next crosses back — which is what
        // the upload step promised would happen.
        await Promise.all([
            page.waitForURL(/\/assets\?/),
            page.click(testid('demo-next')),
        ]);
        await waitForAlpine(page);

        await expect(page.locator(testid('asset-grid'))).toBeVisible();
        await expect(overlay(page)).toHaveAttribute('data-target', 'grid-total');
        await expect(page.locator(testid('demo-finish'))).toBeVisible();
    });

    test('a step whose target is absent is skipped without breaking the page', async ({ page }) => {
        const errors = collectPageErrors(page);

        // No asset matches, so the grid renders its empty state: the view toggles are still
        // there but none of the three view containers is, and there is no tile for the
        // "what a tile tells you" step to point at.
        await openDemo(page, '/assets?search=no-such-asset-xyz', 6);

        await expect(page.locator(testid('asset-grid-empty'))).toBeVisible();

        // Walking to the last step covers every degraded step on the way, and does not
        // depend on how many there are.
        await advanceUntil(page, 'grid-upload');
        await expect(overlay(page)).toHaveAttribute('data-active', 'true');

        expect(errors).toEqual([]);
    });

    test('arrow keys step the demo but are ignored while an input has focus', async ({ page }) => {
        await openDemo(page);

        await page.keyboard.press('ArrowRight');
        await expect(page.locator(testid('demo-step'))).toHaveText('2');

        await page.keyboard.press('ArrowLeft');
        await expect(page.locator(testid('demo-step'))).toHaveText('1');

        // On the search step the arrows belong to the search box, not to the demo.
        await openDemo(page, '/assets', 6);
        await page.locator(testid('grid-search')).focus();
        const before = await spotlitTarget(page);

        await page.keyboard.press('ArrowRight');

        await expect(overlay(page)).toHaveAttribute('data-target', before);
    });

    test('escape abandons the demo and it does not come back on reload', async ({ page }) => {
        await openDemo(page);

        await page.keyboard.press('Escape');
        await expect(overlay(page)).toHaveAttribute('data-active', 'false');

        await page.reload();
        await waitForAlpine(page);

        // Escape strips the demo from the URL, so there is nothing left to boot.
        await expect(overlay(page)).toHaveCount(0);
    });

    test('finishing the last step records the demo against the user', async ({ page }) => {
        const total = await (async () => {
            await openDemo(page, '/assets', 99);

            return Number(await page.locator(testid('demo-steps')).textContent());
        })();

        // Clamped to the last step, so Done is the control on offer.
        await expect(page.locator(testid('demo-step'))).toHaveText(String(total));
        await expect(page.locator(testid('demo-next'))).toBeHidden();

        await page.click(testid('demo-finish'));

        // The successor demo does not exist, so the demo simply closes.
        await expect(overlay(page)).toHaveAttribute('data-active', 'false');

        await gotoStable(page, '/dashboard');
        await expect(page.locator(`${testid('demo-start')} .fa-check`)).toBeVisible();
    });

    // REQ-6a. stop() strips the demo from the address bar, but it cannot strip links the
    // server already rendered — and the grid used to copy its whole query string onto every
    // card link, so opening an asset handed the finished demo straight back. The last step
    // belongs to assets.index, so on the detail page it surfaced as a hand-off card.
    test('a finished demo does not follow the reader onto an asset page', async ({ page }) => {
        const errors = collectPageErrors(page);

        await openDemo(page, '/assets', 99);
        await expect(page.locator(testid('demo-finish'))).toBeVisible();

        // Closes outright rather than offering a successor, because WelcomeDemo names one
        // that is not registered. Register 'admin-basics' and this needs demo-skip first.
        await page.click(testid('demo-finish'));
        await expect(overlay(page)).toHaveAttribute('data-active', 'false');

        await page.locator(testid('asset-card')).first().click();
        await waitForAlpine(page);

        await expect(page).toHaveURL(/\/assets\/\d+/);
        expect(page.url()).not.toContain('demo');
        // REQ-12: nothing armed means the overlay is not rendered at all.
        await expect(overlay(page)).toHaveCount(0);
        expect(errors).toEqual([]);
    });

    test('the dashboard carousel offers the demo', async ({ page }) => {
        await gotoStable(page, '/dashboard');
        await waitForAlpine(page);

        // Assert the link, not a click: the carousel autoplays, so the slide would move
        // out from under the click. The href is the part that can actually regress.
        await expect(page.locator('[data-testid="tour"] a[href*="demo=welcome"]').first())
            .toHaveAttribute('href', /demo=welcome/);
    });

    test.describe('as an editor', () => {
        test.use({ storageState: asEditor });

        test('the welcome demo is playable by a non-admin', async ({ page }) => {
            const errors = collectPageErrors(page);

            await openDemo(page);

            const total = Number(await page.locator(testid('demo-steps')).textContent());

            // Walk the whole demo. Every step must resolve to something an editor can
            // see — the demo deliberately points at nothing gated. Driven by "is Done
            // showing yet" rather than a click count, so the cross-page hand-off and any
            // step skipped for this role are both handled without special cases.
            for (let i = 1; i < total; i++) {
                await settle(page);

                if (await page.locator(testid('demo-finish')).isVisible()) break;

                await page.click(testid('demo-next'));
                await waitForAlpine(page);
            }

            await settle(page);
            await expect(page.locator(testid('demo-finish'))).toBeVisible();
            expect(errors).toEqual([]);
        });
    });
});
