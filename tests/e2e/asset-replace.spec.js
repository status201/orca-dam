// Replacing an asset's file in place — pins specs/features/asset-replace.md.
//
// Only the successful replace needs the bucket (S3Service::replaceFile writes the
// object and the thumbnail is regenerated from it). Everything before the upload —
// the client-side extension guard, staging, clearing, and the confirmation modal —
// runs entirely in the browser, so those tests are not gated.
import { assetCard, expect, gotoAssets, requiresS3, reseed, test, testid } from './support/fixtures.js';
import { pngFixture, uniqueColor, uniqueName } from './support/files.js';

/** Open the replace page for a seeded asset, the way a user gets there. */
async function gotoReplace(page, filename) {
    await gotoAssets(page, { search: filename.replace('.png', '') });
    await assetCard(page, filename).click();
    await page.click(testid('asset-detail-replace'));

    await expect(page.locator(testid('replace-page'))).toBeVisible();
}

test.describe('asset replace', () => {
    test.beforeAll(reseed);

    test('a file whose extension differs from the original is refused', async ({ page }) => {
        await gotoReplace(page, 'e2e-replace-02.png');

        // Real PNG bytes under a .jpg name: the guard compares extensions, and this
        // never reaches the server.
        await page.setInputFiles(testid('replace-input'), pngFixture('e2e-replace-wrong.jpg', { color: uniqueColor() }));

        await expect(page.locator(testid('replace-error'))).toBeVisible();
        await expect(page.locator(testid('replace-submit'))).toHaveCount(0);
    });

    test('a staged file can be cleared again', async ({ page }) => {
        await gotoReplace(page, 'e2e-replace-02.png');

        const name = uniqueName('e2e-replace-staged');
        await page.setInputFiles(testid('replace-input'), pngFixture(name, { color: uniqueColor() }));
        await expect(page.locator(testid('replace-selected-name'))).toHaveText(name);

        await page.click(testid('replace-clear'));

        await expect(page.locator(testid('replace-selected-name'))).toHaveCount(0);
        await expect(page.locator(testid('replace-browse'))).toBeVisible();
    });

    test('cancelling the confirmation leaves the file staged and unsent', async ({ page }) => {
        await gotoReplace(page, 'e2e-replace-02.png');

        const name = uniqueName('e2e-replace-cancel');
        await page.setInputFiles(testid('replace-input'), pngFixture(name, { color: uniqueColor() }));
        await page.click(testid('replace-submit'));

        await expect(page.locator(testid('replace-confirm'))).toBeVisible();
        await page.click(testid('replace-cancel'));

        await expect(page.locator(testid('replace-confirm'))).toBeHidden();
        // Still staged, and nothing was uploaded.
        await expect(page.locator(testid('replace-selected-name'))).toHaveText(name);
        await expect(page.locator(testid('replace-success'))).toBeHidden();
    });

    test.describe('with a bucket', () => {
        requiresS3();

        test('confirming replaces the file and returns to the edit page', async ({ page }) => {
            await gotoReplace(page, 'e2e-replace-01.png');

            await page.setInputFiles(
                testid('replace-input'),
                pngFixture(uniqueName('e2e-replace-new'), { color: uniqueColor(), width: 32, height: 32 }),
            );
            await page.click(testid('replace-submit'));
            await page.click(testid('replace-confirm'));

            // The success panel is only up for ~2s before the module redirects, so the
            // banner on the edit page is the durable signal.
            await expect(page.locator(testid('asset-edit'))).toBeVisible({ timeout: 30_000 });
            await expect(page).toHaveURL(/replaced=1/);
        });
    });
});
