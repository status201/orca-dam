// Session login/logout — pins specs/features/authentication.md.
import { PASSWORD, expect, test, testid, users } from './support/fixtures.js';

test.describe('authentication', () => {
    // These run without a saved session.
    test.use({ storageState: { cookies: [], origins: [] } });

    test('an editor logs in through the real form and reaches the asset library', async ({ page }) => {
        await page.goto('/login');
        await page.fill('#email', users.editor.email);
        await page.fill('#password', PASSWORD);
        await page.click(testid('login-submit'));

        await expect(page).toHaveURL(/\/dashboard$/);
        await expect(page.locator(testid('dashboard'))).toBeVisible();
        await expect(page.locator(testid('nav-user-menu'))).toContainText(users.editor.name);

        await page.click(testid('nav-assets'));
        await expect(page.locator(testid('asset-grid'))).toBeVisible();
    });

    test('a wrong password keeps the user on the login page with an error', async ({ page }) => {
        await page.goto('/login');
        await page.fill('#email', users.editor.email);
        await page.fill('#password', 'not-the-password');
        await page.click(testid('login-submit'));

        await expect(page).toHaveURL(/\/login$/);
        await expect(page.locator('form')).toContainText(/credentials do not match|inloggegevens/i);
        await expect(page.locator(testid('nav-user-menu'))).toHaveCount(0);
    });

    test('an unauthenticated visitor is redirected to login', async ({ page }) => {
        await page.goto('/assets');

        await expect(page).toHaveURL(/\/login$/);
        await expect(page.locator('#email')).toBeVisible();
    });
});

test.describe('logout', () => {
    // Deliberately NOT the shared admin/editor state: logging out invalidates the
    // session *server-side*, which would break every other spec's saved
    // storageState. This test logs in as the spare account and ends that session.
    test.use({ storageState: { cookies: [], origins: [] } });

    test('logging out ends the session', async ({ page }) => {
        await page.goto('/login');
        await page.fill('#email', users.spare.email);
        await page.fill('#password', PASSWORD);
        await page.click(testid('login-submit'));
        await expect(page).toHaveURL(/\/dashboard$/);

        await page.click(testid('nav-user-menu'));
        await page.click(testid('nav-logout'));

        await expect(page).toHaveURL(/\/login$/);

        // The session is really gone, not just navigated away from.
        await page.goto('/assets');
        await expect(page).toHaveURL(/\/login$/);
    });
});
