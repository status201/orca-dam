// /api-docs admin dashboard — pins specs/features/api-docs-admin.md and the token
// half of specs/features/api-tokens-sanctum.md.
import { acceptConfirm, expect, expectToast, reseed, test, testid, users } from './support/fixtures.js';

/**
 * The user dropdown is populated by Alpine, and its option values are database
 * ids, so resolve the value from the option text rather than guessing.
 */
async function selectUser(page, email) {
    const option = page.locator(`${testid('api-token-user')} option`).filter({ hasText: email });
    await expect(option).toHaveCount(1);
    await page.selectOption(testid('api-token-user'), await option.getAttribute('value'));
}

test.describe('api docs and token management', () => {
    test.beforeAll(reseed);

    test('an admin issues a token and then revokes it', async ({ page }) => {
        await page.goto('/api-docs');
        await expect(page.locator(testid('api-docs-page'))).toBeVisible();

        await page.click(testid('api-tab-tokens'));
        await page.fill(testid('api-token-name'), 'e2e-browser-token');
        await selectUser(page, users.api.email);
        await page.click(testid('api-token-create'));

        // The plaintext is shown exactly once.
        await expect(page.locator(testid('api-token-created'))).toBeVisible();
        await expect(page.locator(testid('api-token-created'))).toContainText('|');

        await page.reload();
        await page.click(testid('api-tab-tokens'));
        const row = page.locator('tr').filter({ hasText: 'e2e-browser-token' });
        await expect(row).toBeVisible();

        acceptConfirm(page);
        await row.locator(testid('api-token-revoke')).click();
        await expectToast(page, /revoked|ingetrokken/i);
        await expect(page.locator('tr').filter({ hasText: 'e2e-browser-token' })).toHaveCount(0);
    });

    test('a freshly created token authenticates against the REST API', async ({ page, playwright, baseURL }) => {
        await page.goto('/api-docs');
        await page.click(testid('api-tab-tokens'));
        await page.fill(testid('api-token-name'), 'e2e-usable-token');
        await selectUser(page, users.api.email);
        await page.click(testid('api-token-create'));

        // Wait for Alpine to fill the <code> before reading it, and read
        // textContent — innerText comes back empty for this `break-all` element.
        const code = page.locator(testid('api-token-created')).locator('code').first();
        await expect(code).toContainText('|');
        const plaintext = (await code.textContent()).trim();
        expect(plaintext).toMatch(/^\d+\|/);

        const api = await playwright.request.newContext({
            baseURL,
            extraHTTPHeaders: { Authorization: `Bearer ${plaintext}`, Accept: 'application/json' },
        });
        const response = await api.get('/api/assets');

        expect(response.ok()).toBeTruthy();
        expect(await response.json()).toHaveProperty('data');
        await api.dispose();
    });

    test('the jwt tab generates a secret for a user', async ({ page }) => {
        await page.goto('/api-docs');
        await page.click(testid('api-tab-jwt'));

        // The list only holds users that already have a secret, so generate one.
        const option = page.locator(`${testid('api-jwt-user')} option`).filter({ hasText: users.api.email });
        await expect(option).toHaveCount(1);
        await page.selectOption(testid('api-jwt-user'), await option.getAttribute('value'));
        await page.click(testid('api-jwt-generate'));

        // The panel also contains a usage example in a second <code> block.
        const secret = page.locator(testid('api-jwt-created')).locator('code').first();
        await expect(secret).toBeVisible();
        expect((await secret.textContent()).trim().length).toBeGreaterThan(20);

        await page.reload();
        await page.click(testid('api-tab-jwt'));
        await expect(page.locator('tr').filter({ hasText: users.api.email }).first()).toBeVisible();
    });

    test('the public health endpoint needs no authentication', async ({ page }) => {
        const response = await page.request.get('/api/health');

        expect(response.ok()).toBeTruthy();
    });
});
