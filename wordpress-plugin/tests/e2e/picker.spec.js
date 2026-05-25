// @ts-check
const { test, expect } = require('@playwright/test');

test('ORCA tab is installed on wp.media frames and picker click triggers ORCA import', async ({ page, request }) => {
    await request.post('/wp-json/orca-mock/v1/reset');

    // /wp-admin/upload.php loads wp.media (so our gutenberg.js bundle runs) but
    // also has its own page-level media frame. We open a *fresh* programmatic
    // frame on top of that to test the integration in isolation.
    await page.goto('/wp-admin/upload.php');
    await page.waitForFunction(
        () => typeof window.wp !== 'undefined' && !!window.wp.media,
        null,
        { timeout: 15_000 }
    );

    await page.evaluate(() => {
        const frame = window.wp.media({
            title: 'ORCA DAM',
            button: { text: 'Insert' },
            multiple: false,
        });
        frame.on('open', () => frame.setState('orca'));
        frame.open();
        window.__orcaFrame = frame;
    });

    // Verify the plugin successfully extended this frame with the OrcaState.
    // (Done via JS rather than DOM locators because upload.php's own frame
    // also has the ORCA tab installed, so multiple #menu-item-orca buttons
    // exist in the page — the integration check below is unambiguous.)
    const integration = await page.evaluate(() => ({
        hasOrcaState: !!window.__orcaFrame?.state('orca'),
        currentStateId: window.__orcaFrame?.state()?.id || null,
    }));
    expect(
        integration.hasOrcaState,
        'OrcaState was not registered on the wp.media frame — gutenberg.js plugin script did not run'
    ).toBe(true);
    expect(integration.currentStateId).toBe('orca');

    // The React picker mounts inside the OrcaView content region. Its root has
    // the `orca-dam-picker` class, which is unique to our React tree (not
    // present in upload.php's own list view).
    await page.locator('.orca-dam-picker').waitFor({ timeout: 15_000 });
    await page.locator('.orca-dam-grid button').first().click({ timeout: 10_000 });

    // The pick fires POST /orca/v1/import → which proxies GET /api/assets/{id}
    // to ORCA (i.e. through the mock transport).
    await page.waitForTimeout(2_000);
    const calls = await (await request.get('/wp-json/orca-mock/v1/calls')).json();
    const assetFetch = calls.find(
        (c) => c.method === 'GET' && /\/api\/assets\/\d+$/.test(c.path)
    );
    expect(
        assetFetch,
        `Expected GET /api/assets/{id} from picker import flow — clicking the tile did not trigger the import. Recorded ORCA calls: ${JSON.stringify(calls)}`
    ).toBeTruthy();
});
