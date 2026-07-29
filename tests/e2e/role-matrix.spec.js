// The role × ability matrix as a user actually experiences it — pins
// specs/features/authorization-policies.md. Every assertion here has a Pest
// counterpart at the HTTP layer; what this adds is that the UI does not *offer*
// the action in the first place.
import { asApi, asEditor, expect, gotoAssets, gotoTrash, reseed, test, testid, useTrashViewMode, users } from './support/fixtures.js';

test.describe('admin', () => {
    test('sees every privileged navigation entry', async ({ page }) => {
        await page.goto('/dashboard');

        await expect(page.locator(testid('nav-users'))).toBeVisible();
        await page.click(testid('nav-user-menu'));
        await expect(page.locator(testid('nav-system'))).toBeVisible();
        await expect(page.locator(testid('nav-api'))).toBeVisible();
        await expect(page.locator(testid('nav-import'))).toBeVisible();
    });

    test('can reach the admin-only pages', async ({ page }) => {
        for (const path of ['/system', '/api-docs', '/import', '/export', '/discover', '/users']) {
            const response = await page.request.get(path);
            expect(response.status(), `GET ${path}`).toBe(200);
        }
    });
});

test.describe('editor', () => {
    test.use({ storageState: asEditor });

    test('sees no admin-only navigation', async ({ page }) => {
        await page.goto('/dashboard');

        await expect(page.locator(testid('nav-users'))).toHaveCount(0);
        await page.click(testid('nav-user-menu'));
        await expect(page.locator(testid('nav-system'))).toHaveCount(0);
        await expect(page.locator(testid('nav-api'))).toHaveCount(0);
        await expect(page.locator(testid('nav-import'))).toHaveCount(0);
    });

    test('is refused the admin-only routes', async ({ page }) => {
        for (const path of ['/system', '/api-docs', '/import', '/export', '/discover', '/users']) {
            const response = await page.request.get(path);
            expect(response.status(), `GET ${path}`).toBe(403);
        }
    });

    test('can trash and restore but gets no permanent-delete control', async ({ page }) => {
        await gotoTrash(page);
        await useTrashViewMode(page, 'list');

        const row = page.locator(testid('trash-row')).first();
        await expect(row.locator(testid('trash-row-restore'))).toBeVisible();
        await expect(row.locator(testid('trash-row-force-delete'))).toHaveCount(0);
    });

    test('gets no permanent-delete or move control in the bulk bar', async ({ page }) => {
        await gotoAssets(page, { search: 'e2e-grid-01' });
        await page.locator(testid('asset-card')).first().hover();
        await page.locator(testid('asset-card-checkbox')).first().click();

        await expect(page.locator(testid('bulk-bar'))).toBeVisible();
        await expect(page.locator(testid('bulk-trash'))).toBeVisible();
        await expect(page.locator(testid('bulk-force-delete'))).toHaveCount(0);
        await expect(page.locator(testid('bulk-move'))).toHaveCount(0);
    });
});

test.describe('api role', () => {
    test.use({ storageState: asApi });

    test('can browse but is offered no destructive controls', async ({ page }) => {
        await gotoAssets(page, { search: 'e2e-grid-01' });
        await page.locator(testid('asset-card')).first().hover();
        await page.locator(testid('asset-card-checkbox')).first().click();

        await expect(page.locator(testid('bulk-bar'))).toBeVisible();
        await expect(page.locator(testid('bulk-download'))).toBeVisible();
        await expect(page.locator(testid('bulk-trash'))).toHaveCount(0);
        await expect(page.locator(testid('bulk-force-delete'))).toHaveCount(0);
    });

    test('is refused the trash page and the admin pages', async ({ page }) => {
        for (const path of ['/assets/trash/index', '/system', '/users']) {
            const response = await page.request.get(path);
            expect(response.status(), `GET ${path}`).toBe(403);
        }
    });

    test('cannot delete an asset through the REST API', async ({ api }) => {
        const client = await api('api');
        const list = await client.get('/api/assets');
        expect(list.ok()).toBeTruthy();

        const id = (await list.json()).data[0].id;
        const response = await client.delete(`/api/assets/${id}`);

        expect(response.status()).toBe(403);
    });

    test('can read any asset but only update its own', async ({ api }) => {
        const client = await api('api');

        const mine = (await (await client.get('/api/assets?search=e2e-api-owned-01')).json()).data[0];
        const someoneElses = (await (await client.get('/api/assets?search=e2e-grid-01')).json()).data[0];

        // Reading is unrestricted for the role.
        expect((await client.get(`/api/assets/${someoneElses.id}`)).ok()).toBeTruthy();

        // Writing is not: AssetApiController::update refuses a non-admin editing
        // another user's asset, even though the role holds the `update` ability.
        const foreign = await client.patch(`/api/assets/${someoneElses.id}`, {
            data: { alt_text: 'should not stick' },
        });
        expect(foreign.status()).toBe(403);

        const own = await client.patch(`/api/assets/${mine.id}`, {
            data: { alt_text: 'set by the api role' },
        });
        expect(own.ok()).toBeTruthy();
        expect((await own.json()).data?.alt_text ?? (await own.json()).alt_text).toBe('set by the api role');
    });
});

test.describe('editor via the REST API', () => {
    // Reseed in beforeAll, not in the test: the `api` fixture reads the token
    // file when it is created, and a mid-test reseed would invalidate it.
    test.beforeAll(reseed);

    test('can delete an asset, unlike the api role', async ({ api }) => {
        const client = await api('editor');
        const list = await client.get('/api/assets?search=e2e-bulk-06');
        const asset = (await list.json()).data[0];
        expect(asset.filename).toBe('e2e-bulk-06.png');

        const response = await client.delete(`/api/assets/${asset.id}`);

        expect(response.ok()).toBeTruthy();
    });
});

test.describe('every role', () => {
    test('lands on a dashboard that names them', async ({ browser }) => {
        for (const [role, state] of [['editor', asEditor], ['api', asApi]]) {
            const context = await browser.newContext({ storageState: state });
            const page = await context.newPage();
            await page.goto('/dashboard');

            await expect(page.locator(testid('nav-user-menu'))).toContainText(users[role].name);
            await context.close();
        }
    });
});
