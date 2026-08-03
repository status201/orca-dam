// The client-side tool pages — pins specs/features/client-side-tools.md and the
// server-render entry point of specs/features/tikz-render.md. These are the most
// JS-heavy pages in the app, so a bundle/registration error surfaces here first.
import { expect, test, testid } from './support/fixtures.js';

// The deprecated tikz pages are in here too: they are still routed, still boot an
// Alpine module, and a bundle or registration error is exactly what this catches.
// Their TikZJax CDN loads happen inside an iframe srcdoc built by render(), which
// this never clicks — under the network-isolation fixture that would hang for the
// module's own 90s deadline.
const tools = [
    { card: 'tools-card-tikz-server', root: 'tool-tikz-server', url: /tools\/tikz-server$/ },
    { card: 'tools-card-gif-maker', root: 'tool-gif-maker', url: /tools\/gif-maker$/ },
    { card: 'tools-card-latex-mathml', root: 'tool-latex-mathml', url: /tools\/latex-mathml$/ },
    { card: 'tools-card-tikz-svg', root: 'tool-tikz-svg', url: /tools\/tikz-svg$/ },
    { card: 'tools-card-tikz-svg-fonts', root: 'tool-tikz-svg-fonts', url: /tools\/tikz-svg-fonts$/ },
    { card: 'tools-card-tikz-png', root: 'tool-tikz-png', url: /tools\/tikz-png$/ },
];

// Alpine rejects its transition promise when an element is removed mid-transition
// and logs "Transition was skipped" as an uncaught error. It is noise, not a fault.
const BENIGN = /Transition was skipped/;

test.describe('tools', () => {
    test('the overview lists every tool card', async ({ page }) => {
        await page.goto('/tools');

        for (const id of tools.map((t) => t.card)) {
            await expect(page.locator(testid(id))).toBeVisible();
        }
    });

    for (const tool of tools) {
        test(`${tool.card} opens and boots its Alpine component`, async ({ page }) => {
            const errors = [];
            page.on('pageerror', (error) => {
                if (!BENIGN.test(error.message)) errors.push(error.message);
            });

            await page.goto('/tools');
            await page.click(testid(tool.card));

            await expect(page).toHaveURL(tool.url);
            await expect(page.locator(testid(tool.root))).toBeVisible();
            expect(errors).toEqual([]);
        });
    }

    // specs/features/client-side-tools.md REQ-7. These three pages collect render output from a
    // hidden iframe over postMessage and inject it into the page — the SVG variants through
    // x-html, i.e. innerHTML. postMessage is deliverable by any window holding a reference to
    // this one (an opener, or a page framing ORCA), so the handlers pin the sender to their own
    // iframe. Posting from the page's own window is the cheapest way to prove that: it is a
    // legitimate same-origin sender that is nonetheless not the render iframe, so it must be
    // refused for exactly the reason a hostile opener is.
    // One `{` per entry on purpose: spec-lint resolves a loop's array by counting braces, so a
    // nested literal here would inflate the documented E2E total. The payload shape is built in
    // the test body instead.
    const messageTools = [
        { url: '/tools/tikz-svg', root: 'tool-tikz-svg', type: 'tikz-svgs', shape: 'svgs' },
        { url: '/tools/tikz-svg-fonts', root: 'tool-tikz-svg-fonts', type: 'tikz-svgs-fonts', shape: 'svgs' },
        { url: '/tools/tikz-png', root: 'tool-tikz-png', type: 'tikz-pngs', shape: 'pngs' },
    ];

    for (const tool of messageTools) {
        test(`${tool.root} ignores a forged ${tool.type} message from outside its iframe`, async ({ page }) => {
            await page.goto(tool.url);
            await expect(page.locator(testid(tool.root))).toBeVisible();

            const markup = '<img src="x" onerror="window.__forgedRenderExecuted = true">';
            const data = tool.shape === 'svgs'
                ? { svgs: [markup] }
                : { pngs: [{ dataUrl: markup, width: 1, height: 1 }] };

            await page.evaluate(
                ({ type, payload }) => {
                    window.__forgedRenderExecuted = false;
                    window.postMessage({ type, ...payload }, '*');
                },
                { type: tool.type, payload: data }
            );

            // The load-bearing assertion: an accepted message populates `results`, and the markup
            // reaches the DOM through x-html. Verified by mutation — with the guard removed this
            // finds one `img[src="x"]`, so it is the assertion that actually proves injection.
            await expect(page.locator('img[src="x"]')).toHaveCount(0);
            await expect(page.locator(`${testid(tool.root)} svg`)).toHaveCount(0);

            // Secondary, and deliberately read after the DOM assertions rather than polled for
            // false: `onerror` fires asynchronously, so polling for `false` would pass on its
            // first check and prove nothing on its own.
            expect(await page.evaluate(() => window.__forgedRenderExecuted)).toBe(false);
        });
    }
});
