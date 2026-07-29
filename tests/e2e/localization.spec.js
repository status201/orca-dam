// UI language switching — pins specs/features/localization.md and
// specs/features/user-preferences.md (the encrypted per-user locale override).
// This spec is why every other locator in the suite uses data-testid.
import { expect, expectToast, reseed, test, testid } from './support/fixtures.js';

test.describe('localization', () => {
    test.beforeAll(reseed);

    async function setLocale(page, value) {
        await page.goto('/profile');
        await page.selectOption('#locale', value);
        await page.click(testid('preferences-save'));
        await expectToast(page, /.+/);
    }

    test.afterAll(reseed);

    test('switching to Dutch translates the navigation and sets the html lang', async ({ page }) => {
        await page.goto('/dashboard');
        await expect(page.locator(testid('nav-users'))).toHaveText(/Users/);

        await setLocale(page, 'nl');

        await page.goto('/dashboard');
        await expect(page.locator('html')).toHaveAttribute('lang', 'nl');
        await expect(page.locator(testid('nav-users'))).toHaveText(/Gebruikers/);
        await expect(page.locator(testid('nav-about'))).toHaveText(/Over ORCA/);
    });

    test('the grid still works in Dutch and testid locators are unaffected', async ({ page }) => {
        await setLocale(page, 'nl');

        await page.goto('/assets');
        await expect(page.locator(testid('asset-grid'))).toBeVisible();
        await page.fill(testid('grid-search'), 'e2e-grid-07');
        await Promise.all([
            page.waitForURL((url) => url.searchParams.get('search') === 'e2e-grid-07'),
            page.press(testid('grid-search'), 'Enter'),
        ]);

        await expect(page.locator(testid('asset-card'))).toHaveCount(1);
    });

    test('switching back to English restores the English chrome', async ({ page }) => {
        await setLocale(page, 'nl');
        await setLocale(page, 'en');

        await page.goto('/dashboard');
        await expect(page.locator('html')).toHaveAttribute('lang', 'en');
        await expect(page.locator(testid('nav-users'))).toHaveText(/Users/);
    });
});
