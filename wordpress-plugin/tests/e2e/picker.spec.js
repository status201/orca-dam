// @ts-check
const { test, expect } = require('@playwright/test');

test('ORCA tab appears in media modal and inserts an image', async ({ page, request }) => {
    // Reset mock call log so we can assert reference-tag POSTs cleanly later.
    await request.post('/wp-json/orca-mock/v1/reset');

    await page.goto('/wp-admin/post-new.php');

    // Dismiss any first-time editor modal.
    const welcomeClose = page.locator('button[aria-label="Close"]').first();
    if (await welcomeClose.isVisible().catch(() => false)) {
        await welcomeClose.click();
    }

    // WP 6.x renders the editor canvas inside an iframe at desktop viewports —
    // the title, block content, etc. live inside it.
    const canvas = page.frameLocator('iframe[name="editor-canvas"]');

    // Title
    await canvas
        .locator('h1.editor-post-title__input, [aria-label="Add title"]')
        .first()
        .fill('ORCA picker E2E');

    // Insert an Image block.
    await page.keyboard.press('Enter');
    await page.locator('[aria-label="Add block"], button[aria-label="Add default block"]').first().click().catch(() => {});
    const inserter = page.locator('button[aria-label="Toggle block inserter"], button[aria-label="Block Inserter"]').first();
    if (await inserter.isVisible().catch(() => false)) {
        await inserter.click();
        await page.locator('input[placeholder="Search"]').first().fill('Image');
        await page.locator('button[aria-label="Image"]').first().click();
    }

    // Open the media library from inside the Image block (button lives in the canvas iframe).
    await canvas.getByRole('button', { name: /Media Library/i }).first().click();

    // The custom router tab.
    await page.getByRole('tab', { name: 'ORCA DAM' }).click();

    // First fixture asset.
    await expect(page.getByTitle('logo.png')).toBeVisible({ timeout: 10_000 });
    await page.getByTitle('logo.png').click();

    // Confirm an <img> with the mock URL ended up in the post content (inside the canvas iframe).
    await expect(
        canvas.locator('img[src*="mock.orca.test/assets/branding/logo"]')
    ).toBeVisible();
});
