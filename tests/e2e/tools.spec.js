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

// specs/features/tikz-render.md REQ-8. The page sends one render request per tikzpicture, so a big
// batch outruns the per-minute limit; a 429 must pause the batch, not end it. The render endpoint is
// mocked — the E2E stack ships no TeX Live — which is also why compilerAvailable is forced on: with
// it false the Render button stays disabled.
test.describe('tikz server render rate limit', () => {
    const SNIPPETS = [
        String.raw`\begin{tikzpicture}\draw (0,0) -- (1,0);\end{tikzpicture}`,
        String.raw`\begin{tikzpicture}\draw (0,0) -- (0,1);\end{tikzpicture}`,
    ];
    const SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>';

    // `answers` is called with the 1-based call number and returns 'ok' or a Retry-After in seconds.
    async function mockRender(page, answers) {
        const bodies = [];
        await page.route('**/tools/tikz-server/render', (route) => {
            bodies.push(route.request().postDataJSON().tikz_code);
            const answer = answers(bodies.length);

            return answer === 'ok'
                ? route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    body: JSON.stringify({ variants: [{ type: 'svg_paths', content: SVG, size: SVG.length, mime: 'image/svg+xml' }], log: '' }),
                })
                : route.fulfill({
                    status: 429,
                    headers: { 'Retry-After': String(answer) },
                    contentType: 'application/json',
                    body: JSON.stringify({ message: 'Too Many Attempts.' }),
                });
        });

        return bodies;
    }

    async function startBatch(page) {
        await page.goto('/tools/tikz-server');
        await expect(page.locator(testid('tool-tikz-server'))).toBeVisible();
        await page.evaluate(() => {
            window.Alpine.$data(document.querySelector('[data-testid="tool-tikz-server"]')).compilerAvailable = true;
        });
        await page.fill(testid('tikz-code-input'), SNIPPETS.join('\n\n'));
        await page.click(testid('tikz-render-button'));
    }

    test('a 429 pauses the batch, then retries the same snippet and finishes', async ({ page }) => {
        const bodies = await mockRender(page, (call) => (call === 2 ? 1 : 'ok'));

        await startBatch(page);

        await expect(page.locator(testid('tikz-render-rate-limit'))).toBeVisible();
        await expect(page.locator(testid('tikz-result'))).toHaveCount(2);
        await expect(page.locator(testid('tikz-render-stop'))).toHaveCount(0);
        await expect(page.locator(testid('tikz-render-error'))).toHaveCount(0);
        expect(bodies).toEqual([SNIPPETS[0], SNIPPETS[1], SNIPPETS[1]]);
    });

    test('Stop during the pause ends the batch and keeps the earlier results', async ({ page }) => {
        const bodies = await mockRender(page, (call) => (call === 1 ? 'ok' : 30));

        await startBatch(page);

        await expect(page.locator(testid('tikz-render-rate-limit'))).toBeVisible();
        await page.click(testid('tikz-render-stop'));

        // Well inside the 30s Retry-After: Stop is honoured on the next one-second tick.
        await expect(page.locator(testid('tikz-render-stop'))).toHaveCount(0, { timeout: 5_000 });
        await expect(page.locator(testid('tikz-result'))).toHaveCount(1);
        await expect(page.locator(testid('tikz-render-error'))).toHaveCount(0);
        expect(bodies).toHaveLength(2);
    });

    test('three 429s in a row end the batch with the rate-limit message', async ({ page }) => {
        const bodies = await mockRender(page, () => 1);

        await startBatch(page);

        const message = await page.evaluate(() => window.__pageData.translations.rateLimited);
        await expect(page.locator(testid('tikz-render-error'))).toContainText(message);
        await expect(page.locator(testid('tikz-result'))).toHaveCount(0);
        expect(bodies).toHaveLength(3);
    });
});
