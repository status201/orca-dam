// @ts-check
const { test, expect } = require('@playwright/test');

test('ORCA tab appears in media modal and selecting an asset triggers the import flow', async ({ page, request }) => {
    // Reset the mock call log so we can assert the import call cleanly.
    await request.post('/wp-json/orca-mock/v1/reset');

    // Land on the standalone Media Library page. wp_enqueue_media fires there, so
    // wp.media is available and the plugin's gutenberg.js bundle has installed the
    // OrcaState + ORCA tab on every wp.media frame. This deliberately avoids the
    // block editor entirely — Gutenberg's UI changes too often to drive reliably.
    await page.goto('/wp-admin/upload.php');
    await page.waitForFunction(
        () => typeof window.wp !== 'undefined' && !!window.wp.media,
        null,
        { timeout: 15_000 }
    );

    // Open a media frame programmatically and route it to the ORCA tab.
    await page.evaluate(() => {
        const frame = window.wp.media({
            title: 'ORCA DAM',
            button: { text: 'Insert' },
            multiple: false,
        });
        frame.on('open', () => frame.setState('orca'));
        frame.open();
        window.__orcaTestFrame = frame;
    });

    // The custom router tab and a fixture thumbnail must both appear.
    await expect(page.getByRole('tab', { name: 'ORCA DAM' })).toBeVisible({ timeout: 10_000 });
    await expect(page.getByTitle('logo.png')).toBeVisible({ timeout: 10_000 });

    // Pick the asset. Clicking the tile triggers the picker's onPick handler,
    // which POSTs /orca/v1/import → which proxies GET /api/assets/1001 to ORCA
    // (i.e. through our mock transport).
    await page.getByTitle('logo.png').click();

    // Wait briefly for the import HTTP round-trip to land in the mock log.
    await page.waitForTimeout(2_000);

    const calls = await (await request.get('/wp-json/orca-mock/v1/calls')).json();
    const assetFetch = calls.find(
        (c) => c.method === 'GET' && /\/api\/assets\/1001$/.test(c.path)
    );
    expect(
        assetFetch,
        'Expected GET /api/assets/1001 from the import flow — picker did not import the selected asset'
    ).toBeTruthy();
});
