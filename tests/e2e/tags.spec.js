// Tag management page — pins specs/features/tags.md.
import { acceptConfirm, expect, expectToast, reseed, test, testid } from './support/fixtures.js';

const tagCard = (page, name) => page.locator(`[data-testid="tag-card"][data-tag-name="${name}"]`);

test.describe('tags page', () => {
    test.beforeAll(reseed);

    test('the seeded tags are listed with their type badges', async ({ page }) => {
        await page.goto('/tags');
        await expect(page.locator(testid('tags-page'))).toBeVisible();

        await expect(tagCard(page, 'e2e-shared')).toBeVisible();
        await expect(tagCard(page, 'e2e-ai-tag')).toContainText('ai');
        await expect(tagCard(page, 'e2e-reference-tag')).toContainText('ref');
    });

    test('an ai tag offers no rename control', async ({ page }) => {
        await page.goto('/tags');

        await expect(tagCard(page, 'e2e-ai-tag')).toBeVisible();
        await expect(tagCard(page, 'e2e-ai-tag').locator(testid('tag-card-edit'))).toHaveCount(0);
        await expect(tagCard(page, 'e2e-shared').locator(testid('tag-card-edit'))).toHaveCount(1);
    });

    test('a tag can be renamed', async ({ page }) => {
        await page.goto('/tags');
        await tagCard(page, 'e2e-rename-me').locator(testid('tag-card-edit')).click();

        await page.fill(testid('tag-edit-name'), 'e2e-renamed');
        await page.click(testid('tag-edit-save'));
        await expectToast(page, /updated|bijgewerkt/i);

        await page.goto('/tags');
        await expect(tagCard(page, 'e2e-renamed')).toBeVisible();
        await expect(tagCard(page, 'e2e-rename-me')).toHaveCount(0);
    });

    test('a tag can be deleted', async ({ page }) => {
        await page.goto('/tags');
        await expect(tagCard(page, 'e2e-delete-me')).toBeVisible();

        acceptConfirm(page);
        await tagCard(page, 'e2e-delete-me').locator(testid('tag-card-delete')).click();
        await expectToast(page, /deleted|verwijderd/i);

        await page.goto('/tags');
        await expect(tagCard(page, 'e2e-delete-me')).toHaveCount(0);
    });

    test('a tag card links into the filtered asset library', async ({ page }) => {
        await page.goto('/tags');
        await tagCard(page, 'e2e-shared').locator(testid('tag-card-name')).click();

        await expect(page.locator(testid('asset-grid'))).toBeVisible();
        await expect(page).toHaveURL(/tags(\[\]|%5B%5D)=/);
        await expect(page.locator(testid('asset-card'))).toHaveCount(2);
    });
});
