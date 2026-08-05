// Grid search / filters / sort / view modes — pins specs/features/asset-search.md
// and the assetGrid Alpine module. Read-only: no reseed needed.
import { assetCard, expect, gotoAssets, test, testid, useViewMode } from './support/fixtures.js';

async function search(page, term) {
    await page.fill(testid('grid-search'), term);
    await Promise.all([
        page.waitForURL((url) => url.searchParams.get('search') === term),
        page.press(testid('grid-search'), 'Enter'),
    ]);
    await expect(page.locator(testid('asset-grid'))).toBeVisible();
}

test.describe('asset grid', () => {
    test('the library lists the seeded assets with a total count', async ({ page }) => {
        await gotoAssets(page);

        await expect(page.locator(testid('grid-total'))).toHaveText(/\d+/);
        await expect(page.locator(testid('asset-card')).first()).toBeVisible();
        // Default sort is newest-first, so the highest-numbered fixture is on page 1.
        await expect(assetCard(page, 'e2e-grid-14.png')).toBeVisible();
    });

    // v1.6.0 printed the tail of grid-cards.blade.php's own raw PHP block above the grid.
    // The Feature suite asserts on the rendered HTML; this asserts on what a browser shows,
    // which is the layer the bug was reported from. The grid must be visible first, or an
    // empty result set would satisfy this vacuously — the partial renders only when the
    // result set is non-empty.
    test('the grid renders no leaked Blade source', async ({ page }) => {
        await gotoAssets(page);

        await expect(page.locator(testid('asset-card')).first()).toBeVisible();
        await expect(page.locator('body')).not.toContainText('endphp');
        await expect(page.locator('body')).not.toContainText('$showSuffix');
    });

    test('searching narrows the grid to matching filenames', async ({ page }) => {
        await gotoAssets(page);
        await search(page, 'e2e-grid-01');

        await expect(page.locator(testid('asset-card'))).toHaveCount(1);
        await expect(page.locator(testid('asset-card-filename'))).toHaveText('e2e-grid-01.png');
        await expect(page.locator(testid('grid-active-filters'))).toBeVisible();
    });

    test('a search with no matches shows the empty state', async ({ page }) => {
        await gotoAssets(page);
        await search(page, 'no-such-asset-anywhere');

        await expect(page.locator(testid('asset-grid-empty'))).toBeVisible();
        await expect(page.locator(testid('asset-card'))).toHaveCount(0);
    });

    test('clear all filters returns the full library', async ({ page }) => {
        await gotoAssets(page, { search: 'e2e-grid-01' });
        await expect(page.locator(testid('asset-card'))).toHaveCount(1);

        await Promise.all([
            page.waitForURL((url) => !url.searchParams.has('search')),
            page.click(testid('grid-clear-filters')),
        ]);

        expect(await page.locator(testid('asset-card')).count()).toBeGreaterThan(1);
    });

    test('the type filter selects documents only', async ({ page }) => {
        await gotoAssets(page);

        await Promise.all([
            page.waitForURL((url) => url.searchParams.get('type') === 'document'),
            page.selectOption(testid('grid-filter-type'), 'document'),
        ]);

        await expect(page.locator(testid('asset-card'))).toHaveCount(1);
        await expect(page.locator(testid('asset-card-filename'))).toHaveText('e2e-doc-01.pdf');
    });

    test('the type filter selects videos only', async ({ page }) => {
        await gotoAssets(page, { type: 'video' });

        await expect(page.locator(testid('asset-card'))).toHaveCount(1);
        await expect(page.locator(testid('asset-card-filename'))).toHaveText('e2e-video-01.mp4');
    });

    test('sorting by name puts the alphabetically first asset first', async ({ page }) => {
        await gotoAssets(page, { search: 'e2e-grid' });

        await Promise.all([
            page.waitForURL((url) => url.searchParams.get('sort') === 'name_asc'),
            page.selectOption(testid('grid-sort'), 'name_asc'),
        ]);
        await expect(page.locator(testid('asset-card-filename')).first()).toHaveText('e2e-grid-01.png');

        await Promise.all([
            page.waitForURL((url) => url.searchParams.get('sort') === 'name_desc'),
            page.selectOption(testid('grid-sort'), 'name_desc'),
        ]);
        await expect(page.locator(testid('asset-card-filename')).first()).toHaveText('e2e-grid-14.png');
    });

    test('the three view modes each render and the choice is remembered', async ({ page }) => {
        await gotoAssets(page);
        await expect(page.locator(testid('asset-grid-view'))).toBeVisible();

        await useViewMode(page, 'masonry');
        await expect(page.locator(testid('asset-masonry-card')).first()).toBeVisible();

        await useViewMode(page, 'list');
        await expect(page.locator(testid('asset-row')).first()).toBeVisible();

        // localStorage-backed: still list view after a reload.
        await gotoAssets(page);
        await expect(page.locator(testid('asset-list-view'))).toBeVisible();

        await useViewMode(page, 'grid');
    });

    test('the tag filter panel lists the seeded tags and filters by one', async ({ page }) => {
        await gotoAssets(page);
        await page.click(testid('grid-filter-tags'));
        await expect(page.locator(testid('grid-tag-filter-panel'))).toBeVisible();

        const tag = page.locator(testid('grid-tag-filter-panel')).getByText('e2e-shared', { exact: true });
        await expect(tag).toBeVisible();
        await tag.click();

        await Promise.all([
            page.waitForURL((url) => url.searchParams.has('tags[]')),
            page.locator(testid('grid-tag-filter-panel')).getByRole('button', { name: /apply|toepassen/i }).click(),
        ]);

        // e2e-grid-01 and -02 carry the shared tag.
        await expect(page.locator(testid('asset-card'))).toHaveCount(2);
    });

    test('pagination splits the library across pages', async ({ page }) => {
        await gotoAssets(page, { per_page: '12', search: 'e2e-grid' });

        await expect(page.locator(testid('asset-card'))).toHaveCount(12);
        await page.getByRole('link', { name: '2' }).first().click();
        await expect(page.locator(testid('asset-grid'))).toBeVisible();
        await expect(page.locator(testid('asset-card'))).toHaveCount(2);
    });
});
