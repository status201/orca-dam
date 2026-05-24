// @ts-check
const { test, expect } = require('@playwright/test');

test('publishing a post with an ORCA image POSTs a reference tag', async ({ page, request }) => {
    await request.post('/wp-json/orca-mock/v1/reset');

    // Pre-create a post containing the fixture image and the matching attachment shell.
    // The fastest path is to insert a row via WP-CLI through the mock plugin's REST
    // helper; in this skeleton we drive the editor directly.
    await page.goto('/wp-admin/post-new.php');
    const welcomeClose = page.locator('button[aria-label="Close"]').first();
    if (await welcomeClose.isVisible().catch(() => false)) {
        await welcomeClose.click();
    }

    await page.locator('h1.editor-post-title__input, [aria-label="Add title"]').first().fill('Usage sync test');

    // Switch to code-editor mode and paste raw block markup so we don't depend on
    // the modal flow (which is covered separately by picker.spec.js).
    await page.keyboard.press('Control+Shift+Alt+M');
    await page.locator('textarea.editor-post-text-editor').fill(
        '<!-- wp:image {"id":0} -->\n<figure class="wp-block-image"><img src="https://mock.orca.test/assets/branding/logo.png" alt="" data-orca-asset-id="1001" /></figure>\n<!-- /wp:image -->'
    );
    await page.keyboard.press('Control+Shift+Alt+M');

    // Publish.
    await page.getByRole('button', { name: /^Publish$/i }).click();
    await page.getByRole('button', { name: /^Publish$/i }).nth(1).click({ trial: false }).catch(() => {});

    // Wait for the async TagSyncJob to run (Action Scheduler runs on shutdown
    // in wp-env by default).
    await page.waitForTimeout(3000);

    const calls = await (await request.get('/wp-json/orca-mock/v1/calls')).json();
    const refTagPost = calls.find(
        (c) => c.method === 'POST' && c.path.endsWith('/api/reference-tags')
    );
    expect(refTagPost, 'Expected a POST /api/reference-tags call').toBeTruthy();
    expect(refTagPost.body.asset_id).toBe(1001);
    expect(refTagPost.body.tags[0]).toMatch(/^wp:.+\/post\/\d+$/);
});
