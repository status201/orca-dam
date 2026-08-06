// The shared batch-metadata form (resources/js/alpine/upload-metadata.js) — pins
// the batch-metadata half of specs/features/tag-input.md.
//
// The partial is included by /assets/create, /tools/tikz-server and
// /tools/gif-maker, but only the upload page renders it unconditionally — on the
// two tool pages it sits behind an x-show that needs a rendered result first.
//
// No requiresS3() guard: nothing here uploads anything. Visiting /assets/create
// and filling the form touches the database and the tag search only; it is
// asset-upload.spec.js, which pushes real bytes, that needs the bucket.
import { expect, test, testid } from './support/fixtures.js';

const badge = (page, name) => page.locator(`${testid('batch-metadata-tag')}[data-tag-name="${name}"]`);

async function gotoUploadPage(page) {
    await page.goto('/assets/create');
    await expect(page.locator(testid('upload-page'))).toBeVisible();
}

async function openBatchMetadata(page) {
    await gotoUploadPage(page);

    await page.click(testid('batch-metadata-toggle'));
    await expect(page.locator(testid('batch-metadata-panel'))).toBeVisible();
}

test.describe('batch metadata form', () => {
    test('the panel starts collapsed and opens on click', async ({ page }) => {
        await gotoUploadPage(page);

        await expect(page.locator(testid('batch-metadata-panel'))).toBeHidden();

        await page.click(testid('batch-metadata-toggle'));
        await expect(page.locator(testid('batch-metadata-panel'))).toBeVisible();
    });

    test('comma-separated input becomes one badge per tag', async ({ page }) => {
        await openBatchMetadata(page);

        await page.fill(testid('batch-metadata-tag-input'), 'e2e-batch-one,e2e-batch-two');
        await page.press(testid('batch-metadata-tag-input'), 'Enter');

        await expect(page.locator(testid('batch-metadata-tag'))).toHaveCount(2);
        await expect(badge(page, 'e2e-batch-one')).toBeVisible();
        await expect(badge(page, 'e2e-batch-two')).toBeVisible();
        // The input is consumed, not left holding the raw text.
        await expect(page.locator(testid('batch-metadata-tag-input'))).toHaveValue('');
    });

    test('a tag already added is not added twice', async ({ page }) => {
        await openBatchMetadata(page);

        await page.fill(testid('batch-metadata-tag-input'), 'e2e-batch-dupe');
        await page.click(testid('batch-metadata-tag-add'));
        await expect(page.locator(testid('batch-metadata-tag'))).toHaveCount(1);

        await page.fill(testid('batch-metadata-tag-input'), 'e2e-batch-dupe');
        await page.click(testid('batch-metadata-tag-add'));

        await expect(page.locator(testid('batch-metadata-tag'))).toHaveCount(1);
    });

    test('a badge can be removed again', async ({ page }) => {
        await openBatchMetadata(page);

        await page.fill(testid('batch-metadata-tag-input'), 'e2e-batch-keep,e2e-batch-drop');
        await page.press(testid('batch-metadata-tag-input'), 'Enter');
        await expect(page.locator(testid('batch-metadata-tag'))).toHaveCount(2);

        await badge(page, 'e2e-batch-drop').locator(testid('batch-metadata-tag-remove')).click();

        await expect(page.locator(testid('batch-metadata-tag'))).toHaveCount(1);
        await expect(badge(page, 'e2e-batch-keep')).toBeVisible();
    });

    test('a reference tag chosen from the suggestions is kept separate from user tags', async ({ page }) => {
        await openBatchMetadata(page);

        // The lookup is debounced by 300ms and filtered to user + reference tags.
        await page.fill(testid('batch-metadata-tag-input'), 'e2e-reference');
        const suggestion = page.locator(`${testid('batch-metadata-suggestion')}[data-tag-name="e2e-reference-tag"]`);
        await expect(suggestion).toBeVisible();

        await suggestion.click();

        // Reference tags carry an id and render as their own badge type, so they
        // can be attributed differently from a plain user tag.
        await expect(page.locator(`${testid('batch-metadata-ref-tag')}[data-tag-name="e2e-reference-tag"]`)).toBeVisible();
        await expect(page.locator(testid('batch-metadata-tag'))).toHaveCount(0);
    });

    test('the copyright counter reports the count against the limit', async ({ page }) => {
        // The upload page's half of input-validation.md REQ-7. maxlength truncates in silence, so
        // without the counter a pasted copyright is cut at 500 with nothing on screen to say so.
        await openBatchMetadata(page);

        const counter = page.locator(testid('char-counter-batch-metadata-copyright'));
        await expect(counter).toContainText('0 / 500');

        await page.fill(testid('batch-metadata-copyright'), `© ORCA e2e ${'abcdefghij'.repeat(50)}`.slice(0, 500));

        await expect(counter).toContainText('500 / 500');
    });

    test('the collapsed header reports that metadata is set', async ({ page }) => {
        await openBatchMetadata(page);

        await expect(page.locator(testid('batch-metadata-set'))).toBeHidden();

        await page.fill(testid('batch-metadata-copyright'), '© ORCA E2E');

        await expect(page.locator(testid('batch-metadata-set'))).toBeVisible();
    });
});
