// /system dashboard + runtime settings — pins specs/features/system-admin.md and
// specs/features/settings.md (a setting changed in the UI is honoured by the app).
import { expect, gotoAssets, reseed, test, testid } from './support/fixtures.js';
import { settingValue } from './support/db.js';

test.describe('system administration', () => {
    test.beforeAll(reseed);

    test('the system page renders for an admin', async ({ page }) => {
        await page.goto('/system');

        await expect(page.locator(testid('system-setting-items-per-page'))).toBeVisible();
        await expect(page.locator(testid('system-setting-maintenance-mode'))).toBeAttached();
    });

    test('changing items per page persists and the grid honours it', async ({ page }) => {
        await page.goto('/system');
        await page.selectOption(testid('system-setting-items-per-page'), '12');

        // The select posts on change; wait for the persisted value.
        await expect
            .poll(() => settingValue('items_per_page'), { timeout: 15_000 })
            .toBe('12');

        await gotoAssets(page);
        expect(await page.locator(testid('asset-card')).count()).toBeLessThanOrEqual(12);

        // Restore, so a later spec in this run isn't surprised by a 12-item page.
        await page.goto('/system');
        await page.selectOption(testid('system-setting-items-per-page'), '24');
        await expect.poll(() => settingValue('items_per_page'), { timeout: 15_000 }).toBe('24');
    });

    test('the queue status endpoint answers', async ({ page }) => {
        const response = await page.request.get('/system/queue-status');

        expect(response.ok()).toBeTruthy();
        expect(await response.json()).toHaveProperty('stats');
    });

    test('the log viewer answers', async ({ page }) => {
        const response = await page.request.get('/system/logs');

        expect(response.ok()).toBeTruthy();
    });
});
