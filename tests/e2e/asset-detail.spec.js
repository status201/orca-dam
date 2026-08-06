// Asset detail + edit form — pins specs/features/asset-model.md (editable fields)
// and specs/features/tag-input.md (the edit-page tag input).
import { assetCard, expect, gotoAssets, reseed, test, testid, useViewMode } from './support/fixtures.js';

test.describe('asset detail and editing', () => {
    test.beforeAll(reseed);

    test('a card opens the detail page for that asset', async ({ page }) => {
        await gotoAssets(page, { search: 'e2e-detail-alpha' });
        await assetCard(page, 'e2e-detail-alpha.png').click();

        await expect(page.locator(testid('asset-detail'))).toBeVisible();
        await expect(page.locator(testid('asset-detail-filename'))).toHaveText('e2e-detail-alpha.png');
        // Seeded metadata is rendered.
        await expect(page.locator(testid('asset-detail'))).toContainText('Seeded alt text');
    });

    test('editing an asset persists the filename and alt text', async ({ page }) => {
        await gotoAssets(page, { search: 'e2e-detail-beta' });
        await assetCard(page, 'e2e-detail-beta.png').click();
        await page.click(testid('asset-detail-edit'));

        await expect(page.locator(testid('asset-edit'))).toBeVisible();
        await page.fill('#filename', 'e2e-detail-beta-renamed.png');
        await page.fill('#alt_text', 'Alt text from the browser suite');
        await page.click(testid('asset-edit-save'));

        await expect(page.locator(testid('asset-detail-filename'))).toHaveText('e2e-detail-beta-renamed.png');
        await expect(page.locator(testid('asset-detail'))).toContainText('Alt text from the browser suite');
    });

    test('a copyright at the full documented length saves and displays in full', async ({ page }) => {
        // The reported bug's journey end to end: the form promises 500 characters via maxlength,
        // so a 500-character value must survive the round trip rather than 500'ing at the driver.
        // Note database/e2e.sqlite is as blind to varchar length as the unit DB — this proves the
        // promise, it cannot prove the column is wide enough. That is what the rule↔column audit
        // in tests/Feature/ValidationLimitsTest.php is for. See specs/features/input-validation.md.
        const copyright = `© ORCA e2e ${'abcdefghij'.repeat(50)}`.slice(0, 500);

        await gotoAssets(page, { search: 'e2e-detail-gamma' });
        await assetCard(page, 'e2e-detail-gamma.png').click();
        await page.click(testid('asset-detail-edit'));

        await page.fill('#copyright', copyright);
        // The counter makes the limit visible instead of letting maxlength truncate in silence.
        await expect(page.locator(testid('char-counter-copyright'))).toContainText('500 / 500');

        await page.click(testid('asset-edit-save'));

        await expect(page.locator(testid('asset-detail'))).toContainText(copyright);
    });

    test('a tag added on the edit page ends up on the asset', async ({ page }) => {
        await gotoAssets(page, { search: 'e2e-detail-alpha' });
        await assetCard(page, 'e2e-detail-alpha.png').click();
        await page.click(testid('asset-detail-edit'));

        await page.fill(testid('asset-edit-tag-input'), 'e2e-browser-tag');
        await page.click(testid('asset-edit-tag-add'));
        // The chip is staged in a hidden input before the form is submitted.
        await expect(page.locator('input[name="tags[]"][value="e2e-browser-tag"]')).toHaveCount(1);

        await page.click(testid('asset-edit-save'));

        await expect(page.locator(testid('asset-detail'))).toContainText('e2e-browser-tag');
    });

    test('two comma-separated tags are split into two chips', async ({ page }) => {
        await gotoAssets(page, { search: 'e2e-detail-alpha' });
        await assetCard(page, 'e2e-detail-alpha.png').click();
        await page.click(testid('asset-detail-edit'));

        await page.fill(testid('asset-edit-tag-input'), 'e2e-comma-one,e2e-comma-two');
        await page.press(testid('asset-edit-tag-input'), 'Enter');

        await expect(page.locator('input[name="tags[]"][value="e2e-comma-one"]')).toHaveCount(1);
        await expect(page.locator('input[name="tags[]"][value="e2e-comma-two"]')).toHaveCount(1);
    });

    test('a tag can be added and removed inline from the list view', async ({ page }) => {
        await gotoAssets(page, { search: 'e2e-grid-03' });
        await useViewMode(page, 'list');

        const row = page.locator(testid('asset-row')).first();
        await row.locator(testid('asset-row-tag-add')).click();
        await row.locator(testid('asset-row-tag-input')).fill('e2e-inline-tag');
        await row.locator(testid('asset-row-tag-input')).press('Enter');

        await expect(row).toContainText('e2e-inline-tag');

        // And it survives a reload — the row posted it, it isn't just local state.
        await gotoAssets(page, { search: 'e2e-grid-03' });
        await useViewMode(page, 'list');
        await expect(page.locator(testid('asset-row')).first()).toContainText('e2e-inline-tag');
    });
});
