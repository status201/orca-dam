// Passkey management on the profile page — pins the management half of
// specs/features/passkeys.md.
//
// What is NOT here: registration and passkey sign-in. Both need a CDP virtual
// authenticator, which the harness does not set up, and clicking "Add Passkey"
// without one hangs until the platform gives up. That gap is recorded in
// e2e-testing.md's open questions.
//
// Everything below is reachable without a ceremony: the list is server-rendered,
// rename is a PATCH form with an Alpine-toggled inline editor, and remove is a
// DELETE form behind a window.confirm(). The credentials are seeded (with an empty
// credential payload — enough for listing, renaming and deleting, since nothing
// short of signing in reads it).
import { acceptConfirm, asEditor, expect, reseed, test, testid } from './support/fixtures.js';

const row = (page, name) => page.locator(`${testid('passkey-row')}[data-passkey-name="${name}"]`);

test.describe('passkeys with none registered', () => {
    // The admin account is deliberately left without a passkey.
    test('the profile page reports an empty list and offers to add one', async ({ page }) => {
        await page.goto('/profile');

        await expect(page.locator(testid('passkeys'))).toBeVisible();
        await expect(page.locator(testid('passkeys-empty'))).toBeVisible();
        await expect(page.locator(testid('passkeys-list'))).toHaveCount(0);

        // Chromium supports WebAuthn, so the unsupported-browser warning must not
        // appear — this is the cheap check that the module hydrated at all.
        await expect(page.locator(testid('passkeys-unsupported'))).toHaveCount(0);
        await expect(page.locator(testid('passkey-add'))).toBeEnabled();
    });
});

test.describe('passkeys with credentials registered', () => {
    // The seeded passkeys belong to the editor.
    test.use({ storageState: asEditor });
    test.beforeAll(reseed);

    test('every registered passkey is listed', async ({ page }) => {
        await page.goto('/profile');

        await expect(page.locator(testid('passkey-row'))).toHaveCount(2);
        await expect(row(page, 'e2e-passkey-rename')).toBeVisible();
        await expect(row(page, 'e2e-passkey-delete')).toBeVisible();
    });

    test('the rename editor can be opened and cancelled without changing anything', async ({ page }) => {
        await page.goto('/profile');
        const target = row(page, 'e2e-passkey-rename');

        await target.locator(testid('passkey-rename')).click();
        await expect(target.locator(testid('passkey-name-input'))).toBeVisible();

        await target.locator(testid('passkey-name-input')).fill('typed-then-abandoned');
        await target.locator(testid('passkey-cancel')).click();

        await expect(target.locator(testid('passkey-name-input'))).toHaveCount(0);
        await expect(row(page, 'e2e-passkey-rename')).toBeVisible();
    });

    test('a passkey can be renamed', async ({ page }) => {
        await page.goto('/profile');
        const target = row(page, 'e2e-passkey-rename');

        await target.locator(testid('passkey-rename')).click();
        await target.locator(testid('passkey-name-input')).fill('e2e-passkey-renamed');
        await target.locator(testid('passkey-save')).click();

        await expect(page.locator(testid('passkeys-status'))).toBeVisible();
        await expect(row(page, 'e2e-passkey-renamed')).toBeVisible();
        await expect(row(page, 'e2e-passkey-rename')).toHaveCount(0);
    });

    test('a passkey can be removed after confirming', async ({ page }) => {
        await page.goto('/profile');

        acceptConfirm(page);
        await row(page, 'e2e-passkey-delete').locator(testid('passkey-remove')).click();

        await expect(page.locator(testid('passkeys-status'))).toBeVisible();
        await expect(row(page, 'e2e-passkey-delete')).toHaveCount(0);
    });
});
