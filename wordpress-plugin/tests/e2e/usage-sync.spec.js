// @ts-check
const { test, expect } = require('@playwright/test');

test('publishing a post with an ORCA image POSTs a reference tag', async ({ page, request }) => {
    await request.post('/wp-json/orca-mock/v1/reset');

    // We're already logged in via the setup project's storageState (cookies live
    // in this page's context). To create a published post via WP REST we need the
    // REST nonce, which WordPress exposes as window.wpApiSettings.nonce on any
    // admin page. Skipping the block editor UI entirely avoids the Gutenberg
    // version-dependent locator dance.
    await page.goto('/wp-admin/');
    const nonce = await page.evaluate(() => window.wpApiSettings && window.wpApiSettings.nonce);
    expect(nonce, 'Could not read wpApiSettings.nonce from /wp-admin/').toBeTruthy();

    const create = await page.request.post('/wp-json/wp/v2/posts', {
        headers: {
            'X-WP-Nonce': nonce,
            'Content-Type': 'application/json',
        },
        data: {
            title: 'Usage sync test',
            status: 'publish',
            content:
                '<!-- wp:image {"id":0} -->\n' +
                '<figure class="wp-block-image"><img src="https://mock.orca.test/assets/branding/logo.png" alt="" data-orca-asset-id="1001" /></figure>\n' +
                '<!-- /wp:image -->',
        },
    });
    expect(create.status(), `WP REST create failed: ${await create.text()}`).toBe(201);

    // PostObserver enqueued an Action Scheduler async job. AS only runs when WP
    // cron fires, and WP cron only fires on incoming HTTP requests. The Playwright
    // request fixture is idle between calls, so we need to *make* a request to
    // trigger cron — hit /wp-cron.php twice to give AS a chance to dequeue and run.
    await page.request.get('/wp-cron.php?doing_wp_cron=1');
    await page.waitForTimeout(1_000);
    await page.request.get('/wp-cron.php?doing_wp_cron=1');
    await page.waitForTimeout(2_000);

    const calls = await (await request.get('/wp-json/orca-mock/v1/calls')).json();
    const refTagPost = calls.find(
        (c) => c.method === 'POST' && c.path.endsWith('/api/reference-tags')
    );
    expect(refTagPost, 'Expected a POST /api/reference-tags call').toBeTruthy();
    expect(refTagPost.body.asset_id).toBe(1001);
    expect(refTagPost.body.tags[0]).toMatch(/^wp:.+\/post\/\d+$/);
});
