// @ts-check
const { test, expect } = require('@playwright/test');

test('settings page shows configured connection and Test connection succeeds', async ({ page }) => {
    await page.goto('/wp-admin/options-general.php?page=orca-dam-picker');
    await expect(page.locator('#orca-dam-settings-root')).toBeVisible();
    await expect(page.getByLabel('ORCA base URL')).toHaveValue('https://mock.orca.test');

    await page.getByRole('button', { name: /Test connection/i }).click();
    await expect(page.getByText('Connected to ORCA.')).toBeVisible({ timeout: 10_000 });
});

test('Broken assets card renders and Run scan now is callable', async ({ page }) => {
    await page.goto('/wp-admin/options-general.php?page=orca-dam-picker');
    await expect(page.getByRole('heading', { name: 'Broken assets' })).toBeVisible();
    await page.getByRole('button', { name: /Run scan now/i }).click();
    await expect(page.getByText(/Scan queued/i)).toBeVisible();
});
