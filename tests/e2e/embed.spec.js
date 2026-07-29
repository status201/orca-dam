// The iframe-friendly asset browser — pins specs/features/iframe-embedding.md.
import { expect, test, testid } from './support/fixtures.js';

test.describe('embed view', () => {
    test('the embed view renders the grid without the app chrome', async ({ page }) => {
        await page.goto('/assets/embed');

        await expect(page.locator(testid('embed-page'))).toBeVisible();
        await expect(page.locator(testid('asset-grid'))).toBeVisible();
        await expect(page.locator(testid('asset-card')).first()).toBeVisible();

        // No application navigation inside an embed.
        await expect(page.locator(testid('app-nav'))).toHaveCount(0);
    });

    test('the embed response relaxes X-Frame-Options into a frame-ancestors CSP', async ({ page }) => {
        const response = await page.request.get('/assets/embed');

        expect(response.ok()).toBeTruthy();
        const headers = response.headers();
        // E2eSeeder seeds embed_allowed_domains, so the middleware swaps XFO for CSP.
        expect(headers['content-security-policy']).toContain('frame-ancestors');
        expect(headers['x-frame-options']).toBeUndefined();
    });

    test('search works inside the embed and keeps the embed route', async ({ page }) => {
        await page.goto('/assets/embed');
        await page.fill(testid('grid-search'), 'e2e-grid-05');
        await Promise.all([
            page.waitForURL(/assets\/embed/),
            page.press(testid('grid-search'), 'Enter'),
        ]);

        await expect(page.locator(testid('asset-card'))).toHaveCount(1);
        await expect(page.locator(testid('app-nav'))).toHaveCount(0);
    });

    test('a non-image asset type filter works inside the embed', async ({ page }) => {
        await page.goto('/assets/embed?type=document');

        await expect(page.locator(testid('asset-card-filename'))).toHaveText('e2e-doc-01.pdf');
    });
});
