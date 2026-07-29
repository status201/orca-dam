// Soft-delete lifecycle through the UI — pins specs/features/asset-trash.md.
import {
    acceptConfirm,
    assetCard,
    expect,
    gotoAssets,
    gotoTrash,
    reseed,
    test,
    testid,
    useTrashViewMode,
    useViewMode,
} from './support/fixtures.js';

const trashRow = (page, filename) =>
    page.locator(testid('trash-row')).filter({ hasText: filename });

/**
 * Trash one asset from its row in the list view. Waits on the DELETE response
 * rather than the toast: the row's handler reloads the page ~1s later, which
 * makes any toast assertion a race.
 */
async function trashFromList(page, filename) {
    await gotoAssets(page, { search: filename.replace('.png', '') });
    await useViewMode(page, 'list');

    const row = page.locator(testid('asset-row')).first();
    await expect(row).toContainText(filename);

    acceptConfirm(page);
    const [response] = await Promise.all([
        page.waitForResponse((r) => r.request().method() === 'DELETE' && /\/assets\/\d+$/.test(r.url())),
        row.locator(testid('asset-row-delete')).click(),
    ]);
    expect(response.ok()).toBeTruthy();
}

/** Assert an asset is browsable in the library (in grid view, whatever the last mode was). */
async function expectInLibrary(page, filename) {
    await gotoAssets(page, { search: filename.replace('.png', '') });
    await useViewMode(page, 'grid');
    await expect(assetCard(page, filename)).toBeVisible();
}

test.describe('trash lifecycle', () => {
    test.beforeAll(reseed);

    test('deleting an asset from the list view moves it to trash, and restoring brings it back', async ({ page }) => {
        await trashFromList(page, 'e2e-trash-01.png');

        await gotoAssets(page, { search: 'e2e-trash-01' });
        await expect(page.locator(testid('asset-grid-empty'))).toBeVisible();

        await gotoTrash(page);
        await useTrashViewMode(page, 'list');
        await expect(trashRow(page, 'e2e-trash-01.png')).toBeVisible();

        acceptConfirm(page);
        await trashRow(page, 'e2e-trash-01.png').locator(testid('trash-row-restore')).click();

        // The restore posts a form, so the trash page reloads without the row.
        await expect(page.locator(testid('trash-page'))).toBeVisible();
        await expect(trashRow(page, 'e2e-trash-01.png')).toHaveCount(0);

        await expectInLibrary(page, 'e2e-trash-01.png');
    });

    test('the seeded soft-deleted asset is listed in trash and not in the library', async ({ page }) => {
        await gotoAssets(page, { search: 'e2e-trash-04' });
        await expect(page.locator(testid('asset-grid-empty'))).toBeVisible();

        await gotoTrash(page);
        await useTrashViewMode(page, 'list');
        await expect(trashRow(page, 'e2e-trash-04.png')).toBeVisible();
    });

    test('an admin permanently deletes a trashed asset', async ({ page }) => {
        // Trash it first, so this test consumes a fixture of its own.
        await trashFromList(page, 'e2e-trash-02.png');

        await gotoTrash(page);
        await useTrashViewMode(page, 'list');
        await expect(trashRow(page, 'e2e-trash-02.png')).toBeVisible();

        acceptConfirm(page);
        await trashRow(page, 'e2e-trash-02.png').locator(testid('trash-row-force-delete')).click();

        await expect(page.locator(testid('trash-page'))).toBeVisible();
        await expect(trashRow(page, 'e2e-trash-02.png')).toHaveCount(0);

        // Gone for good: not in trash, not in the library.
        await gotoAssets(page, { search: 'e2e-trash-02' });
        await expect(page.locator(testid('asset-grid-empty'))).toBeVisible();
    });

    test('bulk restore reports what it restored and puts the assets back', async ({ page }) => {
        await trashFromList(page, 'e2e-trash-03.png');

        await gotoTrash(page);
        await useTrashViewMode(page, 'list');
        await trashRow(page, 'e2e-trash-03.png').locator(testid('trash-row-checkbox')).click();
        await expect(page.locator(testid('trash-bulk-restore'))).toBeVisible();

        acceptConfirm(page);
        await page.click(testid('trash-bulk-restore'));

        // The bulk endpoint answers with a filename summary, which gates the reload.
        await expect(page.locator(testid('trash-restore-summary'))).toBeVisible();
        await expect(page.locator(testid('trash-restore-summary-list'))).toHaveValue(/e2e-trash-03\.png/);
        await page.click(testid('trash-restore-summary-done'));

        await expect(page.locator(testid('trash-page'))).toBeVisible();
        await expect(trashRow(page, 'e2e-trash-03.png')).toHaveCount(0);

        await expectInLibrary(page, 'e2e-trash-03.png');
    });
});
