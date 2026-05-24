// @ts-check
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const STATE_PATH = path.resolve(__dirname, '.auth/admin.json');

test('admin login + mock plugin installation', async ({ page, request }) => {
    fs.mkdirSync(path.dirname(STATE_PATH), { recursive: true });

    // Install the mock MU-plugin via WP-CLI by copying it into wp-content/mu-plugins.
    // wp-env exposes this through `npx wp-env run cli ...`, but in CI we already
    // bind-mount the project root, so the MU-plugin sits at
    // `wp-content/mu-plugins/orca-dam-mock.php` via wp-env's `mappings` (configured
    // in .wp-env.json).

    await page.goto('/wp-login.php');
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'password');
    await page.click('#wp-submit');
    await expect(page).toHaveURL(/wp-admin/);

    await page.context().storageState({ path: STATE_PATH });
});
