// Direct upload through the browser into MinIO — pins specs/features/asset-upload.md,
// specs/features/duplicate-detection.md and the storage boundary of
// specs/features/s3-storage.md. Needs real object storage (requiresS3).
import {
    assetCard,
    expect,
    gotoAssets,
    requiresS3,
    reseed,
    test,
    testid,
    useViewMode,
} from './support/fixtures.js';
import { pngFixture, uniqueColor, uniqueName } from './support/files.js';

/**
 * Stage one file and submit, resolving with the POST /assets response.
 *
 * A fully clean batch redirects to the library ~1s later, so the "Uploaded" badge
 * is not a stable thing to assert on — the response is.
 */
async function upload(page, file, { keepOriginalFilename = false } = {}) {
    await page.goto('/assets/create');
    await expect(page.locator(testid('upload-page'))).toBeVisible();

    if (keepOriginalFilename) {
        // Turning the toggle on fires a native confirm() (asset-uploader.js); Alpine only sets the
        // flag once it is accepted, so without this handler the checkbox silently unticks itself.
        page.once('dialog', (dialog) => dialog.accept());
        await page.click(testid('upload-keep-filename-input'));
        await expect(page.locator(testid('upload-keep-filename-input'))).toBeChecked();
    }

    await page.setInputFiles(testid('upload-input'), file);
    // Alpine has rendered the staged row, so the submit button exists.
    await expect(page.locator(testid('upload-row'))).toHaveCount(1);
    await expect(page.locator(testid('upload-submit'))).toBeVisible();

    const [response] = await Promise.all([
        page.waitForResponse((r) => r.request().method() === 'POST' && /\/assets$/.test(new URL(r.url()).pathname), {
            timeout: 60_000,
        }),
        page.click(testid('upload-submit')),
    ]);

    return response;
}

test.describe('asset upload', () => {
    requiresS3();
    test.beforeAll(reseed);

    test('uploading an image stores it and its generated thumbnail is publicly fetchable', async ({ page }) => {
        const name = uniqueName('e2e-upload');

        const response = await upload(page, pngFixture(name, { color: uniqueColor() }));
        expect(response.ok()).toBeTruthy();

        await gotoAssets(page, { search: name.replace('.png', '') });
        const card = assetCard(page, name);
        await expect(card).toBeVisible();

        // The thumbnail was generated server-side (sync queue) and served straight
        // from the bucket — the part no Pest test can assert.
        const thumb = card.locator('img').first();
        await expect(thumb).toBeVisible();
        const src = await thumb.getAttribute('src');
        expect(src).toContain('thumbnails/');

        const object = await page.request.get(src);
        expect(object.ok(), `GET ${src} responded ${object.status()}`).toBeTruthy();
        expect(object.headers()['content-type']).toContain('image');
    });

    test('re-uploading identical bytes is reported as a duplicate, not stored twice', async ({ page }) => {
        const color = uniqueColor();
        const first = uniqueName('e2e-dupe');

        expect((await upload(page, pngFixture(first, { color }))).ok()).toBeTruthy();

        // Same bytes, different filename → same etag → duplicate.
        const again = await upload(page, pngFixture(uniqueName('e2e-dupe-again'), { color }));
        expect(again.status()).toBe(409);

        // A duplicate keeps the user on the page, so the panel is stable.
        await expect(page.locator(testid('upload-status-duplicate'))).toBeVisible();
        await expect(page.locator(testid('upload-duplicates'))).toContainText(first);
    });

    test('identical bytes under a different name are a duplicate even when keeping the filename', async ({ page }) => {
        // The reported bug, against a real MinIO etag — the only place in the suite where the etag
        // is computed from the bytes rather than stipulated by a mock. The dedup check used to be
        // gated on the keep-filename flag rather than on an actual s3_key collision, so ticking the
        // box turned dedup off entirely and this second upload became a second asset.
        const color = uniqueColor();
        const first = uniqueName('e2e-keepdupe');

        expect((await upload(page, pngFixture(first, { color }), { keepOriginalFilename: true })).ok()).toBeTruthy();

        const again = await upload(page, pngFixture(uniqueName('e2e-keepdupe-again'), { color }), {
            keepOriginalFilename: true,
        });
        expect(again.status()).toBe(409);

        await expect(page.locator(testid('upload-status-duplicate'))).toBeVisible();
        await expect(page.locator(testid('upload-duplicates'))).toContainText(first);
    });

    test('an upload lands in the selected folder', async ({ page }) => {
        const name = uniqueName('e2e-folder');

        await page.goto('/assets/create');
        await page.selectOption(testid('upload-folder'), 'assets/e2e');
        await page.setInputFiles(testid('upload-input'), pngFixture(name, { color: uniqueColor() }));
        await expect(page.locator(testid('upload-submit'))).toBeVisible();
        const [response] = await Promise.all([
            page.waitForResponse((r) => r.request().method() === 'POST' && /\/assets$/.test(new URL(r.url()).pathname)),
            page.click(testid('upload-submit')),
        ]);
        expect(response.ok()).toBeTruthy();

        await gotoAssets(page, { search: name.replace('.png', '') });
        await useViewMode(page, 'list');
        await expect(page.locator(testid('asset-row')).first()).toContainText('assets/e2e/');
    });

    test('a disallowed file type is rejected', async ({ page }) => {
        const response = await upload(page, {
            name: 'e2e-not-allowed.exe',
            mimeType: 'application/x-msdownload',
            buffer: Buffer.from('MZ not really an executable'),
        });

        expect(response.status()).toBe(422);
        // A failure also keeps the user on the page.
        await expect(page.locator(testid('upload-status-failed'))).toBeVisible();
    });
});
