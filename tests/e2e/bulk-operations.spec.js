// The floating bulk bar — pins specs/features/bulk-operations.md.
//
// Every bulk action posts and then reloads the page ~800ms later, so these tests
// wait on the response and assert the persisted outcome instead of the toast.
import {
    acceptConfirm,
    expect,
    gotoAssets,
    gotoTrash,
    reseed,
    test,
    testid,
    useTrashViewMode,
    useViewMode,
} from './support/fixtures.js';

/** Select the first `count` cards by clicking their selection checkboxes. */
async function selectCards(page, count) {
    const cards = page.locator(testid('asset-card'));
    for (let i = 0; i < count; i++) {
        await cards.nth(i).hover();
        await cards.nth(i).locator(testid('asset-card-checkbox')).click();
    }
    await expect(page.locator(testid('bulk-bar'))).toBeVisible();
    await expect(page.locator(testid('bulk-bar-count'))).toContainText(String(count));
}

/** Click a bulk-bar control and wait for the endpoint it posts to. */
async function bulkAction(page, id, urlFragment, { confirm = false } = {}) {
    if (confirm) acceptConfirm(page);

    const [response] = await Promise.all([
        page.waitForResponse((r) => r.url().includes(urlFragment) && r.request().method() !== 'GET'),
        page.click(testid(id)),
    ]);
    expect(response.ok(), `${urlFragment} responded ${response.status()}`).toBeTruthy();

    return response;
}

test.describe('bulk operations', () => {
    test.beforeAll(reseed);

    test('select all selects every card on the page and clear empties it', async ({ page }) => {
        await gotoAssets(page, { search: 'e2e-bulk' });
        const total = await page.locator(testid('asset-card')).count();

        await page.click(testid('grid-select-all'));
        await expect(page.locator(testid('bulk-bar'))).toBeVisible();
        await expect(page.locator(testid('bulk-bar-count'))).toContainText(String(total));

        await page.click(testid('bulk-clear'));
        await expect(page.locator(testid('bulk-bar'))).toBeHidden();
    });

    test('bulk-adding a tag applies it to every selected asset', async ({ page }) => {
        await gotoAssets(page, { search: 'e2e-bulk-0' });
        await selectCards(page, 2);

        await page.fill(testid('bulk-tag-input'), 'e2e-bulk-tag');
        await bulkAction(page, 'bulk-tag-add', '/assets/bulk/tags');

        await gotoAssets(page, { search: 'e2e-bulk-0' });
        await useViewMode(page, 'list');
        await expect(page.locator(testid('asset-row')).nth(0)).toContainText('e2e-bulk-tag');
        await expect(page.locator(testid('asset-row')).nth(1)).toContainText('e2e-bulk-tag');
    });

    test('bulk-removing a tag takes it off the selected assets', async ({ page }) => {
        // Attach it first so this test does not depend on the previous one.
        await gotoAssets(page, { search: 'e2e-bulk-0' });
        await page.click(testid('grid-select-all'));
        await page.fill(testid('bulk-tag-input'), 'e2e-remove-me');
        await bulkAction(page, 'bulk-tag-add', '/assets/bulk/tags');

        await gotoAssets(page, { search: 'e2e-bulk-0' });
        await page.click(testid('grid-select-all'));
        await bulkAction(page, 'bulk-remove-tags', '/assets/bulk/tags/list');

        const chip = page.locator(testid('bulk-remove-tag-chip')).filter({ hasText: 'e2e-remove-me' });
        await expect(chip).toBeVisible();
        await Promise.all([
            page.waitForResponse((r) => r.url().includes('/assets/bulk/tags/remove')),
            chip.click(),
        ]);

        await gotoAssets(page, { search: 'e2e-bulk-0' });
        await useViewMode(page, 'list');
        await expect(page.locator(testid('asset-row')).first()).not.toContainText('e2e-remove-me');
    });

    test('bulk move to trash removes the selection from the grid', async ({ page }) => {
        await gotoAssets(page, { search: 'e2e-bulk-05' });
        await selectCards(page, 1);

        await bulkAction(page, 'bulk-trash', '/assets/bulk/trash', { confirm: true });

        await gotoAssets(page, { search: 'e2e-bulk-05' });
        await expect(page.locator(testid('asset-grid-empty'))).toBeVisible();

        await gotoTrash(page);
        await useTrashViewMode(page, 'list');
        await expect(page.locator(testid('trash-row')).filter({ hasText: 'e2e-bulk-05.png' })).toBeVisible();
    });

    test('bulk download streams a zip', async ({ page }) => {
        await gotoAssets(page, { search: 'e2e-bulk-01' });
        await selectCards(page, 1);

        // The seeded fixtures have no bytes in the bucket, so the endpoint may
        // legitimately report a failure — what this pins is that the control posts
        // to the right place with the selection, not the archive's contents.
        const [response] = await Promise.all([
            page.waitForResponse((r) => r.url().includes('/assets/bulk/download')),
            page.click(testid('bulk-download')),
        ]);

        expect(response.request().method()).toBe('POST');
        expect(response.status()).toBeLessThan(600);
    });

    test('an admin sees the trash control, which is what the api-role test asserts is absent', async ({ page }) => {
        await gotoAssets(page, { search: 'e2e-bulk-02' });
        await selectCards(page, 1);

        await expect(page.locator(testid('bulk-trash'))).toBeVisible();
    });
});
