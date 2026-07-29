// S3 discovery — pins the browser half of specs/features/discovery-import.md.
//
// The whole file needs the bucket, and the guard is not optional here:
// S3Service::listObjects swallows its exceptions and returns [], so without MinIO
// a scan reports zero unmapped objects and any assertion about scan results would
// pass for entirely the wrong reason.
//
// Making a genuinely orphaned object is the awkward part. The bucket is anonymous
// read-only, so the test cannot PUT directly, and every delete path in the app
// removes the object along with the row. So: upload through the UI, then drop only
// the database row with tinker(), which leaves the object behind exactly as a
// bucket populated outside ORCA would.
//
// Note what is *not* asserted: that a scan finds nothing. The bucket outlives the
// database — every reseed orphans whatever earlier spec files uploaded — so "all
// clear" is not a state this suite can arrange. Assertions here are scoped to the
// one object the test made itself.
import { acceptConfirm, expect, expectToast, requiresS3, reseed, test, testid, tinker } from './support/fixtures.js';
import { pngFixture, uniqueColor, uniqueName } from './support/files.js';

/** Upload a file, then orphan its object by deleting only the database row. */
async function orphanAnObject(page) {
    const name = uniqueName('e2e-discover');

    await page.goto('/assets/create');
    await expect(page.locator(testid('upload-page'))).toBeVisible();
    await page.setInputFiles(testid('upload-input'), pngFixture(name, { color: uniqueColor() }));
    await expect(page.locator(testid('upload-row'))).toHaveCount(1);

    const [response] = await Promise.all([
        page.waitForResponse((r) => r.request().method() === 'POST' && /\/assets$/.test(new URL(r.url()).pathname), {
            timeout: 60_000,
        }),
        page.click(testid('upload-submit')),
    ]);
    expect(response.ok()).toBeTruthy();

    // forceDelete, not delete(): a soft-deleted row still maps the object, and
    // discovery would rightly skip it.
    await tinker(`App\\Models\\Asset::withTrashed()->where('filename', '${name}')->forceDelete();`);

    return name;
}

test.describe('s3 discovery', () => {
    requiresS3();

    test.beforeAll(reseed);

    test('an object with no database row is found and can be imported', async ({ page }) => {
        const orphan = await orphanAnObject(page);

        await page.goto('/discover');
        await expect(page.locator(testid('discover-page'))).toBeVisible();

        await page.click(testid('discover-scan'));
        await expect(page.locator(testid('discover-results'))).toBeVisible();

        const row = page.locator(testid('discover-row')).filter({ hasText: orphan });
        await expect(row).toHaveCount(1);

        await row.locator(testid('discover-row-select')).click();
        acceptConfirm(page);
        await page.click(testid('discover-import'));

        await expectToast(page, /import/i);

        // The object now has a row again, so it is no longer unmapped — and it stays
        // gone through the re-scan the module fires a couple of seconds later.
        await expect(page.locator(testid('discover-row')).filter({ hasText: orphan })).toHaveCount(0);

        // And it really became an asset.
        await page.goto(`/assets?search=${orphan.replace('.png', '')}`);
        await expect(page.locator(testid('asset-card'))).toHaveCount(1);
    });

    test('selecting all then deselecting all clears the import button', async ({ page }) => {
        await orphanAnObject(page);

        await page.goto('/discover');
        await page.click(testid('discover-scan'));
        await expect(page.locator(testid('discover-results'))).toBeVisible();

        await page.click(testid('discover-select-all'));
        await expect(page.locator(testid('discover-import'))).toBeEnabled();

        await page.click(testid('discover-deselect-all'));
        await expect(page.locator(testid('discover-import'))).toBeDisabled();
    });
});
