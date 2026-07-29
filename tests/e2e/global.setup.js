// Runs once, before every other project: rebuild the fixtures, then log in as
// each role through the real form and save the session.
// Contract: specs/features/e2e-testing.md ("Layer touchpoints & ordering").
import { mkdirSync } from 'node:fs';
import path from 'node:path';
import { PASSWORD, expect, test, testid, users } from './support/fixtures.js';
import { reseed } from './support/db.js';

const AUTH_DIR = path.resolve(import.meta.dirname, '.auth');

// The reseed must precede the logins: it drops the users table, which would
// orphan any session saved before it.
test('fixtures are reseeded', async () => {
    test.slow();
    mkdirSync(AUTH_DIR, { recursive: true });
    await reseed();
});

for (const role of ['admin', 'editor', 'api']) {
    test(`${role} session is saved`, async ({ page }) => {
        await page.goto('/login');
        await page.fill('#email', users[role].email);
        await page.fill('#password', PASSWORD);
        await page.click(testid('login-submit'));

        // Breeze redirects to the dashboard; every role can reach it.
        await expect(page).toHaveURL(/\/dashboard$/);
        await expect(page.locator(testid('nav-user-menu'))).toContainText(users[role].name);

        await page.context().storageState({ path: path.join(AUTH_DIR, `${role}.json`) });
    });
}
