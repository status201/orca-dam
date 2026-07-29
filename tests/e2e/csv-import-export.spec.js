// CSV metadata import + asset export — pins specs/features/csv-export-import.md.
// Both are admin-only, so the default admin storageState is what we want.
//
// These are the two Alpine modules with the most state and the least server-side
// rendering: import.js drives a three-step machine entirely from fetch results,
// and export.js has no success UI at all (its submit is a native form POST that
// streams a file back), so the download is the only observable outcome.
import { expect, reseed, test, testid } from './support/fixtures.js';

// Matching is on s3_key, never filename: `filename` is itself an updatable
// field, so a filename-keyed row writes the value it matched on and counts as
// "updated" even when nothing changed — which would make the counts meaningless.
const IMPORT_CSV = [
    's3_key,alt_text,caption',
    'assets/e2e/e2e-import-01.png,Alt text from the CSV,Caption from the CSV',
    'assets/e2e/e2e-import-nonexistent.png,Never matched,Never matched',
].join('\n');

async function preview(page, csv) {
    await page.goto('/import');
    await expect(page.locator(testid('import-page'))).toBeVisible();

    await page.fill(testid('import-csv'), csv);
    await page.click(testid('import-preview'));
}

/** Read a triggered download as text. */
async function downloadText(download) {
    const stream = await download.createReadStream();
    const chunks = [];
    for await (const chunk of stream) chunks.push(chunk);

    return Buffer.concat(chunks).toString('utf8');
}

test.describe('csv metadata import', () => {
    test.beforeAll(reseed);

    test('a preview reports matched and unmatched rows before anything is written', async ({ page }) => {
        await preview(page, IMPORT_CSV);

        await expect(page.locator(testid('import-preview-panel'))).toBeVisible();
        await expect(page.locator(testid('import-count-total'))).toHaveText('2');
        await expect(page.locator(testid('import-count-matched'))).toHaveText('1');
        await expect(page.locator(testid('import-count-unmatched'))).toHaveText('1');

        // The matched row names the asset it resolved to; the missing key is listed
        // separately rather than silently dropped.
        await expect(page.locator(testid('import-result-row'))).toHaveCount(1);
        await expect(page.locator(testid('import-result-row'))).toHaveAttribute('data-filename', 'e2e-import-01.png');
        await expect(page.locator(testid('import-unmatched-row'))).toContainText('e2e-import-nonexistent.png');
    });

    test('importing writes the CSV metadata onto the matched asset', async ({ page }) => {
        await preview(page, IMPORT_CSV);
        await page.click(testid('import-run'));

        await expect(page.locator(testid('import-done-panel'))).toBeVisible();
        await expect(page.locator(testid('import-done-updated'))).toHaveText('1');
        // The unmatched row is skipped, not an error.
        await expect(page.locator(testid('import-done-skipped'))).toHaveText('1');
        await expect(page.locator(testid('import-done-errors'))).toHaveText('0');

        // And it really landed on the asset, not just in the response.
        await page.goto('/assets?search=e2e-import-01');
        await page.locator(testid('asset-card')).first().click();
        await expect(page.locator(testid('asset-detail'))).toContainText('Alt text from the CSV');
    });

    test('user_tags are added to an asset without removing the tags it already has', async ({ page }) => {
        await preview(page, [
            's3_key,user_tags',
            'assets/e2e/e2e-import-02.png,"e2e-import-added"',
        ].join('\n'));

        await page.click(testid('import-run'));
        await expect(page.locator(testid('import-done-updated'))).toHaveText('1');

        await page.goto('/assets?search=e2e-import-02');
        await page.locator(testid('asset-card')).first().click();
        const detail = page.locator(testid('asset-detail'));
        await expect(detail).toContainText('e2e-import-added');
        // The seeded tag survived the import.
        await expect(detail).toContainText('e2e-import-existing');
    });

    test('a CSV without the match column is rejected instead of importing nothing silently', async ({ page }) => {
        await preview(page, ['alt_text,caption', 'Orphan row,No key here'].join('\n'));

        await expect(page.locator(testid('import-error'))).toBeVisible();
        // And it never advanced past the paste step.
        await expect(page.locator(testid('import-preview-panel'))).toBeHidden();
    });

    test('Start Over clears the pasted CSV and returns to the paste step', async ({ page }) => {
        await preview(page, IMPORT_CSV);
        await page.click(testid('import-run'));
        await expect(page.locator(testid('import-done-panel'))).toBeVisible();

        await page.click(testid('import-start-over'));

        await expect(page.locator(testid('import-done-panel'))).toBeHidden();
        await expect(page.locator(testid('import-csv'))).toHaveValue('');
    });
});

test.describe('csv asset export', () => {
    // Read-only: the export streams a file and mutates nothing, so no reseed.

    test('the tag filter loads its tags from the API', async ({ page }) => {
        await page.goto('/export');
        await expect(page.locator(testid('export-page'))).toBeVisible();

        // The list is fetched, not server-rendered — a seeded tag appearing proves
        // the request resolved and the module rendered it.
        await expect(page.locator(`${testid('export-tag')}[data-tag-name="e2e-export-only"]`)).toBeVisible();
        await expect(page.locator(testid('export-tags-empty'))).toBeHidden();
    });

    test('a tag-filtered export contains only the assets carrying that tag', async ({ page }) => {
        await page.goto('/export');
        const tag = page.locator(`${testid('export-tag')}[data-tag-name="e2e-export-only"]`);
        await expect(tag).toBeVisible();

        // click(), not check(): selecting a tag re-renders it into the "pinned"
        // block, so the element check() would verify against is already gone.
        await tag.locator('input[type="checkbox"]').click();

        // The selection reaches the server only as Alpine-rendered hidden inputs,
        // so this is what has to exist before the form is submitted.
        await expect(page.locator('input[name="tags[]"]')).toHaveCount(1);

        const downloadPromise = page.waitForEvent('download');
        await page.click(testid('export-download'));
        const download = await downloadPromise;

        expect(download.suggestedFilename()).toMatch(/^orca-assets-export-.*\.csv$/);

        const csv = await downloadText(download);
        expect(csv).toContain('e2e-export-01.png');
        // An asset without the tag is excluded — the filter was actually applied.
        expect(csv).not.toContain('e2e-grid-01.png');
    });

    test('Reset Filters clears an active filter back to exporting everything', async ({ page }) => {
        await page.goto('/export');
        await expect(page.locator(testid('export-page'))).toBeVisible();

        await page.selectOption(testid('export-folder'), 'assets/e2e');
        await expect(page.locator(testid('export-page'))).toContainText('assets/e2e');

        await page.click(testid('export-reset'));

        await expect(page.locator(testid('export-folder'))).toHaveValue('');
    });
});
