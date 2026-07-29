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
});
