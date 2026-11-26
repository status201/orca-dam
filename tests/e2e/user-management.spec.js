// Admin user CRUD — pins specs/features/user-management.md.
import { expect, reseed, test, testid, users } from './support/fixtures.js';

const userRow = (page, email) =>
    page.locator(testid('user-row')).filter({ hasText: email });

test.describe('user management', () => {
    test.beforeAll(reseed);

    test('the users table lists the seeded accounts with their roles', async ({ page }) => {
        await page.goto('/users');
        await expect(page.locator(testid('users-table'))).toBeVisible();

        await expect(userRow(page, users.admin.email).locator(testid('user-row-role'))).toHaveText('Admin');
        await expect(userRow(page, users.editor.email).locator(testid('user-row-role'))).toHaveText('Editor');
        await expect(userRow(page, users.api.email).locator(testid('user-row-role'))).toHaveText('Api');
    });

    test('an admin creates a user, re-roles it and deletes it', async ({ page }) => {
        await page.goto('/users');
        await page.click(testid('users-create'));

        await page.fill('#name', 'E2E Created User');
        await page.fill('#email', 'created@e2e.test');
        await page.selectOption('#role', 'editor');
        await page.fill('#password', 'e2e-password-123');
        await page.fill('#password_confirmation', 'e2e-password-123');
        await page.click(testid('user-form-submit'));

        await expect(page.locator(testid('users-table'))).toBeVisible();
        const row = userRow(page, 'created@e2e.test');
        await expect(row.locator(testid('user-row-role'))).toHaveText('Editor');

        // Promote to admin.
        await row.locator(testid('user-row-edit')).click();
        await page.selectOption('#role', 'admin');
        await page.click(testid('user-form-submit'));
        await expect(userRow(page, 'created@e2e.test').locator(testid('user-row-role'))).toHaveText('Admin');

        // Delete (no owned assets, so no transfer target is required).
        await userRow(page, 'created@e2e.test').locator(testid('user-row-delete')).click();
        await page.click(testid('user-delete-confirm'));

        await expect(page.locator(testid('users-table'))).toBeVisible();
        await expect(userRow(page, 'created@e2e.test')).toHaveCount(0);
    });

    test('deleting a user who owns assets requires a transfer target', async ({ page }) => {
        await page.goto('/users');
        await userRow(page, users.editor.email).locator(testid('user-row-delete')).click();

        // The editor owns the seeded fixtures, so the confirm button stays disabled
        // until a transfer target is chosen.
        await expect(page.locator('#transfer_to_user_id')).toBeVisible();
        await expect(page.locator(testid('user-delete-confirm'))).toBeDisabled();

        await page.keyboard.press('Escape');
    });

    test('the current admin cannot delete their own account', async ({ page }) => {
        await page.goto('/users');

        await expect(userRow(page, users.admin.email)).toContainText('(');
        await expect(userRow(page, users.admin.email).locator(testid('user-row-delete'))).toHaveCount(0);
    });

    // Last in the file: it deletes the seeded editor (assets go to the admin).
    test('deleting a user and transferring their assets returns to the user list', async ({ page }) => {
        await page.goto('/users');
        await userRow(page, users.editor.email).locator(testid('user-row-delete')).click();
        const adminOption = page.locator('#transfer_to_user_id option', { hasText: users.admin.email });
        await page.selectOption('#transfer_to_user_id', await adminOption.getAttribute('value'));

        // Hold the DELETE in flight long enough to observe the button while it runs.
        let release;
        const held = new Promise((resolve) => { release = resolve; });
        await page.route('**/users/*', async (route) => {
            if (route.request().method() === 'POST') await held;
            await route.continue();
        });

        // Read the button from inside the page: with the request held the page is mid-navigation,
        // which Playwright's locators wait out rather than query.
        const disabledWhileInFlight = await page.evaluate(async (selector) => {
            const button = document.querySelector(selector);
            button.click();
            await new Promise((resolve) => setTimeout(resolve, 50)); // let Alpine re-render
            return button.disabled;
        }, testid('user-delete-confirm'));
        expect(disabledWhileInFlight).toBe(true);
        release();

        await expect(page).toHaveURL(/\/users$/);
        await expect(userRow(page, users.editor.email)).toHaveCount(0);
    });
});
